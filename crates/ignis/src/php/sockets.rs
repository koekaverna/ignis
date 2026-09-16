//! A4 (ADR-0018): `ext/sockets` calls park the fiber instead of blocking the OS thread.
//!
//! `ext/sockets` never goes through `php_stream`, so the ADR-0007 transport hook does not reach
//! it: `socket_create()` returns a `Socket` object and every `socket_*` call blocks the thread,
//! stalling every fiber on it. The mechanism here is the one `accept.rs` proved — "park, then
//! delegate": swap `internal_function.handler` at MINIT, wait for readiness on the reactor, then
//! call the ORIGINAL handler, which returns at once because the fd is ready. Semantics, warnings
//! and return shapes stay upstream's; this file only removes the blocking wait.
//!
//! Out of scope, with reasons in ADR-0018: `socket_connect` (nothing to watch before connecting),
//! `socket_addrinfo_connect` (creates *and* connects inside one call), `socket_addrinfo_lookup`
//! (blocking `getaddrinfo()` with no fd). `IGNIS_NO_SOCKETS_HOOK=1` disables the hook.

use std::ffi::{CStr, c_void};
use std::sync::OnceLock;

use ignis_sys as sys;

use super::zval;
use crate::reactor::Op;

type Handler = unsafe extern "C" fn(*mut sys::zend_execute_data, *mut sys::zval);

/// Originals, indexed like `HOOKS`.
static ORIG: OnceLock<Vec<Handler>> = OnceLock::new();

unsafe fn cg() -> *mut sys::zend_compiler_globals {
    unsafe { (sys::tsrm_get_ls_cache() as *mut u8).add(sys::compiler_globals_offset) as *mut sys::zend_compiler_globals }
}
unsafe fn eg() -> *mut sys::zend_executor_globals {
    unsafe { (sys::tsrm_get_ls_cache() as *mut u8).add(sys::executor_globals_offset) as *mut sys::zend_executor_globals }
}

#[derive(Clone, Copy, PartialEq, Eq)]
enum Wait {
    Read,
    Write,
}

struct Hook {
    name: &'static CStr,
    wait: Wait,
    /// 1-based index of the `int $flags` argument (as `zval::arg`), when the function has one.
    flags_arg: Option<u32>,
    /// Operates on a listening socket (only `socket_accept`).
    listener: bool,
    /// Carries an explicit peer address, so an unconnected socket is legitimate.
    addressed: bool,
    tramp: Handler,
}

/// One `extern "C"` trampoline per hooked function: the handler signature has no room for the
/// table index, and recovering it from `ex` would cost a string compare on every call.
macro_rules! tramp {
    ($name:ident, $idx:expr) => {
        unsafe extern "C" fn $name(ex: *mut sys::zend_execute_data, rv: *mut sys::zval) {
            unsafe { dispatch($idx, ex, rv) }
        }
    };
}
tramp!(t_read, 0);
tramp!(t_recv, 1);
tramp!(t_recvfrom, 2);
tramp!(t_recvmsg, 3);
tramp!(t_accept, 4);
tramp!(t_write, 5);
tramp!(t_send, 6);
tramp!(t_sendto, 7);
tramp!(t_sendmsg, 8);

/// Signatures verified against php-src `ext/sockets/sockets.stub.php` (php-8.5.10); arg 0 is the
/// `Socket` in every one of them.
static HOOKS: &[Hook] = &[
    Hook { name: c"socket_read", wait: Wait::Read, flags_arg: None, tramp: t_read, listener: false, addressed: false },
    Hook { name: c"socket_recv", wait: Wait::Read, flags_arg: Some(4), tramp: t_recv, listener: false, addressed: false },
    Hook { name: c"socket_recvfrom", wait: Wait::Read, flags_arg: Some(4), tramp: t_recvfrom, listener: false, addressed: true },
    Hook { name: c"socket_recvmsg", wait: Wait::Read, flags_arg: Some(3), tramp: t_recvmsg, listener: false, addressed: true },
    Hook { name: c"socket_accept", wait: Wait::Read, flags_arg: None, tramp: t_accept, listener: true, addressed: false },
    Hook { name: c"socket_write", wait: Wait::Write, flags_arg: None, tramp: t_write, listener: false, addressed: false },
    Hook { name: c"socket_send", wait: Wait::Write, flags_arg: Some(4), tramp: t_send, listener: false, addressed: false },
    Hook { name: c"socket_sendto", wait: Wait::Write, flags_arg: Some(4), tramp: t_sendto, listener: false, addressed: true },
    Hook { name: c"socket_sendmsg", wait: Wait::Write, flags_arg: Some(3), tramp: t_sendmsg, listener: false, addressed: true },
];

