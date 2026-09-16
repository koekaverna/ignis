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
}

static ORIG_SSL: OnceLock<sys::php_stream_transport_factory> = OnceLock::new();
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

/// Wrap an already-connected socket fd (from `stream_socket_accept`, E6'') in a hooked stream:
/// the reactor adopts a dup of the fd. Returns null (the caller keeps the stock stream) on failure.
///
/// # Safety
/// PHP thread, inside a fiber, from an internal function frame.
pub unsafe fn adopt_fd(fd: c_int) -> *mut sys::php_stream {
    unsafe {
        let dup = libc::dup(fd);
        if dup < 0 {
            return ptr::null_mut();
        }
        let id = reactor().submit(Op::Adopt { fd: dup });
        match await_op(id) {
            Some(Outcome::Connected { conn, fd, local, peer }) => {
                let sock = Box::new(Sock { conn, pending: Vec::new(), pos: 0, eof: false, tls_on_connect: false, fd, local, peer });
                sys::_php_stream_alloc(&OPS.0, Box::into_raw(sock) as *mut c_void, ptr::null(), c"r+".as_ptr())
            }
            _ => ptr::null_mut(),
        }
    }
}

/// True if `zv` is a hooked stream holding bytes the kernel fd no longer shows (our read-ahead):
/// `stream_select()` must report it readable without parking.
pub unsafe fn has_buffered(zv: *mut sys::zval) -> bool {
    unsafe {
        if super::zval::type_of(zv) != sys::IS_RESOURCE {
            return false;
        }
        let stream = sys::zend_fetch_resource2_ex(zv, ptr::null(), sys::php_file_le_stream(), sys::php_file_le_pstream()) as *mut sys::php_stream;
        if stream.is_null() || (*stream).ops != &OPS.0 as *const sys::php_stream_ops {
            return false;
        }
        let s = sock_of(stream);
        !s.is_null() && ((*s).pending.len() > (*s).pos || (*s).eof)
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
    Some((host, p.parse().ok()?))
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
        let is_tls = proto_str != "tcp";
        if !in_fiber || !persistent_id.is_null() || (flags & STREAM_XPORT_SERVER) != 0 {
            let orig = if is_tls { ORIG_SSL.get().copied().flatten() } else { ORIG_TCP.get().copied().flatten() };
            let Some(orig) = orig else { return ptr::null_mut() };
            return orig(proto, protolen, res, reslen, persistent_id, options, flags, timeout, context);
        }
        let sock = Box::new(Sock { conn: 0, pending: Vec::new(), pos: 0, eof: false, tls_on_connect: is_tls, fd: -1, local: String::new(), peer: String::new() });
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
            let id = reactor().submit(Op::Read { conn: (*s).conn, max: count.max(8192) });
            match await_op(id) {
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
const NOTIMPL: c_int = sys::PHP_STREAM_OPTION_RETURN_NOTIMPL as c_int;

unsafe extern "C" fn op_set_option(stream: *mut sys::php_stream, option: c_int, _value: c_int, ptrparam: *mut c_void) -> c_int {
    unsafe {
        match option as u32 {
            sys::PHP_STREAM_OPTION_XPORT_API => {
                let xp = ptrparam as *mut sys::php_stream_xport_param;
                match (*xp).op {
                    sys::STREAM_XPORT_OP_CONNECT | sys::STREAM_XPORT_OP_CONNECT_ASYNC => {
                        let name = std::str::from_utf8(std::slice::from_raw_parts((*xp).inputs.name as *const u8, (*xp).inputs.namelen))
                            .unwrap_or("");
                        let Some((host, port)) = parse_host_port(name) else {
                            (*xp).outputs.returncode = -1;
                            return OK;
                        };
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
            // Timeouts/blocking mode are the reactor's business; accept silently.
            sys::PHP_STREAM_OPTION_READ_TIMEOUT | sys::PHP_STREAM_OPTION_BLOCKING | sys::PHP_STREAM_OPTION_CHECK_LIVENESS => {
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
    }
}
