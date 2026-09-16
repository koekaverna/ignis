//! Fiber-scoped superglobals (ADR-0006): `$_SERVER`, `$_GET`, `$_POST`,
//! `$_COOKIE` are saved/restored per `zend_fiber_context` by the Zend fiber
//! switch observer, so interleaved requests on one thread never see each
//! other's globals.
//!
//! FFI contract:
//! - Handlers run on the switching PHP thread, before the stack switch, with
//!   EG fully valid (`zend_observer_fiber_switch_notify`, zend_fibers.c:496).
//! - Saved zvals hold their own reference (`zval_add_ref`); they are released
//!   with `zval_ptr_dtor` on fiber destroy or when overwritten. The map key is
//!   the context pointer, which is stable for the fiber's lifetime and removed
//!   in the destroy hook, so no dangling key outlives its fiber.
//! - `zend_hash_str_update` takes ownership of one reference of the stored
//!   zval and releases the previous entry (Zend/zend_hash.c).
use std::cell::Cell;
use std::ffi::c_char;

use ignis_sys as sys;

use super::zval;

const KEYS: [&[u8]; 4] = [b"_SERVER", b"_GET", b"_POST", b"_COOKIE"];

/// Index into `zend_fiber_context.reserved[]` allocated once per process at
/// MINIT via `zend_get_resource_handle` (the documented extension slot; the
/// observer/tracing extensions use the same mechanism). Each context keeps a
/// heap-allocated `[zval; 4]` there; O(1), no hashing, freed in the destroy hook.
static SLOT: std::sync::OnceLock<usize> = std::sync::OnceLock::new();

thread_local! {
    /// Counts switches (for the bench output only).
    pub static SWITCHES: Cell<u64> = const { Cell::new(0) };
    /// E13': the superglobals of the "unisolated" world ({main} and every fiber that never called
    /// `ignis_set_superglobals`). Saved when leaving that world for an isolated fiber, restored on
    /// the way back. Switches between two unisolated fibers cost nothing.
    static BASE: std::cell::RefCell<Option<[sys::zval; 4]>> = const { std::cell::RefCell::new(None) };
    /// Whose view the symbol table currently holds: null = the base world, else the isolated
    /// fiber context that installed its entries. Children started from an isolated fiber inherit
    /// its view without becoming owners (they have no slot).
    static VIEW: Cell<*mut sys::zend_fiber_context> = const { Cell::new(std::ptr::null_mut()) };
}

unsafe fn eg_ptr() -> *mut sys::zend_executor_globals {
    unsafe { (sys::tsrm_get_ls_cache() as *mut u8).add(sys::executor_globals_offset) as *mut sys::zend_executor_globals }
}

/// Save the currently installed view where it belongs (the owner's slot, or BASE).
unsafe fn save_current_view() {
    unsafe {
        let owner = VIEW.with(|v| v.get());
        let snap = snapshot();
        if owner.is_null() {
            BASE.with(|b| {
                let mut b = b.borrow_mut();
                if let Some(old) = b.as_mut() {
                    release(old);
                }
                *b = Some(snap);
            });
        } else {
            let slot = slot_of(owner);
            if (*slot).is_null() {
                *slot = Box::into_raw(Box::new(snap));
            } else {
                release(&mut **slot);
                **slot = snap;
            }
        }
    }
}

/// Mark the running fiber as isolated: it gets its own slot (E13'). Called by `ignis_set_superglobals`.
unsafe fn isolate_current() {
    unsafe {
        let base = sys::tsrm_get_ls_cache() as *mut u8;
        let eg = base.add(sys::executor_globals_offset) as *mut sys::zend_executor_globals;
        let fiber = (*eg).active_fiber;
        if fiber.is_null() {
            return; // {main}: the base world itself
        }
        let ctx = &raw mut (*fiber).context;
        let slot = slot_of(ctx);
        if (*slot).is_null() {
            // Whatever is installed right now belongs to the current view's owner (the base world,
            // or an isolated ancestor): save it there, then this fiber takes over as owner.
            save_current_view();
            *slot = Box::into_raw(Box::new(undef4()));
            VIEW.with(|v| v.set(ctx));
        }
    }
}

unsafe fn undef4() -> [sys::zval; 4] {
    unsafe { [undef(), undef(), undef(), undef()] }
}

unsafe fn slot_of(ctx: *mut sys::zend_fiber_context) -> *mut *mut [sys::zval; 4] {
    // SAFETY: `reserved` is a fixed array of ZEND_MAX_RESERVED_RESOURCES void*
    // and SLOT is < that (checked at MINIT).
    unsafe { (&raw mut (*ctx).reserved[*SLOT.get().unwrap_unchecked()]) as *mut *mut [sys::zval; 4] }
}

/// `&EG(symbol_table)` for the calling thread.
///
/// # Safety
/// PHP thread after startup.
unsafe fn symbol_table() -> *mut sys::HashTable {
    unsafe {
        let base = sys::tsrm_get_ls_cache() as *mut u8;
        let eg = base.add(sys::executor_globals_offset) as *mut sys::zend_executor_globals;
        &raw mut (*eg).symbol_table
    }
}

unsafe fn undef() -> sys::zval {
    // SAFETY: an all-zero zval is IS_UNDEF, which every Zend API treats as "no value".
    unsafe { std::mem::zeroed() }
}

/// Copies the four current entries (addref'd) out of the symbol table.
unsafe fn snapshot() -> [sys::zval; 4] {
    unsafe {
        let st = symbol_table();
        let mut out = [undef(), undef(), undef(), undef()];
        for (i, k) in KEYS.iter().enumerate() {
            let p = sys::zend_hash_str_find(st, k.as_ptr() as *const c_char, k.len());
            if !p.is_null() {
                out[i] = *p;
                sys::zval_add_ref(&mut out[i]);
            }
        }
        out
    }
}

