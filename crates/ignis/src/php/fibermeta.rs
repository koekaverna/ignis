//! ADR-0043: what the runtime knows about a fiber, kept in a reserved slot of its
//! `zend_fiber_context` and read by the interposer through `EG(active_fiber)`.
//!
//! FFI contract: one `Box<FiberMeta>` per context, allocated in the init observer, freed in the
//! destroy observer; nothing here outlives its context.
use std::ffi::c_char;
use std::ptr;
use std::sync::OnceLock;

use ignis_sys as sys;

use super::tsrm;
use super::zval;
use crate::scoreboard;

#[repr(C)]
#[derive(Default)]
pub struct FiberMeta {
    pub request_id: u64,
    /// The loop asked for this fiber to be force-closed: no park of any kind is granted to it.
    pub kill_pending: bool,
    /// `Ignis\allowBlocking()`: blocking calls inside are reported at info, never fail a test.
    pub allow_blocking: bool,
}

static SLOT: OnceLock<usize> = OnceLock::new();

/// The meta of a context, or null before MINIT / for a context created without one.
///
/// # Safety
/// `ctx` must be a live fiber context on this thread.
unsafe fn meta_of(ctx: *mut sys::zend_fiber_context) -> *mut FiberMeta {
    let Some(index) = SLOT.get() else { return ptr::null_mut() };
    if ctx.is_null() {
        return ptr::null_mut();
    }
    // SAFETY: `index` came from zend_get_resource_handle, so it is inside `reserved`.
    unsafe { (*ctx).reserved[*index] as *mut FiberMeta }
}

/// The meta of the running fiber, or null in {main} and before MINIT.
///
/// # Safety
/// PHP thread with a live TSRM context.
pub unsafe fn current() -> *mut FiberMeta {
    // SAFETY: the caller upholds the contract; active_fiber is a plain pointer field.
    unsafe {
        let fiber = (*tsrm::executor_globals()).active_fiber;
        if fiber.is_null() {
            return ptr::null_mut();
        }
        meta_of(&raw mut (*fiber).context)
    }
}

/// The meta of `fiber`, or null.
///
/// # Safety
/// `fiber` must be a live fiber object on this thread.
pub unsafe fn of(fiber: *mut sys::zend_fiber) -> *mut FiberMeta {
    // SAFETY: the caller upholds the contract.
    unsafe { meta_of(&raw mut (*fiber).context) }
}

/// The `zend_fiber` a context belongs to (it is embedded in the object), or null for {main}.
unsafe fn fiber_of_context(ctx: *mut sys::zend_fiber_context) -> *mut sys::zend_fiber {
    // SAFETY: a non-main context is the `context` field of a zend_fiber; the main one is answered null.
    unsafe {
        let main = (*tsrm::executor_globals()).main_fiber_context;
        if ctx.is_null() || ctx == main {
            return ptr::null_mut();
        }
        (ctx as *mut u8).sub(std::mem::offset_of!(sys::_zend_fiber, context)) as *mut sys::zend_fiber
    }
}

unsafe extern "C" fn on_init(ctx: *mut sys::zend_fiber_context) {
    let Some(index) = SLOT.get() else { return };
    // SAFETY: called by zend_fiber_init_context with a live context; the box is released in on_destroy.
    unsafe {
        (*ctx).reserved[*index] = Box::into_raw(Box::new(FiberMeta::default())) as *mut std::ffi::c_void;
    }
}

unsafe extern "C" fn on_destroy(ctx: *mut sys::zend_fiber_context) {
    let Some(index) = SLOT.get() else { return };
    // SAFETY: called by zend_fiber_destroy_context; the pointer is on_init's or null, taken out before freeing.
    unsafe {
        let meta = (*ctx).reserved[*index] as *mut FiberMeta;
        (*ctx).reserved[*index] = ptr::null_mut();
        if !meta.is_null() {
            drop(Box::from_raw(meta));
        }
    }
}

unsafe extern "C" fn on_switch(_from: *mut sys::zend_fiber_context, to: *mut sys::zend_fiber_context) {
    // SAFETY: called by Zend on the switching thread before the jump with live contexts.
    unsafe {
        let fiber = fiber_of_context(to);
        let meta = meta_of(to);
        let request_id = if meta.is_null() { 0 } else { (*meta).request_id };
        scoreboard::set_current_fiber(fiber as usize, request_id);
    }
}

/// MINIT: claims the slot and registers the three observers.
///
/// # Safety
/// MINIT on the main thread, after `zend_observer_startup`.
pub unsafe fn install() {
    // SAFETY: MINIT on the main thread; zend_get_resource_handle answers a slot index or -1.
    unsafe {
        let h = sys::zend_get_resource_handle(c"ignis-fiber-meta".as_ptr());
        if h < 0 {
            tracing::error!("no free zend_fiber_context reserved slot; per-fiber recovery metadata disabled");
            return;
        }
        let _ = SLOT.set(h as usize);
        sys::zend_observer_fiber_init_register(Some(on_init));
        sys::zend_observer_fiber_destroy_register(Some(on_destroy));
        sys::zend_observer_fiber_switch_register(Some(on_switch));
    }
}

