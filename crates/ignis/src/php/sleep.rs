//! E15c: `sleep()` / `usleep()` inside a fiber park the fiber on a reactor timer instead of
//! blocking the thread. The internal-function handlers are swapped at MINIT, on the main
//! thread, before `zend_post_startup` copies the global function table for worker threads
//! (ZTS `function_copy_ctor` memcpy's each `zend_internal_function`, handler included).
//! Outside a fiber, or on a thread without a reactor, the original handlers run.

use std::ffi::c_char;
use std::sync::OnceLock;

use ignis_sys as sys;

use super::zval;
use crate::reactor::Op;

type Handler = unsafe extern "C" fn(*mut sys::zend_execute_data, *mut sys::zval);

static ORIG_SLEEP: OnceLock<Handler> = OnceLock::new();
static ORIG_USLEEP: OnceLock<Handler> = OnceLock::new();

unsafe fn cg() -> *mut sys::zend_compiler_globals {
    unsafe { (sys::tsrm_get_ls_cache() as *mut u8).add(sys::compiler_globals_offset) as *mut sys::zend_compiler_globals }
}

unsafe fn eg() -> *mut sys::zend_executor_globals {
    unsafe { (sys::tsrm_get_ls_cache() as *mut u8).add(sys::executor_globals_offset) as *mut sys::zend_executor_globals }
}

/// Swap one internal function's handler; returns the original.
unsafe fn swap(name: &std::ffi::CStr, new: Handler) -> Option<Handler> {
    unsafe {
        let zv = sys::zend_hash_str_find((*cg()).function_table, name.as_ptr(), name.to_bytes().len());
        if zv.is_null() {
            return None;
        }
        let func = (*zv).value.func;
        let orig = (*func).internal_function.handler?;
        (*func).internal_function.handler = Some(new);
        Some(orig)
    }
}

/// MINIT (main thread). `IGNIS_NO_SLEEP_HOOK=1` disables it.
pub unsafe fn install() {
    if std::env::var_os("IGNIS_NO_SLEEP_HOOK").is_some() {
        return;
    }
    unsafe {
        match swap(c"sleep", hooked_sleep) {
            Some(o) => {
                let _ = ORIG_SLEEP.set(o);
            }
            None => tracing::warn!("sleep() not found; hook disabled"),
        }
        match swap(c"usleep", hooked_usleep) {
            Some(o) => {
                let _ = ORIG_USLEEP.set(o);
            }
            None => tracing::warn!("usleep() not found; hook disabled"),
        }
    }
}

/// Park the current fiber for `us` microseconds. False = not possible here (caller runs the original).
unsafe fn park_for(us: u64) -> bool {
    unsafe {
        if (*eg()).active_fiber.is_null() || sys::zend_fiber_switch_blocked() {
            return false;
        }
        let Some(reactor) = super::module::try_reactor() else { return false };
        let id = reactor.submit(Op::Sleep { us });
        // If parking fails after submit (unwinding fiber), the completion is dropped by the loop.
        super::stream::await_op(id).is_some()
    }
}

unsafe extern "C" fn hooked_sleep(ex: *mut sys::zend_execute_data, rv: *mut sys::zval) {
    // SAFETY: VM frame on the PHP thread; the argument is read through zend_parse_parameters.
    unsafe {
        let mut secs: sys::zend_long = 0;
        if sys::zend_parse_parameters(zval::num_args(ex), c"l".as_ptr(), &mut secs) != sys::SUCCESS {
            return;
        }
        if secs > 0 && park_for(secs as u64 * 1_000_000) {
            zval::set_long(rv, 0);
            return;
        }
        (ORIG_SLEEP.get().expect("original sleep"))(ex, rv);
    }
}

unsafe extern "C" fn hooked_usleep(ex: *mut sys::zend_execute_data, rv: *mut sys::zval) {
    // SAFETY: as above.
    unsafe {
        let mut us: sys::zend_long = 0;
        if sys::zend_parse_parameters(zval::num_args(ex), c"l".as_ptr(), &mut us) != sys::SUCCESS {
            return;
        }
        if us > 0 && park_for(us as u64) {
            zval::set_null(rv);
            return;
        }
        (ORIG_USLEEP.get().expect("original usleep"))(ex, rv);
    }
}

#[allow(dead_code)]
fn _handler_type_matches(_: Option<Handler>, h: sys::zif_handler) -> sys::zif_handler {
    h
}

#[allow(dead_code)]
const _: *const c_char = std::ptr::null();
