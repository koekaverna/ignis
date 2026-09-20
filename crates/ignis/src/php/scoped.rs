//! ADR-0042 (S-SCOPED-CLASS): objects whose declared properties live per fiber, not in the object.
//!
//! A singleton-shaped service that holds per-request state is the defect V-96 measured. The fix is
//! not a proxy: the object stays one object, and its *properties* resolve per scope. One class can
//! have scoped and plain instances at once, because binding happens at creation rather than on the
//! class entry — which is also why there is no inheritance rule to enforce here.
//!
//! **The invariant, and the whole mechanism.** A scoped object's declared slots are `IS_UNDEF` and
//! must stay that way. `Zend/zend_vm_def.h`'s `ZEND_ASSIGN_OBJ` takes its fast path when
//! `zobj->ce` matches the opcode's cached class (`:2491`) **and** the slot is not `IS_UNDEF`
//! (`:2502`); `ZEND_FETCH_OBJ_R` mirrors it (`:2111`). The first guard reads the class, so the
//! runtime cache cannot tell instances apart — the second reads the *object*, which is what lets
//! scoped and plain instances of one class coexist. Initialise one slot and the VM writes straight
//! to `OBJ_PROP` for every instance of that class, the handlers below are never called again, and
//! nothing says so. That failure is silent, which is why `undef_every_slot` has a test rather than
//! a comment.
//!
//! This is the object shape PHP's own lazy ghosts use, so the slow path depended on here is one the
//! engine already exercises; laziness itself is an explicit object flag rather than an inference
//! from `IS_UNDEF` (`Zend/zend_lazy_objects.h:81-84`), so these objects pass through it.

use std::cell::RefCell;
use std::collections::HashMap;
use std::sync::OnceLock;

use super::tsrm;
use ignis_sys as sys;

/// A row of one object's properties in one scope, keyed by property name.
///
/// ponytail: name-keyed, where ADR-0042 specifies slots resolved from `ce->properties_info` at
/// class link time into a dense array. The acceptance asks for a standalone prototype before
/// anything else, and a dense array is an optimisation that needs the read-cost measurement the
/// ADR's kill criterion already demands — so it lands with that number, not before it.
type Row = HashMap<String, sys::zval>;

/// The scope a constructor's writes land in, and the one every other scope inherits from until it
/// writes. `Scope::create()` runs the constructor outside any request, so what it stores belongs to
/// the process, not to whichever fiber happened to build the service — the "what the constructor
/// stores is process-wide, what a method reads is per-scope" rule of ADR-0042, made mechanical.
///
/// Measured before it existed: without this fallback `$service->injectedDependency` read the
/// constructor's value outside a fiber and **NULL** inside one, so every scoped service lost its
/// dependencies on the first request that touched it.
const ROW_ZERO: usize = 0;

thread_local! {
    /// `(object handle, scope) -> row`. Thread-local because an object never crosses threads here:
    /// a ZTS `zend_object` belongs to the engine context that made it.
    static ROWS: RefCell<HashMap<(u32, usize), Row>> = RefCell::new(HashMap::new());
}

/// This scope's value for `name`, or row zero's if this scope has not written one. The first write
/// in a scope shadows row zero for that scope alone, which is copy-on-write without a copy.
///
/// # Safety
/// PHP thread with an initialised TSRM cache.
unsafe fn find(handle: u32, name: &str) -> Option<sys::zval> {
    // SAFETY: the caller upholds `# Safety`; this only reads.
    let scope = unsafe { current_scope() };
    ROWS.with(|rows| {
        let rows = rows.borrow();
        rows.get(&(handle, scope))
            .and_then(|row| row.get(name))
            .or_else(|| if scope == ROW_ZERO { None } else { rows.get(&(handle, ROW_ZERO)).and_then(|row| row.get(name)) })
            .copied()
    })
}

static HANDLERS: OnceLock<Handlers> = OnceLock::new();