/// `ignis_fiber_request(int $id): void` — the request the current fiber serves (0 = none); a new
/// request on a pooled fiber clears the previous one's kill and allow flags.
pub unsafe extern "C" fn zif_ignis_fiber_request(ex: *mut sys::zend_execute_data, _rv: *mut sys::zval) {
    // SAFETY: VM frame on the PHP thread.
    unsafe {
        let Some(id) = zval::arg_long(ex, 1) else {
            sys::zend_type_error(c"ignis_fiber_request(): argument #1 ($id) must be of type int".as_ptr());
            return;
        };
        let meta = current();
        if !meta.is_null() {
            (*meta).request_id = id.max(0) as u64;
            (*meta).kill_pending = false;
            (*meta).allow_blocking = false;
        }
        scoreboard::set_current_request(id.max(0) as u64);
    }
}

/// `ignis_fiber_kill_pending(Fiber $fiber, bool $on): bool` — marks `$fiber` for force-close, so no
/// park is granted to it any more. False when the fiber has no metadata.
pub unsafe extern "C" fn zif_ignis_fiber_kill_pending(ex: *mut sys::zend_execute_data, rv: *mut sys::zval) {
    // SAFETY: VM frame on the PHP thread; the object pointer is VM-owned for the call.
    unsafe {
        let mut zfiber: *mut sys::zval = ptr::null_mut();
        let mut on: bool = false;
        if sys::zend_parse_parameters(zval::num_args(ex), c"Ob".as_ptr(), &mut zfiber, sys::zend_ce_fiber, &mut on) != sys::SUCCESS {
            return;
        }
        let fiber = (*zfiber).value.obj as *mut sys::zend_fiber;
        let meta = of(fiber);
        if meta.is_null() {
            zval::set_bool(rv, false);
            return;
        }
        (*meta).kill_pending = on;
        zval::set_bool(rv, true);
    }
}

/// `ignis_allow_blocking(bool $on): bool` — the previous value for the current fiber.
pub unsafe extern "C" fn zif_ignis_allow_blocking(ex: *mut sys::zend_execute_data, rv: *mut sys::zval) {
    // SAFETY: VM frame on the PHP thread.
    unsafe {
        let mut on: bool = false;
        if sys::zend_parse_parameters(zval::num_args(ex), c"b".as_ptr(), &mut on) != sys::SUCCESS {
            return;
        }
        let meta = current();
        if meta.is_null() {
            zval::set_bool(rv, false);
            return;
        }
        let previous = (*meta).allow_blocking;
        (*meta).allow_blocking = on;
        zval::set_bool(rv, previous);
    }
}

/// `ignis_fiber_where(Fiber $fiber): ?string` — `file:line` of a suspended fiber's suspension point,
/// read from its saved frame; null when it is not suspended or has no user frame.
pub unsafe extern "C" fn zif_ignis_fiber_where(ex: *mut sys::zend_execute_data, rv: *mut sys::zval) {
    // SAFETY: VM frame on the PHP thread; a suspended fiber's saved frames stay allocated until it
    // terminates and are only read here.
    unsafe {
        let mut zfiber: *mut sys::zval = ptr::null_mut();
        if sys::zend_parse_parameters(zval::num_args(ex), c"O".as_ptr(), &mut zfiber, sys::zend_ce_fiber) != sys::SUCCESS {
            return;
        }
        let fiber = (*zfiber).value.obj as *mut sys::zend_fiber;
        if (*fiber).context.status != sys::ZEND_FIBER_STATUS_SUSPENDED {
            zval::set_null(rv);
            return;
        }
        match suspension_point((*fiber).execute_data) {
            Some(text) => *rv = zval::string_zval(text.as_bytes()),
            None => zval::set_null(rv),
        }
    }
}

/// The park site as the application sees it: the first user frame outwards that is not the
/// runtime's own (`Ignis\` namespace), else the innermost user frame, as `file:line`.
pub(super) unsafe fn suspension_point(mut frame: *mut sys::zend_execute_data) -> Option<String> {
    // SAFETY: the caller hands a frame chain of a suspended fiber; every dereference is guarded.
    unsafe {
        let mut innermost = None;
        while !frame.is_null() {
            if let Some(site) = user_site(frame) {
                if !belongs_to_the_runtime((*frame).func) {
                    return Some(site);
                }
                innermost.get_or_insert(site);
            }
            frame = (*frame).prev_execute_data;
        }
        innermost
    }
}

/// `file:line` of a user-code frame; None for an internal function's frame.
unsafe fn user_site(frame: *mut sys::zend_execute_data) -> Option<String> {
    // SAFETY: as `suspension_point`; a user function's `op_array` is the live member of the union.
    unsafe {
        let func = (*frame).func;
        if func.is_null() || (*func).type_ as u32 != sys::ZEND_USER_FUNCTION {
            return None;
        }
        let op_array = &(*func).op_array;
        let opline = (*frame).opline;
        if op_array.filename.is_null() || opline.is_null() {
            return None;
        }
        let file = std::ffi::CStr::from_ptr((*op_array.filename).val.as_ptr() as *const c_char).to_string_lossy();
        Some(format!("{file}:{}", (*opline).lineno))
    }
}

/// Whether a user function is the runtime's (`Ignis\Loop::parkOn`, `Ignis\sleep`, ...) rather than the application's.
unsafe fn belongs_to_the_runtime(func: *const sys::zend_function) -> bool {
    // SAFETY: `func` is a user function (checked by `user_site`), so `op_array` is live and its
    // scope and name are interned strings that outlive this read.
    unsafe {
        let scope = (*func).op_array.scope;
        let name = if scope.is_null() { (*func).op_array.function_name } else { (*scope).name };
        !name.is_null() && zval::zstr_to_string(name).starts_with("Ignis\\")
    }
}
