//! E18 (ADR-0020): universal park — the Rust side of the interposed libc calls in csrc/park.c.
//!
//! Three questions, in order, on every interposed call:
//! 1. **Gate** (a thread-local byte, ~8 ns — research 28): `0` = not inside a fiber on a PHP thread,
//!    `1` = inside one, `2` = inside one of these handlers already. Anything but `1` forwards to
//!    the raw syscall at once; that is the path every tokio thread, offload worker, curl resolver
//!    thread and nested call takes.
//! 2. **Policy**: the caller's return address (captured by the C shim) is resolved with `dladdr`
//!    once per call site and cached; only a library named in `IGNIS_PARK` (e.g. `libcurl,libpq`)
//!    parks. An entry may name one interposed symbol — `libphp:usleep` — because libphp is built
//!    with `-fvisibility=hidden` and `dladdr` cannot see its `zif_*` call sites (ADR-0037 §2: the
//!    key is library × symbol; a call site calls exactly one symbol, so the per-site cache holds).
//!    parks. The default for everyone else — libphp included — is `block`: delegating is always
//!    semantically correct, parking wrongly is a hang (ADR-0018).
//! 3. **Readiness first** (the A4 rule; H31's lesson that a reactor round trip costs ~100 µs at
//!    low concurrency, V-33): a zero-timeout `poll` on the fd; only if it is not ready does the
//!    fiber park on `Op::Watch` and make the real call when woken.
//!
//! Every forward is `libc::syscall(SYS_*)`, never the libc wrapper, because the wrapper *is* the
//! symbol we export — a handler must not be able to re-enter itself.
//!
//! The gate is set by a fiber-switch observer of its own (`install`), independent of the
//! superglobals one (ADR-0006): switching into any non-main fiber sets `1`, returning to the main
//! context sets `0`. `IGNIS_NO_UNIVERSAL_PARK=1` is the hook-off control: the observer is not
//! registered and every call forwards.
//!
//! Interposed today: read/write/recv/send/recvfrom/sendto/poll/ppoll/`__poll_chk`/select/connect/
//! accept/accept4/the vectored calls/nanosleep/usleep/sleep/flock — stage 1 (V-45) and stage 2
//! (V-47, V-48) both shipped. `getaddrinfo` is not: this box's libcurl resolves on a helper thread
//! the `poll` interposer already catches, and the runtime resolver is recorded as a risk rather than
//! built (R-DNS, owner decision 2026-09-17). Cancellation of a parked call (ADR-0009) falls back to
//! the blocking call.
//!
//! The module is gated by `php/mod.rs`; no inner attribute is needed here.

use std::cell::{Cell, RefCell};
use std::collections::{BTreeMap, HashMap};
use std::ffi::{CStr, c_char, c_int, c_uint, c_void};
use std::sync::atomic::{AtomicBool, AtomicU64, Ordering};
use std::sync::{Mutex, OnceLock};

use super::tsrm;
use ignis_sys as sys;

use super::wait::{await_any, await_op};
use crate::lock::LockUnpoisoned;
use crate::reactor::{Op, Outcome};

thread_local! {
    /// 0 = no fiber, 1 = fiber active, 2 = inside a handler.
    static PARK: Cell<u8> = const { Cell::new(0) };
    /// Return address → does this call site's library park? Filled by `dladdr` once per site.
    static SITES: RefCell<HashMap<usize, bool>> = RefCell::new(HashMap::new());
}

/// Policy rows from `IGNIS_PARK`: `lib` (every symbol) or `lib:symbol`, comma-separated basename
/// prefixes. Unset = the seed below; set but empty = nothing parks.
static LIBS: OnceLock<Vec<(String, Option<String>)>> = OnceLock::new();

/// The default table (ADR-0037 §2): research 27's verdicts (libcurl, libpq, OpenSSL park) plus
/// libphp's audited groups (research 30: (a) ext/sockets, (b) sleep, (c) streams/network/openssl —
/// every row lock-free). Anything libphp calls that is not listed stays `block`; `getaddrinfo`
/// has no fd and is offload's, not park's.
const SEED: &str = "libphp:sleep,libphp:usleep,libphp:nanosleep,libphp:select,libphp:accept,libphp:poll,libphp:recv,libphp:send,libphp:recvfrom,libphp:sendto,libphp:recvmsg,libphp:sendmsg,libphp:connect,libphp:read,libphp:write,libphp:flock,libphp:waitpid,libcurl,libpq,libssl,libcrypto";

/// Every `(library, symbol)` pair that has actually made an interposed call in this process, and
/// whether the policy let it park.
///
/// The policy is a whitelist of shared objects, so a library nobody listed blocks the OS thread and
/// says nothing about it — and which libraries an application even has is a property of its
/// deployment, not of this repository: a PECL extension is its own `.so`, and `deb.sury.org` ships
/// 85 of them for PHP 8.5. Guessing the list is therefore not possible and reading it off a running
/// process is, because `site_parks` already resolves the calling object through `dladdr`. This is
/// that resolution, kept.
///
/// Written on the cache-miss path only — once per distinct call site, never per call — so the cost
/// is the same `dladdr` that was already being paid.
static INVENTORY: Mutex<BTreeMap<String, BTreeMap<String, bool>>> = Mutex::new(BTreeMap::new());

/// What has called us so far, library by library. `false` means the call blocked the thread.
pub fn inventory() -> BTreeMap<String, BTreeMap<String, bool>> {
    INVENTORY.lock_unpoisoned().clone()
}

/// Set only while the boot self-check probes: makes `site_parks` record which library each
/// resolved call site came from, so the check can prove a third-party `.so` really binds to us.
static PROBING: AtomicBool = AtomicBool::new(false);
static PROBE_HITS: Mutex<Vec<String>> = Mutex::new(Vec::new());

/// How many times a call site whose policy says `park` could not park and blocked instead
/// (ADR-0037 §4's detector, the surprising half: policy said park, the runtime could not).
/// Read by `ignis_stats()`; a non-zero value means a fiber thread blocked where it should not have.
pub static PARK_FAILED: AtomicU64 = AtomicU64::new(0);

/// Called by the four park helpers when they give up and the caller falls through to the blocking
/// syscall. Logged at `warn` because this is not supposed to happen on a worker thread.
fn park_failed(what: &str) {
    PARK_FAILED.fetch_add(1, Ordering::Relaxed);
    tracing::warn!(what, "universal park: policy says park but the call could not park — it blocked the thread");
}

/// `IGNIS_PARK_TRACE=1`: one stderr line per decision, for diagnosing a library that misbehaves
/// under `park`. Off by default; the check is a `OnceLock<bool>` load.
fn trace(msg: &str) {
    static ON: OnceLock<bool> = OnceLock::new();
    if *ON.get_or_init(|| std::env::var_os("IGNIS_PARK_TRACE").is_some()) {
        // Raw syscall on purpose: `eprintln!` would go through the interposed `write`.
        let line = format!("park: {msg}\n");
        // SAFETY: `line` is a live local and its length is its own; write(2) only reads those bytes.
        unsafe { libc::syscall(libc::SYS_write, 2, line.as_ptr(), line.len()) };
    }
}

fn libs() -> &'static [(String, Option<String>)] {
    LIBS.get_or_init(|| {
        let v = std::env::var("IGNIS_PARK").unwrap_or_else(|_| SEED.to_string());
        v.split(',')
            .map(str::trim)
            .filter(|s| !s.is_empty())
            .map(|s| match s.split_once(':') {
                Some((l, sym)) => (l.to_string(), Some(sym.to_string())),
                None => (s.to_string(), None),
            })
            .collect()
    })
}

/// One-line description of the active policy, for the startup banner: libraries, and how many
/// symbol-scoped rows sit behind each (`libphp:15` reads better than fifteen names).
pub fn policy_summary() -> String {
    if std::env::var_os("IGNIS_NO_UNIVERSAL_PARK").is_some() {
        return "off".into();
    }
    let mut whole: Vec<&str> = Vec::new();
    let mut scoped: BTreeMap<&str, usize> = BTreeMap::new();
    for (lib, sym) in libs() {
        match sym {
            None => whole.push(lib.as_str()),
            Some(_) => *scoped.entry(lib.as_str()).or_default() += 1,
        }
    }
    if whole.is_empty() && scoped.is_empty() {
        return "none".into();
    }
    let mut parts: Vec<String> = whole.iter().map(|l| (*l).to_string()).collect();
    parts.extend(scoped.iter().map(|(l, n)| format!("{l}:{n} symbols")));
    parts.join(",")
}

/// Registers the gate's fiber-switch observer. Called at MINIT (superglobals.rs).
pub fn install() {
    if std::env::var_os("IGNIS_NO_UNIVERSAL_PARK").is_some() {
        tracing::info!("universal park disabled (IGNIS_NO_UNIVERSAL_PARK)");
        return;
    }
    // SAFETY: zend_observer_startup() ran before MINIT; registering a second switch observer is
    // supported (observers are a list). The callback only touches thread-locals and EG().
    unsafe { sys::zend_observer_fiber_switch_register(Some(on_switch)) };
    tracing::info!(libs = ?libs(), "universal park installed");
}

