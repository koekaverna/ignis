//! Output belongs to a fiber, not to a thread.
//!
//! Every `echo`, `print` and `var_dump` leaves PHP through `sapi_module.ub_write`. The embed SAPI's
//! own implementation writes to stdout, which is right for a script and wrong for a server: several
//! requests run as fibers on one OS thread, and one request's bytes must not end up in another's
//! answer.
//!
//! Doing that in PHP with `ob_start()` does not work, and V-72 measured why: the output-buffer stack
//! is per thread and nested buffers only behave if they close last-in-first-out, which interleaved
//! fibers do not — two responses swapped bodies. Making it safe there needed a lock, and a lock
//! serialises every streamed response on the thread.
//!
//! Here there is nothing to lock. `ub_write` runs on the PHP thread with `EG(active_fiber)` telling
//! us exactly whose bytes these are, so each fiber appends to its own buffer and a fiber with no
//! buffer falls through to stdout exactly as before. This is ADR-0006's context mechanism applied to
//! output, next to the superglobals it already applies to.
//!
//! The buffers are a stack per fiber so a nested capture keeps the outer one intact.

use std::cell::RefCell;
use std::collections::HashMap;
use std::ffi::c_char;

use ignis_sys as sys;

use super::zval;

thread_local! {
    /// Fiber context pointer (0 = `{main}`) → the stack of buffers that fiber is filling.
    static SINKS: RefCell<HashMap<usize, Vec<Vec<u8>>>> = RefCell::new(HashMap::new());
    /// Fibers whose output goes straight out as response chunks.
    ///
    /// This exists because PHP's own `ob_start()` cannot do it safely. Its buffer stack is per
    /// **thread**, so a fiber that merely echoes while another is streaming writes into that other
    /// buffer, and by the time the handler runs the bytes are already mixed and unattributable —
    /// measured, a second request's `echo` was framed into the first one's body (V-76). `ub_write`
    /// runs at the moment of the write and knows whose it is, which is the only place the question
    /// can still be answered.
    static BOUND: RefCell<HashMap<usize, Bound>> = RefCell::new(HashMap::new());
}

/// A fiber whose output is a response body.
///
/// The status line is **not** sent at bind time — it goes out with the first byte, whatever produced
/// it. That is what lets a producer which fails before writing anything still answer `500`: nothing
/// has been promised to the client yet.
struct Bound {
    id: u64,
    status: u16,
    headers: Vec<(String, String)>,
    pending: Vec<u8>,
    started: bool,
}

impl Bound {
    /// Sends `frame`, opening the response first if this is its first byte. Returns the bytes that
    /// did not fit, which happens only when the client is behind.
    fn push(&mut self, frame: bytes::Bytes) -> Vec<u8> {
        if !self.started {
            if !crate::php::module::reactor().respond_start(
                self.id,
                self.status,
                std::mem::take(&mut self.headers),
                super::module::stream_chunks(),
            ) {
                return Vec::new(); // the client is already gone
            }
            self.started = true;
        }
        match crate::php::module::reactor().stream_sender(self.id) {
            Some(tx) => match tx.try_send(frame) {
                Err(tokio::sync::mpsc::error::TrySendError::Full(back)) => back.to_vec(),
                _ => Vec::new(),
            },
            None => Vec::new(),
        }
    }
}

/// Bytes a bound fiber may accumulate before a frame is pushed out.
fn frame_bytes() -> usize {
    static V: std::sync::OnceLock<usize> = std::sync::OnceLock::new();
    *V.get_or_init(|| std::env::var("IGNIS_STREAM_FRAME_BYTES").ok().and_then(|v| v.parse().ok()).unwrap_or(8192usize))
}

/// Which fiber is running, as an opaque key. `{main}` is 0.
///
/// # Safety
/// Must be called on a PHP thread with an initialised TSRM cache.
unsafe fn current() -> usize {
    unsafe {
        let eg = (sys::tsrm_get_ls_cache() as *mut u8).add(sys::executor_globals_offset) as *mut sys::zend_executor_globals;
        (*eg).active_fiber as usize
    }
}

