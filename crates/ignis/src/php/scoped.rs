//! ADR-0042 (S-SCOPED-CLASS): objects whose declared properties live per fiber, not in the object.
//!
//! A singleton-shaped service that holds per-request state is the defect V-96 measured. The fix is
//! not a proxy: the object stays one object, and its *properties* resolve per scope. Binding happens
//! at creation rather than on the class entry, so one class can have scoped and plain instances at
//! once and there is no inheritance rule to enforce.
//!
//! **The mechanism is that there is almost no mechanism.** A scoped object keeps an ordinary
//! properties table holding the current scope's values, and **every standard handler is left
//! alone** — visibility, typed properties, `readonly`, private-name mangling, `__get`/`__set`,
//! `get_properties`, `clone`, references and the VM's own fast path all work because the engine is
//! doing them and we are not. What this module does is move that table between scopes when the
//! fiber switches, the way `superglobals.rs` moves `$_SERVER` and friends. ADR-0037 calls this the
//! `context` mechanism and describes it exactly: "a slot swap is pointer moves".
//!
//! **Why the swap cannot be lazy**, which is the one thing to know before changing this:
//! `ZEND_ASSIGN_OBJ` writes straight to `OBJ_PROP` when the slot holds a value
//! (`Zend/zend_vm_def.h:2502`, mirrored for reads at `:2111`), so nothing of ours is guaranteed to
//! run between a scope change and the next access. The table must already be right when the fiber
//! resumes, which is why this costs at the switch and not at the access.
//!
//! The alternative — keeping the slots `IS_UNDEF` so custom handlers always run — was built,
//! measured and deleted on 2026-09-20. It cost **7.7×** a plain property against this variant's
//! **1.1×** (V-97), and every path exercised found another piece of engine semantics it did not
//! have: no type verification, no `readonly`, no visibility, no mangling, no magic-method fallback,
//! no declared defaults. Its failures were silent — a `readonly string` read back as `''`.
//! Reinstating it means reinstating all of that.

use std::cell::RefCell;
use std::collections::HashMap;
use std::sync::OnceLock;

use super::tsrm;
use ignis_sys as sys;

/// The scope a constructor's writes are sealed into, and the one every other scope inherits from
/// until it writes. Enforced by `seal()` rather than remembered, so "what the constructor stores is
/// process-wide, what a method reads is per-scope" holds wherever the service was built.
const ROW_ZERO: usize = 0;

static HANDLERS: OnceLock<Handlers> = OnceLock::new();

/// `zend_object_handlers` is only ever read by the engine through a `*const`, and this one lives for
/// the process, so sharing it across threads is sound even though the raw struct is not `Sync`.
struct Handlers(sys::zend_object_handlers);
// SAFETY: written once under `OnceLock` before any scoped object exists and never mutated after,
// and the engine only reads it. There is no interior mutability and no thread-affine pointer in it.
unsafe impl Sync for Handlers {}
// SAFETY: as above -- it is immutable shared data for the life of the process.
unsafe impl Send for Handlers {}

thread_local! {
    /// Live scoped objects by handle. `free_obj` drops entries, and that is the only handler this
    /// module overrides: `zend_object` handles are **reused**, so without it a new object would
    /// inherit a dead one's tables.
    static LIVE: RefCell<HashMap<u32, *mut sys::zend_object>> = RefCell::new(HashMap::new());

    /// `scope -> (object handle -> that scope's copy of the declared slots)`.
    ///
    /// Scope first, not object first, and that is a decision rather than a detail. Upstream's
    /// per-coroutine store is "engine-owned per-coroutine storage under process-unique numeric
    /// keys" (`true-async/php-src@14af3cb2`, `Zend/zend_async_API.h:473`), so a scope-keyed outer
    /// map is **one** `internal_context` entry under one `zend_async_internal_context_key_alloc`
    /// when backend (b) becomes a real target — the whole port rather than a rewrite. It is also
    /// the better shape today: dropping a scope is one removal instead of a scan of every key, and
    /// that scan sat on the fiber-switch path, which is the one cost this design pays.
    static TABLES: RefCell<HashMap<usize, HashMap<u32, Vec<sys::zval>>>> = RefCell::new(HashMap::new());
}

