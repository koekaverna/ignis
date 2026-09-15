//! The `ignis` internal PHP module: registers the C-level primitives the
//! userland scheduler (php/ignis.php) is built on.
//!
//! Functions exposed to PHP (all thread-affine, all cheap):
//! - `ignis_submit_sleep(int $ms): int`  → op id
//! - `ignis_poll(int $timeout_ms): array<int,int>` → id => late_us; -1 blocks
//! - `ignis_inflight(): int`
//!
//! FFI contract: see `ignis_sys` crate docs. Every static here lives for the
//! process lifetime, which is what `zend_module_entry` requires.
use std::ffi::{CStr, c_char, c_int};
use std::ptr;
use std::sync::{Arc, OnceLock};
use std::time::Duration;

use ignis_sys as sys;

use super::zval;
use crate::reactor::{Completion, Op, Outcome, Reactor};

/// Process-wide reactor handle for the (single, in Cycle 0) PHP thread.
static REACTOR: OnceLock<Arc<Reactor>> = OnceLock::new();

pub fn install_reactor(r: Arc<Reactor>) {
    REACTOR.set(r).ok().expect("reactor installed twice");
}

fn reactor() -> &'static Reactor {
    REACTOR.get().expect("reactor not installed before PHP started")
}

/// Wrapper so a struct holding raw pointers can be a `static`. The pointees
/// are `'static` string literals and other statics; nothing is ever mutated
/// after process start, so sharing across threads is sound.
#[repr(transparent)]
struct SyncStatic<T>(T);
unsafe impl<T> Sync for SyncStatic<T> {}

const fn arg_info(name: &'static CStr) -> sys::zend_internal_arg_info {
    sys::zend_internal_arg_info {
        name: name.as_ptr(),
        type_: sys::zend_type { ptr: ptr::null_mut(), type_mask: 0 },
        default_value: ptr::null(),
    }
}

/// First entry of an arg-info array encodes `required_num_args` in `name`
/// (Zend's `ZEND_BEGIN_ARG_INFO_EX` does exactly this cast).
const fn arg_info_head(required: usize) -> sys::zend_internal_arg_info {
    sys::zend_internal_arg_info {
        name: required as *const c_char,
        type_: sys::zend_type { ptr: ptr::null_mut(), type_mask: 0 },
        default_value: ptr::null(),
    }
}

static ARGINFO_ONE_INT: SyncStatic<[sys::zend_internal_arg_info; 2]> =
    SyncStatic([arg_info_head(1), arg_info(c"value")]);
static ARGINFO_NONE: SyncStatic<[sys::zend_internal_arg_info; 1]> = SyncStatic([arg_info_head(0)]);

unsafe extern "C" fn zif_ignis_submit_sleep(ex: *mut sys::zend_execute_data, rv: *mut sys::zval) {
    // SAFETY: called by the Zend VM on a PHP thread with a valid frame.
    unsafe {
        if zval::num_args(ex) != 1 {
            sys::zend_argument_count_error(c"ignis_submit_sleep() expects exactly 1 argument".as_ptr());
            return;
        }
        let Some(ms) = zval::arg_long(ex, 1) else {
            sys::zend_type_error(c"ignis_submit_sleep(): argument #1 ($ms) must be of type int".as_ptr());
            return;
        };
        let id = reactor().submit(Op::Sleep { ms: ms.max(0) as u64 });
        zval::set_long(rv, id as i64);
    }
}

unsafe extern "C" fn zif_ignis_poll(ex: *mut sys::zend_execute_data, rv: *mut sys::zval) {
    // SAFETY: as above. The array we return is emalloc'd and owned by the
    // returned zval; the VM frees it.
    unsafe {
        if zval::num_args(ex) != 1 {
            sys::zend_argument_count_error(c"ignis_poll() expects exactly 1 argument".as_ptr());
            return;
        }
        let Some(timeout_ms) = zval::arg_long(ex, 1) else {
            sys::zend_type_error(c"ignis_poll(): argument #1 ($timeout_ms) must be of type int".as_ptr());
            return;
        };
        let timeout = if timeout_ms < 0 { None } else { Some(Duration::from_millis(timeout_ms as u64)) };
        let done: Vec<Completion> = reactor().poll(timeout);
        zval::set_new_array(rv);
        for c in done {
            let payload = match c.outcome {
                Outcome::Slept { late_us } => late_us as i64,
            };
            sys::add_index_long(rv, c.id, payload);
        }
    }
}

