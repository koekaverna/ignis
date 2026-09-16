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
//! Stage 1 scope: read/write/recv/send/recvfrom/sendto/poll/connect/nanosleep/usleep/sleep.
//! `getaddrinfo` (runtime resolver), `select`, `accept*`, the vectored calls and `__poll_chk`
//! are stage 2. Cancellation of a parked call (ADR-0009) falls back to the blocking call for now.
#![cfg(feature = "universal-park")]

use std::cell::{Cell, RefCell};
use std::collections::HashMap;
use std::ffi::{c_int, c_uint, c_void, CStr};
use std::sync::OnceLock;

use ignis_sys as sys;

use super::stream::{await_any, await_op};
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
/// the libphp sleep group audited in research 30 group (b). libphp's other symbols stay `block`
/// until research 30 has a row for them.
const SEED: &str = "libphp:sleep,libphp:usleep,libphp:nanosleep,libcurl,libpq,libssl,libcrypto";

/// `IGNIS_PARK_TRACE=1`: one stderr line per decision, for diagnosing a library that misbehaves
/// under `park`. Off by default; the check is a `OnceLock<bool>` load.
fn trace(msg: &str) {
    static ON: OnceLock<bool> = OnceLock::new();
    if *ON.get_or_init(|| std::env::var_os("IGNIS_PARK_TRACE").is_some()) {
        // Raw syscall on purpose: `eprintln!` would go through the interposed `write`.
        let line = format!("park: {msg}\n");
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
        let main = (*eg()).main_fiber_context;
        PARK.with(|p| p.set(if to == main { 0 } else { 1 }));
    }
}

