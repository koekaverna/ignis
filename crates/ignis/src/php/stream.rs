//! `tcp://` transport replacement (ADR-0007): unmodified stream I/O inside a
//! fiber suspends the fiber; the reactor completes the I/O on tokio.
//!
//! FFI contract:
//! - The factory and every op run on the PHP thread that owns the stream.
//!   `stream->abstract` is a `Box<Sock>` owned by the stream and freed in `close`.
//! - Suspension: an op registers `(op id → EG(active_fiber))` in a thread-local
//!   table and calls `zend_fiber_suspend`. The fiber object stays alive because
//!   its owner (the pool / userland) holds it; the raw pointer is only used
//!   once, by `resume_parked` running in `ignis_poll` on the same thread.
//! - Results are handed over through a thread-local table keyed by op id, so
//!   no Rust reference crosses the fiber switch.
//! - Outside a fiber (`EG(active_fiber) == NULL`) the original factory is
//!   used, i.e. blocking behaviour is unchanged.
use std::cell::RefCell;
use std::collections::HashMap;
use std::ffi::{c_char, c_int, c_void};
use std::ptr;
use std::sync::OnceLock;

use bytes::Bytes;
use ignis_sys as sys;

use super::module::reactor;
use crate::reactor::{Op, Outcome};

/// The stock `tcp` factory, looked up at MINIT before we replace it.
static ORIG_TCP: OnceLock<sys::php_stream_transport_factory> = OnceLock::new();

thread_local! {
    /// op id → fiber parked inside a stream op.
    static PARKED: RefCell<HashMap<u64, *mut sys::zend_fiber>> = RefCell::new(HashMap::new());
    /// op id → outcome delivered by `ignis_poll` before the fiber is resumed.
    static RESULTS: RefCell<HashMap<u64, Outcome>> = RefCell::new(HashMap::new());
}

struct Sock {
    conn: u64,
    /// Bytes read from the socket but not yet handed to PHP.
    pending: Vec<u8>,
    pos: usize,
    eof: bool,
    /// `ssl://`/`tls://` stream: handshake on connect (ADR-0017). `tcp://` streams may still
    /// upgrade later through `stream_socket_enable_crypto()`.
    tls_on_connect: bool,
    /// dup of the socket for `stream_select()` / `socket_import_stream()`; -1 until connected.
    fd: i32,
    local: String,
    peer: String,
    /// `stream_set_blocking()`: false = reads return what is there (or nothing) without parking.
    blocking: bool,
    /// `stream_set_timeout()` (A1): read timeout in µs, `None` = wait forever. Honoured by
    /// `op_read`; without it a `fread()` with no data parked the fiber for ever
    /// (php-src stream_get_meta_data_socket_variation2 timed the whole process out).
    read_timeout_us: Option<u64>,
    /// Set when a read hit `read_timeout_us`; reported by `stream_get_meta_data()` and cleared
    /// by the next successful read, as xp_socket does.
    timed_out: bool,
    /// A4 (ADR-0018): `unix://` — the address is a filesystem path, not `host:port`.
    is_unix: bool,
}

static ORIG_SSL: OnceLock<sys::php_stream_transport_factory> = OnceLock::new();
/// A4: the stock `unix` factory, kept for the out-of-fiber and server cases.
static ORIG_UNIX: OnceLock<sys::php_stream_transport_factory> = OnceLock::new();
const TLS_PROTOS: [&std::ffi::CStr; 4] = [c"ssl", c"tls", c"tlsv1.2", c"tlsv1.3"];

