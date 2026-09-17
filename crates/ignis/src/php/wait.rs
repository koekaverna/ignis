//! The C-side park registry: a fiber suspended inside an internal call (universal park,
//! `ignis_watch`, a Custom op) is keyed here by op id, and `ignis_poll` resumes it on the same
//! thread when the completion arrives (ADR-0007's mechanism, kept after the transport factory
//! it was written for was retired — ADR-0037 §6 step 4).
//!
//! FFI contract:
//! - Suspension: an op registers `(op id → EG(active_fiber))` in a thread-local table and calls
//!   `zend_fiber_suspend`. The fiber object stays alive because its owner (the pool / userland)
//!   holds it; the raw pointer is used once, by `resume_parked` running in `ignis_poll` on the
//!   same thread.
//! - Results cross through a thread-local table keyed by op id, so no Rust reference crosses the
//!   fiber switch.
//! - Outside a fiber (`EG(active_fiber) == NULL`) nothing here applies: the caller blocks as stock.
use std::cell::RefCell;
use std::collections::HashMap;
use std::ptr;

use ignis_sys as sys;

use crate::reactor::Outcome;

thread_local! {
    /// op id → fiber parked inside a stream op.
    static PARKED: RefCell<HashMap<u64, *mut sys::zend_fiber>> = RefCell::new(HashMap::new());
    /// op id → outcome delivered by `ignis_poll` before the fiber is resumed.
    static RESULTS: RefCell<HashMap<u64, Outcome>> = RefCell::new(HashMap::new());
}

#[cfg(feature = "universal-park")]
unsafe fn eg() -> *mut sys::zend_executor_globals {
    unsafe { (sys::tsrm_get_ls_cache() as *mut u8).add(sys::executor_globals_offset) as *mut sys::zend_executor_globals }
}

/// Parks the running fiber until op `id` completes. `None` = could not park
/// (not in a fiber, switching blocked, or the fiber was unwound meanwhile).
///
/// # Safety
/// PHP thread, inside an internal call on the current fiber's stack.
#[cfg(feature = "universal-park")]
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
#[cfg(feature = "universal-park")]
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
