//! The thread-local Zend globals, resolved through TSRM.
//!
//! Under ZTS each PHP thread owns its own executor and compiler globals, reached by adding a
//! link-time offset to that thread's TSRM cache pointer (ADR-0025). Three modules kept their own
//! copy of the same three lines; this is the one copy.

use ignis_sys as sys;

/// This thread's `EG`.
///
/// # Safety
/// The calling thread must have a TSRM context — every thread that runs PHP does, from
/// `php_embed_init` or `WorkerThread::attach` onwards.
pub(super) unsafe fn executor_globals() -> *mut sys::zend_executor_globals {
    // SAFETY: the caller guarantees a live TSRM cache, and executor_globals_offset comes from the
    // libphp this binary is linked against, so the sum lands inside that thread's own globals.
    unsafe { (sys::tsrm_get_ls_cache() as *mut u8).add(sys::executor_globals_offset) as *mut sys::zend_executor_globals }
}

/// This thread's `CG`.
///
/// # Safety
/// As [`executor_globals`].
pub(super) unsafe fn compiler_globals() -> *mut sys::zend_compiler_globals {
    // SAFETY: as `executor_globals`, with the compiler globals' own offset.
    unsafe { (sys::tsrm_get_ls_cache() as *mut u8).add(sys::compiler_globals_offset) as *mut sys::zend_compiler_globals }
}