unsafe extern "C" fn on_switch(_from: *mut sys::zend_fiber_context, to: *mut sys::zend_fiber_context) {
    // SAFETY: called by Zend on the switching thread with live contexts; EG() is this thread's.
    unsafe {
        let main = (*tsrm::executor_globals()).main_fiber_context;
        PARK.with(|p| p.set(if to == main { 0 } else { 1 }));
    }
}

/// Holds the gate at `2` while a handler runs; restores `1` on drop.
struct InHandler;
impl Drop for InHandler {
    fn drop(&mut self) {
        PARK.with(|p| p.set(1));
    }
}

/// `Some(guard)` only when this call may park: gate at 1 and (caller's library, `sym`) on the list.
unsafe fn may_park(ret: *const c_void, sym: &str) -> Option<InHandler> {
    if PARK.with(|p| p.get()) != 1 {
        return None;
    }
    PARK.with(|p| p.set(2));
    let guard = InHandler;
    // SAFETY: `ret` is a return address from the interposer, used only as a lookup key and passed to
    // dladdr, which validates it itself. The gate is already at 2, so site_parks cannot re-enter.
    if unsafe { site_parks(ret, sym) } { Some(guard) } else { None }
}

unsafe fn site_parks(ret: *const c_void, sym: &str) -> bool {
    let key = ret as usize;
    if let Some(b) = SITES.with(|s| s.borrow().get(&key).copied()) {
        return b;
    }
    // SAFETY: dladdr only reads the loader's tables; Dl_info is plain C data.
    let parks = unsafe {
        let mut info: libc::Dl_info = std::mem::zeroed();
        if libc::dladdr(ret, &mut info) != 0 && !info.dli_fname.is_null() {
            let name = CStr::from_ptr(info.dli_fname).to_string_lossy();
            let base = name.rsplit('/').next().unwrap_or(&name).to_string();
            let parks = libs().iter().any(|(l, s)| base.starts_with(l.as_str()) && s.as_deref().is_none_or(|s| s == sym));
            if PROBING.load(Ordering::Relaxed) && parks {
                PROBE_HITS.lock_unpoisoned().push(base.clone());
            }
            INVENTORY.lock_unpoisoned().entry(base.clone()).or_default().insert(sym.to_string(), parks);
            trace(&format!("site {key:#x} {sym} from {base}: parks={parks}"));
            parks
        } else {
            // Not in any loaded object (JIT'd code, a trampoline): nothing to key a policy on.
            trace(&format!("site {key:#x} {sym}: dladdr found no object, parks=false"));
            false
        }
    };
    SITES.with(|s| s.borrow_mut().insert(key, parks));
    parks
}

/// Would this call block in the kernel? Only then is parking a substitute for blocking.
///
/// A non-blocking fd never blocks: the library asked "is there data *now*" and expects `EAGAIN`,
/// then blocks in its own `poll`, which we park. Turning that probe into a wait is a hang — the
/// first curl_exec under park hung exactly there: after the body arrived, curl's next `recv` on
/// its keep-alive socket parked for data the server would never send (H31 at the syscall layer:
/// readiness is not what the caller asked for). A regular file cannot be parked on either (epoll
/// refuses it).
///
/// The question this answers is "is readiness meaningful for this descriptor", and the kernel's own
/// answer is whether `epoll_ctl` accepts it. Measured on 6.18 (`S_IFMT` → `epoll_ctl`): regular
/// file, directory, `/dev/null`, `/dev/zero` and `memfd` are all `EPERM`; pipes, sockets and every
/// **anonymous inode** are accepted. The last group is the one this used to get wrong. `eventfd`,
/// `timerfd`, `epoll`, `signalfd`, `inotify` and `pidfd` all report `S_IFMT == 0`, a value no real
/// file type has, and answering "cannot park" for them blocked the whole OS thread on descriptors
/// the reactor could have waited on perfectly well — a thread-pool wakeup through an eventfd, a
/// sleep on a timerfd, `signalfd`, `inotify`. Note that character devices are *not* in the group:
/// `/dev/null` is `EPERM`, so no blanket rule by device class would have been right.
///
/// This question is asked by the `read`/`write`/`recv`/`send`/`accept` families and by nothing else:
/// `poll()` and `select()` go through `poll_impl`, which submits an `Op::Watch` per descriptor
/// without consulting anything here. So an event loop is *not* covered — nothing `read()`s an epoll
/// descriptor, a loop calls `epoll_wait`, which is not interposed and would buy nothing if it were:
/// no library in the default policy imports it (measured, V-111).
/// What `fstat` reports for a descriptor with no file behind it — `eventfd`, `timerfd`, `epoll`,
/// `signalfd`, `inotify`, `pidfd`. Linux gives these an anonymous inode whose `S_IFMT` is zero,
/// which no real file type uses, so the value identifies the class exactly.
const ANONYMOUS_INODE: libc::mode_t = 0;

unsafe fn would_block(fd: c_int) -> bool {
    // SAFETY: F_GETFL takes no pointer and validates the descriptor itself, returning -1 for a bad
    // one -- which is handled on the next line.
    let flags = unsafe { libc::fcntl(fd, libc::F_GETFL) };
    if flags < 0 || flags & libc::O_NONBLOCK != 0 {
        return false;
    }
    // SAFETY: libc::stat is plain C data with no niche, so all-zero is a valid value to overwrite.
    let mut st: libc::stat = unsafe { std::mem::zeroed() };
    // SAFETY: fstat writes one struct stat into `st`, a live local of exactly that type.
    if unsafe { libc::fstat(fd, &mut st as *mut libc::stat) } != 0 {
        return false;
    }
    let kind = st.st_mode & libc::S_IFMT;
    if kind == libc::S_IFIFO || kind == ANONYMOUS_INODE {
        return true;
    }
    if kind != libc::S_IFSOCK {
        return false;
    }
    // A4's rule (ADR-0018, V-29), which the `ext/sockets` hooks used to own and which moves here
    // with them (ADR-0037 §6 step 3): readiness is NOT "the call would succeed". A data call on a
    // LISTENING socket fails with ENOTCONN at once, and `poll` on it only ever reports a pending
    // connection — parking is a hang, not a wait (php-src socket_read_params). Same for an
    // unconnected stream socket. A datagram socket needs no peer, only a local address: an
    // unbound one never becomes readable either.
    //
    // The direction is not known here, and it does not need to be: `accept` has its own handler
    // and never asks this question.
    // SAFETY: both helpers below only pass `fd` and their own local storage to getsockopt/getpeername,
    // which validate the descriptor themselves.
    unsafe {
        if getsockopt_int(fd, libc::SO_ACCEPTCONN) == Some(1) {
            return false; // listening: a data call errors out now
        }
        if is_connected(fd) {
            return true;
        }
        match getsockopt_int(fd, libc::SO_TYPE) {
            Some(t) if t == libc::SOCK_DGRAM => is_bound(fd), // recvfrom on a bound UDP socket waits legitimately
            _ => false,                                       // unconnected stream: ENOTCONN now
        }
    }
}

unsafe fn getsockopt_int(fd: c_int, opt: c_int) -> Option<c_int> {
    let mut v: c_int = 0;
    let mut l = size_of::<c_int>() as libc::socklen_t;
    // SAFETY: getsockopt only writes `l` bytes into `v`, which is a live local of that size.
    let rc = unsafe { libc::getsockopt(fd, libc::SOL_SOCKET, opt, &mut v as *mut c_int as *mut c_void, &mut l) };
    if rc == 0 { Some(v) } else { None }
}

/// Has a peer (`getpeername` succeeds) — i.e. the socket is connected.
unsafe fn is_connected(fd: c_int) -> bool {
    // SAFETY: sockaddr_storage is plain C data; all-zero is a valid value for getpeername to fill.
    let mut ss: libc::sockaddr_storage = unsafe { std::mem::zeroed() };
    let mut l = size_of::<libc::sockaddr_storage>() as libc::socklen_t;
    // SAFETY: getpeername writes at most `l` bytes into `ss`, a live local of that size.
    unsafe { libc::getpeername(fd, &raw mut ss as *mut libc::sockaddr, &mut l) == 0 }
}