pub unsafe fn install() {
    if std::env::var_os("IGNIS_NO_SOCKETS_HOOK").is_some() {
        return;
    }
    unsafe {
        // ext/sockets may not be present in a stripped build; then there is nothing to hook.
        if sys::socket_ce.is_null() {
            tracing::warn!("sockets hook: socket_ce is null at MINIT (ext/sockets not initialised yet)");
            return;
        }
        let mut origs = Vec::with_capacity(HOOKS.len());
        for h in HOOKS {
            let n = h.name.to_bytes();
            let zv = sys::zend_hash_str_find((*cg()).function_table, n.as_ptr() as *const _, n.len());
            if zv.is_null() {
                tracing::warn!(func = ?h.name, "sockets hook: function not found");
                return; // partial hooking would be worse than none
            }
            let func = (*zv).value.func;
            match (*func).internal_function.handler {
                Some(orig) => origs.push(orig),
                None => return,
            }
        }
        // Only swap once every original is in hand, so a failure leaves the engine untouched.
        if ORIG.set(origs).is_err() {
            return;
        }
        for h in HOOKS {
            let n = h.name.to_bytes();
            let zv = sys::zend_hash_str_find((*cg()).function_table, n.as_ptr() as *const _, n.len());
            (*(*zv).value.func).internal_function.handler = Some(h.tramp);
        }
        tracing::info!(n = HOOKS.len(), "sockets hook installed");
    }
}

/// The raw fd behind a `Socket` argument, or `None` if this is not a live `Socket`.
unsafe fn socket_fd(zv: *mut sys::zval) -> Option<i32> {
    unsafe {
        if zval::type_of(zv) != sys::IS_OBJECT {
            return None;
        }
        let obj = (*zv).value.obj;
        if obj.is_null() || (*obj).ce != sys::socket_ce {
            return None;
        }
        // SAFETY: the object's class is socket_ce, so it is embedded in a php_socket at the
        // offset bindgen derived from ext/sockets/php_sockets.h.
        let sock = (obj as *mut u8).sub(std::mem::offset_of!(sys::php_socket, std)) as *mut sys::php_socket;
        let fd = (*sock).bsd_socket;
        if fd < 0 { None } else { Some(fd) }
    }
}

/// Non-blocking state is read from the fd, not from `php_socket.blocking`: `php_read()` itself
/// derives it with `fcntl(F_GETFL)` and only falls back to the struct field when that fails, so
/// the field can lag the real flag (ADR-0018). A non-blocking socket must never park — the stock
/// semantics are "return now with EAGAIN".
unsafe fn is_nonblocking(fd: i32) -> bool {
    let m = unsafe { libc::fcntl(fd, libc::F_GETFL) };
    m >= 0 && (m & libc::O_NONBLOCK) != 0
}

/// One `poll` with a zero timeout: an already-ready socket skips the reactor round trip entirely,
/// which is the common case for writes.
unsafe fn ready_now(fd: i32, wait: Wait) -> bool {
    let mut p = libc::pollfd {
        fd,
        events: if wait == Wait::Write { libc::POLLOUT } else { libc::POLLIN },
        revents: 0,
    };
    unsafe { libc::poll(&mut p, 1, 0) > 0 }
}

/// `SO_RCVTIMEO`/`SO_SNDTIMEO` in microseconds, if set. They live only in the kernel — ext/sockets
/// passes `setsockopt` straight through and mirrors them nowhere — so a parked call with no timer
/// would hang where stock PHP times out (ADR-0018).
unsafe fn so_timeout_us(fd: i32, wait: Wait) -> Option<u64> {
    let opt = if wait == Wait::Write { libc::SO_SNDTIMEO } else { libc::SO_RCVTIMEO };
    let mut tv = libc::timeval { tv_sec: 0, tv_usec: 0 };
    let mut len = std::mem::size_of::<libc::timeval>() as libc::socklen_t;
    let rc = unsafe { libc::getsockopt(fd, libc::SOL_SOCKET, opt, &mut tv as *mut _ as *mut c_void, &mut len) };
    if rc != 0 {
        return None;
    }
    let us = tv.tv_sec as u64 * 1_000_000 + tv.tv_usec as u64;
    if us == 0 { None } else { Some(us) }
}

