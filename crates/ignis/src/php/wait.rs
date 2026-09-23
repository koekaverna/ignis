//! The C-side park registry: a fiber suspended inside an internal call (universal park,
//! `ignis_watch`, a Custom op) is keyed here by op id, and `ignis_poll` resumes it on the same
//! thread when the completion arrives (ADR-0007's mechanism, kept after the transport factory
//! it was written for was retired — ADR-0037 §6 step 4).
//!
//! FFI contract:
//! - Suspension: an op registers `(op id → EG(active_fiber))` in a thread-local table and calls
//!   `zend_fiber_suspend`. This table holds a reference to the fiber for the length of the park
//!   and releases it on the loop's side after the resume returned, never from inside the fiber.
//! - Results cross through a thread-local table keyed by op id, so no Rust reference crosses the
//!   fiber switch.
//! - Outside a fiber (`EG(active_fiber) == NULL`) nothing here applies: the caller blocks as stock.
//! - A fiber being force-closed (`kill_pending` or the engine's `DESTROYED` flag) is refused any
//!   park and answered `Parked::Cancelled`.
use std::cell::RefCell;
use std::collections::HashMap;
use std::ptr;

#[cfg(feature = "universal-park")]
use super::tsrm;
use ignis_sys as sys;

#[cfg(feature = "universal-park")]
use crate::reactor::Op;
use crate::reactor::Outcome;

thread_local! {
    /// op id → fiber parked inside a stream op.
    static PARKED: RefCell<HashMap<u64, *mut sys::zend_fiber>> = RefCell::new(HashMap::new());
    /// op id → outcome delivered by `ignis_poll` before the fiber is resumed. An outcome is taken
    /// out by the fiber it was delivered for, including when that fiber wakes into an unwind: a
    /// cancellation arriving after the completion left one entry per cancelled park behind for the
    /// life of the thread.
    static RESULTS: RefCell<HashMap<u64, Outcome>> = RefCell::new(HashMap::new());
    /// timer op id → the parked op it bounds (`park_timeout_ms`, S-FIBER-TIMEOUT).
    static PARK_TIMERS: RefCell<HashMap<u64, u64>> = RefCell::new(HashMap::new());
    /// parked op id → its timer, so a resume can call the timer off.
    static TIMER_OF: RefCell<HashMap<u64, u64>> = RefCell::new(HashMap::new());
}

/// How a C-side park came back.
#[derive(Debug)]
#[cfg_attr(not(feature = "universal-park"), allow(dead_code))]
pub enum Parked<T> {
    Done(T),
    /// The fiber is being force-closed: no park was granted, or the park was resumed into an
    /// unwind. The caller answers `ECANCELED` and returns to the library at once.
    Cancelled,
    /// Not in a fiber, or switching is blocked: the caller blocks as stock.
    Unavailable,
}

/// True when the loop or the engine has marked `fiber` as closing (ADR-0043 L2, research 49 H2).
///
/// # Safety
/// `fiber` must be a live fiber object on this thread.
#[cfg(feature = "universal-park")]
unsafe fn closing(fiber: *mut sys::zend_fiber) -> bool {
    // SAFETY: the caller upholds the contract; `flags` is a plain byte and the meta is this fiber's.
    unsafe {
        if (*fiber).flags & sys::ZEND_FIBER_FLAG_DESTROYED as u8 != 0 {
            return true;
        }
        let meta = super::fibermeta::of(fiber);
        !meta.is_null() && (*meta).kill_pending
    }
}

/// True when the running fiber would be refused a park: the caller then answers `ECANCELED`
/// without submitting an op that nothing will wait for.
///
/// # Safety
/// PHP thread.
#[cfg(feature = "universal-park")]
pub(crate) unsafe fn refuses_park() -> bool {
    // SAFETY: active_fiber is a plain pointer field; `closing` reads the fiber's own bytes.
    unsafe {
        let fiber = (*tsrm::executor_globals()).active_fiber;
        !fiber.is_null() && closing(fiber)
    }
}

/// Takes the reference that keeps the fiber object alive while it is parked here.
///
/// # Safety
/// `fiber` must be a live fiber object on this thread.
#[cfg(feature = "universal-park")]
unsafe fn hold(fiber: *mut sys::zend_fiber) {
    // SAFETY: GC_ADDREF on an object this thread owns; released by `release` on the loop's side.
    unsafe { (*fiber).std.gc.refcount += 1 };
}