/// `sapi_module.ub_write`: append to the running fiber's buffer, or write through to stdout.
///
/// # Safety
/// Called by PHP with `str_length` valid bytes at `str_`.
pub unsafe extern "C" fn ub_write(str_: *const c_char, str_length: usize) -> usize {
    if str_length == 0 {
        return 0;
    }
    // SAFETY: the SAPI contract is `str_length` readable bytes at `str_`.
    let bytes = unsafe { std::slice::from_raw_parts(str_ as *const u8, str_length) };
    let key = unsafe { current() };

    // Bound to a response stream: the bytes are frames, not a buffer to collect.
    let streamed = BOUND.with(|b| {
        let mut b = b.borrow_mut();
        let Some(bound) = b.get_mut(&key) else { return false };
        bound.pending.extend_from_slice(bytes);
        if bound.pending.len() >= frame_bytes() {
            // `try_send` only: this runs on the PHP thread inside an internal frame, so it can
            // neither block the thread nor suspend the fiber. A full channel means the client is
            // behind, and the bytes stay here until PHP drains them, where awaiting is legal.
            let frame = std::mem::take(&mut bound.pending);
            bound.pending = bound.push(bytes::Bytes::from(frame));
        }
        true
    });
    if streamed {
        return str_length;
    }

    let captured = SINKS.with(|s| {
        let mut s = s.borrow_mut();
        match s.get_mut(&key).and_then(|stack| stack.last_mut()) {
            Some(buf) => {
                buf.extend_from_slice(bytes);
                true
            }
            None => false,
        }
    });
    if captured {
        return str_length;
    }

    // Nobody is capturing: stdout, unbuffered, the way a script expects.
    let mut written = 0;
    while written < str_length {
        // SAFETY: a plain write of the caller's buffer to fd 1.
        let n = unsafe { libc::write(1, bytes[written..].as_ptr() as *const libc::c_void, str_length - written) };
        if n <= 0 {
            break;
        }
        written += n as usize;
    }

    str_length
}

/// `ignis_capture_start(): bool` — this fiber's output goes to a fresh buffer until it is taken.
///
/// # Safety
/// VM frame on a PHP thread.
pub unsafe extern "C" fn zif_capture_start(_ex: *mut sys::zend_execute_data, rv: *mut sys::zval) {
    let key = unsafe { current() };
    SINKS.with(|s| s.borrow_mut().entry(key).or_default().push(Vec::new()));
    // SAFETY: `rv` is the VM's return slot.
    unsafe { zval::set_bool(rv, true) };
}

/// `ignis_capture_take(): string` — the bytes written since the matching start, and stop capturing.
///
/// # Safety
/// VM frame on a PHP thread.
pub unsafe extern "C" fn zif_capture_take(_ex: *mut sys::zend_execute_data, rv: *mut sys::zval) {
    let key = unsafe { current() };
    let taken = SINKS.with(|s| {
        let mut s = s.borrow_mut();
        let out = s.get_mut(&key).and_then(|stack| stack.pop());
        if s.get(&key).is_some_and(Vec::is_empty) {
            s.remove(&key);
        }
        out
    });
    // SAFETY: `rv` is the VM's return slot; `string_zval` hands back an owned string zval.
    unsafe { *rv = super::route::string_zval(&taken.unwrap_or_default()) };
}

/// `sapi_module.flush`: PHP's `flush()` — push whatever this fiber has pending as a frame now.
///
/// Without it a bound fiber's output only leaves at the frame threshold, so a handler echoing a few
/// bytes per row would batch its whole response. `flush()` is what `StreamedResponse` and every CGI
/// -era script call to say "send this now", and this is the only place that request can be honoured.
///
/// # Safety
/// Called by PHP on the PHP thread; the argument is the SAPI's opaque context and is not read.
pub unsafe extern "C" fn flush(_server_context: *mut std::ffi::c_void) {
    let key = unsafe { current() };
    BOUND.with(|b| {
        let mut b = b.borrow_mut();
        let Some(bound) = b.get_mut(&key) else { return };
        if bound.pending.is_empty() {
            return;
        }
        let frame = std::mem::take(&mut bound.pending);
        bound.pending = bound.push(bytes::Bytes::from(frame));
    });
}