/// Sets the timeout to `us` (0 = none) and returns the previous value, so the original handler can
/// be made to report a timeout the stock way without blocking (the trick `accept.rs` uses on
/// `default_socket_timeout`).
unsafe fn swap_so_timeout(fd: i32, wait: Wait, us: u64) -> Option<libc::timeval> {
    let opt = if wait == Wait::Write { libc::SO_SNDTIMEO } else { libc::SO_RCVTIMEO };
    let mut old = libc::timeval { tv_sec: 0, tv_usec: 0 };
    let mut len = std::mem::size_of::<libc::timeval>() as libc::socklen_t;
    if unsafe { libc::getsockopt(fd, libc::SOL_SOCKET, opt, &mut old as *mut _ as *mut c_void, &mut len) } != 0 {
        return None;
    }
    let new = libc::timeval { tv_sec: (us / 1_000_000) as libc::time_t, tv_usec: (us % 1_000_000) as libc::suseconds_t };
    unsafe { libc::setsockopt(fd, libc::SOL_SOCKET, opt, &new as *const _ as *const c_void, len) };
    Some(old)
}

unsafe fn restore_so_timeout(fd: i32, wait: Wait, tv: libc::timeval) {
    let opt = if wait == Wait::Write { libc::SO_SNDTIMEO } else { libc::SO_RCVTIMEO };
    let len = std::mem::size_of::<libc::timeval>() as libc::socklen_t;
    unsafe { libc::setsockopt(fd, libc::SOL_SOCKET, opt, &tv as *const _ as *const c_void, len) };
}

unsafe fn getsockopt_int(fd: i32, opt: i32) -> Option<i32> {
    let mut v: libc::c_int = 0;
    let mut l = std::mem::size_of::<libc::c_int>() as libc::socklen_t;
    let rc = unsafe { libc::getsockopt(fd, libc::SOL_SOCKET, opt, &mut v as *mut _ as *mut c_void, &mut l) };
    if rc == 0 { Some(v) } else { None }
}

/// Has a peer (`getpeername` succeeds) — i.e. the socket is connected.
unsafe fn is_connected(fd: i32) -> bool {
    let mut ss: libc::sockaddr_storage = unsafe { std::mem::zeroed() };
    let mut l = std::mem::size_of::<libc::sockaddr_storage>() as libc::socklen_t;
    unsafe { libc::getpeername(fd, &raw mut ss as *mut libc::sockaddr, &mut l) == 0 }
}

/// Has a local address that can actually receive: a non-zero port for IP, a non-empty path for
/// AF_UNIX. An unbound socket never becomes readable, so waiting on one is a hang.
unsafe fn is_bound(fd: i32) -> bool {
    let mut ss: libc::sockaddr_storage = unsafe { std::mem::zeroed() };
    let mut l = std::mem::size_of::<libc::sockaddr_storage>() as libc::socklen_t;
    if unsafe { libc::getsockname(fd, &raw mut ss as *mut libc::sockaddr, &mut l) } != 0 {
        return false;
    }
    match ss.ss_family as i32 {
        libc::AF_INET => unsafe { (*(&raw const ss as *const libc::sockaddr_in)).sin_port != 0 },
        libc::AF_INET6 => unsafe { (*(&raw const ss as *const libc::sockaddr_in6)).sin6_port != 0 },
        libc::AF_UNIX => unsafe { (*(&raw const ss as *const libc::sockaddr_un)).sun_path[0] != 0 },
        _ => false,
    }
}

