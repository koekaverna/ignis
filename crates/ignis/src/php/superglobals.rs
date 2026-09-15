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
        let snap = snapshot();
        let from_slot = slot_of(from);
        if (*from_slot).is_null() {
            *from_slot = Box::into_raw(Box::new(snap));
        } else {
            release(&mut **from_slot);
            **from_slot = snap;
        }
        let to_slot = slot_of(to);
        if !(*to_slot).is_null() {
            install(&**to_slot);
        }
        // else: first entry into `to` → inherit the resumer's entries (already installed).
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
    }
}

/// Registered as the module's MINIT: installs the observers unless
/// `IGNIS_NO_SUPERGLOBALS` is set (used to measure the swap cost).
pub unsafe extern "C" fn minit(_type: std::ffi::c_int, _module_number: std::ffi::c_int) -> sys::zend_result {
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
        let (mut h0, mut h1, mut h2, mut h3): (*mut sys::HashTable, *mut sys::HashTable, *mut sys::HashTable, *mut sys::HashTable) =
            (std::ptr::null_mut(), std::ptr::null_mut(), std::ptr::null_mut(), std::ptr::null_mut());
        if sys::zend_parse_parameters(zval::num_args(ex), c"hhhh".as_ptr(), &mut h0, &mut h1, &mut h2, &mut h3) != sys::SUCCESS {
            return;
        }
        let hts = [h0, h1, h2, h3];
        let mut vals = [undef(), undef(), undef(), undef()];
        for i in 0..4 {
            vals[i].value.arr = hts[i];
            vals[i].u1.type_info = sys::IGNIS_IS_ARRAY_EX;
            // The VM owns one reference (the argument); install() adds one for
            // the table, so no extra addref here.
        }
        install(&vals);
        zval::set_null(rv);
    }
}