/// The current scope: this fiber, or `ROW_ZERO` for `{main}`. The same key `Ignis\Scope` uses in PHP
/// and `output.rs` uses for its sinks, so a request's tables die with the same boundary its bag does.
///
/// # Safety
/// Must be called on a PHP thread with an initialised TSRM cache.
unsafe fn current_scope() -> usize {
    // SAFETY: the caller upholds `# Safety`. `active_fiber` is a plain pointer field the engine
    // keeps current; `&raw mut` takes the address of its `context` field without forming a
    // reference, and the result is used only as a key.
    unsafe {
        let fiber = (*tsrm::executor_globals()).active_fiber;
        if fiber.is_null() {
            return ROW_ZERO;
        }
        (&raw mut (*fiber).context) as usize
    }
}

/// The scope key a fiber context stands for, `ROW_ZERO` for `{main}`.
///
/// The context pointer *is* the key for a real fiber: `zend_fiber` embeds its `zend_fiber_context`
/// by value, so the address the observer is handed is the address `current_scope()` takes of that
/// field. `{main}` is the exception and must be forced to `ROW_ZERO`, because `current_scope()`
/// reaches it through a **null** `active_fiber` while the observer is handed
/// `EG(main_fiber_context)`, a real pointer. Two keys for one scope meant the constructor's values
/// were saved under an address nothing ever looked up.
///
/// # Safety
/// PHP thread with an initialised TSRM cache.
unsafe fn scope_of(context: *mut sys::zend_fiber_context) -> usize {
    if context.is_null() {
        return ROW_ZERO;
    }
    // SAFETY: the caller upholds `# Safety`; `main_fiber_context` is a plain pointer field.
    if context == unsafe { (*tsrm::executor_globals()).main_fiber_context } {
        return ROW_ZERO;
    }
    context as usize
}

/// Builds the handler table once: the standard one, with `free_obj` replaced.
///
/// # Safety
/// MINIT on a PHP thread, before any scoped object exists.
pub unsafe fn install() {
    HANDLERS.get_or_init(|| {
        // SAFETY: MINIT; `std_object_handlers` is engine-owned immutable data, fully initialised by
        // the time any module's MINIT runs, and this copies it rather than aliasing it.
        let mut handlers = unsafe { sys::std_object_handlers };
        handlers.free_obj = Some(free_obj);
        Handlers(handlers)
    });
}

/// An instance of `class` whose declared properties resolve per scope. The constructor is **not**
/// run here: `Ignis\Scope::create()` runs it next and then seals what it wrote into row zero.
///
/// # Safety
/// PHP thread with an initialised TSRM cache, after `install()`.
pub unsafe fn allocate(class: &str) -> Result<*mut sys::zend_object, String> {
    // SAFETY: the caller upholds `# Safety`. `zval::string_zval` owns the name for the length of
    // this call and is released on both paths; `zend_lookup_class` runs the autoloader, which
    // matters because the container names a class as a string and need not have loaded it yet, and
    // returns null when it cannot be found.
    unsafe {
        let Some(handlers) = HANDLERS.get() else {
            return Err("ignis: scoped objects are not installed in this build".into());
        };
        let mut name = super::zval::string_zval(class.as_bytes());
        let class_entry = sys::zend_lookup_class(name.value.str_);
        sys::zval_ptr_dtor(&raw mut name);
        if class_entry.is_null() {
            return Err(format!("ignis: class \"{class}\" not found"));
        }

        let object = sys::zend_objects_new(class_entry);
        sys::object_properties_init(object, class_entry);
        (*object).handlers = &raw const handlers.0;
        LIVE.with(|live| live.borrow_mut().insert((*object).handle, object));
        Ok(object)
    }
}

/// Promotes what the constructor just wrote into row zero, whatever scope it ran in.
///
/// Without this, row zero would only ever be filled by a switch away from `{main}`, so a service the
/// container builds lazily — inside a request, which is Symfony's normal path — would trap its
/// constructor's values in that one request's scope. Measured before it existed: fiber A creates the
/// service and fiber B reads a `readonly string` as `''`.
///
/// # Safety
/// PHP thread with an initialised TSRM cache; `object` is a live scoped object whose constructor has
/// just returned.
pub unsafe fn seal(object: *mut sys::zend_object) {
    // SAFETY: the caller upholds `# Safety`. Row zero takes its own reference to every value it
    // keeps, and whatever it held before is released with no borrow outstanding, because a release
    // can run a destructor that re-enters this module.
    unsafe {
        let handle = (*object).handle;
        let count = (*(*object).ce).default_properties_count as usize;
        let slots = (&raw mut (*object).properties_table) as *mut sys::zval;
        let mut kept: Vec<sys::zval> = Vec::with_capacity(count);
        for index in 0..count {
            let mut value = *slots.add(index);
            sys::zval_add_ref(&raw mut value);
            kept.push(value);
        }
        let displaced = TABLES.with(|tables| tables.borrow_mut().entry(ROW_ZERO).or_default().insert(handle, kept));
        for mut value in displaced.unwrap_or_default() {
            sys::zval_ptr_dtor(&raw mut value);
        }
    }
}