/// Has a local address that can actually receive: a non-zero port for IP, a non-empty path for
/// AF_UNIX. An unbound socket never becomes readable, so waiting on one is a hang.
unsafe fn is_bound(fd: c_int) -> bool {
    // SAFETY: as `is_connected`; the family tag decides which member of the union is read.
    unsafe {
        let mut ss: libc::sockaddr_storage = std::mem::zeroed();
        let mut l = size_of::<libc::sockaddr_storage>() as libc::socklen_t;
        if libc::getsockname(fd, &raw mut ss as *mut libc::sockaddr, &mut l) != 0 {
            return false;
        }
        match ss.ss_family as i32 {
            libc::AF_INET => (*(&raw const ss as *const libc::sockaddr_in)).sin_port != 0,
            libc::AF_INET6 => (*(&raw const ss as *const libc::sockaddr_in6)).sin6_port != 0,
            libc::AF_UNIX => (*(&raw const ss as *const libc::sockaddr_un)).sun_path[0] != 0,
            _ => false,
        }
    }
}

/// Zero-timeout readiness probe, raw syscall.
unsafe fn ready_now(fd: c_int, events: i16) -> bool {
    let mut p = libc::pollfd { fd, events, revents: 0 };
    // SAFETY: one live pollfd, and the count says one. A zero timeout cannot block.
    unsafe { libc::syscall(libc::SYS_poll, &mut p as *mut libc::pollfd, 1usize, 0) > 0 }
}

/// Park until `fd` is ready in `dir`. `false` = could not park (no fiber/reactor, switching
/// blocked, unwound by a cancellation) — the caller then makes the blocking call as before.
unsafe fn park_on(fd: c_int, write: bool) -> bool {
    let Some(r) = super::module::try_reactor() else {
        park_failed("park_on: no reactor");
        return false;
    };
    let id = r.submit(Op::Watch { fd, write });
    trace(&format!("park_on fd={fd} write={write} op={id}"));
    // SAFETY: reached from an interposer on a PHP thread inside a fiber, which is await_any's
    // contract; `id` was just submitted to this thread's own reactor.
    let ok = unsafe { await_any(&[id]).is_some() };
    trace(&format!("park_on fd={fd} resumed ok={ok}"));
    if !ok {
        park_failed("park_on: the fiber could not suspend (switch blocked or unwinding)");
    }
    ok
}

/// The socket's own kernel timeout (`SO_RCVTIMEO` for reads, `SO_SNDTIMEO` for writes) in ms;
/// 0 = none, and 0 for anything that is not a socket (a pipe has no such option).
unsafe fn sock_timeout_ms(fd: c_int, write: bool) -> c_int {
    // SAFETY: timeval is two integers; all-zero is a valid value for getsockopt to overwrite.
    let mut tv: libc::timeval = unsafe { std::mem::zeroed() };
    let mut len = size_of::<libc::timeval>() as libc::socklen_t;
    let opt = if write { libc::SO_SNDTIMEO } else { libc::SO_RCVTIMEO };
    // SAFETY: `len` says how much of `tv` may be written and both are live locals of that size; a
    // non-socket fd just returns an error, which is handled.
    if unsafe { libc::getsockopt(fd, libc::SOL_SOCKET, opt, &mut tv as *mut libc::timeval as *mut c_void, &mut len) } != 0 {
        return 0;
    }
    milliseconds_ceil(tv.tv_sec as i64, tv.tv_usec as i64, 1000)
}

#[derive(PartialEq)]
enum Wait {
    /// Ready, or could not park: make the real call as before.
    Ready,
    /// The socket's own timeout elapsed first — the kernel would return `EAGAIN` now, and the
    /// real call would block for the whole timeout again, so the handler answers `EAGAIN` itself.
    TimedOut,
}

/// Park until `fd` is ready in `dir` or its `SO_RCVTIMEO`/`SO_SNDTIMEO` elapses (research 30:
/// `ext/sockets` users set those and expect the timeout; the point hook raced it, so does this).
unsafe fn park_io(fd: c_int, write: bool) -> Wait {
    // SAFETY: sock_timeout_ms only reads a socket option into its own storage.
    let timeout = unsafe { sock_timeout_ms(fd, write) };
    let fds = [libc::pollfd { fd, events: if write { libc::POLLOUT } else { libc::POLLIN }, revents: 0 }];
    trace(&format!("park_io fd={fd} write={write} timeout={timeout}"));
    // SAFETY: `fds` is a live local array and park_pollfds borrows it for the call only; we are on a
    // PHP thread inside a fiber, which is its contract.
    match unsafe { park_pollfds(&fds, timeout) } {
        Some(false) => Wait::TimedOut,
        _ => Wait::Ready,
    }
}

unsafe fn park_sleep(us: u64) -> bool {
    let Some(r) = super::module::try_reactor() else {
        park_failed("park_sleep: no reactor");
        return false;
    };
    let id = r.submit(Op::Sleep { us });
    // SAFETY: PHP thread inside a fiber (checked by the caller), and `id` is this thread's own op.
    unsafe { matches!(await_op(id), Some(Outcome::Slept { .. })) }
}

// ---- the handlers: signatures mirror csrc/park.c ---------------------------------------------

#[unsafe(no_mangle)]
pub unsafe extern "C" fn ignis_park_read(ret: *const c_void, fd: c_int, buf: *mut c_void, n: usize) -> isize {
    // SAFETY: csrc/park.c calls this from the interposed symbol with exactly the arguments libc was
    // given, and `ret` is that call site's return address. Nothing here dereferences the caller's
    // buffer -- it is handed straight back to the kernel -- so the syscall is as sound as the call
    // the program already made; parking only delays it.
    unsafe {
        if let Some(_g) = may_park(ret, "read")
            && would_block(fd)
            && !ready_now(fd, libc::POLLIN)
            && park_io(fd, false) == Wait::TimedOut
        {
            *libc::__errno_location() = libc::EAGAIN;
            return -1;
        }
        libc::syscall(libc::SYS_read, fd, buf, n) as isize
    }
}

#[unsafe(no_mangle)]
pub unsafe extern "C" fn ignis_park_write(ret: *const c_void, fd: c_int, buf: *const c_void, n: usize) -> isize {
    // SAFETY: csrc/park.c calls this from the interposed symbol with exactly the arguments libc was
    // given, and `ret` is that call site's return address. Nothing here dereferences the caller's
    // buffer -- it is handed straight back to the kernel -- so the syscall is as sound as the call
    // the program already made; parking only delays it.
    unsafe {
        if let Some(_g) = may_park(ret, "write")
            && would_block(fd)
            && !ready_now(fd, libc::POLLOUT)
            && park_io(fd, true) == Wait::TimedOut
        {
            *libc::__errno_location() = libc::EAGAIN;
            return -1;
        }
        libc::syscall(libc::SYS_write, fd, buf, n) as isize
    }
}

#[unsafe(no_mangle)]
pub unsafe extern "C" fn ignis_park_recv(ret: *const c_void, fd: c_int, buf: *mut c_void, n: usize, flags: c_int) -> isize {
    // SAFETY: csrc/park.c calls this from the interposed symbol with exactly the arguments libc was
    // given, and `ret` is that call site's return address. Nothing here dereferences the caller's
    // buffer -- it is handed straight back to the kernel -- so the syscall is as sound as the call
    // the program already made; parking only delays it.
    unsafe {
        if let Some(_g) = may_park(ret, "recv")
            && flags & libc::MSG_DONTWAIT == 0
            && would_block(fd)
            && !ready_now(fd, libc::POLLIN)
            && park_io(fd, false) == Wait::TimedOut
        {
            *libc::__errno_location() = libc::EAGAIN;
            return -1;
        }
        libc::syscall(libc::SYS_recvfrom, fd, buf, n, flags, std::ptr::null_mut::<c_void>(), std::ptr::null_mut::<c_void>()) as isize
    }
}

#[unsafe(no_mangle)]
pub unsafe extern "C" fn ignis_park_send(ret: *const c_void, fd: c_int, buf: *const c_void, n: usize, flags: c_int) -> isize {
    // SAFETY: csrc/park.c calls this from the interposed symbol with exactly the arguments libc was
    // given, and `ret` is that call site's return address. Nothing here dereferences the caller's
    // buffer -- it is handed straight back to the kernel -- so the syscall is as sound as the call
    // the program already made; parking only delays it.
    unsafe {
        if let Some(_g) = may_park(ret, "send")
            && flags & libc::MSG_DONTWAIT == 0
            && would_block(fd)
            && !ready_now(fd, libc::POLLOUT)
            && park_io(fd, true) == Wait::TimedOut
        {
            *libc::__errno_location() = libc::EAGAIN;
            return -1;
        }
        libc::syscall(libc::SYS_sendto, fd, buf, n, flags, std::ptr::null::<c_void>(), 0usize) as isize
    }
}