/// Drops the reference `hold` took, once the fiber is no longer running on the stack a free could take away.
///
/// # Safety
/// On the loop's side, after `zend_fiber_resume` / `zend_fiber_resume_exception` returned.
unsafe fn release(fiber: *mut sys::zend_fiber) {
    // SAFETY: zval_ptr_dtor drops one reference through the engine's own destroy and free handlers.
    unsafe {
        let mut zv: sys::zval = std::mem::zeroed();
        zv.value.obj = &raw mut (*fiber).std;
        zv.u1.type_info = sys::IGNIS_IS_OBJECT_EX;
        sys::zval_ptr_dtor(&mut zv);
    }
}

/// Parks the running fiber until op `id` completes.
///
/// # Safety
/// PHP thread, inside an internal call on the current fiber's stack.
#[cfg(feature = "universal-park")]
pub(crate) unsafe fn await_op(id: u64) -> Parked<Outcome> {
    // SAFETY: the caller upholds `# Safety` above -- PHP thread, internal call, current fiber's
    // stack. `fiber` is checked non-null and switching unblocked before it is suspended, and the
    // zval handed to zend_fiber_suspend is zeroed storage this frame owns.
    unsafe {
        let fiber = (*tsrm::executor_globals()).active_fiber;
        if fiber.is_null() || sys::zend_fiber_switch_blocked() {
            return Parked::Unavailable;
        }
        if closing(fiber) {
            return Parked::Cancelled;
        }
        hold(fiber);
        PARKED.with(|p| p.borrow_mut().insert(id, fiber));
        arm_park_timeout(id);
        let mut ret: sys::zval = std::mem::zeroed();
        sys::zend_fiber_suspend(fiber, ptr::null_mut(), &mut ret);
        sys::zval_ptr_dtor(&mut ret);
        disarm_park_timeout(id);
        if !(*tsrm::executor_globals()).exception.is_null() {
            PARKED.with(|p| p.borrow_mut().remove(&id));
            RESULTS.with(|r| r.borrow_mut().remove(&id));
            return Parked::Cancelled;
        }
        match RESULTS.with(|r| r.borrow_mut().remove(&id)) {
            Some(outcome) => Parked::Done(outcome),
            None => Parked::Cancelled,
        }
    }
}