/// Moves every live scoped object's declared slots from the outgoing scope's copy to the incoming
/// one.
///
/// # Safety
/// Called from the fiber-switch observer on a PHP thread, with both contexts live.
pub unsafe fn on_switch(from: *mut sys::zend_fiber_context, to: *mut sys::zend_fiber_context) {
    // SAFETY: the caller upholds `# Safety`. Values moved between two places this module owns are
    // moves, not new references, so no refcount changes; the one place a value is *copied* -- a
    // scope inheriting row zero -- takes its own reference.
    unsafe {
        let (leaving, entering) = (scope_of(from), scope_of(to));
        if leaving == entering {
            return;
        }
        let objects: Vec<*mut sys::zend_object> = LIVE.with(|live| live.borrow().values().copied().collect());
        for object in objects {
            let handle = (*object).handle;
            let count = (*(*object).ce).default_properties_count as usize;
            let slots = (&raw mut (*object).properties_table) as *mut sys::zval;

            let outgoing: Vec<sys::zval> = (0..count).map(|index| *slots.add(index)).collect();
            TABLES.with(|tables| tables.borrow_mut().entry(leaving).or_default().insert(handle, outgoing));

            let incoming = TABLES.with(|tables| tables.borrow_mut().get_mut(&entering).and_then(|objects| objects.remove(&handle)));
            match incoming {
                Some(values) => {
                    for (index, value) in values.into_iter().enumerate() {
                        std::ptr::write(slots.add(index), value);
                    }
                }
                None => {
                    let defaults = TABLES.with(|tables| tables.borrow().get(&ROW_ZERO).and_then(|objects| objects.get(&handle)).cloned());
                    for index in 0..count {
                        let mut value = defaults.as_ref().map_or_else(|| std::mem::zeroed(), |values| values[index]);
                        sys::zval_add_ref(&raw mut value);
                        std::ptr::write(slots.add(index), value);
                    }
                }
            }
        }
    }
}

/// Drops the current scope's tables. Called at request end through `Ignis\Scope::clear()`, for the
/// same reason the key-value bag is cleared there: a pooled fiber's next request must not read the
/// previous one's values. Row zero is never dropped — it outlives every request.
///
/// # Safety
/// PHP thread with an initialised TSRM cache.
pub unsafe fn rows_clear() {
    // SAFETY: the caller upholds `# Safety`. Every value in a table holds its own reference, so each
    // is released exactly once, with the borrow already dropped.
    unsafe {
        let scope = current_scope();
        if scope == ROW_ZERO {
            return;
        }
        // Dropping the stored table is not enough, and measuring a **pooled fiber serving two
        // sequential requests** is what showed it: the object's own slots still hold the dying
        // request's values, and the next `on_switch` out of this fiber saves them straight back
        // under the same key, so the next request on this fiber read the previous one's state.
        // Every arm until then used concurrent requests on different fibers, where the path never
        // arises. So the slots are returned to row zero here, which is what the next request would
        // have inherited had this fiber never run.
        let objects: Vec<*mut sys::zend_object> = LIVE.with(|live| live.borrow().values().copied().collect());
        for object in objects {
            let handle = (*object).handle;
            let count = (*(*object).ce).default_properties_count as usize;
            let slots = (&raw mut (*object).properties_table) as *mut sys::zval;
            let defaults = TABLES.with(|tables| tables.borrow().get(&ROW_ZERO).and_then(|objects| objects.get(&handle)).cloned());
            for index in 0..count {
                let mut value = defaults.as_ref().map_or_else(|| std::mem::zeroed(), |values| values[index]);
                sys::zval_add_ref(&raw mut value);
                let previous = std::ptr::replace(slots.add(index), value);
                let mut previous = previous;
                sys::zval_ptr_dtor(&raw mut previous);
            }
        }
        let dropped = TABLES.with(|tables| tables.borrow_mut().remove(&scope));
        for table in dropped.map(|objects| objects.into_values().collect::<Vec<Vec<sys::zval>>>()).unwrap_or_default() {
            for mut value in table {
                sys::zval_ptr_dtor(&raw mut value);
            }
        }
    }
}