unsafe extern "C" fn zif_ignis_inflight(_ex: *mut sys::zend_execute_data, rv: *mut sys::zval) {
    // SAFETY: rv is VM-owned writable storage.
    unsafe { zval::set_long(rv, reactor().inflight() as i64) }
}

const fn fe(
    name: &'static CStr,
    handler: unsafe extern "C" fn(*mut sys::zend_execute_data, *mut sys::zval),
    arg_info: *const sys::zend_internal_arg_info,
    num_args: u32,
) -> sys::zend_function_entry {
    sys::zend_function_entry {
        fname: name.as_ptr(),
        handler: Some(handler),
        arg_info,
        num_args,
        flags: 0,
        frameless_function_infos: ptr::null(),
        doc_comment: ptr::null(),
    }
}

const fn fe_end() -> sys::zend_function_entry {
    sys::zend_function_entry {
        fname: ptr::null(),
        handler: None,
        arg_info: ptr::null(),
        num_args: 0,
        flags: 0,
        frameless_function_infos: ptr::null(),
        doc_comment: ptr::null(),
    }
}

static FUNCTIONS: SyncStatic<[sys::zend_function_entry; 4]> = SyncStatic([
    fe(c"ignis_submit_sleep", zif_ignis_submit_sleep, ARGINFO_ONE_INT.0.as_ptr(), 1),
    fe(c"ignis_poll", zif_ignis_poll, ARGINFO_ONE_INT.0.as_ptr(), 1),
    fe(c"ignis_inflight", zif_ignis_inflight, ARGINFO_NONE.0.as_ptr(), 0),
    fe_end(),
]);

/// `zend_module_entry` for the ignis module. Mutable because Zend writes
/// `module_started`, `module_number`, `handle` into it at registration.
pub static mut MODULE: sys::zend_module_entry = sys::zend_module_entry {
    size: sys::IGNIS_SIZEOF_ZEND_MODULE_ENTRY as u16,
    zend_api: sys::IGNIS_ZEND_MODULE_API_NO,
    zend_debug: sys::IGNIS_ZEND_DEBUG,
    zts: sys::IGNIS_USING_ZTS,
    ini_entry: ptr::null(),
    deps: ptr::null(),
    name: c"ignis".as_ptr(),
    functions: FUNCTIONS.0.as_ptr(),
    module_startup_func: None,
    module_shutdown_func: None,
    request_startup_func: None,
    request_shutdown_func: None,
    info_func: None,
    version: c"0.0.1".as_ptr(),
    globals_size: 0,
    globals_id_ptr: ptr::null_mut(),
    globals_ctor: None,
    globals_dtor: None,
    post_deactivate_func: None,
    module_started: 0,
    type_: 0,
    handle: ptr::null_mut(),
    module_number: 0,
    build_id: sys::IGNIS_ZEND_MODULE_BUILD_ID.as_ptr() as *const c_char,
};

/// Replacement for `php_embed_module.startup`: identical to the stock one
/// except it registers our module as `additional_module`.
pub unsafe extern "C" fn ignis_sapi_startup(sapi: *mut sys::sapi_module_struct) -> c_int {
    // SAFETY: called once by php_embed_init on the main PHP thread; MODULE is
    // a process-lifetime static and Zend keeps a pointer to it, which is the
    // documented contract for internal modules.
    unsafe { sys::php_module_startup(sapi, &raw mut MODULE) as c_int }
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn module_entry_matches_header_constants() {
        // SAFETY: read-only access to a static in a single test thread.
        let m = unsafe { &*(&raw const MODULE) };
        assert_eq!(m.size as u64, std::mem::size_of::<sys::zend_module_entry>() as u64);
        assert_eq!(m.zts, 1, "must be built against a ZTS PHP");
        let bid = unsafe { CStr::from_ptr(m.build_id) }.to_str().unwrap();
        assert!(bid.ends_with(",TS"), "build id {bid} is not a TS build");
        assert!(bid.starts_with(&format!("API{}", m.zend_api)));
    }

    #[test]
    fn function_table_is_terminated() {
        let last = &FUNCTIONS.0[FUNCTIONS.0.len() - 1];
        assert!(last.fname.is_null() && last.handler.is_none());
        assert_eq!(unsafe { CStr::from_ptr(FUNCTIONS.0[0].fname) }.to_str().unwrap(), "ignis_submit_sleep");
    }
}