/// Installs `vals` into the symbol table (each entry addref'd for the table;
/// UNDEF entries leave the current value in place).
unsafe fn install(vals: &[sys::zval; 4]) {
    unsafe {
        let st = symbol_table();
        for (i, k) in KEYS.iter().enumerate() {
            if zval::type_of(&vals[i]) == sys::IS_UNDEF {
                continue;
            }
            let mut copy = vals[i];
            sys::zval_add_ref(&mut copy);
            sys::zend_hash_str_update(st, k.as_ptr() as *const c_char, k.len(), &mut copy);
        }
    }
}

unsafe fn release(vals: &mut [sys::zval; 4]) {
    unsafe {
        for v in vals.iter_mut() {
            if zval::type_of(v) != sys::IS_UNDEF {
                sys::zval_ptr_dtor(v);
                *v = undef();
            }
        }
    }
}

unsafe extern "C" fn on_switch(from: *mut sys::zend_fiber_context, to: *mut sys::zend_fiber_context) {
    // SAFETY: see module docs. `from` is the running context, `to` the target;
    // both are live for the duration of the call. The boxes are owned by the
    // contexts' reserved slots and freed in on_destroy.
    unsafe {
        SWITCHES.with(|c| c.set(c.get() + 1));
        let _ = from;
        let to_slot = slot_of(to);
        let to_isolated = !(*to_slot).is_null();
        let to_main = to == (*eg_ptr()).main_fiber_context;
        let owner = VIEW.with(|v| v.get());
        if to_isolated {
            if owner == to {
                return; // its view is already installed (a child returned to it)
            }
            save_current_view();
            install(&**to_slot);
            VIEW.with(|v| v.set(to));
        } else if to_main {
            if owner.is_null() {
                return; // base world → base world
            }
            save_current_view();
            BASE.with(|b| {
                if let Some(base) = b.borrow().as_ref() {
                    install(base);
                }
            });
            VIEW.with(|v| v.set(std::ptr::null_mut()));
        }
        // else: a fiber without a slot (pool fiber, or a child of an isolated fiber): it inherits
        // whatever view is installed — nothing to swap (E13' lazy swap).
    }
}

unsafe extern "C" fn on_destroy(ctx: *mut sys::zend_fiber_context) {
    // SAFETY: called from zend_fiber_destroy_context on the owning thread;
    // the box was created by on_switch and is not referenced anywhere else.
    unsafe {
        let slot = slot_of(ctx);
        if !(*slot).is_null() {
            let mut b = Box::from_raw(*slot);
            release(&mut b);
            *slot = std::ptr::null_mut();
        }
        // A dying owner: the installed view has no owner any more; the next switch to main restores BASE.
        VIEW.with(|v| {
            if v.get() == ctx {
                v.set(std::ptr::null_mut());
            }
        });
    }
}

/// Registered as the module's MINIT: installs the observers unless
/// `IGNIS_NO_SUPERGLOBALS` is set (used to measure the swap cost).
pub unsafe extern "C" fn minit(_type: std::ffi::c_int, _module_number: std::ffi::c_int) -> sys::zend_result {
    // SAFETY: MINIT on the main thread (ADR-0007 transport hook).
    unsafe {
        super::stream::install();
        super::accept::install();
        super::sockets::install();
        #[cfg(feature = "universal-park")]
        super::park::install(); // E18 (ADR-0020)
        super::route::install();
        super::embed::fix_php_binary(_module_number);
    }
    if std::env::var_os("IGNIS_NO_SUPERGLOBALS").is_none() {
        // SAFETY: zend_observer_startup() ran in php_module_startup before MINIT;
        // zend_get_resource_handle hands out one of ZEND_MAX_RESERVED_RESOURCES
        // per-context slots (or -1 when exhausted).
        unsafe {
            let h = sys::zend_get_resource_handle(c"ignis".as_ptr());
            if h < 0 {
                tracing::error!("no free zend_fiber_context reserved slot; superglobal isolation disabled");
                return sys::SUCCESS;
            }
            let _ = SLOT.set(h as usize);
            sys::zend_observer_fiber_switch_register(Some(on_switch));
            sys::zend_observer_fiber_destroy_register(Some(on_destroy));
        }
    }
    sys::SUCCESS
}

/// `ignis_set_superglobals(array $server, array $get, array $post, array $cookie): void`
/// — installs the four arrays for the *current* fiber (or {main}).
pub unsafe extern "C" fn zif_ignis_set_superglobals(ex: *mut sys::zend_execute_data, rv: *mut sys::zval) {
    // SAFETY: VM frame on the PHP thread; the four HashTables are VM-owned and
    // we store an addref'd zval pointing at each.
    unsafe {
        // Parse as array zvals and copy the zval bits verbatim: their type flags
        // already say whether the array is refcounted. Forging IS_ARRAY_EX on an
        // immutable literal (`[]` is the process-shared zend_empty_array) made
        // several threads bump a shared refcount and corrupted the heap (V-15).
        let (mut z0, mut z1, mut z2, mut z3): (*mut sys::zval, *mut sys::zval, *mut sys::zval, *mut sys::zval) =
            (std::ptr::null_mut(), std::ptr::null_mut(), std::ptr::null_mut(), std::ptr::null_mut());
        if sys::zend_parse_parameters(zval::num_args(ex), c"aaaa".as_ptr(), &mut z0, &mut z1, &mut z2, &mut z3) != sys::SUCCESS {
            return;
        }
        let vals = [*z0, *z1, *z2, *z3];
        isolate_current(); // captures the base world first (E13')
        install(&vals);
        zval::set_null(rv);
    }
}
