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

/// `ignis_capture_reset(): bool` — drops whatever this fiber left behind. Called when a request
/// ends, so a fiber that died inside a capture does not hand its bytes to the next request that
/// reuses it (fibers are pooled, V-67).
///
/// # Safety
/// VM frame on a PHP thread.
pub unsafe extern "C" fn zif_capture_reset(_ex: *mut sys::zend_execute_data, rv: *mut sys::zval) {
    let key = unsafe { current() };
    let had = SINKS.with(|s| s.borrow_mut().remove(&key).is_some());
    // SAFETY: `rv` is the VM's return slot.
    unsafe { zval::set_bool(rv, had) };
}