/// PHP's `ssl` context options → TlsOpts (defaults as in ext/openssl: verify on, host name = peer_name or the connect host).
unsafe fn tls_opts(stream: *mut sys::php_stream, host: &str) -> crate::reactor::TlsOpts {
    unsafe {
        let ctx = if (*stream).ctx.is_null() { ptr::null() } else { (*(*stream).ctx).ptr as *const sys::php_stream_context };
        let get = |name: &std::ffi::CStr| -> *mut sys::zval {
            if ctx.is_null() { ptr::null_mut() } else { sys::php_stream_context_get_option(ctx, c"ssl".as_ptr(), name.as_ptr()) }
        };
        let flag = |name: &std::ffi::CStr, default: bool| -> bool {
            let z = get(name);
            if z.is_null() { default } else { sys::zend_is_true(z) }
        };
        let string = |name: &std::ffi::CStr| -> Option<String> {
            let z = get(name);
            if z.is_null() || super::zval::type_of(z) != sys::IS_STRING { None } else { Some(super::zval::zstr_to_string((*z).value.str_)) }
        };
        crate::reactor::TlsOpts {
            server_name: string(c"peer_name").unwrap_or_else(|| host.to_string()),
            verify_peer: flag(c"verify_peer", true),
            verify_peer_name: flag(c"verify_peer_name", true),
            allow_self_signed: flag(c"allow_self_signed", false),
            cafile: string(c"cafile"),
        }
    }
}

/// `EG(...)` base pointer for the calling thread.
unsafe fn eg() -> *mut sys::zend_executor_globals {
    unsafe { (sys::tsrm_get_ls_cache() as *mut u8).add(sys::executor_globals_offset) as *mut sys::zend_executor_globals }
}

/// Parks the running fiber until op `id` completes. `None` = could not park
/// (not in a fiber, switching blocked, or the fiber was unwound meanwhile).
///
/// # Safety
/// PHP thread, inside an internal call on the current fiber's stack.
pub(crate) unsafe fn await_op(id: u64) -> Option<Outcome> {
    unsafe {
        let fiber = (*eg()).active_fiber;
        if fiber.is_null() || sys::zend_fiber_switch_blocked() {
            return None;
        }
        PARKED.with(|p| p.borrow_mut().insert(id, fiber));
        let mut ret: sys::zval = std::mem::zeroed();
        // Hands control to whoever resumed us (the loop). Returns when
        // resume_parked() calls zend_fiber_resume, or when the fiber is
        // being destroyed (then EG(exception) carries an unwind exit).
        sys::zend_fiber_suspend(fiber, ptr::null_mut(), &mut ret);
        sys::zval_ptr_dtor(&mut ret);
        if !(*eg()).exception.is_null() {
            PARKED.with(|p| p.borrow_mut().remove(&id));
            return None;
        }
        RESULTS.with(|r| r.borrow_mut().remove(&id))
    }
}

/// Park the running fiber until ANY of `ids` completes; returns the id that did. The other ids'
/// completions are dropped later (no fiber waits on them any more). `None` = could not park.
///
/// # Safety
/// PHP thread, inside an internal call on the current fiber's stack.
pub(crate) unsafe fn await_any(ids: &[u64]) -> Option<(u64, Outcome)> {
    unsafe {
        let fiber = (*eg()).active_fiber;
        if fiber.is_null() || sys::zend_fiber_switch_blocked() || ids.is_empty() {
            return None;
        }
        PARKED.with(|p| {
            let mut p = p.borrow_mut();
            for id in ids {
                p.insert(*id, fiber);
            }
        });
        let mut ret: sys::zval = std::mem::zeroed();
        sys::zend_fiber_suspend(fiber, ptr::null_mut(), &mut ret);
        sys::zval_ptr_dtor(&mut ret);
        // Whichever id resumed us left its outcome in RESULTS; unpark the rest.
        PARKED.with(|p| {
            let mut p = p.borrow_mut();
            for id in ids {
                p.remove(id);
            }
        });
        if !(*eg()).exception.is_null() {
            return None;
        }
        RESULTS.with(|r| {
            let mut r = r.borrow_mut();
            for id in ids {
                if let Some(o) = r.remove(id) {
                    return Some((*id, o));
                }
            }
            None
        })
    }
}

/// True if a fiber is parked (C-side) on op `id` on this thread.
pub fn is_parked(id: u64) -> bool {
    PARKED.with(|p| p.borrow().contains_key(&id))
}