/// Park the running fiber until ANY of `ids` completes; returns the id that did. The other ids'
/// completions are dropped later (no fiber waits on them any more).
///
/// # Safety
/// PHP thread, inside an internal call on the current fiber's stack.
#[cfg(feature = "universal-park")]
pub(crate) unsafe fn await_any(ids: &[u64]) -> Parked<(u64, Outcome)> {
    // SAFETY: as `await_op` -- the caller upholds `# Safety` above, and the same non-null and
    // switch-blocked checks guard the suspension.
    unsafe {
        let fiber = (*tsrm::executor_globals()).active_fiber;
        if fiber.is_null() || sys::zend_fiber_switch_blocked() || ids.is_empty() {
            return Parked::Unavailable;
        }
        if closing(fiber) {
            return Parked::Cancelled;
        }
        hold(fiber);
        PARKED.with(|p| {
            let mut p = p.borrow_mut();
            for id in ids {
                p.insert(*id, fiber);
            }
        });
        arm_park_timeout(ids[0]);
        let mut ret: sys::zval = std::mem::zeroed();
        sys::zend_fiber_suspend(fiber, ptr::null_mut(), &mut ret);
        sys::zval_ptr_dtor(&mut ret);
        disarm_park_timeout(ids[0]);
        // Whichever id resumed us left its outcome in RESULTS; unpark the rest.
        PARKED.with(|p| {
            let mut p = p.borrow_mut();
            for id in ids {
                p.remove(id);
            }
        });
        if !(*tsrm::executor_globals()).exception.is_null() {
            RESULTS.with(|r| {
                let mut r = r.borrow_mut();
                for id in ids {
                    r.remove(id);
                }
            });
            return Parked::Cancelled;
        }
        RESULTS.with(|r| {
            let mut r = r.borrow_mut();
            for id in ids {
                if let Some(o) = r.remove(id) {
                    return Parked::Done((*id, o));
                }
            }
            Parked::Cancelled
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
    // SAFETY: inside ignis_poll; the fiber comes out of PARKED, which only this thread writes while
    // the fiber is suspended, and the remove() makes the resume happen exactly once.
    unsafe {
        let Some(fiber) = PARKED.with(|p| p.borrow_mut().remove(&id)) else { return false };
        if (*fiber).context.status != sys::ZEND_FIBER_STATUS_SUSPENDED {
            tracing::error!(id, "a completion arrived for a fiber that is not suspended; the resume is refused");
            release(fiber);
            return true;
        }
        RESULTS.with(|r| r.borrow_mut().insert(id, outcome));
        let mut ret: sys::zval = std::mem::zeroed();
        sys::zend_fiber_resume(fiber, ptr::null_mut(), &mut ret);
        sys::zval_ptr_dtor(&mut ret);
        release(fiber);
        true
    }
}

#[cfg(feature = "universal-park")]
/// Bounds the park on `id` with a reactor timer when `park_timeout_ms` is set.
fn arm_park_timeout(id: u64) {
    let milliseconds = crate::recovery::Settings::global().park_timeout_ms;
    if milliseconds == 0 {
        return;
    }
    let Some(reactor) = super::module::try_reactor() else { return };
    let timer = reactor.submit(Op::Sleep { us: milliseconds.saturating_mul(1000) });
    PARK_TIMERS.with(|t| t.borrow_mut().insert(timer, id));
    TIMER_OF.with(|t| t.borrow_mut().insert(id, timer));
}

#[cfg(feature = "universal-park")]
/// Calls the park's timer off once the park ended on its own.
fn disarm_park_timeout(id: u64) {
    let Some(timer) = TIMER_OF.with(|t| t.borrow_mut().remove(&id)) else { return };
    PARK_TIMERS.with(|t| t.borrow_mut().remove(&timer));
    if let Some(reactor) = super::module::try_reactor() {
        reactor.submit(Op::CancelWatch { target: timer });
    }
}

/// `ignis_poll`'s first question about a `Slept` completion: was it a park's timer? True when it
/// was, and the fiber still parked on that op has been resumed with `Ignis\DeadlineExceededException`.
///
/// # Safety
/// Inside `ignis_poll`, on the PHP thread, with a valid resumer frame.
pub unsafe fn expire_park_timer(timer: u64) -> bool {
    let Some(id) = PARK_TIMERS.with(|t| t.borrow_mut().remove(&timer)) else { return false };
    TIMER_OF.with(|t| t.borrow_mut().remove(&id));
    let Some(fiber) = PARKED.with(|p| p.borrow_mut().remove(&id)) else { return true };
    // SAFETY: as `resume_parked`; the exception object is ours until the resume copied it.
    unsafe {
        if (*fiber).context.status != sys::ZEND_FIBER_STATUS_SUSPENDED {
            release(fiber);
            return true;
        }
        let mut exception = park_timeout_exception(
            crate::recovery::Settings::global().park_timeout_ms,
            super::fibermeta::suspension_point((*fiber).execute_data),
        );
        let mut ret: sys::zval = std::mem::zeroed();
        sys::zend_fiber_resume_exception(fiber, &mut exception, &mut ret);
        sys::zval_ptr_dtor(&mut ret);
        sys::zval_ptr_dtor(&mut exception);
        release(fiber);
    }
    true
}

/// A new `Ignis\DeadlineExceededException` (or `\Error` without the runtime package) naming the ceiling and the park site.
///
/// # Safety
/// PHP thread at an opcode boundary; the returned zval owns one reference the caller drops.
unsafe fn park_timeout_exception(milliseconds: u64, parked_at: Option<String>) -> sys::zval {
    let text = match parked_at {
        Some(site) => format!("park timeout after {milliseconds} ms, parked at {site}"),
        None => format!("park timeout after {milliseconds} ms"),
    };
    // SAFETY: object_init_ex fills the zval we own; the message is copied into the object.
    unsafe {
        let mut zv: sys::zval = std::mem::zeroed();
        sys::object_init_ex(&mut zv, super::kill::runtime_class_or_error(c"Ignis\\DeadlineExceededException"));
        let message = std::ffi::CString::new(text).unwrap_or_default();
        sys::zend_update_property_string(sys::zend_ce_exception, zv.value.obj, c"message".as_ptr(), 7, message.as_ptr());
        zv
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
        if sys::zend_parse_parameters(super::zval::num_args(ex), c"Oo".as_ptr(), &mut zfiber, sys::zend_ce_fiber, &mut exc) != sys::SUCCESS
        {
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
        release(fiber);
        super::zval::set_bool(rv, true);
    }
}