#[unsafe(no_mangle)]
pub unsafe extern "C" fn ignis_park_recvfrom(
    ret: *const c_void,
    fd: c_int,
    buf: *mut c_void,
    n: usize,
    flags: c_int,
    addr: *mut c_void,
    alen: *mut c_uint,
) -> isize {
    // SAFETY: csrc/park.c calls this from the interposed symbol with exactly the arguments libc was
    // given, and `ret` is that call site's return address. Nothing here dereferences the caller's
    // buffer -- it is handed straight back to the kernel -- so the syscall is as sound as the call
    // the program already made; parking only delays it.
    unsafe {
        if let Some(_g) = may_park(ret, "recvfrom")
            && flags & libc::MSG_DONTWAIT == 0
            && would_block(fd)
            && !ready_now(fd, libc::POLLIN)
            && park_io(fd, false) == Wait::TimedOut
        {
            *libc::__errno_location() = libc::EAGAIN;
            return -1;
        }
        libc::syscall(libc::SYS_recvfrom, fd, buf, n, flags, addr, alen) as isize
    }
}

#[unsafe(no_mangle)]
pub unsafe extern "C" fn ignis_park_sendto(
    ret: *const c_void,
    fd: c_int,
    buf: *const c_void,
    n: usize,
    flags: c_int,
    addr: *const c_void,
    alen: c_uint,
) -> isize {
    // SAFETY: csrc/park.c calls this from the interposed symbol with exactly the arguments libc was
    // given, and `ret` is that call site's return address. Nothing here dereferences the caller's
    // buffer -- it is handed straight back to the kernel -- so the syscall is as sound as the call
    // the program already made; parking only delays it.
    unsafe {
        if let Some(_g) = may_park(ret, "sendto")
            && flags & libc::MSG_DONTWAIT == 0
            && would_block(fd)
            && !ready_now(fd, libc::POLLOUT)
            && park_io(fd, true) == Wait::TimedOut
        {
            *libc::__errno_location() = libc::EAGAIN;
            return -1;
        }
        libc::syscall(libc::SYS_sendto, fd, buf, n, flags, addr, alen as usize) as isize
    }
}

/// The parking core behind poll/ppoll/select: one watch per interest, the caller's timeout as a
/// timer in the same race. `None` = could not park (the caller makes the blocking call itself);
/// `Some(true)` = an fd woke us; `Some(false)` = the timer won.
unsafe fn park_pollfds(fds: &[libc::pollfd], timeout_ms: c_int) -> Option<bool> {
    let Some(reactor) = super::module::try_reactor() else {
        park_failed("park_pollfds: no reactor");
        return None;
    };
    let mut ids = Vec::with_capacity(fds.len() * 2 + 1);
    for p in fds {
        if p.fd < 0 {
            continue;
        }
        if p.events & libc::POLLIN != 0 {
            ids.push(reactor.submit(Op::Watch { fd: p.fd, write: false }));
        }
        if p.events & libc::POLLOUT != 0 {
            ids.push(reactor.submit(Op::Watch { fd: p.fd, write: true }));
        }
    }
    let timer = (timeout_ms > 0).then(|| reactor.submit(Op::Sleep { us: timeout_ms as u64 * 1000 }));
    ids.extend(timer);
    if ids.is_empty() {
        return None;
    }
    // SAFETY: PHP thread inside a fiber; every id in `ids` was submitted to this thread's reactor
    // just above, and the ones that did not win are cancelled immediately after.
    let (woke, _) = unsafe { await_any(&ids) }?;
    for id in &ids {
        if *id != woke {
            reactor.submit(Op::CancelWatch { target: *id });
        }
    }
    Some(Some(woke) != timer)
}

/// Milliseconds from whole seconds plus a sub-second remainder, rounded up so a short wait never
/// becomes a spin. Saturates **before** the clamp: a caller-supplied timeout past the millisecond
/// range becomes the longest wait `c_int` can express, where multiplying first would wrap it into
/// a short one — or, in a debug build, panic across the FFI boundary (A-PARK-ARITHMETIC).
fn milliseconds_ceil(seconds: i64, sub_second: i64, units_per_millisecond: i64) -> c_int {
    let whole = seconds.saturating_mul(1000);
    let remainder = sub_second.saturating_add(units_per_millisecond - 1) / units_per_millisecond;
    whole.saturating_add(remainder).clamp(0, c_int::MAX as i64) as c_int
}

/// Microseconds for a `nanosleep` interval, or `None` when POSIX says the call is `EINVAL` --
/// a negative `tv_sec`, or a `tv_nsec` outside `[0, 999_999_999]`. Returning `None` hands the
/// request to the real syscall, which produces that error itself rather than us guessing at it.
/// The cast this replaces was `tv_sec as u64`, which turned a negative interval into a wait of
/// roughly 584,000 years (A-PARK-ARITHMETIC).
fn microseconds_of(req: &libc::timespec) -> Option<u64> {
    let seconds = req.tv_sec;
    let nanoseconds = req.tv_nsec;
    if seconds < 0 || !(0..1_000_000_000).contains(&nanoseconds) {
        return None;
    }
    Some((seconds as u64).saturating_mul(1_000_000).saturating_add(nanoseconds as u64 / 1000))
}

/// What POSIX `sleep()` owes its caller: 0 when the interval elapsed, otherwise the seconds still
/// to go, rounded **up** so an interrupted sleep never claims to have finished. This returned a
/// flat 0 on every path until 2026-09-19, which told a caller its sleep completed when a signal
/// had cut it short (A-PARK-ARITHMETIC (c)).
fn unslept_seconds(syscall_result: c_int, remaining: &libc::timespec) -> c_uint {
    if syscall_result == 0 {
        return 0;
    }
    let seconds = remaining.tv_sec.max(0);
    let rounded_up = if remaining.tv_nsec > 0 { seconds.saturating_add(1) } else { seconds };
    rounded_up.clamp(0, c_uint::MAX as i64) as c_uint
}

/// Milliseconds for a timespec timeout.
fn ms_ceil(ts: &libc::timespec) -> c_int {
    milliseconds_ceil(ts.tv_sec, ts.tv_nsec, 1_000_000)
}

unsafe fn poll_impl(ret: *const c_void, sym: &str, fds: *mut libc::pollfd, n: libc::nfds_t, timeout: c_int) -> c_int {
    // SAFETY: the caller is an interposer handing over libc's own arguments, so `fds` really does
    // point to `n` pollfds. They are passed to the kernel unchanged, and the fallback `real()` issues
    // exactly the call the program made.
    unsafe {
        let real = |t: c_int| libc::syscall(libc::SYS_poll, fds, n as usize, t) as c_int;
        let Some(_g) = may_park(ret, sym) else { return real(timeout) };
        if timeout == 0 || n == 0 {
            return real(timeout);
        }
        let r = real(0);
        if r != 0 {
            trace(&format!("{sym} n={n} timeout={timeout}: ready now r={r}"));
            return r; // already ready, or an error: exactly what the caller would have seen
        }
        trace(&format!("{sym} n={n} timeout={timeout}: not ready, parking"));
        match park_pollfds(std::slice::from_raw_parts(fds, n as usize), timeout) {
            Some(by_fd) => {
                let r = real(0);
                trace(&format!("{sym}: woke by {}, revents fill r={r}", if by_fd { "fd" } else { "timer" }));
                r // 0 if the timer won
            }
            None => {
                trace(&format!("{sym}: could not park, blocking"));
                real(timeout)
            }
        }
    }
}

#[unsafe(no_mangle)]
pub unsafe extern "C" fn ignis_park_poll(ret: *const c_void, fds: *mut libc::pollfd, n: libc::nfds_t, timeout: c_int) -> c_int {
    // SAFETY: arguments forwarded unchanged from the interposed `poll`, which is poll_impl's contract.
    unsafe { poll_impl(ret, "poll", fds, n, timeout) }
}

#[unsafe(no_mangle)]
pub unsafe extern "C" fn ignis_park_ppoll(
    ret: *const c_void,
    fds: *mut libc::pollfd,
    n: libc::nfds_t,
    ts: *const libc::timespec,
    mask: *const libc::sigset_t,
) -> c_int {
    // SAFETY: csrc/park.c calls this from the interposed symbol with exactly the arguments libc was
    // given, and `ret` is that call site's return address. `fds` and `mask` are handed to the
    // kernel unchanged, but unlike its neighbours this one **does** read the caller's memory:
    // `ms_ceil(&*ts)` dereferences the caller's `timespec` to turn the wait into milliseconds.
    // That is sound for the same reason the kernel's own read of it is -- libc's caller owns a
    // live `timespec` for the duration of the call -- and it is a different contract from the
    // shared paragraph that sat here until 2026-09-19, which claimed nothing here dereferences
    // the caller's buffer.
    unsafe {
        // A signal mask changes what the wait observes; that wait stays the kernel's.
        if !mask.is_null() {
            return libc::syscall(libc::SYS_ppoll, fds, n as usize, ts, mask, size_of::<libc::sigset_t>()) as c_int;
        }
        let timeout = if ts.is_null() { -1 } else { ms_ceil(&*ts) };
        poll_impl(ret, "ppoll", fds, n, timeout)
    }
}