/// Drops everything this module holds for a dying object, then hands over to the standard free.
///
/// # Safety
/// Called by the engine as the object is freed.
unsafe extern "C" fn free_obj(object: *mut sys::zend_object) {
    // SAFETY: the engine upholds `# Safety`. Borrows are dropped before any release, because a
    // release can run PHP code that re-enters this module.
    unsafe {
        let handle = (*object).handle;
        LIVE.with(|live| live.borrow_mut().remove(&handle));
        let tables = TABLES
            .with(|tables| tables.borrow_mut().values_mut().filter_map(|objects| objects.remove(&handle)).collect::<Vec<Vec<sys::zval>>>());
        for table in tables {
            for mut value in table {
                sys::zval_ptr_dtor(&raw mut value);
            }
        }
        if let Some(free) = sys::std_object_handlers.free_obj {
            free(object);
        }
    }
}

#[cfg(test)]
mod tests {
    use super::*;

    /// A stored long, read back. The union access is the only unsafe part of these tests, so it
    /// lives here once rather than at every assertion.
    fn long_of(value: Option<sys::zval>) -> Option<i64> {
        // SAFETY: every zval these tests store was written by `set_long`, so `lval` is the live arm.
        value.map(|value| unsafe { value.value.lval })
    }

    fn long_zval(value: i64) -> sys::zval {
        // SAFETY: a zval is a union of integers and pointers with no niche, so all-zero is the valid
        // IS_UNDEF representation, and this holds no refcounted value.
        let mut zval: sys::zval = unsafe { std::mem::zeroed() };
        // SAFETY: this function's own stack storage, holding no refcounted value.
        unsafe { super::super::zval::set_long(&mut zval, value) };
        zval
    }

    /// Row zero is what a constructor's values are sealed into, and a scope that has not touched an
    /// object inherits from it. Without this a scoped service lost every injected dependency the
    /// moment a fiber touched it.
    #[test]
    fn a_scope_without_a_table_inherits_row_zero() {
        TABLES.with(|tables| tables.borrow_mut().clear());
        TABLES.with(|tables| tables.borrow_mut().entry(ROW_ZERO).or_default().insert(1, vec![long_zval(10)]));

        let inherited = TABLES.with(|tables| {
            let tables = tables.borrow();
            tables
                .get(&555)
                .and_then(|objects| objects.get(&1))
                .or_else(|| tables.get(&ROW_ZERO).and_then(|objects| objects.get(&1)))
                .map(|table| table[0])
        });
        assert_eq!(long_of(inherited), Some(10), "an untouched scope sees the constructor's value");

        TABLES.with(|tables| tables.borrow_mut().entry(555).or_default().insert(1, vec![long_zval(20)]));
        let own = TABLES.with(|tables| tables.borrow().get(&555).and_then(|objects| objects.get(&1)).map(|table| table[0]));
        assert_eq!(long_of(own), Some(20), "its own table shadows row zero");
        let row_zero = TABLES.with(|tables| tables.borrow().get(&ROW_ZERO).and_then(|objects| objects.get(&1)).map(|table| table[0]));
        assert_eq!(long_of(row_zero), Some(10), "and row zero is untouched by it");
    }

    /// One object, two scopes, two tables: the isolation this module exists for, on the pure part.
    #[test]
    fn two_scopes_of_one_object_do_not_share_a_table() {
        TABLES.with(|tables| {
            let mut tables = tables.borrow_mut();
            tables.clear();
            tables.entry(100).or_default().insert(7, vec![long_zval(1)]);
            tables.entry(200).or_default().insert(7, vec![long_zval(2)]);
            assert_eq!(long_of(Some(tables[&100][&7][0])), Some(1));
            assert_eq!(long_of(Some(tables[&200][&7][0])), Some(2), "the second scope has its own table");
            assert_eq!(tables.len(), 2, "one object, two scopes, two tables");
        });
    }

    /// `{main}` must have one key, not two: `current_scope()` answers `ROW_ZERO` through a null
    /// `active_fiber`, and `scope_of` has to agree for a null context.
    #[test]
    fn a_null_context_is_row_zero() {
        // SAFETY: a null context takes the early return and touches no engine state.
        assert_eq!(unsafe { scope_of(std::ptr::null_mut()) }, ROW_ZERO);
    }
}