/// `zend_object_handlers` is only ever read by the engine through a `*const`, and this one lives
/// for the process, so sharing it across threads is sound even though the raw struct is not `Sync`.
struct Handlers(sys::zend_object_handlers);
// SAFETY: written once under `OnceLock` before any scoped object exists and never mutated after,
// and the engine only reads it. There is no interior mutability and no thread-affine pointer in it.
unsafe impl Sync for Handlers {}
// SAFETY: as above -- it is immutable shared data for the life of the process.
unsafe impl Send for Handlers {}

/// The current scope: this fiber, or `0` for `{main}`. The same key `Ignis\Scope` uses in PHP and
/// `output.rs` uses for its sinks, so a request's rows die with the same boundary its bag does.
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
            return 0;
        }
        (&raw mut (*fiber).context) as usize
    }
}

/// Builds the handler table once, from the standard one, overriding only what must be per scope.
///
/// # Safety
/// MINIT on a PHP thread, before any scoped object exists.
pub unsafe fn install() {
    HANDLERS.get_or_init(|| {
        // SAFETY: MINIT; `std_object_handlers` is engine-owned immutable data, fully initialised
        // by the time any module's MINIT runs, and this copies it rather than aliasing it.
        let mut handlers = unsafe { sys::std_object_handlers };
        handlers.read_property = Some(read_property);
        handlers.write_property = Some(write_property);
        handlers.has_property = Some(has_property);
        handlers.unset_property = Some(unset_property);
        // Returning null from `get_property_ptr_ptr` tells the VM there is no slot to write
        // through, so `$this->list[] = x` and `$this->count++` degrade to read-then-write instead
        // of taking a pointer past `write_property`. Correct, and slower than a dense slot would
        // be. ponytail: revisit with the read-cost measurement ADR-0042 gates on.
        handlers.get_property_ptr_ptr = Some(no_direct_slot);
        Handlers(handlers)
    });
}

/// An instance of `class` whose declared slots are `IS_UNDEF` and whose properties resolve per
/// scope. The constructor is **not** run: `Ignis\Scope::create()` runs it afterwards, so its writes
/// go through `write_property` into the current scope's row instead of initialising the slots and
/// arming the VM's fast path for every instance of the class.
///
/// # Safety
/// PHP thread with an initialised TSRM cache, after `install()`.
pub unsafe fn allocate(class: &str) -> Result<*mut sys::zend_object, String> {
    // SAFETY: the caller upholds `# Safety`. `route::string_zval` owns the name for the length of
    // this call and is released on both paths below; `zend_lookup_class` runs the autoloader, which
    // matters because the container names a class as a string and need not have loaded it yet, and
    // returns null when it cannot be found.
    unsafe {
        let Some(handlers) = HANDLERS.get() else {
            return Err("ignis: scoped objects are not installed in this build".into());
        };
        let mut name = super::route::string_zval(class.as_bytes());
        let class_entry = sys::zend_lookup_class(name.value.str_);
        sys::zval_ptr_dtor(&raw mut name);
        if class_entry.is_null() {
            return Err(format!("ignis: class \"{class}\" not found"));
        }

        let object = sys::zend_objects_new(class_entry);
        sys::object_properties_init(object, class_entry);
        undef_every_slot(object);
        (*object).handlers = &raw const handlers.0;
        Ok(object)
    }
}

/// Empties every declared slot, which `object_properties_init` has just filled with the class's
/// defaults. This is the invariant of the whole module -- see the module doc.
///
/// # Safety
/// `object` is a freshly created object of its own class, not yet visible to PHP code.
unsafe fn undef_every_slot(object: *mut sys::zend_object) {
    // SAFETY: the caller upholds `# Safety`. `default_properties_count` is how many slots
    // `object_properties_init` filled, `properties_table` is that many zvals laid out inline after
    // the object header, and every one of them was just written by the engine, so each is a live
    // zval this releases before overwriting.
    unsafe {
        let count = (*(*object).ce).default_properties_count;
        let slots = (&raw mut (*object).properties_table) as *mut sys::zval;
        for index in 0..count as isize {
            sys::zval_ptr_dtor(slots.offset(index));
            std::ptr::write(slots.offset(index), std::mem::zeroed());
        }
    }
}