/// `select(2)` parks through the poll core. The kernel clears the sets in place, so the readiness
/// probe runs on copies; the caller's sets are read for interests and filled by the final call.
/// An fd wanted only for exceptions has no watch here — such a call is forwarded as it was.
#[unsafe(no_mangle)]
pub unsafe extern "C" fn ignis_park_select(
    ret: *const c_void,
    n: c_int,
    r: *mut libc::fd_set,
    w: *mut libc::fd_set,
    e: *mut libc::fd_set,
    tv: *mut libc::timeval,
) -> c_int {
    // SAFETY: csrc/park.c calls this from the interposed symbol with exactly the arguments libc was
    // given, and `ret` is that call site's return address. This one reads **and writes** the
    // caller's memory, which the shared paragraph that sat here until 2026-09-19 denied: the fd
    // sets are copied out with `ptr::read`, tested with `FD_ISSET`, and written back with
    // `ptr::write` so the caller sees exactly the ready set `select` promises, and `tv` is read
    // for the timeout. All four are live objects owned by libc's caller for the duration of the
    // call -- the same lifetime the kernel relies on for the unparked path -- and each is
    // null-checked before use, because `select` allows any of them to be null.
    unsafe {
        // pselect6 is the one select syscall every Linux arch has; a NULL sigmask makes it select.
        let sel = |r: *mut libc::fd_set, w: *mut libc::fd_set, e: *mut libc::fd_set, ts: *const libc::timespec| {
            libc::syscall(libc::SYS_pselect6, n, r, w, e, ts, std::ptr::null::<c_void>()) as c_int
        };
        let ts_of = |tv: *const libc::timeval| -> Option<libc::timespec> {
            (!tv.is_null()).then(|| libc::timespec { tv_sec: (*tv).tv_sec, tv_nsec: (*tv).tv_usec as libc::c_long * 1000 })
        };
        let caller_ts = ts_of(tv);
        let real = |r, w, e| sel(r, w, e, caller_ts.as_ref().map_or(std::ptr::null(), |t| t as *const _));
        let Some(_g) = may_park(ret, "select") else { return real(r, w, e) };
        let timeout = caller_ts.as_ref().map_or(-1, ms_ceil);
        if n <= 0 || timeout == 0 {
            return real(r, w, e);
        }
        let copy = |p: *mut libc::fd_set| (!p.is_null()).then(|| std::ptr::read(p));
        let (mut rc, mut wc, mut ec) = (copy(r), copy(w), copy(e));
        let ptr = |c: &mut Option<libc::fd_set>| c.as_mut().map_or(std::ptr::null_mut(), |s| s as *mut _);
        let zero = libc::timespec { tv_sec: 0, tv_nsec: 0 };
        let probe = sel(ptr(&mut rc), ptr(&mut wc), ptr(&mut ec), &zero);
        if probe != 0 {
            // Ready (or an error): hand the caller exactly what the kernel filled in.
            for (dst, src) in [(r, rc), (w, wc), (e, ec)] {
                if let Some(src) = src {
                    std::ptr::write(dst, src);
                }
            }
            trace(&format!("select n={n} timeout={timeout}: ready now r={probe}"));
            return probe;
        }
        let mut fds = Vec::new();
        for fd in 0..n {
            let mut events = 0i16;
            if !r.is_null() && libc::FD_ISSET(fd, r) {
                events |= libc::POLLIN;
            }
            if !w.is_null() && libc::FD_ISSET(fd, w) {
                events |= libc::POLLOUT;
            }
            if events == 0 && !e.is_null() && libc::FD_ISSET(fd, e) {
                trace(&format!("select fd={fd}: exception interest only, blocking"));
                return real(r, w, e);
            }
            if events != 0 {
                fds.push(libc::pollfd { fd, events, revents: 0 });
            }
        }
        trace(&format!("select n={n} timeout={timeout}: not ready, parking on {} fds", fds.len()));
        match park_pollfds(&fds, timeout) {
            Some(by_fd) => {
                let r2 = sel(r, w, e, &zero);
                trace(&format!("select: woke by {}, fill r={r2}", if by_fd { "fd" } else { "timer" }));
                r2
            }
            None => {
                trace("select: could not park, blocking");
                real(r, w, e)
            }
        }
    }
}

#[unsafe(no_mangle)]
pub unsafe extern "C" fn ignis_park_connect(ret: *const c_void, fd: c_int, addr: *const c_void, alen: c_uint) -> c_int {
    // SAFETY: csrc/park.c calls this from the interposed symbol with exactly the arguments libc was
    // given, and `ret` is that call site's return address. Nothing here dereferences the caller's
    // buffer -- it is handed straight back to the kernel -- so the syscall is as sound as the call
    // the program already made; parking only delays it.
    unsafe {
        let real = || libc::syscall(libc::SYS_connect, fd, addr, alen as usize) as c_int;
        let Some(_g) = may_park(ret, "connect") else { return real() };
        let flags = libc::fcntl(fd, libc::F_GETFL);
        if flags < 0 || flags & libc::O_NONBLOCK != 0 {
            trace(&format!("connect fd={fd}: already non-blocking, forwarding"));
            return real(); // already non-blocking: the library drives it itself
        }
        trace(&format!("connect fd={fd}: blocking socket, parking on writable"));
        // The library never sees the flag: on, connect, park on writable, off, report SO_ERROR.
        libc::fcntl(fd, libc::F_SETFL, flags | libc::O_NONBLOCK);
        let r = real();
        let err = *libc::__errno_location();
        if r == 0 || err != libc::EINPROGRESS {
            libc::fcntl(fd, libc::F_SETFL, flags);
            *libc::__errno_location() = err;
            return r;
        }
        let parked = park_on(fd, true);
        libc::fcntl(fd, libc::F_SETFL, flags);
        if !parked {
            // Could not park: finish the way a blocking connect would, by waiting for writability.
            let mut p = libc::pollfd { fd, events: libc::POLLOUT, revents: 0 };
            libc::syscall(libc::SYS_poll, &mut p as *mut libc::pollfd, 1usize, -1);
        }
        let mut so_err: c_int = 0;
        let mut len = size_of::<c_int>() as libc::socklen_t;
        if libc::getsockopt(fd, libc::SOL_SOCKET, libc::SO_ERROR, &mut so_err as *mut c_int as *mut c_void, &mut len) < 0 {
            return -1;
        }
        if so_err == 0 {
            0
        } else {
            *libc::__errno_location() = so_err;
            -1
        }
    }
}

#[unsafe(no_mangle)]
pub unsafe extern "C" fn ignis_park_nanosleep(ret: *const c_void, req: *const libc::timespec, rem: *mut libc::timespec) -> c_int {
    // SAFETY: csrc/park.c calls this from the interposed symbol with exactly the arguments libc was
    // given, and `ret` is that call site's return address. Unlike its neighbours this one **does**
    // read the caller's buffer: `*req` is dereferenced below to compute the wait. That is sound for
    // the same reason the kernel's own read of it is -- libc's caller owns a live `timespec` for the
    // duration of the call -- but it is a different contract, and the shared paragraph claiming
    // nothing here dereferences the caller's buffer sat over this function until 2026-09-19.
    // `rem` is written only on the success path, where the full interval elapsed.
    unsafe {
        if let Some(_g) = may_park(ret, "nanosleep")
            && !req.is_null()
            && let Some(us) = microseconds_of(&*req)
            && park_sleep(us)
        {
            if !rem.is_null() {
                (*rem).tv_sec = 0;
                (*rem).tv_nsec = 0;
            }
            return 0;
        }
        libc::syscall(libc::SYS_nanosleep, req, rem) as c_int
    }
}

#[unsafe(no_mangle)]
pub unsafe extern "C" fn ignis_park_usleep(ret: *const c_void, us: c_uint) -> c_int {
    // SAFETY: csrc/park.c calls this from the interposed symbol with exactly the arguments libc was
    // given, and `ret` is that call site's return address. Nothing here dereferences the caller's
    // buffer -- it is handed straight back to the kernel -- so the syscall is as sound as the call
    // the program already made; parking only delays it.
    unsafe {
        if let Some(_g) = may_park(ret, "usleep")
            && park_sleep(us as u64)
        {
            return 0;
        }
        let ts = libc::timespec { tv_sec: (us / 1_000_000) as libc::time_t, tv_nsec: ((us % 1_000_000) * 1000) as libc::c_long };
        libc::syscall(libc::SYS_nanosleep, &ts as *const libc::timespec, std::ptr::null_mut::<libc::timespec>()) as c_int
    }
}

