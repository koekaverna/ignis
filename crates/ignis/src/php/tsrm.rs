//! The Zend globals, per thread or per process depending on which engine this was built against.
//!
//! Under ZTS each PHP thread owns its own executor and compiler globals, reached by adding a
//! link-time offset to that thread's TSRM cache pointer (ADR-0025). Three modules kept their own
//! copy of the same three lines; this is the one copy.
//!
//! Under NTS (`cfg(php_nts)`, S-NTS-MODE) there is no TSRM cache and no per-thread anything: one
//! interpreter exists in the process and the globals are plain exported symbols. The three
//! functions keep their signatures and their `# Safety` contracts so nothing above this file knows
//! which engine it is talking to — the whole ABI difference is the six lines below.

use ignis_sys as sys;

/// This thread's `EG`.
///
/// # Safety
/// The calling thread must have a TSRM context — every thread that runs PHP does, from
/// `php_embed_init` or `WorkerThread::attach` onwards.
#[cfg(not(php_nts))]
pub(super) unsafe fn executor_globals() -> *mut sys::zend_executor_globals {
    // SAFETY: the caller guarantees a live TSRM cache, and executor_globals_offset comes from the
    // libphp this binary is linked against, so the sum lands inside that thread's own globals.
    unsafe { (sys::tsrm_get_ls_cache() as *mut u8).add(sys::executor_globals_offset) as *mut sys::zend_executor_globals }
}

/// This process's `EG`.
///
/// # Safety
/// As the ZTS one: the engine must be started. There is nothing per-thread to get wrong here.
#[cfg(php_nts)]
pub(super) unsafe fn executor_globals() -> *mut sys::zend_executor_globals {
    &raw mut sys::executor_globals
}

/// This thread's `SG`.
///
/// # Safety
/// As [`executor_globals`].
#[cfg(not(php_nts))]
pub(super) unsafe fn sapi_globals() -> *mut sys::sapi_globals_struct {
    // SAFETY: as `executor_globals`, with the SAPI globals' own offset.
    unsafe { (sys::tsrm_get_ls_cache() as *mut u8).add(sys::sapi_globals_offset) as *mut sys::sapi_globals_struct }
}

/// This process's `SG`.
///
/// # Safety
/// As [`executor_globals`].
#[cfg(php_nts)]
pub(super) unsafe fn sapi_globals() -> *mut sys::sapi_globals_struct {
    &raw mut sys::sapi_globals
}

/// This thread's `CG`.
///
/// # Safety
/// As [`executor_globals`].
#[cfg(not(php_nts))]
pub(super) unsafe fn compiler_globals() -> *mut sys::zend_compiler_globals {
    // SAFETY: as `executor_globals`, with the compiler globals' own offset.
    unsafe { (sys::tsrm_get_ls_cache() as *mut u8).add(sys::compiler_globals_offset) as *mut sys::zend_compiler_globals }
}

/// This process's `CG`.
///
/// # Safety
/// As [`executor_globals`].
#[cfg(php_nts)]
pub(super) unsafe fn compiler_globals() -> *mut sys::zend_compiler_globals {
    &raw mut sys::compiler_globals
}

/// This thread's (ZTS) or this process's (NTS) `PG`.
///
/// # Safety
/// As [`executor_globals`].
#[cfg(not(php_nts))]
pub(super) unsafe fn core_globals() -> *mut sys::_php_core_globals {
    // SAFETY: as `executor_globals`, with the core globals' own offset.
    unsafe { (sys::tsrm_get_ls_cache() as *mut u8).add(sys::core_globals_offset) as *mut sys::_php_core_globals }
}

/// This process's `PG`.
///
/// # Safety
/// As [`executor_globals`].
#[cfg(php_nts)]
pub(super) unsafe fn core_globals() -> *mut sys::_php_core_globals {
    &raw mut sys::core_globals
}
