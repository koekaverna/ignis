//! Backend (b): the true-async fork's scheduler ABI (php-src PR #22561).
//!
//! Prototype scope (H9b): the *reference* scheduler `ext/test_scheduler`
//! stays the provider; Ignis plugs the tokio reactor into its idle point via
//! the `patches/0001-test-scheduler-idle-hook.patch` hook and exposes
//! `ignis_await_op(int $id): int` that parks the *current engine coroutine*
//! until the reactor completes op `$id`.
//!
//! FFI contract:
//! - `zend_async_suspend_fn` / `zend_async_enqueue_coroutine_fn` are the ABI's
//!   exported slot pointers; they are only valid while a scheduler is
//!   registered (`zend_async_is_enabled()`), which we check per call.
//! - Coroutine pointers stored in `WAITING` are owned by the scheduler's live
//!   table; the reference provider guarantees a coroutine is not freed while
//!   SUSPENDED (only retired from the loop after it FINISHES), so a pointer
//!   parked here is valid until we enqueue it. We never dereference it after
//!   the enqueue.
//! - All of this runs on the PHP thread (the hook is called from the scheduler
//!   fiber context on that same thread).
use std::cell::RefCell;
use std::collections::HashMap;

use ignis_sys as sys;

use crate::php::zval;
use crate::reactor::Outcome;

thread_local! {
    /// op id => parked coroutine (see FFI contract above).
    static WAITING: RefCell<HashMap<u64, *mut sys::zend_coroutine_t>> = RefCell::new(HashMap::new());
    /// op id => payload delivered before the coroutine ran again.
    static RESULTS: RefCell<HashMap<u64, i64>> = RefCell::new(HashMap::new());
}

/// `ZEND_ASYNC_CURRENT_COROUTINE`: `ZEND_ASYNC_G(coroutine)` through the TSRM
/// fast offset, same expression as the C macro in a ZTS build.
///
/// # Safety
/// PHP thread after startup.
unsafe fn current_coroutine() -> *mut sys::zend_coroutine_t {
    unsafe {
        let base = sys::tsrm_get_ls_cache() as *mut u8;
        let g = base.add(sys::zend_async_globals_offset) as *mut sys::zend_async_globals_t;
        (*g).coroutine
    }
}

/// `ignis_await_op(int $id): int` — parks the current coroutine until op `$id`
/// completes; returns the op payload (timer lateness in µs).
pub unsafe extern "C" fn zif_ignis_await_op(ex: *mut sys::zend_execute_data, rv: *mut sys::zval) {
    // SAFETY: VM frame on the PHP thread; see module contract.
    unsafe {
        let Some(id) = zval::arg_long(ex, 1) else {
            sys::zend_type_error(c"ignis_await_op(): argument #1 ($id) must be of type int".as_ptr());
            return;
        };
        let id = id as u64;
        if !sys::zend_async_is_enabled() {
            sys::zend_throw_exception(std::ptr::null_mut(), c"ignis_await_op(): no async scheduler registered".as_ptr(), 0);
            return;
        }
        // Already delivered (completion raced ahead of the await)? Return now.
        if let Some(v) = RESULTS.with(|r| r.borrow_mut().remove(&id)) {
            zval::set_long(rv, v);
            return;
        }
        let co = current_coroutine();
        if co.is_null() {
            sys::zend_throw_exception(std::ptr::null_mut(), c"ignis_await_op(): not inside a coroutine".as_ptr(), 0);
            return;
        }
        WAITING.with(|w| w.borrow_mut().insert(id, co));
        // ZEND_ASYNC_SUSPEND(): switches to the scheduler; returns when we are
        // enqueued again by the idle hook. `false` = an exception is pending.
        let ok = (sys::zend_async_suspend_fn.expect("suspend slot"))(false, false);
        if !ok {
            WAITING.with(|w| w.borrow_mut().remove(&id));
            return;
        }
        let v = RESULTS.with(|r| r.borrow_mut().remove(&id)).unwrap_or(-1);
        zval::set_long(rv, v);
    }
}

/// Idle hook installed into the reference scheduler: blocks on the reactor and
/// enqueues every coroutine whose op completed. Returns true if any was enqueued.
unsafe extern "C" fn idle_hook() -> bool {
    // SAFETY: called on the PHP thread from the scheduler context; the reactor
    // handle is process-wide and the coroutine pointers follow the contract above.
    unsafe {
        if WAITING.with(|w| w.borrow().is_empty()) {
            return false; // nothing of ours is parked: let the scheduler declare deadlock
        }
        let done = crate::php::module::reactor().poll(None);
        let mut any = false;
        for c in done {
            let payload = match c.outcome {
                Outcome::Slept { late_us } => late_us as i64,
                Outcome::Request(_) => -2, // HTTP on backend (b) is out of scope for H9b
            };
            RESULTS.with(|r| r.borrow_mut().insert(c.id, payload));
            if let Some(co) = WAITING.with(|w| w.borrow_mut().remove(&c.id)) {
                // ZEND_ASYNC_ENQUEUE_COROUTINE(co): status QUEUED, pushed on the FIFO.
                (sys::zend_async_enqueue_coroutine_fn.expect("enqueue slot"))(co, std::ptr::null_mut(), false);
                any = true;
            }
        }
        any
    }
}

/// Registers the idle hook. Called once from the module's MINIT-equivalent
/// (Engine::init) when the ABI is present.
pub fn install() {
    // SAFETY: the exported setter only stores the pointer.
    unsafe { sys::test_scheduler_set_idle_hook(Some(idle_hook)) }
}