/// `ignis_stream_write(string $bytes): int` — send `$bytes` as a frame of the response this fiber is
/// bound to, opening it if this is the first byte. Returns `0` if the runtime took it, an op id to
/// await if the queue to the socket is full, and `-1` if this fiber is not streaming.
///
/// This is what a producer calls instead of `echo` when it wants the client's back-pressure: `echo`
/// can only ever be taken optimistically (`ub_write` runs where a fiber cannot suspend), while the op
/// this returns is awaitable, so a slow client parks the producer.
///
/// # Safety
/// VM frame on a PHP thread; the string argument is copied before anything is submitted.
pub unsafe extern "C" fn zif_stream_write(ex: *mut sys::zend_execute_data, rv: *mut sys::zval) {
    unsafe {
        let mut buf: *mut c_char = std::ptr::null_mut();
        let mut len: usize = 0;
        if sys::zend_parse_parameters(zval::num_args(ex), c"s".as_ptr(), &mut buf, &mut len) != sys::SUCCESS {
            return;
        }
        let key = current();
        // Anything `echo`ed before this call is still pending; it must go first or the response
        // would be reordered. `push` opens the response if this is its first byte.
        let leftover = BOUND.with(|b| {
            let mut b = b.borrow_mut();
            let bound = b.get_mut(&key)?;
            bound.pending.extend_from_slice(std::slice::from_raw_parts(buf as *const u8, len));
            let frame = std::mem::take(&mut bound.pending);
            let back = bound.push(bytes::Bytes::from(frame));
            let id = bound.id;
            bound.pending = Vec::new();
            Some((id, back))
        });
        let Some((id, leftover)) = leftover else {
            zval::set_long(rv, -1);
            return;
        };
        // What did not fit is worth an op: awaiting it is exactly the back-pressure.
        zval::set_long(rv, if leftover.is_empty() { 0 } else { super::module::send_chunk(id, bytes::Bytes::from(leftover)) });
    }
}

/// `ignis_stream_bind(int $id, int $status, array $headers): bool` — this fiber's output becomes the
/// body of response `$id`.
///
/// The status line is not sent here: it goes out with the first byte, whatever wrote it. That is
/// what lets a producer which fails before writing anything still answer `500`.
///
/// # Safety
/// VM frame on a PHP thread; the arguments are copied into owned Rust data.
pub unsafe extern "C" fn zif_stream_bind(ex: *mut sys::zend_execute_data, rv: *mut sys::zval) {
    unsafe {
        let mut id: sys::zend_long = 0;
        let mut status: sys::zend_long = 200;
        let mut ht: *mut sys::HashTable = std::ptr::null_mut();
        if sys::zend_parse_parameters(zval::num_args(ex), c"llh".as_ptr(), &mut id, &mut status, &mut ht) != sys::SUCCESS {
            return;
        }
        let headers = super::module::header_pairs(ht, "ignis_stream_bind");
        let key = current();
        BOUND.with(|b| {
            b.borrow_mut()
                .insert(key, Bound { id: id as u64, status: status.clamp(100, 599) as u16, headers, pending: Vec::new(), started: false })
        });
        zval::set_bool(rv, true);
    }
}

/// `ignis_stream_unbind(): array` — stops forwarding and reports `[tail, started]`.
///
/// Anything still pending is pushed out here, so `tail` is only what did not fit — PHP sends that,
/// because there it may await. `started` is how the loop knows whether a failure can still become a
/// `500` or can only truncate what the client is already reading.
///
/// # Safety
/// VM frame on a PHP thread.
pub unsafe extern "C" fn zif_stream_unbind(_ex: *mut sys::zend_execute_data, rv: *mut sys::zval) {
    let key = unsafe { current() };
    // Push whatever is pending first — opening the response if this is its first byte — so the tail
    // handed back is only what did not fit, which PHP can then await.
    let (tail, started) = BOUND.with(|b| {
        let mut b = b.borrow_mut();
        let Some(bound) = b.get_mut(&key) else { return (Vec::new(), false) };
        if !bound.pending.is_empty() {
            let frame = std::mem::take(&mut bound.pending);
            bound.pending = bound.push(bytes::Bytes::from(frame));
        }
        let out = (std::mem::take(&mut bound.pending), bound.started);
        b.remove(&key);
        out
    });
    // SAFETY: `rv` is the VM's return slot; the string zval is owned by the caller.
    unsafe {
        zval::set_new_array(rv);
        sys::add_next_index_stringl(rv, tail.as_ptr() as *const c_char, tail.len());
        sys::add_next_index_bool(rv, started);
    }
}

/// `ignis_capture_reset(): bool` — drops whatever this fiber left behind. Called when a request
/// ends, so a fiber that died inside a capture does not hand its bytes to the next request that
/// reuses it (fibers are pooled, V-67).
///
/// # Safety
/// VM frame on a PHP thread.
pub unsafe extern "C" fn zif_capture_reset(_ex: *mut sys::zend_execute_data, rv: *mut sys::zval) {
    let key = unsafe { current() };
    let had = SINKS.with(|s| s.borrow_mut().remove(&key).is_some()) | BOUND.with(|b| b.borrow_mut().remove(&key).is_some());
    // SAFETY: `rv` is the VM's return slot.
    unsafe { zval::set_bool(rv, had) };
}