#[unsafe(no_mangle)]
pub unsafe extern "C" fn ignis_park_sleep(ret: *const c_void, s: c_uint) -> c_uint {
    // SAFETY: csrc/park.c calls this from the interposed symbol with exactly the arguments libc was
    // given, and `ret` is that call site's return address. Nothing here dereferences the caller's
    // buffer -- it is handed straight back to the kernel -- so the syscall is as sound as the call
    // the program already made; parking only delays it.
    unsafe {
        if let Some(_g) = may_park(ret, "sleep")
            && park_sleep((s as u64).saturating_mul(1_000_000))
        {
            return 0;
        }
        let requested = libc::timespec { tv_sec: s as libc::time_t, tv_nsec: 0 };
        let mut remaining = libc::timespec { tv_sec: 0, tv_nsec: 0 };
        let rc = libc::syscall(libc::SYS_nanosleep, &requested as *const libc::timespec, &mut remaining as *mut libc::timespec);
        unslept_seconds(rc as c_int, &remaining)
    }
}

// ---- stage 2 (ADR-0037 cycle 2): the same shapes as read/write/recv/send -----------------------

#[unsafe(no_mangle)]
pub unsafe extern "C" fn ignis_park_accept4(ret: *const c_void, fd: c_int, addr: *mut c_void, alen: *mut c_uint, flags: c_int) -> c_int {
    // SAFETY: csrc/park.c calls this from the interposed symbol with exactly the arguments libc was
    // given, and `ret` is that call site's return address. Nothing here dereferences the caller's
    // buffer -- it is handed straight back to the kernel -- so the syscall is as sound as the call
    // the program already made; parking only delays it.
    unsafe {
        // A listening socket is readable when a connection is pending; the real accept4 follows
        // either way, so "readable" that is not "would succeed" ends as it does in stock PHP.
        if let Some(_g) = may_park(ret, "accept")
            && would_block(fd)
            && !ready_now(fd, libc::POLLIN)
            && park_io(fd, false) == Wait::TimedOut
        {
            *libc::__errno_location() = libc::EAGAIN;
            return -1;
        }
        libc::syscall(libc::SYS_accept4, fd, addr, alen, flags) as c_int
    }
}

#[unsafe(no_mangle)]
pub unsafe extern "C" fn ignis_park_recvmsg(ret: *const c_void, fd: c_int, msg: *mut c_void, flags: c_int) -> isize {
    // SAFETY: csrc/park.c calls this from the interposed symbol with exactly the arguments libc was
    // given, and `ret` is that call site's return address. Nothing here dereferences the caller's
    // buffer -- it is handed straight back to the kernel -- so the syscall is as sound as the call
    // the program already made; parking only delays it.
    unsafe {
        if let Some(_g) = may_park(ret, "recvmsg")
            && flags & libc::MSG_DONTWAIT == 0
            && would_block(fd)
            && !ready_now(fd, libc::POLLIN)
            && park_io(fd, false) == Wait::TimedOut
        {
            *libc::__errno_location() = libc::EAGAIN;
            return -1;
        }
        libc::syscall(libc::SYS_recvmsg, fd, msg, flags) as isize
    }
}

#[unsafe(no_mangle)]
pub unsafe extern "C" fn ignis_park_sendmsg(ret: *const c_void, fd: c_int, msg: *const c_void, flags: c_int) -> isize {
    // SAFETY: csrc/park.c calls this from the interposed symbol with exactly the arguments libc was
    // given, and `ret` is that call site's return address. Nothing here dereferences the caller's
    // buffer -- it is handed straight back to the kernel -- so the syscall is as sound as the call
    // the program already made; parking only delays it.
    unsafe {
        if let Some(_g) = may_park(ret, "sendmsg")
            && flags & libc::MSG_DONTWAIT == 0
            && would_block(fd)
            && !ready_now(fd, libc::POLLOUT)
            && park_io(fd, true) == Wait::TimedOut
        {
            *libc::__errno_location() = libc::EAGAIN;
            return -1;
        }
        libc::syscall(libc::SYS_sendmsg, fd, msg, flags) as isize
    }
}

/// How long a fiber waits between `flock(LOCK_NB)` attempts, doubling from the first to the second.
/// Short enough that an uncontended-by-the-time-we-look lock costs little, long enough that a lock
/// held for a whole request is not polled hundreds of times.
// ponytail: this retry loop has no overall deadline, and deliberately so. A blocking `flock()`
// has no timeout in POSIX -- the caller asked to wait until the lock is theirs -- so returning
// EWOULDBLOCK after some interval of our choosing would be an error no caller is written to
// expect. The ceiling that remains is real and named here: a lock whose holder died without
// releasing it parks this fiber for the life of the process. That is strictly better than the
// behaviour it replaced, where the same case blocked the whole OS thread and every fiber on it
// (R-SESS, V-58), and it is visible rather than silent, because each retry is an `Op::Sleep` on
// this thread's reactor and research 42 proposes `ignis_op_oldest_age_seconds` over exactly that
// id space. Upgrade path: a per-request watchdog that throws into a fiber which has not
// progressed (S-POOL-LEASE-AGE fix 3) releases this the same way it releases every other held
// resource -- one mechanism instead of a timeout per call site.
const FLOCK_RETRY_FIRST_US: u64 = 200;
const FLOCK_RETRY_MAX_US: u64 = 20_000;

/// `flock` is the one blocking call in this set whose target is a regular file, and that is exactly
/// why it needed a handler: a regular file cannot be registered with epoll (research 30 group (d)),
/// so a blocking `LOCK_EX` inside a fiber cannot park and takes the OS thread down with it -- and
/// because the loop can then never resume whoever holds the lock, it never comes back (V-58).
///
/// The shape is the one Symfony's cache lock uses by hand and V-58 already measured working:
/// try `LOCK_NB`, and on contention park on a timer instead of blocking. The thread keeps serving,
/// which is what lets the holder reach its own release.
///
/// Not `fcntl`: opcache's `zend_shared_alloc_lock` uses that one, and build.rs says so in capitals.
#[unsafe(no_mangle)]
pub unsafe extern "C" fn ignis_park_flock(ret: *const c_void, fd: c_int, operation: c_int) -> c_int {
    // SAFETY: csrc/park.c calls this from the interposed symbol with the arguments libc was given.
    // `flock` takes no pointer, so there is nothing to dereference: the syscall is exactly the one
    // the program asked for, issued with LOCK_NB added while we are willing to wait for it.
    unsafe {
        let blocking = operation & libc::LOCK_NB == 0 && operation & libc::LOCK_UN == 0;
        let Some(_guard) = may_park(ret, "flock").filter(|_| blocking) else {
            return libc::syscall(libc::SYS_flock, fd, operation) as c_int;
        };

        let mut wait_us = FLOCK_RETRY_FIRST_US;
        loop {
            let rc = libc::syscall(libc::SYS_flock, fd, operation | libc::LOCK_NB) as c_int;
            if rc == 0 {
                return 0;
            }
            if *libc::__errno_location() != libc::EWOULDBLOCK {
                return rc;
            }
            if !park_sleep(wait_us) {
                // Nothing to park on (no reactor, not in a fiber): do what the caller asked for and
                // let the thread block, which is at least the behaviour it had before this existed.
                park_failed("flock");
                return libc::syscall(libc::SYS_flock, fd, operation) as c_int;
            }
            wait_us = (wait_us * 2).min(FLOCK_RETRY_MAX_US);
        }
    }
}

#[unsafe(no_mangle)]
pub unsafe extern "C" fn ignis_park_readv(ret: *const c_void, fd: c_int, iov: *const c_void, cnt: c_int) -> isize {
    // SAFETY: csrc/park.c calls this from the interposed symbol with exactly the arguments libc was
    // given, and `ret` is that call site's return address. Nothing here dereferences the caller's
    // buffer -- it is handed straight back to the kernel -- so the syscall is as sound as the call
    // the program already made; parking only delays it.
    unsafe {
        if let Some(_g) = may_park(ret, "readv")
            && would_block(fd)
            && !ready_now(fd, libc::POLLIN)
            && park_io(fd, false) == Wait::TimedOut
        {
            *libc::__errno_location() = libc::EAGAIN;
            return -1;
        }
        libc::syscall(libc::SYS_readv, fd, iov, cnt) as isize
    }
}

#[unsafe(no_mangle)]
pub unsafe extern "C" fn ignis_park_writev(ret: *const c_void, fd: c_int, iov: *const c_void, cnt: c_int) -> isize {
    // SAFETY: csrc/park.c calls this from the interposed symbol with exactly the arguments libc was
    // given, and `ret` is that call site's return address. Nothing here dereferences the caller's
    // buffer -- it is handed straight back to the kernel -- so the syscall is as sound as the call
    // the program already made; parking only delays it.
    unsafe {
        if let Some(_g) = may_park(ret, "writev")
            && would_block(fd)
            && !ready_now(fd, libc::POLLOUT)
            && park_io(fd, true) == Wait::TimedOut
        {
            *libc::__errno_location() = libc::EAGAIN;
            return -1;
        }
        libc::syscall(libc::SYS_writev, fd, iov, cnt) as isize
    }
}