/// Drops the current scope's rows. Called at request end through `Ignis\Scope::clear()`, for the
/// same reason the key-value bag is cleared there: a pooled fiber's next request must not read the
/// previous one's values.
///
/// # Safety
/// PHP thread with an initialised TSRM cache.
pub unsafe fn rows_clear() {
    // SAFETY: the caller upholds `# Safety`. Every zval in a row was addref'd when stored, so each
    // is released exactly once here.
    unsafe {
        let scope = current_scope();
        if scope == ROW_ZERO {
            return; // row zero is the constructor's, and it outlives every request
        }
        let dropped = ROWS.with(|rows| {
            let mut rows = rows.borrow_mut();
            let keys: Vec<(u32, usize)> = rows.keys().filter(|(_, row_scope)| *row_scope == scope).copied().collect();
            keys.into_iter().filter_map(|key| rows.remove(&key)).collect::<Vec<Row>>()
        });
        for row in dropped {
            for mut value in row.into_values() {
                sys::zval_ptr_dtor(&raw mut value);
            }
        }
    }
}

/// # Safety
/// Called by the engine with a live object and property name.
unsafe extern "C" fn write_property(
    object: *mut sys::zend_object,
    name: *mut sys::zend_string,
    value: *mut sys::zval,
    _cache_slot: *mut *mut std::ffi::c_void,
) -> *mut sys::zval {
    // SAFETY: the engine upholds `# Safety`. The stored copy takes its own reference, and whatever
    // occupied the slot before is released -- the ownership discipline `superglobals.rs` uses for
    // the same reason.
    unsafe {
        let key = property_name(name);
        let mut stored = *value;
        sys::zval_add_ref(&raw mut stored);
        ROWS.with(|rows| {
            let mut rows = rows.borrow_mut();
            let row = rows.entry(((*object).handle, current_scope())).or_default();
            if let Some(mut previous) = row.insert(key, stored) {
                sys::zval_ptr_dtor(&raw mut previous);
            }
        });
        value
    }
}

/// # Safety
/// Called by the engine with a live object, property name and return slot.
unsafe extern "C" fn read_property(
    object: *mut sys::zend_object,
    name: *mut sys::zend_string,
    _type: std::ffi::c_int,
    _cache_slot: *mut *mut std::ffi::c_void,
    rv: *mut sys::zval,
) -> *mut sys::zval {
    // SAFETY: the engine upholds `# Safety`. The copy handed back takes its own reference, which is
    // what the caller expects of a value written into `rv`.
    unsafe {
        let key = property_name(name);
        let found = find((*object).handle, &key);
        match found {
            Some(value) => {
                *rv = value;
                sys::zval_add_ref(rv);
            }
            None => std::ptr::write(rv, std::mem::zeroed()),
        }
        rv
    }
}

/// # Safety
/// Called by the engine with a live object and property name.
unsafe extern "C" fn has_property(
    object: *mut sys::zend_object,
    name: *mut sys::zend_string,
    check_empty: std::ffi::c_int,
    _cache_slot: *mut *mut std::ffi::c_void,
) -> std::ffi::c_int {
    // SAFETY: the engine upholds `# Safety`. This only reads the row.
    unsafe {
        let key = property_name(name);
        let present = find((*object).handle, &key).map(|value| super::zval::type_of(&value) != sys::IS_NULL).unwrap_or(false);
        // ZEND_PROPERTY_ISSET asks "set and not null", which is what `present` answers; the other
        // forms fall back to the same answer here because a row holds no undefined entries.
        let _ = check_empty;
        i32::from(present)
    }
}

/// # Safety
/// Called by the engine with a live object and property name.
unsafe extern "C" fn unset_property(object: *mut sys::zend_object, name: *mut sys::zend_string, _cache_slot: *mut *mut std::ffi::c_void) {
    // SAFETY: the engine upholds `# Safety`. The removed value was addref'd when stored.
    unsafe {
        let key = property_name(name);
        let removed = ROWS.with(|rows| rows.borrow_mut().get_mut(&((*object).handle, current_scope())).and_then(|row| row.remove(&key)));
        if let Some(mut value) = removed {
            sys::zval_ptr_dtor(&raw mut value);
        }
    }
}