/// Whether waiting for readiness can ever end. Readiness is NOT the same as "the call would
/// succeed": on a listening socket a data op fails with ENOTCONN at once, and on an unconnected
/// or unbound socket it fails too — in both cases `poll` never fires and parking hangs forever
/// (php-src socket_read_params, gh17921, socket_recv_overflow, socket_send*_params). The original
/// also validates its arguments before any syscall, so anything we are unsure about must reach it
/// unparked. Delegating is always semantically correct; parking wrongly is a hang.
unsafe fn can_block(fd: i32, h: &Hook) -> bool {
    unsafe {
        let listening = getsockopt_int(fd, libc::SO_ACCEPTCONN) == Some(1);
        if h.listener {
            return listening; // accept only ever makes sense on a listener
        }
        if listening {
            return false;
        }
        if is_connected(fd) {
            return true;
        }
        // Unconnected: legal only for the address-carrying ops, and only once bound.
        h.addressed && is_bound(fd)
    }
}

unsafe fn dispatch(idx: usize, ex: *mut sys::zend_execute_data, rv: *mut sys::zval) {
    // SAFETY: called as a Zend internal-function handler, so `ex` is a live VM frame on the PHP
    // thread and its arguments stay alive for the duration of the call.
    unsafe {
        let h = &HOOKS[idx];
        let orig = ORIG.get().expect("originals installed")[idx];

        let in_fiber = !(*eg()).active_fiber.is_null() && !sys::zend_fiber_switch_blocked();
        if !in_fiber || super::module::try_reactor().is_none() || zval::num_args(ex) < 1 {
            tracing::debug!(func = ?h.name, "sockets hook: delegating (not in fiber / no reactor / no args)");
            orig(ex, rv);
            return;
        }
        let a0 = zval::arg(ex, 1); // 1-based: the Socket is always the first parameter
        let Some(fd) = socket_fd(a0) else {
            tracing::debug!(func = ?h.name, ztype = zval::type_of(a0), "sockets hook: arg 0 is not a live Socket");
            orig(ex, rv); // not a live Socket: let the original raise the stock error
            return;
        };
        // Readiness is probed BEFORE the blocking-mode check on purpose: an already-ready socket is
        // the common case (every write to a drained buffer), and it must cost one syscall, not two.
        // `fcntl` only matters when we are about to park, so it is paid only then.
        if ready_now(fd, h.wait) {
            orig(ex, rv);
            return;
        }
        if is_nonblocking(fd) {
            tracing::debug!(func = ?h.name, fd, "sockets hook: fd is non-blocking, not parking");
            orig(ex, rv);
            return;
        }
        // MSG_DONTWAIT means "return now"; MSG_WAITALL breaks the premise of delegating after one
        // readiness event, because POLLIN only promises one byte while the kernel blocks for the
        // full length. Both run the original directly (a known thread-blocking path, ADR-0018).
        if let Some(i) = h.flags_arg
            && zval::num_args(ex) >= i
            && let Some(f) = zval::arg_long(ex, i)
        {
            let f = f as i32;
            if f & libc::MSG_DONTWAIT != 0 || (h.wait == Wait::Read && f & libc::MSG_WAITALL != 0) {
                orig(ex, rv);
                return;
            }
        }
        if !can_block(fd, h) {
            tracing::debug!(func = ?h.name, fd, "sockets hook: socket cannot block for this op, delegating");
            orig(ex, rv);
            return;
        }
        let reactor = super::module::reactor();
        let watch = reactor.submit(Op::Watch { fd, write: h.wait == Wait::Write });
        let timeout_us = so_timeout_us(fd, h.wait);
        let timer = timeout_us.map(|us| reactor.submit(Op::Sleep { us }));
        let ids: Vec<u64> = std::iter::once(watch).chain(timer).collect();
        tracing::debug!(func = ?h.name, fd, ?timeout_us, "sockets hook: parking");
        let woke = super::stream::await_any(&ids);
        tracing::debug!(func = ?h.name, fd, woke = woke.is_some(), "sockets hook: woke");
        for id in &ids {
            if woke.as_ref().map(|w| w.0) != Some(*id) {
                reactor.submit(Op::CancelWatch { target: *id });
            }
        }
        let Some((woke_id, _)) = woke else {
            return; // the fiber unwound while parked (cancellation, ADR-0009)
        };
        if woke_id != watch {
            // Timed out. Run the original with a 1 µs kernel timeout so it reports the timeout the
            // stock way (false + EAGAIN) instead of blocking the thread for the full period again.
            let saved = swap_so_timeout(fd, h.wait, 1);
            orig(ex, rv);
            if let Some(tv) = saved {
                restore_so_timeout(fd, h.wait, tv);
            }
            return;
        }
        orig(ex, rv);
    }
}