/// Called by `ignis_poll` for every completion: if a fiber is parked on it,
/// stores the outcome and resumes the fiber right here. Returns true if consumed.
///
/// # Safety
/// PHP thread, from inside `ignis_poll` (an internal function frame on the
/// loop's stack), which is a valid resumer context.
pub unsafe fn resume_parked(id: u64, outcome: Outcome) -> bool {
    unsafe {
        let Some(fiber) = PARKED.with(|p| p.borrow_mut().remove(&id)) else { return false };
        RESULTS.with(|r| r.borrow_mut().insert(id, outcome));
        let mut ret: sys::zval = std::mem::zeroed();
        sys::zend_fiber_resume(fiber, ptr::null_mut(), &mut ret);
        sys::zval_ptr_dtor(&mut ret);
        true
    }
}

/// `ignis_cancel_parked_any(Fiber $fiber, Throwable $e): bool` — if `$fiber` is
/// parked in a stream op, resume it by throwing `$e` at the suspension point
/// (ADR-0009). Returns false if it is not parked here.
pub unsafe extern "C" fn zif_ignis_cancel_parked_any(ex: *mut sys::zend_execute_data, rv: *mut sys::zval) {
    // SAFETY: VM frame on the PHP thread. `zend_fiber` embeds `zend_object std`
    // as its first member, so the object pointer is the zend_fiber pointer.
    // The exception zval is VM-owned; zend_fiber_resume_exception copies it.
    unsafe {
        let mut zfiber: *mut sys::zval = ptr::null_mut();
        let mut exc: *mut sys::zval = ptr::null_mut();
        if sys::zend_parse_parameters(super::zval::num_args(ex), c"oo".as_ptr(), &mut zfiber, &mut exc) != sys::SUCCESS {
            return;
        }
        let fiber = (*zfiber).value.obj as *mut sys::zend_fiber;
        let found = PARKED.with(|p| {
            let mut p = p.borrow_mut();
            let key = p.iter().find(|(_, f)| **f == fiber).map(|(k, _)| *k);
            key.map(|k| p.remove(&k))
        });
        if found.is_none() {
            super::zval::set_bool(rv, false);
            return;
        }
        let mut ret: sys::zval = std::mem::zeroed();
        sys::zend_fiber_resume_exception(fiber, exc, &mut ret);
        sys::zval_ptr_dtor(&mut ret);
        super::zval::set_bool(rv, true);
    }
}

fn parse_host_port(res: &str) -> Option<(String, u16)> {
    let (h, p) = res.rsplit_once(':')?;
    let host = h.trim_start_matches('[').trim_end_matches(']').to_string();
    // A1: PHP parses the port with atoi() into an unsigned short, so a literal above 65535 WRAPS
    // instead of failing — php-src bug69521 expects tcp://127.0.0.1:74321 to reach port 8785.
    // Parsing straight into u16 rejected it, and the stream then failed with errno 0 inside a
    // fiber while {main} and the stock CLI connected fine.
    let port: i64 = p.parse().ok()?;
    Some((host, port as u16))
}

unsafe extern "C" fn ignis_tcp_factory(
    proto: *const c_char,
    protolen: usize,
    res: *const c_char,
    reslen: usize,
    persistent_id: *const c_char,
    options: c_int,
    flags: c_int,
    timeout: *mut sys::timeval,
    context: *mut sys::php_stream_context,
) -> *mut sys::php_stream {
    // SAFETY: called by _php_stream_xport_create on the PHP thread with valid,
    // call-scoped pointers.
    unsafe {
        // main/streams/php_stream_transport.h: STREAM_XPORT_SERVER = 1 (stream_socket_server()).
        // Server sockets (bind/listen/accept) stay on the stock transport: the hook only
        // owns client connections (E15c finding: NOTIMPL on BIND made them fail inside fibers).
        const STREAM_XPORT_SERVER: c_int = 1;
        let in_fiber = !(*eg()).active_fiber.is_null();
        let proto_str = std::str::from_utf8(std::slice::from_raw_parts(proto as *const u8, protolen)).unwrap_or("tcp");
        let is_unix = proto_str == "unix";
        let is_tls = !is_unix && proto_str != "tcp";
        if !in_fiber || !persistent_id.is_null() || (flags & STREAM_XPORT_SERVER) != 0 {
            let orig = if is_tls { ORIG_SSL.get().copied().flatten() } else if is_unix { ORIG_UNIX.get().copied().flatten() } else { ORIG_TCP.get().copied().flatten() };
            let Some(orig) = orig else { return ptr::null_mut() };
            return orig(proto, protolen, res, reslen, persistent_id, options, flags, timeout, context);
        }
        let sock = Box::new(Sock { conn: 0, pending: Vec::new(), pos: 0, eof: false, tls_on_connect: is_tls, fd: -1, local: String::new(), peer: String::new(), blocking: true, read_timeout_us: None, timed_out: false, is_unix });
        sys::_php_stream_alloc(&OPS.0, Box::into_raw(sock) as *mut c_void, ptr::null(), c"r+".as_ptr())
    }
}