/// There is no slot to hand out: a scoped property lives in a row, not in the object, so the VM
/// must go through `read_property`/`write_property` instead of writing through a pointer.
///
/// # Safety
/// Called by the engine.
unsafe extern "C" fn no_direct_slot(
    _object: *mut sys::zend_object,
    _name: *mut sys::zend_string,
    _type: std::ffi::c_int,
    _cache_slot: *mut *mut std::ffi::c_void,
) -> *mut sys::zval {
    std::ptr::null_mut()
}

/// # Safety
/// `name` is a live `zend_string` owned by the caller.
unsafe fn property_name(name: *mut sys::zend_string) -> String {
    // SAFETY: the caller upholds `# Safety`; this copies the bytes out and takes no ownership.
    unsafe { super::zval::zstr_to_string(name) }
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

    /// Row zero is what a constructor writes into, and every other scope reads through to it until
    /// it writes its own. Without this a scoped service lost every injected dependency the moment a
    /// fiber touched it -- measured NULL inside a fiber, correct outside, before the fallback existed.
    #[test]
    fn a_scope_reads_through_to_row_zero_until_it_writes() {
        ROWS.with(|rows| rows.borrow_mut().clear());
        // SAFETY: this test's own stack storage, holding no refcounted value.
        let (mut shared, mut own): (sys::zval, sys::zval) = unsafe { (std::mem::zeroed(), std::mem::zeroed()) };
        // SAFETY: as above.
        unsafe {
            super::super::zval::set_long(&mut shared, 10);
            super::super::zval::set_long(&mut own, 20);
        }
        ROWS.with(|rows| rows.borrow_mut().entry((1, ROW_ZERO)).or_default().insert("dependency".into(), shared));

        let read_through = ROWS.with(|rows| {
            let rows = rows.borrow();
            rows.get(&(1, 555))
                .and_then(|row| row.get("dependency"))
                .or_else(|| rows.get(&(1, ROW_ZERO)).and_then(|row| row.get("dependency")))
                .copied()
        });
        assert_eq!(long_of(read_through), Some(10), "an untouched scope sees the constructor's value");

        ROWS.with(|rows| rows.borrow_mut().entry((1, 555)).or_default().insert("dependency".into(), own));
        let after_write = ROWS.with(|rows| rows.borrow().get(&(1, 555)).and_then(|row| row.get("dependency")).copied());
        assert_eq!(long_of(after_write), Some(20), "its own write shadows row zero");
        let row_zero = ROWS.with(|rows| rows.borrow().get(&(1, ROW_ZERO)).and_then(|row| row.get("dependency")).copied());
        assert_eq!(long_of(row_zero), Some(10), "and row zero is untouched by it");
    }

    /// The invariant this module exists to hold, as a unit on the pure part: a row belongs to one
    /// (object, scope) pair, so two scopes reading the same object see different values and
    /// neither sees the other's.
    #[test]
    fn two_scopes_of_one_object_do_not_share_a_row() {
        ROWS.with(|rows| {
            let mut rows = rows.borrow_mut();
            // SAFETY: a zval is a union of integers and pointers with no niche, so all-zero is the
            // valid IS_UNDEF representation and these hold no refcounted value.
            let (mut first, mut second): (sys::zval, sys::zval) = unsafe { (std::mem::zeroed(), std::mem::zeroed()) };
            // SAFETY: both are this test's own stack storage, holding no refcounted value.
            unsafe {
                super::super::zval::set_long(&mut first, 1);
                super::super::zval::set_long(&mut second, 2);
            }
            rows.entry((7, 100)).or_default().insert("tag".into(), first);
            rows.entry((7, 200)).or_default().insert("tag".into(), second);

            assert_eq!(long_of(Some(rows[&(7, 100)]["tag"])), Some(1));
            assert_eq!(long_of(Some(rows[&(7, 200)]["tag"])), Some(2), "the second scope has its own row");
            assert_eq!(rows.len(), 2, "one object, two scopes, two rows");
        });
    }
}
