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

use super::wait::{await_any, await_op};
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
const SEED: &str = "libphp:sleep,libphp:usleep,libphp:nanosleep,libphp:select,libphp:accept,libphp:poll,libphp:recv,libphp:send,libphp:recvfrom,libphp:sendto,libphp:recvmsg,libphp:sendmsg,libphp:connect,libphp:read,libphp:write,libcurl,libpq,libssl,libcrypto";

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

/// The socket's own kernel timeout (`SO_RCVTIMEO` for reads, `SO_SNDTIMEO` for writes) in ms;
/// 0 = none, and 0 for anything that is not a socket (a pipe has no such option).
unsafe fn sock_timeout_ms(fd: c_int, write: bool) -> c_int {
    let mut tv: libc::timeval = unsafe { std::mem::zeroed() };
    let mut len = std::mem::size_of::<libc::timeval>() as libc::socklen_t;
    let opt = if write { libc::SO_SNDTIMEO } else { libc::SO_RCVTIMEO };
    if unsafe { libc::getsockopt(fd, libc::SOL_SOCKET, opt, &mut tv as *mut libc::timeval as *mut c_void, &mut len) } != 0 {
        return 0;
    }
    (tv.tv_sec as i64 * 1000 + (tv.tv_usec as i64 + 999) / 1000).clamp(0, c_int::MAX as i64) as c_int
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
    let timeout = unsafe { sock_timeout_ms(fd, write) };
    let fds = [libc::pollfd { fd, events: if write { libc::POLLOUT } else { libc::POLLIN }, revents: 0 }];
    trace(&format!("park_io fd={fd} write={write} timeout={timeout}"));
    match unsafe { park_pollfds(&fds, timeout) } {
        Some(false) => Wait::TimedOut,
        _ => Wait::Ready,
    }
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
            if park_io(fd, false) == Wait::TimedOut {
                *libc::__errno_location() = libc::EAGAIN;
                return -1;
            }
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
            if park_io(fd, true) == Wait::TimedOut {
                *libc::__errno_location() = libc::EAGAIN;
                return -1;
            }
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
            if park_io(fd, false) == Wait::TimedOut {
                *libc::__errno_location() = libc::EAGAIN;
                return -1;
            }
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
            if park_io(fd, true) == Wait::TimedOut {
                *libc::__errno_location() = libc::EAGAIN;
                return -1;
            }
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
            if park_io(fd, false) == Wait::TimedOut {
                *libc::__errno_location() = libc::EAGAIN;
                return -1;
            }
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
            if park_io(fd, true) == Wait::TimedOut {
                *libc::__errno_location() = libc::EAGAIN;
                return -1;
            }
        }
        libc::syscall(libc::SYS_sendto, fd, buf, n, flags, addr, alen as usize) as isize
    }
}

/// The parking core behind poll/ppoll/select: one watch per interest, the caller's timeout as a
/// timer in the same race. `None` = could not park (the caller makes the blocking call itself);
/// `Some(true)` = an fd woke us; `Some(false)` = the timer won.
unsafe fn park_pollfds(fds: &[libc::pollfd], timeout_ms: c_int) -> Option<bool> {
    let reactor = super::module::try_reactor()?;
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
    let (woke, _) = unsafe { await_any(&ids) }?;
    for id in &ids {
        if *id != woke {
            reactor.submit(Op::CancelWatch { target: *id });
        }
    }
    Some(Some(woke) != timer)
}

/// Milliseconds for a timespec timeout, rounded up so a short wait never becomes a spin.
fn ms_ceil(ts: &libc::timespec) -> c_int {
    (ts.tv_sec as i64 * 1000 + (ts.tv_nsec as i64 + 999_999) / 1_000_000).clamp(0, c_int::MAX as i64) as c_int
}

unsafe fn poll_impl(ret: *const c_void, sym: &str, fds: *mut libc::pollfd, n: libc::nfds_t, timeout: c_int) -> c_int {
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
    unsafe { poll_impl(ret, "poll", fds, n, timeout) }
}