unsafe fn sock_of(stream: *mut sys::php_stream) -> *mut Sock {
    unsafe { (*stream).abstract_ as *mut Sock }
}

unsafe extern "C" fn op_write(stream: *mut sys::php_stream, buf: *const c_char, count: usize) -> isize {
    unsafe {
        let s = sock_of(stream);
        let data = Bytes::copy_from_slice(std::slice::from_raw_parts(buf as *const u8, count));
        let id = reactor().submit(Op::Write { conn: (*s).conn, data });
        match await_op(id) {
            Some(Outcome::Written(n)) => n as isize,
            Some(Outcome::Error(e)) => {
                tracing::debug!(error = %e, "ignis tcp write failed");
                -1
            }
            _ => -1,
        }
    }
}

unsafe extern "C" fn op_read(stream: *mut sys::php_stream, buf: *mut c_char, count: usize) -> isize {
    unsafe {
        let s = sock_of(stream);
        if (*s).pos >= (*s).pending.len() && !(*s).eof {
            let max = count.max(8192);
            (*s).timed_out = false;
            // A1: with a `stream_set_timeout()` deadline the read must be abandonable, and an
            // `Op::Read` cannot be cancelled — a timer that won would strand it and lose the bytes
            // it later delivers. So wait on READINESS (`Op::Watch`, cancellable) against the timer
            // and only then take the data with a non-blocking `Op::TryRead`. Costs one extra
            // reactor hop, paid solely when a timeout is set; the untimed path below is unchanged.
            // Known gap (roadmap A6): on a TLS stream readiness of the raw fd is not the same as
            // plaintext being available, because rustls buffers records the fd no longer shows.
            let timed = if (*s).blocking && (*s).fd >= 0 { (*s).read_timeout_us } else { None };
            let deadline = timed.map(|us| std::time::Instant::now() + std::time::Duration::from_micros(us));
            loop {
                if let Some(dl) = deadline {
                    let left = dl.saturating_duration_since(std::time::Instant::now());
                    if left.is_zero() {
                        (*s).timed_out = true;
                        return 0;
                    }
                    let r = reactor();
                    let watch = r.submit(Op::Watch { fd: (*s).fd, write: false });
                    let timer = r.submit(Op::Sleep { us: left.as_micros() as u64 });
                    let ids = [watch, timer];
                    let woke = await_any(&ids);
                    for id in &ids {
                        if woke.as_ref().map(|w| w.0) != Some(*id) {
                            r.submit(Op::CancelWatch { target: *id });
                        }
                    }
                    let Some((woke_id, _)) = woke else {
                        return -1; // unwound while parked
                    };
                    if woke_id == timer {
                        // Stock xp_socket reports a timeout as "no bytes this call", with the flag
                        // visible through stream_get_meta_data(); it is not EOF and not an error.
                        (*s).timed_out = true;
                        return 0;
                    }
                }
                let id = if (*s).blocking && (*s).read_timeout_us.is_none() {
                    reactor().submit(Op::Read { conn: (*s).conn, max })
                } else {
                    reactor().submit(Op::TryRead { conn: (*s).conn, max })
                };
                match await_op(id) {
                    Some(Outcome::WouldBlock) => {
                        // H31 (V-36): readiness of the dup'd fd is not the same as the connection
                        // actor holding the bytes — the kernel can have them while the actor has
                        // not been polled yet. On a *timed blocking* read the only correct move is
                        // to go back to the wait until the deadline; returning 0 makes PHP's
                        // get_line report "no line" and a blocking caller abandons a response that
                        // is already on its way. That was ~0.1-0.3 % of requests under load.
                        if deadline.is_some() {
                            continue;
                        }
                        // Non-blocking stream, nothing there: 0 bytes and no EOF, like recv() → EAGAIN.
                        return 0;
                    }
                    Some(Outcome::Data(d)) => {
                        if d.is_empty() {
                            (*s).eof = true;
                        } else {
                            (*s).pending = d.to_vec();
                            (*s).pos = 0;
                        }
                    }
                    Some(Outcome::Error(e)) => {
                        tracing::debug!(error = %e, "ignis tcp read failed");
                        (*s).eof = true;
                        (*stream).set_eof(1);
                        return -1;
                    }
                    _ => {
                        (*s).eof = true;
                        (*stream).set_eof(1);
                        return -1;
                    }
                }
                break;
            }
        }
        let avail = (*s).pending.len() - (*s).pos;
        if avail == 0 {
            (*stream).set_eof(1);
            return 0;
        }
        let n = avail.min(count);
        ptr::copy_nonoverlapping((*s).pending.as_ptr().add((*s).pos), buf as *mut u8, n);
        (*s).pos += n;
        n as isize
    }
}

