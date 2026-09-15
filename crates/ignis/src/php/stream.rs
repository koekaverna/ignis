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
unsafe fn await_op(id: u64) -> Option<Outcome> {
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
        let in_fiber = !(*eg()).active_fiber.is_null();
        if !in_fiber || !persistent_id.is_null() {
            let orig = ORIG_TCP.get().copied().flatten().expect("original tcp factory");
            return orig(proto, protolen, res, reslen, persistent_id, options, flags, timeout, context);
        }
        let sock = Box::new(Sock { conn: 0, pending: Vec::new(), pos: 0, eof: false });
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
            (*stream).abstract_ = ptr::null_mut();
        }
        0
    }
}

unsafe extern "C" fn op_flush(_stream: *mut sys::php_stream) -> c_int {
    0
}

unsafe extern "C" fn op_cast(_stream: *mut sys::php_stream, _castas: c_int, _ret: *mut *mut c_void) -> c_int {
    sys::FAILURE as c_int // no file descriptor: stream_select() on this stream fails loudly
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
                        let id = reactor().submit(Op::Connect { host, port });
                        match await_op(id) {
                            Some(Outcome::Connected { conn }) => {
                                (*sock_of(stream)).conn = conn;
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
    label: c"ignis_tcp".as_ptr(),
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
    }
}