unsafe fn eg() -> *mut sys::zend_executor_globals {
    unsafe { (sys::tsrm_get_ls_cache() as *mut u8).add(sys::executor_globals_offset) as *mut sys::zend_executor_globals }
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
unsafe fn would_block(fd: c_int) -> bool {
    let flags = unsafe { libc::fcntl(fd, libc::F_GETFL) };
    if flags < 0 || flags & libc::O_NONBLOCK != 0 {
        return false;
    }
    let mut st: libc::stat = unsafe { std::mem::zeroed() };
    if unsafe { libc::fstat(fd, &mut st as *mut libc::stat) } != 0 {
        return false;
    }
    let kind = st.st_mode & libc::S_IFMT;
    kind == libc::S_IFSOCK || kind == libc::S_IFIFO
}

/// Zero-timeout readiness probe, raw syscall.
unsafe fn ready_now(fd: c_int, events: i16) -> bool {
    let mut p = libc::pollfd { fd, events, revents: 0 };
    unsafe { libc::syscall(libc::SYS_poll, &mut p as *mut libc::pollfd, 1usize, 0) > 0 }
}

/// Park until `fd` is ready in `dir`. `false` = could not park (no fiber/reactor, switching
/// blocked, unwound by a cancellation) — the caller then makes the blocking call as before.
unsafe fn park_on(fd: c_int, write: bool) -> bool {
    let Some(r) = super::module::try_reactor() else { trace("park_on: no reactor"); return false };
    let id = r.submit(Op::Watch { fd, write });
    trace(&format!("park_on fd={fd} write={write} op={id}"));
    let ok = unsafe { await_any(&[id]).is_some() };
    trace(&format!("park_on fd={fd} resumed ok={ok}"));
    ok
}

unsafe fn park_sleep(us: u64) -> bool {
    let Some(r) = super::module::try_reactor() else { return false };
    let id = r.submit(Op::Sleep { us });
    unsafe { matches!(await_op(id), Some(Outcome::Slept { .. })) }
}

// ---- the handlers: signatures mirror csrc/park.c ---------------------------------------------

#[unsafe(no_mangle)]
pub unsafe extern "C" fn ignis_park_read(ret: *const c_void, fd: c_int, buf: *mut c_void, n: usize) -> isize {
    unsafe {
        if let Some(_g) = may_park(ret, "read")
            && would_block(fd)
            && !ready_now(fd, libc::POLLIN)
        {
            park_on(fd, false);
        }
        libc::syscall(libc::SYS_read, fd, buf, n) as isize
    }
}

#[unsafe(no_mangle)]
pub unsafe extern "C" fn ignis_park_write(ret: *const c_void, fd: c_int, buf: *const c_void, n: usize) -> isize {
    unsafe {
        if let Some(_g) = may_park(ret, "write")
            && would_block(fd)
            && !ready_now(fd, libc::POLLOUT)
        {
            park_on(fd, true);
        }
        libc::syscall(libc::SYS_write, fd, buf, n) as isize
    }
}

#[unsafe(no_mangle)]
pub unsafe extern "C" fn ignis_park_recv(ret: *const c_void, fd: c_int, buf: *mut c_void, n: usize, flags: c_int) -> isize {
    unsafe {
        if let Some(_g) = may_park(ret, "recv")
            && flags & libc::MSG_DONTWAIT == 0
            && would_block(fd)
            && !ready_now(fd, libc::POLLIN)
        {
            park_on(fd, false);
        }
        libc::syscall(libc::SYS_recvfrom, fd, buf, n, flags, std::ptr::null_mut::<c_void>(), std::ptr::null_mut::<c_void>()) as isize
    }
}

#[unsafe(no_mangle)]
pub unsafe extern "C" fn ignis_park_send(ret: *const c_void, fd: c_int, buf: *const c_void, n: usize, flags: c_int) -> isize {
    unsafe {
        if let Some(_g) = may_park(ret, "send")
            && flags & libc::MSG_DONTWAIT == 0
            && would_block(fd)
            && !ready_now(fd, libc::POLLOUT)
        {
            park_on(fd, true);
        }
        libc::syscall(libc::SYS_sendto, fd, buf, n, flags, std::ptr::null::<c_void>(), 0usize) as isize
    }
}

#[unsafe(no_mangle)]
pub unsafe extern "C" fn ignis_park_recvfrom(ret: *const c_void, fd: c_int, buf: *mut c_void, n: usize, flags: c_int, addr: *mut c_void, alen: *mut c_uint) -> isize {
    unsafe {
        if let Some(_g) = may_park(ret, "recvfrom")
            && flags & libc::MSG_DONTWAIT == 0
            && would_block(fd)
            && !ready_now(fd, libc::POLLIN)
        {
            park_on(fd, false);
        }
        libc::syscall(libc::SYS_recvfrom, fd, buf, n, flags, addr, alen) as isize
    }
}

#[unsafe(no_mangle)]
pub unsafe extern "C" fn ignis_park_sendto(ret: *const c_void, fd: c_int, buf: *const c_void, n: usize, flags: c_int, addr: *const c_void, alen: c_uint) -> isize {
    unsafe {
        if let Some(_g) = may_park(ret, "sendto")
            && flags & libc::MSG_DONTWAIT == 0
            && would_block(fd)
            && !ready_now(fd, libc::POLLOUT)
        {
            park_on(fd, true);
        }
        libc::syscall(libc::SYS_sendto, fd, buf, n, flags, addr, alen as usize) as isize
    }
}

#[unsafe(no_mangle)]
pub unsafe extern "C" fn ignis_park_poll(ret: *const c_void, fds: *mut libc::pollfd, n: libc::nfds_t, timeout: c_int) -> c_int {
    unsafe {
        let real = |t: c_int| libc::syscall(libc::SYS_poll, fds, n as usize, t) as c_int;
        let Some(_g) = may_park(ret, "poll") else { return real(timeout) };
        if timeout == 0 || n == 0 {
            return real(timeout);
        }
        let r = real(0);
        if r != 0 {
            trace(&format!("poll n={n} timeout={timeout}: ready now r={r}"));
            return r; // already ready, or an error: exactly what the caller would have seen
        }
        trace(&format!("poll n={n} timeout={timeout}: not ready, parking"));
        let Some(reactor) = super::module::try_reactor() else { return real(timeout) };
        // One watch per interest; the library's own timeout becomes a timer in the same race.
        let mut ids = Vec::with_capacity(n as usize * 2 + 1);
        for i in 0..n as usize {
            let p = &*fds.add(i);
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
        if timeout > 0 {
            ids.push(reactor.submit(Op::Sleep { us: timeout as u64 * 1000 }));
        }
        if ids.is_empty() {
            return real(timeout);
        }
        match await_any(&ids) {
            Some((woke, _)) => {
                for id in &ids {
                    if *id != woke {
                        reactor.submit(Op::CancelWatch { target: *id });
                    }
                }
                let r = real(0);
                trace(&format!("poll: woke by op {woke}, revents fill r={r}"));
                r // 0 if the timer won
            }
            None => {
                trace("poll: could not park, blocking");
                real(timeout)
            }
        }
    }
}

#[unsafe(no_mangle)]
pub unsafe extern "C" fn ignis_park_connect(ret: *const c_void, fd: c_int, addr: *const c_void, alen: c_uint) -> c_int {
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
        let mut len = std::mem::size_of::<c_int>() as libc::socklen_t;
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
    unsafe {
        if let Some(_g) = may_park(ret, "nanosleep")
            && !req.is_null()
        {
            let us = (*req).tv_sec as u64 * 1_000_000 + (*req).tv_nsec as u64 / 1000;
            if park_sleep(us) {
                if !rem.is_null() {
                    (*rem).tv_sec = 0;
                    (*rem).tv_nsec = 0;
                }
                return 0;
            }
        }
        libc::syscall(libc::SYS_nanosleep, req, rem) as c_int
    }
}

#[unsafe(no_mangle)]
pub unsafe extern "C" fn ignis_park_usleep(ret: *const c_void, us: c_uint) -> c_int {
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
    unsafe {
        if let Some(_g) = may_park(ret, "sleep")
            && park_sleep(s as u64 * 1_000_000)
        {
            return 0;
        }
        let ts = libc::timespec { tv_sec: s as libc::time_t, tv_nsec: 0 };
        libc::syscall(libc::SYS_nanosleep, &ts as *const libc::timespec, std::ptr::null_mut::<libc::timespec>());
        0
    }
}