unsafe extern "C" fn op_close(stream: *mut sys::php_stream, _close_handle: c_int) -> c_int {
    unsafe {
        let s = sock_of(stream);
        if !s.is_null() {
            let sock = Box::from_raw(s);
            if sock.conn != 0 {
                // Fire and forget: the actor closes the socket; nobody waits.
                reactor().submit(Op::Close { conn: sock.conn });
            }
            if sock.fd >= 0 {
                libc::close(sock.fd);
            }
            (*stream).abstract_ = ptr::null_mut();
        }
        0
    }
}

unsafe extern "C" fn op_flush(_stream: *mut sys::php_stream) -> c_int {
    0
}

/// `stream_select()` / `socket_import_stream()` get a dup of the socket: readiness is real,
/// the data still flows through the reactor ops (E6''; research 17/15 asked for this).
unsafe extern "C" fn op_cast(stream: *mut sys::php_stream, castas: c_int, ret: *mut *mut c_void) -> c_int {
    unsafe {
        const CAST_MASK: c_int = 0x1fff_ffff; // PHP_STREAM_CAST_MASK
        let fd = (*sock_of(stream)).fd;
        match (castas & CAST_MASK) as u32 {
            sys::PHP_STREAM_AS_FD_FOR_SELECT | sys::PHP_STREAM_AS_FD | sys::PHP_STREAM_AS_SOCKETD if fd >= 0 => {
                // Protocol (php_sockop_cast): `ret` points at a php_socket_t (int), not at a void*.
                if !ret.is_null() {
                    *(ret as *mut c_int) = fd;
                }
                sys::SUCCESS as c_int
            }
            _ => sys::FAILURE as c_int,
        }
    }
}

const OK: c_int = sys::PHP_STREAM_OPTION_RETURN_OK as c_int;
const ERR: c_int = sys::PHP_STREAM_OPTION_RETURN_ERR as c_int;
const NOTIMPL: c_int = sys::PHP_STREAM_OPTION_RETURN_NOTIMPL as c_int;