/// `waitpid` for a named child: wait on a `pidfd` instead of the thread.
///
/// `proc_close()` is the caller that made this worth building. `exec()`/`shell_exec()` already park,
/// because they read the child's stdout through a pipe and the child is reaped after it has died —
/// but `proc_open()` with no descriptors has no pipe, so libphp goes straight here and the OS thread
/// is held for the child's whole life. Measured: three concurrent 300 ms children, 915 ms through
/// `proc_close` against 306 ms through `exec()` (V-112).
///
/// A `pidfd` is readable exactly when the process it names has exited, and epoll accepts one — which
/// is what makes this the same shape as every other handler here, rather than a new mechanism. Three
/// cases decline and fall through to the real call unchanged:
///
/// - `WNOHANG`, which does not block and has nothing to wait for;
/// - `pid <= 0` — "any child" and "any child in a process group" name no single process, and
///   `pidfd_open` cannot express either;
/// - `pidfd_open` failing at all, which covers a kernel older than 5.3 (`ENOSYS`), a child already
///   reaped (`ESRCH`) and anything else: the real `waitpid` then produces the right answer or the
///   right errno by itself, exactly as it did before this existed.
#[unsafe(no_mangle)]
pub unsafe extern "C" fn ignis_park_waitpid(ret: *const c_void, pid: c_int, status: *mut c_int, options: c_int) -> c_int {
    // SAFETY: csrc/park.c calls this from the interposed symbol with the arguments libc was given.
    // `status` is never dereferenced here — it is handed to the real `waitpid`, which is the same
    // call the program already made; parking only delays it. The pidfd is opened and closed here and
    // escapes nowhere.
    unsafe {
        if options & libc::WNOHANG == 0
            && pid > 0
            && let Some(_g) = may_park(ret, "waitpid")
        {
            let pidfd = libc::syscall(libc::SYS_pidfd_open, pid as libc::pid_t, 0) as c_int;
            if pidfd >= 0 {
                park_io(pidfd, false);
                libc::close(pidfd);
            }
        }
        // The raw syscall, never `libc::waitpid` — that is the symbol this function interposes, and
        // calling it here would re-enter this handler for ever. `waitpid(p, s, o)` is
        // `wait4(p, s, o, NULL)`.
        libc::syscall(libc::SYS_wait4, pid, status, options, std::ptr::null_mut::<c_void>()) as c_int
    }
}

// ---- boot self-check (ADR-0037 §4(a), research 32) --------------------------------------------

/// Proves that the interposed symbols really bind inside the third-party libraries the policy
/// names — research 28's mistake ("0 hits" looked like success) made mechanical.
///
/// Runs once, on the main thread, after PHP MINIT (so `dlopen(RTLD_NOLOAD)` can see the extensions'
/// libraries) and before any worker thread exists. For each policy library that is *loaded*, it
/// makes that library's own code call an interposed symbol and checks that the call reached us.
/// Nothing here is on any hot path.
///
/// `Ok(())` when every probed library was hit — or when there is nothing to probe (an empty policy,
/// a build where neither curl nor libpq is loaded). `Err(msg)` names the library that missed.
pub fn selfcheck() -> Result<(), String> {
    if std::env::var_os("IGNIS_NO_UNIVERSAL_PARK").is_some() {
        tracing::info!("park self-check skipped (IGNIS_NO_UNIVERSAL_PARK)");
        return Ok(());
    }
    if std::env::var_os("IGNIS_SKIP_PARK_SELFCHECK").is_some() {
        tracing::warn!("park self-check skipped (IGNIS_SKIP_PARK_SELFCHECK)");
        return Ok(());
    }
    // Probe only third-party libraries the policy names: libphp is always loaded and its call
    // sites are covered by the source audit (research 30), not by binding.
    let wanted: Vec<(&str, &str)> = [("libcurl", "libcurl.so.4"), ("libpq", "libpq.so.5")]
        .into_iter()
        .filter(|(name, _)| libs().iter().any(|(l, _)| l == name))
        .collect();
    if wanted.is_empty() {
        tracing::info!("park self-check: no third-party library in the policy, nothing to probe");
        return Ok(());
    }

    PROBE_HITS.lock_unpoisoned().clear();
    PROBING.store(true, Ordering::Relaxed);
    // The gate is a thread-local: pretend a fiber is active so the handlers do their policy
    // resolution. There is no reactor on this thread, so nothing can actually park — a probe that
    // reaches `park_on` forwards, which is exactly the behaviour we want at boot.
    PARK.with(|p| p.set(1));
    let mut probed: Vec<&str> = Vec::new();
    for (name, soname) in &wanted {
        // SAFETY: dlopen(RTLD_NOLOAD) only reports whether the object is already mapped; every
        // symbol below is called with the signature its own header declares.
        unsafe {
            let c_so = std::ffi::CString::new(*soname).unwrap();
            let h = libc::dlopen(c_so.as_ptr(), libc::RTLD_NOW | libc::RTLD_NOLOAD);
            if h.is_null() {
                tracing::debug!(lib = name, "park self-check: not loaded in this process, not probed");
                continue;
            }
            probed.push(name);
            if *name == "libcurl" {
                probe_libcurl(h);
            } else {
                probe_libpq(h);
            }
        }
    }
    PARK.with(|p| p.set(0));
    PROBING.store(false, Ordering::Relaxed);
    let hits = PROBE_HITS.lock_unpoisoned().clone();

    let missed: Vec<&str> = probed.iter().copied().filter(|name| !hits.iter().any(|h| h.starts_with(name))).collect();
    if missed.is_empty() {
        tracing::info!(probed = ?probed, hits = hits.len(), "park self-check ok");
        return Ok(());
    }
    Err(format!(
        "universal park is enabled and the policy names {missed:?}, but a call made by {} own code did not reach the \
         interposed symbols — the library is loaded and its blocking calls would silently block the thread. \
         Check that the binary exports them (`nm -D`) and that no LD_PRELOAD shadows them; \
         IGNIS_SKIP_PARK_SELFCHECK=1 starts anyway, IGNIS_PARK= disables the policy.",
        if missed.len() == 1 { "its" } else { "their" }
    ))
}

/// Make libcurl's own code call `connect(2)` against a unix socket path that does not exist: the
/// kernel answers `ENOENT` at once, so the probe costs nothing and needs no network, no DNS and no
/// listener. (A loopback port nothing listens on is *not* equivalent: on this box a `connect` to a
/// closed loopback port hangs until the timeout instead of answering ECONNREFUSED — that made the
/// first version of this self-check cost 1.3 s on every process start.) Only who called us matters.
unsafe fn probe_libcurl(h: *mut c_void) {
    // SAFETY: `h` is a handle dlopen returned. Every symbol is transmuted to the signature libcurl
    // documents and called only when dlsym found it, and the handle stays open for the calls.
    unsafe {
        let init: Option<unsafe extern "C" fn() -> *mut c_void> = std::mem::transmute(libc::dlsym(h, c"curl_easy_init".as_ptr()));
        let setopt: Option<unsafe extern "C" fn(*mut c_void, c_int, ...) -> c_int> =
            std::mem::transmute(libc::dlsym(h, c"curl_easy_setopt".as_ptr()));
        let perform: Option<unsafe extern "C" fn(*mut c_void) -> c_int> =
            std::mem::transmute(libc::dlsym(h, c"curl_easy_perform".as_ptr()));
        let cleanup: Option<unsafe extern "C" fn(*mut c_void)> = std::mem::transmute(libc::dlsym(h, c"curl_easy_cleanup".as_ptr()));
        let (Some(init), Some(setopt), Some(perform), Some(cleanup)) = (init, setopt, perform, cleanup) else {
            tracing::warn!("park self-check: libcurl loaded but its API is not resolvable; not probed");
            return;
        };
        let e = init();
        if e.is_null() {
            return;
        }
        const CURLOPT_URL: c_int = 10_002;
        const CURLOPT_NOSIGNAL: c_int = 99;
        const CURLOPT_CONNECTTIMEOUT_MS: c_int = 156;
        const CURLOPT_UNIX_SOCKET_PATH: c_int = 10_231;
        setopt(e, CURLOPT_URL, c"http://localhost/".as_ptr());
        setopt(e, CURLOPT_UNIX_SOCKET_PATH, c"/nonexistent/ignis-park-selfcheck".as_ptr());
        setopt(e, CURLOPT_NOSIGNAL, 1_i64);
        setopt(e, CURLOPT_CONNECTTIMEOUT_MS, 200_i64); // a cap, not the expected cost
        let _ = perform(e);
        cleanup(e);
    }
}