#[unsafe(no_mangle)]
pub unsafe extern "C" fn ignis_park_ppoll(ret: *const c_void, fds: *mut libc::pollfd, n: libc::nfds_t, ts: *const libc::timespec, mask: *const libc::sigset_t) -> c_int {
    unsafe {
        // A signal mask changes what the wait observes; that wait stays the kernel's.
        if !mask.is_null() {
            return libc::syscall(libc::SYS_ppoll, fds, n as usize, ts, mask, std::mem::size_of::<libc::sigset_t>()) as c_int;
        }
        let timeout = if ts.is_null() { -1 } else { ms_ceil(&*ts) };
        poll_impl(ret, "ppoll", fds, n, timeout)
    }
}

/// `select(2)` parks through the poll core. The kernel clears the sets in place, so the readiness
/// probe runs on copies; the caller's sets are read for interests and filled by the final call.
/// An fd wanted only for exceptions has no watch here — such a call is forwarded as it was.
#[unsafe(no_mangle)]
pub unsafe extern "C" fn ignis_park_select(ret: *const c_void, n: c_int, r: *mut libc::fd_set, w: *mut libc::fd_set, e: *mut libc::fd_set, tv: *mut libc::timeval) -> c_int {
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

// ---- stage 2 (ADR-0037 cycle 2): the same shapes as read/write/recv/send -----------------------

#[unsafe(no_mangle)]
pub unsafe extern "C" fn ignis_park_accept4(ret: *const c_void, fd: c_int, addr: *mut c_void, alen: *mut c_uint, flags: c_int) -> c_int {
    unsafe {
        // A listening socket is readable when a connection is pending; the real accept4 follows
        // either way, so "readable" that is not "would succeed" ends as it does in stock PHP.
        if let Some(_g) = may_park(ret, "accept")
            && would_block(fd)
            && !ready_now(fd, libc::POLLIN)
        {
            if park_io(fd, false) == Wait::TimedOut {
                *libc::__errno_location() = libc::EAGAIN;
                return -1;
            }
        }
        libc::syscall(libc::SYS_accept4, fd, addr, alen, flags) as c_int
    }
}

#[unsafe(no_mangle)]
pub unsafe extern "C" fn ignis_park_recvmsg(ret: *const c_void, fd: c_int, msg: *mut c_void, flags: c_int) -> isize {
    unsafe {
        if let Some(_g) = may_park(ret, "recvmsg")
            && flags & libc::MSG_DONTWAIT == 0
            && would_block(fd)
            && !ready_now(fd, libc::POLLIN)
        {
            if park_io(fd, false) == Wait::TimedOut {
                *libc::__errno_location() = libc::EAGAIN;
                return -1;
            }
        }
        libc::syscall(libc::SYS_recvmsg, fd, msg, flags) as isize
    }
}

#[unsafe(no_mangle)]
pub unsafe extern "C" fn ignis_park_sendmsg(ret: *const c_void, fd: c_int, msg: *const c_void, flags: c_int) -> isize {
    unsafe {
        if let Some(_g) = may_park(ret, "sendmsg")
            && flags & libc::MSG_DONTWAIT == 0
            && would_block(fd)
            && !ready_now(fd, libc::POLLOUT)
        {
            if park_io(fd, true) == Wait::TimedOut {
                *libc::__errno_location() = libc::EAGAIN;
                return -1;
            }
        }
        libc::syscall(libc::SYS_sendmsg, fd, msg, flags) as isize
    }
}

#[unsafe(no_mangle)]
pub unsafe extern "C" fn ignis_park_readv(ret: *const c_void, fd: c_int, iov: *const c_void, cnt: c_int) -> isize {
    unsafe {
        if let Some(_g) = may_park(ret, "readv")
            && would_block(fd)
            && !ready_now(fd, libc::POLLIN)
        {
            if park_io(fd, false) == Wait::TimedOut {
                *libc::__errno_location() = libc::EAGAIN;
                return -1;
            }
        }
        libc::syscall(libc::SYS_readv, fd, iov, cnt) as isize
    }
}

#[unsafe(no_mangle)]
pub unsafe extern "C" fn ignis_park_writev(ret: *const c_void, fd: c_int, iov: *const c_void, cnt: c_int) -> isize {
    unsafe {
        if let Some(_g) = may_park(ret, "writev")
            && would_block(fd)
            && !ready_now(fd, libc::POLLOUT)
        {
            if park_io(fd, true) == Wait::TimedOut {
                *libc::__errno_location() = libc::EAGAIN;
                return -1;
            }
        }
        libc::syscall(libc::SYS_writev, fd, iov, cnt) as isize
    }
}