unsafe extern "C" fn op_set_option(stream: *mut sys::php_stream, option: c_int, value: c_int, ptrparam: *mut c_void) -> c_int {
    unsafe {
        match option as u32 {
            sys::PHP_STREAM_OPTION_XPORT_API => {
                let xp = ptrparam as *mut sys::php_stream_xport_param;
                match (*xp).op {
                    sys::STREAM_XPORT_OP_CONNECT | sys::STREAM_XPORT_OP_CONNECT_ASYNC => {
                        let name = std::str::from_utf8(std::slice::from_raw_parts((*xp).inputs.name as *const u8, (*xp).inputs.namelen))
                            .unwrap_or("");
                        // A4: a unix socket is addressed by path; host:port parsing does not apply.
                        if (*sock_of(stream)).is_unix {
                            let id = reactor().submit(Op::ConnectUnix { path: name.to_string() });
                            match await_op(id) {
                                Some(Outcome::Connected { conn, fd, local, peer }) => {
                                    let s = sock_of(stream);
                                    (*s).conn = conn;
                                    (*s).fd = fd;
                                    (*s).local = local;
                                    (*s).peer = peer;
                                    (*xp).outputs.returncode = 0;
                                }
                                Some(Outcome::Error(e)) => {
                                    if (*xp).want_errortext() != 0 {
                                        let msg = std::ffi::CString::new(e).unwrap_or_default();
                                        (*xp).outputs.error_text = sys::zend_strpprintf(0, c"%s".as_ptr(), msg.as_ptr());
                                    }
                                    (*xp).outputs.returncode = -1;
                                }
                                _ => (*xp).outputs.returncode = -1,
                            }
                            return OK;
                        }
                        let Some((host, port)) = parse_host_port(name) else {
                            // A1: an unparseable address used to fail silently — returncode -1 with
                            // no error_text, so $errstr/$errno came back empty where stock fills them.
                            if (*xp).want_errortext() != 0 {
                                // No interpolation of `name`: it is a &str over raw bytes, not a
                                // NUL-terminated C string, and may itself contain NUL bytes.
                                (*xp).outputs.error_text =
                                    sys::zend_strpprintf(0, c"%s".as_ptr(), c"Failed to parse address".as_ptr());
                            }
                            (*xp).outputs.returncode = -1;
                            return OK;
                        };
                        // A1: stock rejects a NUL in the host name with this exact wording before it
                        // resolves anything (php-src ghsa-3cr5-j632-f35r); passing it through to the
                        // resolver produced a different message.
                        if host.contains('\0') {
                            if (*xp).want_errortext() != 0 {
                                (*xp).outputs.error_text =
                                    sys::zend_strpprintf(0, c"%s".as_ptr(), c"The hostname must not contain null bytes".as_ptr());
                            }
                            (*xp).outputs.returncode = -1;
                            return OK;
                        }
                        let tls = if (*sock_of(stream)).tls_on_connect { Some(tls_opts(stream, &host)) } else { None };
                        let id = reactor().submit(Op::Connect { host, port, tls });
                        match await_op(id) {
                            Some(Outcome::Connected { conn, fd, local, peer }) => {
                                let s = sock_of(stream);
                                (*s).conn = conn;
                                (*s).fd = fd;
                                (*s).local = local;
                                (*s).peer = peer;
                                (*xp).outputs.returncode = 0;
                            }
                            Some(Outcome::Error(e)) => {
                                if (*xp).want_errortext() != 0 {
                                    let msg = std::ffi::CString::new(e).unwrap_or_default();
                                    (*xp).outputs.error_text = sys::zend_strpprintf(0, c"%s".as_ptr(), msg.as_ptr());
                                }
                                (*xp).outputs.returncode = -1;
                            }
                            _ => (*xp).outputs.returncode = -1,
                        }
                        OK
                    }
                    sys::STREAM_XPORT_OP_SHUTDOWN => OK,
                    sys::STREAM_XPORT_OP_GET_NAME | sys::STREAM_XPORT_OP_GET_PEER_NAME => {
                        let s = sock_of(stream);
                        let name = if (*xp).op == sys::STREAM_XPORT_OP_GET_NAME { &(*s).local } else { &(*s).peer };
                        if name.is_empty() {
                            (*xp).outputs.returncode = -1;
                            return OK;
                        }
                        if (*xp).want_textaddr() != 0 {
                            let c = std::ffi::CString::new(name.as_str()).unwrap_or_default();
                            (*xp).outputs.textaddr = sys::zend_strpprintf(0, c"%s".as_ptr(), c.as_ptr());
                        }
                        (*xp).outputs.returncode = 0;
                        OK
                    }
                    _ => NOTIMPL,
                }
            }
            // STARTTLS (stream_socket_enable_crypto): SETUP records nothing (method is always TLS
            // client here), ENABLE with activate=1 upgrades the connection in place (ADR-0017).
            sys::PHP_STREAM_OPTION_CRYPTO_API => {
                let cp = ptrparam as *mut sys::php_stream_xport_crypto_param;
                match (*cp).op {
                    sys::STREAM_XPORT_CRYPTO_OP_SETUP => {
                        (*cp).outputs.returncode = 0;
                        OK
                    }
                    sys::STREAM_XPORT_CRYPTO_OP_ENABLE => {
                        if (*cp).inputs.activate == 0 {
                            (*cp).outputs.returncode = -1; // TLS shutdown/downgrade not supported
                            return OK;
                        }
                        let conn = (*sock_of(stream)).conn;
                        let opts = tls_opts(stream, "");
                        if opts.server_name.is_empty() {
                            sys::php_error_docref(ptr::null(), sys::E_WARNING as c_int, c"ignis: stream_socket_enable_crypto() needs the 'peer_name' ssl context option on a hooked stream".as_ptr());
                            (*cp).outputs.returncode = -1;
                            return OK;
                        }
                        let id = reactor().submit(Op::Upgrade { conn, tls: opts });
                        (*cp).outputs.returncode = match await_op(id) {
                            Some(Outcome::Ready) => 1,
                            Some(Outcome::Error(e)) => {
                                let msg = std::ffi::CString::new(e).unwrap_or_default();
                                sys::php_error_docref(ptr::null(), sys::E_WARNING as c_int, c"%s".as_ptr(), msg.as_ptr());
                                -1
                            }
                            _ => -1,
                        };
                        OK
                    }
                    _ => NOTIMPL,
                }
            }
            sys::PHP_STREAM_OPTION_BLOCKING => {
                let s = sock_of(stream);
                let was = (*s).blocking;
                (*s).blocking = value != 0;
                was as c_int // the previous mode, as xp_socket reports it
            }
            // stream_get_meta_data(): the keys xp_socket fills in.
            sys::PHP_STREAM_OPTION_META_DATA_API => {
                let s = sock_of(stream);
                let arr = ptrparam as *mut sys::zval;
                sys::add_assoc_bool_ex(arr, c"timed_out".as_ptr(), 9, (*s).timed_out);
                sys::add_assoc_bool_ex(arr, c"blocked".as_ptr(), 7, (*s).blocking);
                sys::add_assoc_bool_ex(arr, c"eof".as_ptr(), 3, (*s).eof && (*s).pos >= (*s).pending.len());
                OK
            }
            // feof() on a stream with nothing buffered asks whether the peer is still there
            // (xp_socket: poll + MSG_PEEK). Without this a `while (!feof($fp))` spins forever
            // once the peer closed (bug70198).
            sys::PHP_STREAM_OPTION_CHECK_LIVENESS => {
                let s = sock_of(stream);
                if (*s).eof {
                    return ERR;
                }
                if (*s).pending.len() > (*s).pos || (*s).fd < 0 {
                    return OK;
                }
                let mut pfd = libc::pollfd { fd: (*s).fd, events: libc::POLLIN | libc::POLLPRI, revents: 0 };
                let ms: c_int = if value == -1 { 0 } else { value };
                if libc::poll(&mut pfd, 1, ms) > 0 && pfd.revents & (libc::POLLIN | libc::POLLPRI | libc::POLLHUP | libc::POLLERR) != 0 {
                    let mut b = [0u8; 1];
                    let n = libc::recv((*s).fd, b.as_mut_ptr() as *mut c_void, 1, libc::MSG_PEEK | libc::MSG_DONTWAIT);
                    if n == 0 || (n < 0 && std::io::Error::last_os_error().raw_os_error() != Some(libc::EAGAIN)) {
                        return ERR;
                    }
                }
                OK
            }
            // A1: `stream_set_timeout()` — ptrparam is a `struct timeval`. Previously accepted and
            // ignored, which made a `fread()` with no data park for ever instead of timing out.
            sys::PHP_STREAM_OPTION_READ_TIMEOUT => {
                let s = sock_of(stream);
                let tv = ptrparam as *const libc::timeval;
                if tv.is_null() {
                    (*s).read_timeout_us = None;
                } else {
                    let us = (*tv).tv_sec as i64 * 1_000_000 + (*tv).tv_usec as i64;
                    // A negative timeval means "no timeout", as xp_socket reads it.
                    (*s).read_timeout_us = if us > 0 { Some(us as u64) } else { None };
                }
                OK
            }
            _ => NOTIMPL,
        }
    }
}