/// Make libpq's own code call `connect(2)`, same shape.
unsafe fn probe_libpq(h: *mut c_void) {
    // SAFETY: as probe_libcurl -- `h` is a live dlopen handle, each symbol is transmuted to libpq's
    // documented signature, and each is called only if dlsym found it.
    unsafe {
        let connectdb: Option<unsafe extern "C" fn(*const c_char) -> *mut c_void> =
            std::mem::transmute(libc::dlsym(h, c"PQconnectdb".as_ptr()));
        let finish: Option<unsafe extern "C" fn(*mut c_void)> = std::mem::transmute(libc::dlsym(h, c"PQfinish".as_ptr()));
        let (Some(connectdb), Some(finish)) = (connectdb, finish) else {
            tracing::warn!("park self-check: libpq loaded but its API is not resolvable; not probed");
            return;
        };
        // A leading slash makes libpq treat `host` as a unix-socket directory: connect to
        // `<dir>/.s.PGSQL.5432`, which does not exist, so it fails immediately.
        let conn = connectdb(c"host=/nonexistent connect_timeout=2".as_ptr());
        if !conn.is_null() {
            finish(conn);
        }
    }
}

#[cfg(test)]
mod tests {
    use super::*;

    fn timespec(seconds: i64, nanoseconds: i64) -> libc::timespec {
        libc::timespec { tv_sec: seconds as libc::time_t, tv_nsec: nanoseconds as _ }
    }

    /// A-PARK-ARITHMETIC (b): the clamp is applied after the multiply, so a timeout larger than
    /// `i64::MAX / 1000` wraps instead of saturating — in a debug build that is an overflow panic
    /// across an FFI boundary, and in a release build a long wait silently becomes a spin.
    #[test]
    fn a_timeout_past_the_millisecond_range_saturates_instead_of_wrapping() {
        assert_eq!(ms_ceil(&timespec(i64::MAX, 0)), c_int::MAX);
        assert_eq!(ms_ceil(&timespec(i64::MAX / 1000 + 1, 0)), c_int::MAX);
    }

    #[test]
    fn a_sub_millisecond_timeout_rounds_up_so_it_never_becomes_a_spin() {
        assert_eq!(ms_ceil(&timespec(0, 1)), 1);
        assert_eq!(ms_ceil(&timespec(0, 999_999)), 1);
        assert_eq!(ms_ceil(&timespec(0, 1_000_001)), 2);
    }

    #[test]
    fn a_zero_timeout_stays_zero_and_a_negative_one_clamps_to_it() {
        assert_eq!(ms_ceil(&timespec(0, 0)), 0);
        assert_eq!(ms_ceil(&timespec(-1, 0)), 0);
    }

    #[test]
    fn whole_seconds_and_nanoseconds_are_added_not_replaced() {
        assert_eq!(ms_ceil(&timespec(2, 500_000_000)), 2500);
    }

    /// A-PARK-ARITHMETIC (b): `tv_sec as u64` turned a negative interval into ~584,000 years of
    /// sleep. POSIX calls that `EINVAL`, so the interval is refused here and the real syscall gets
    /// to produce the error itself.
    #[test]
    fn a_nanosleep_interval_posix_calls_invalid_is_refused_rather_than_wrapped() {
        assert_eq!(microseconds_of(&timespec(-1, 0)), None);
        assert_eq!(microseconds_of(&timespec(0, -1)), None);
        assert_eq!(microseconds_of(&timespec(0, 1_000_000_000)), None);
    }

    /// A-PARK-ARITHMETIC (c): an interrupted `sleep()` owes its caller the remainder, and every
    /// path returned 0 -- which reads as "the interval elapsed".
    #[test]
    fn an_interrupted_sleep_reports_what_is_left_rounded_up() {
        assert_eq!(unslept_seconds(0, &timespec(9, 0)), 0, "a completed sleep owes nothing");
        assert_eq!(unslept_seconds(-1, &timespec(3, 0)), 3);
        assert_eq!(unslept_seconds(-1, &timespec(3, 1)), 4, "a part second still to go counts as one");
        assert_eq!(unslept_seconds(-1, &timespec(0, 0)), 0);
    }

    /// The wiring, not the arithmetic: outside a fiber `may_park` declines, so the shim must hand
    /// the call to the kernel unchanged and return exactly what libc would. Without this the two
    /// fixes above were covered only as pure functions — the gate would not have noticed an
    /// interposer that computed the right number and then delegated wrongly.
    #[test]
    fn outside_a_fiber_the_shims_delegate_and_answer_as_libc_does() {
        let here = core::ptr::null::<c_void>();
        let valid = timespec(0, 1_000_000);
        // SAFETY: not in a fiber and no reactor on this thread, so `may_park` declines and the only
        // call made is the real nanosleep with a live, well-formed interval of our own.
        let slept = unsafe { ignis_park_nanosleep(here, &valid, core::ptr::null_mut()) };
        assert_eq!(slept, 0, "a 1 ms sleep outside a fiber succeeds through the kernel");

        let invalid = timespec(0, 2_000_000_000);
        // SAFETY: same, with an interval POSIX defines as EINVAL; the kernel rejects it rather
        // than us, which is the whole point of `microseconds_of` returning None.
        let refused = unsafe { ignis_park_nanosleep(here, &invalid, core::ptr::null_mut()) };
        assert_eq!(refused, -1, "an out-of-range tv_nsec is the kernel's error, not a long sleep");
        // SAFETY: reading errno on the thread that just made the failing call.
        assert_eq!(unsafe { *libc::__errno_location() }, libc::EINVAL);

        // SAFETY: as above; a zero-second sleep returns immediately with nothing left unslept.
        assert_eq!(unsafe { ignis_park_sleep(here, 0) }, 0, "nothing was interrupted, so nothing is owed");
    }

    /// Whether a descriptor can park is the question "is readiness meaningful for it", and the
    /// kernel's own answer is whether `epoll_ctl` will accept it. So each descriptor is asked both
    /// ways and the two must agree, rather than a table of file types being written down here —
    /// a table would say nothing on the next kernel, and the matrix is where this has to hold.
    ///
    /// Sockets are excluded on purpose: `would_block` deliberately says *no* for a listening or
    /// unconnected socket that `epoll` would accept, because readiness there is not "the call would
    /// succeed" (A4's rule, ADR-0018).
    #[test]
    fn a_descriptor_parks_exactly_when_the_kernel_says_readiness_is_meaningful() {
        // SAFETY: every descriptor below is created here, used only through libc calls that
        // validate it, and closed at the end; `would_block` reads nothing but the descriptor.
        unsafe {
            let epoll = libc::epoll_create1(0);
            assert!(epoll >= 0, "no epoll on this kernel: nothing here can be checked");
            let accepts = |fd: c_int| {
                let mut event = libc::epoll_event { events: libc::EPOLLIN as u32, u64: 0 };
                libc::epoll_ctl(epoll, libc::EPOLL_CTL_ADD, fd, &mut event) == 0
            };

            let path = std::ffi::CString::new(std::env::temp_dir().join("ignis-park-fdkind").to_string_lossy().as_ref()).unwrap();
            let regular = libc::open(path.as_ptr(), libc::O_CREAT | libc::O_RDWR, 0o600);
            let directory = libc::open(c"/tmp".as_ptr(), libc::O_RDONLY | libc::O_DIRECTORY);
            let null = libc::open(c"/dev/null".as_ptr(), libc::O_RDWR);
            let mut pipe_fds = [0 as c_int; 2];
            assert_eq!(libc::pipe(pipe_fds.as_mut_ptr()), 0);
            let event = libc::eventfd(0, 0);
            let timer = libc::timerfd_create(libc::CLOCK_MONOTONIC, 0);
            let nested = libc::epoll_create1(0);

            let cases: [(&str, c_int); 7] = [
                ("a regular file", regular),
                ("a directory", directory),
                ("/dev/null", null),
                ("a pipe", pipe_fds[0]),
                ("an eventfd", event),
                ("a timerfd", timer),
                ("an epoll descriptor", nested),
            ];
            for (what, fd) in cases {
                assert!(fd >= 0, "{what} could not be created");
                assert_eq!(would_block(fd), accepts(fd), "{what}: park eligibility and what epoll accepts must be the same answer");
            }

            // Named outright as well, so this still says something on a kernel where epoll is
            // uniformly permissive and the agreement above becomes vacuous.
            assert!(!would_block(regular), "a regular file blocks: epoll refuses it (EPERM)");
            assert!(!would_block(null), "and so does a character device with no readiness");
            assert!(would_block(event), "an eventfd is an anonymous inode and parks");
            assert!(would_block(timer), "so is a timerfd");
            assert!(would_block(nested), "so is an epoll descriptor, though nothing read()s one");

            for fd in [regular, directory, null, pipe_fds[0], pipe_fds[1], event, timer, nested, epoll] {
                libc::close(fd);
            }
            libc::unlink(path.as_ptr());
        }
    }

    #[test]
    fn a_valid_nanosleep_interval_is_microseconds_and_saturates() {
        assert_eq!(microseconds_of(&timespec(0, 0)), Some(0));
        assert_eq!(microseconds_of(&timespec(1, 500_000)), Some(1_000_500));
        assert_eq!(microseconds_of(&timespec(i64::MAX, 999_999_999)), Some(u64::MAX));
    }
}