#[repr(transparent)]
struct SyncStatic<T>(T);
unsafe impl<T> Sync for SyncStatic<T> {}

static OPS: SyncStatic<sys::php_stream_ops> = SyncStatic(sys::php_stream_ops {
    write: Some(op_write),
    read: Some(op_read),
    close: Some(op_close),
    flush: Some(op_flush),
    // The stock label: code and tests check `stream_get_meta_data()['stream_type'] === 'tcp_socket'`.
    label: c"tcp_socket".as_ptr(),
    seek: None,
    cast: Some(op_cast),
    stat: None,
    set_option: Some(op_set_option),
});

/// Replaces the `tcp` transport factory. Called from MINIT (after
/// `php_init_stream_wrappers`, main.c:2321 vs :2341). Process-wide, once.
/// Set `IGNIS_NO_STREAM_HOOK=1` to keep the stock transport.
pub unsafe fn install() {
    if std::env::var_os("IGNIS_NO_STREAM_HOOK").is_some() {
        return;
    }
    // SAFETY: MINIT on the main thread; the transport hash exists and is not
    // mutated concurrently before worker threads start.
    unsafe {
        let ht = sys::php_stream_xport_get_hash();
        let zv = sys::zend_hash_str_find(ht, c"tcp".as_ptr(), 3);
        if zv.is_null() {
            tracing::error!("no stock tcp transport found; stream hook disabled");
            return;
        }
        let orig: sys::php_stream_transport_factory = std::mem::transmute::<*mut c_void, sys::php_stream_transport_factory>((*zv).value.ptr);
        let _ = ORIG_TCP.set(orig);
        sys::php_stream_xport_register(c"tcp".as_ptr(), Some(ignis_tcp_factory));
        // ADR-0017: the TLS protocol names too (ext/openssl registered them; it is the fallback).
        let ssl = sys::zend_hash_str_find(ht, c"ssl".as_ptr(), 3);
        if !ssl.is_null() && std::env::var_os("IGNIS_NO_SSL_HOOK").is_none() {
            let orig_ssl = std::mem::transmute::<*mut c_void, sys::php_stream_transport_factory>((*ssl).value.ptr);
            let _ = ORIG_SSL.set(orig_ssl);
            for name in TLS_PROTOS {
                sys::php_stream_xport_register(name.as_ptr(), Some(ignis_tcp_factory));
            }
        }
        // A4 (ADR-0018): `unix://`. Only the client side is taken, like tcp — a `unix` server
        // socket keeps the stock factory (the BIND/LISTEN/ACCEPT path is not ours, E15c).
        // `udp`/`udg` are deliberately NOT registered: they are connectionless and addressed per
        // packet, which does not fit the reactor's one-actor-per-connection model at all.
        if std::env::var_os("IGNIS_NO_UNIX_HOOK").is_none() {
            let ux = sys::zend_hash_str_find(ht, c"unix".as_ptr(), 4);
            if !ux.is_null() {
                let orig_unix = std::mem::transmute::<*mut c_void, sys::php_stream_transport_factory>((*ux).value.ptr);
                let _ = ORIG_UNIX.set(orig_unix);
                sys::php_stream_xport_register(c"unix".as_ptr(), Some(ignis_tcp_factory));
            }
        }
    }
}
