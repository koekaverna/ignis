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
    /// `scope -> (object handle -> row)`. Thread-local because an object never crosses threads
    /// here: a ZTS `zend_object` belongs to the engine context that made it.
    ///
    /// Scope first, not object first, and that is a decision rather than a detail. Upstream's
    /// per-coroutine store is "engine-owned per-coroutine storage under process-unique numeric
    /// keys" (`zend_async_API.h:473`), so a scope-keyed outer map is **one** `internal_context`
    /// entry under one `zend_async_internal_context_key_alloc` when backend (b) becomes a real
    /// target — the whole port, instead of a rewrite. It is also the better shape today: dropping a
    /// scope is one removal rather than a scan of every key, and that scan sat on the fiber-switch
    /// path, which is the one cost the swap variant pays.
    static ROWS: RefCell<HashMap<usize, HashMap<u32, Row>>> = RefCell::new(HashMap::new());
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
        let here = rows.get(&scope).and_then(|objects| objects.get(&handle)).and_then(|row| row.get(name));
        match here {
            Some(value) => Some(*value),
            None if scope == ROW_ZERO => None,
            None => rows.get(&ROW_ZERO).and_then(|objects| objects.get(&handle)).and_then(|row| row.get(name)).copied(),
        }
    })
}

static HANDLERS: OnceLock<Handlers> = OnceLock::new();
static SWAP_HANDLERS: OnceLock<Handlers> = OnceLock::new();

/// Which shape a scoped object takes. `handlers` keeps the declared slots `IS_UNDEF` so our own
/// handlers always run, and reimplements what it needs. `swap` leaves the slots holding real values
/// so **every** standard handler works untouched — visibility, typed properties, `readonly`, magic
/// methods, name mangling, `get_properties`, `clone`, references, `var_dump` — and pays for it by
/// moving each scoped object's table on every fiber switch.
///
/// The swap cannot be made lazy: `ZEND_ASSIGN_OBJ`'s fast path writes straight to `OBJ_PROP` when
/// the slot holds a value (`zend_vm_def.h:2502`), so nothing of ours is guaranteed to run between a
/// scope change and the next access. That is why this costs per switch rather than per access, and
/// it is the whole trade this knob exists to measure.
/// `swap` is the default since 2026-09-20 and `handlers` is kept only so V-97's comparison can be
/// reproduced. `handlers` is **known to be wrong** in ways `swap` cannot be, because it replaces
/// the engine's semantics instead of delegating to them: it performs no type verification, no
/// `readonly` and no visibility checks, does not mangle private names, never falls back to
/// `__get`/`__set`, and — measured — discards the class's declared defaults, so
/// `private ?string $seen = null` comes back `IS_UNDEF` and PHP reports
/// "Return value must be of type ?string, null returned". Do not reach for it outside that
/// measurement.
fn swapping() -> bool {
    static SWAP: OnceLock<bool> = OnceLock::new();
    *SWAP.get_or_init(|| std::env::var("IGNIS_SCOPED_MODE").map(|mode| mode != "handlers").unwrap_or(true))
}

thread_local! {
    /// Live scoped objects in the swap variant, by handle. Entries are dropped by `free_obj`, which
    /// is the only reason either variant overrides a handler it otherwise would not: `zend_object`
    /// handles are **reused**, so without this a new object inherits a dead one's rows.
    static LIVE: RefCell<HashMap<u32, *mut sys::zend_object>> = RefCell::new(HashMap::new());
    /// `scope -> (object handle -> that scope's copy of the declared slots)`, swap variant only,
    /// keyed the same way and for the same reasons as `ROWS` above.
    static TABLES: RefCell<HashMap<usize, HashMap<u32, Vec<sys::zval>>>> = RefCell::new(HashMap::new());
}

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
        handlers.free_obj = Some(free_obj);
        Handlers(handlers)
    });
    SWAP_HANDLERS.get_or_init(|| {
        // SAFETY: as above -- a copy of engine-owned immutable data at MINIT.
        let mut handlers = unsafe { sys::std_object_handlers };
        handlers.free_obj = Some(free_obj);
        Handlers(handlers)
    });
}

/// Drops everything this module holds for a dying object, then hands over to the standard free.
///
/// `zend_object` handles are reused by the engine, so an object's rows must die with it or the next
/// object to take that handle inherits them. Both variants override this and nothing else in
/// common.
///
/// # Safety
/// Called by the engine as the object is freed.
unsafe extern "C" fn free_obj(object: *mut sys::zend_object) {
    // SAFETY: the engine upholds `# Safety`. Borrows are dropped before any release, because a
    // release can run PHP code that re-enters this module.
    unsafe {
        let handle = (*object).handle;
        LIVE.with(|live| live.borrow_mut().remove(&handle));
        let rows = ROWS.with(|rows| rows.borrow_mut().values_mut().filter_map(|objects| objects.remove(&handle)).collect::<Vec<Row>>());
        for row in rows {
            for mut value in row.into_values() {
                sys::zval_ptr_dtor(&raw mut value);
            }
        }
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

/// Moves every live scoped object's declared slots from the outgoing scope's copy to the incoming
/// one. Swap variant only; a no-op otherwise.
///
/// # Safety
/// Called from the fiber-switch observer on a PHP thread, with both contexts live.
pub unsafe fn on_switch(from: *mut sys::zend_fiber_context, to: *mut sys::zend_fiber_context) {
    if !swapping() {
        return;
    }
    // SAFETY: the caller upholds `# Safety`. Only zvals this module owns are moved, by value and
    // without touching their refcounts -- a move between two places we own is not a new reference.
    unsafe {
        let (leaving, entering) = (scope_of(from), scope_of(to));
        if leaving == entering {
            return;
        }
        let objects: Vec<*mut sys::zend_object> = LIVE.with(|live| live.borrow().values().copied().collect());
        for object in objects {
            let count = (*(*object).ce).default_properties_count as usize;
            let slots = (&raw mut (*object).properties_table) as *mut sys::zval;
            let outgoing: Vec<sys::zval> = (0..count).map(|index| *slots.add(index)).collect();
            TABLES.with(|tables| tables.borrow_mut().entry(leaving).or_default().insert((*object).handle, outgoing));
            let incoming =
                TABLES.with(|tables| tables.borrow_mut().get_mut(&entering).and_then(|objects| objects.remove(&(*object).handle)));
            match incoming {
                Some(values) => {
                    for (index, value) in values.into_iter().enumerate() {
                        std::ptr::write(slots.add(index), value);
                    }
                }
                None => {
                    // A scope that has not touched this object yet starts from the constructor's
                    // table, copied rather than moved so row zero keeps its own reference.
                    let defaults =
                        TABLES.with(|tables| tables.borrow().get(&ROW_ZERO).and_then(|objects| objects.get(&(*object).handle)).cloned());
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

/// The scope key a fiber context stands for, `ROW_ZERO` for `{main}`.
///
/// The context pointer *is* the key for a real fiber: `zend_fiber` embeds its `zend_fiber_context`
/// by value, so the address the observer is handed is the address `current_scope()` takes of that
/// field. `{main}` is the exception and must be forced to `ROW_ZERO`, because `current_scope()`
/// reaches it through a **null** `active_fiber` and answers `ROW_ZERO`, while the observer is handed
/// `EG(main_fiber_context)`, a real pointer. Two keys for one scope meant the constructor's values
/// were saved under an address nothing looked up, and the first fiber to read one got the engine's
/// own "must not be accessed before initialization" — which is variant `swap` working, reporting a
/// bug of mine through a check variant `handlers` does not have at all.
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
        if swapping() {
            let Some(swap) = SWAP_HANDLERS.get() else {
                return Err("ignis: scoped objects are not installed in this build".into());
            };
            (*object).handlers = &raw const swap.0;
            LIVE.with(|live| live.borrow_mut().insert((*object).handle, object));
        } else {
            undef_every_slot(object);
            (*object).handlers = &raw const handlers.0;
        }
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

/// Which shape is in effect, for anything that reports it. A probe that prints the mode it guessed
/// from the environment lies the moment the default changes, which it did on 2026-09-20.
pub fn mode() -> &'static str {
    if swapping() { "swap" } else { "handlers" }
}

/// Promotes what the constructor just wrote into row zero, whatever scope it ran in.
///
/// Without this, row zero is only ever filled by a switch *away* from `{main}`, so a service the
/// container builds lazily — inside a request, which is Symfony's normal path — traps its
/// constructor's values in that one request's scope. Measured before it existed: fiber A creates
/// the service, fiber B reads a `readonly string` and gets `''` under `handlers` and the engine's
/// "must not be accessed before initialization" under `swap`. Sealing is explicit rather than a
/// consequence of where `new` happened, because "what the constructor stores is process-wide" has
/// to be a rule the mechanism enforces, not an accident of the caller's scope.
///
/// # Safety
/// PHP thread with an initialised TSRM cache; `object` is a live scoped object whose constructor
/// has just returned.
pub unsafe fn seal(object: *mut sys::zend_object) {
    // SAFETY: the caller upholds `# Safety`. Row zero takes its own reference to every value it
    // keeps, and whatever it held before is released with no borrow outstanding.
    unsafe {
        let handle = (*object).handle;
        if swapping() {
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
            return;
        }
        let scope = current_scope();
        if scope == ROW_ZERO {
            return; // the constructor already wrote row zero
        }
        let moved = ROWS.with(|rows| rows.borrow_mut().get_mut(&scope).and_then(|objects| objects.remove(&handle)));
        let Some(moved) = moved else { return };
        let displaced = ROWS.with(|rows| rows.borrow_mut().entry(ROW_ZERO).or_default().insert(handle, moved));
        for mut value in displaced.map(|row| row.into_values().collect::<Vec<_>>()).unwrap_or_default() {
            sys::zval_ptr_dtor(&raw mut value);
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
        let dropped = ROWS.with(|rows| rows.borrow_mut().remove(&scope));
        let tables = TABLES.with(|tables| tables.borrow_mut().remove(&scope));
        for row in dropped.map(|objects| objects.into_values().collect::<Vec<Row>>()).unwrap_or_default() {
            for mut value in row.into_values() {
                sys::zval_ptr_dtor(&raw mut value);
            }
        }
        for table in tables.map(|objects| objects.into_values().collect::<Vec<Vec<sys::zval>>>()).unwrap_or_default() {
            for mut value in table {
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
        let displaced = ROWS
            .with(|rows| rows.borrow_mut().entry(current_scope()).or_default().entry((*object).handle).or_default().insert(key, stored));
        // The release happens with the borrow dropped, and that is not tidiness. Freeing the last
        // reference to an object runs its `__destruct`, which may touch a scoped property and
        // re-enter this handler; under a held `borrow_mut` that is a `RefCell` panic, and a panic
        // across `extern "C"` aborts the process. Every mutation in this module drops its borrow
        // before it can run PHP code.
        if let Some(mut previous) = displaced {
            sys::zval_ptr_dtor(&raw mut previous);
        }
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
        let removed = ROWS.with(|rows| {
            rows.borrow_mut()
                .get_mut(&current_scope())
                .and_then(|objects| objects.get_mut(&(*object).handle))
                .and_then(|row| row.remove(&key))
        });
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
        ROWS.with(|rows| rows.borrow_mut().entry(ROW_ZERO).or_default().entry(1).or_default().insert("dependency".into(), shared));

        let read_through = ROWS.with(|rows| {
            let rows = rows.borrow();
            rows.get(&555)
                .and_then(|objects| objects.get(&1))
                .and_then(|row| row.get("dependency"))
                .or_else(|| rows.get(&ROW_ZERO).and_then(|objects| objects.get(&1)).and_then(|row| row.get("dependency")))
                .copied()
        });
        assert_eq!(long_of(read_through), Some(10), "an untouched scope sees the constructor's value");

        ROWS.with(|rows| rows.borrow_mut().entry(555).or_default().entry(1).or_default().insert("dependency".into(), own));
        let after_write =
            ROWS.with(|rows| rows.borrow().get(&555).and_then(|objects| objects.get(&1)).and_then(|row| row.get("dependency")).copied());
        assert_eq!(long_of(after_write), Some(20), "its own write shadows row zero");
        let row_zero = ROWS
            .with(|rows| rows.borrow().get(&ROW_ZERO).and_then(|objects| objects.get(&1)).and_then(|row| row.get("dependency")).copied());
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
            rows.entry(100).or_default().entry(7).or_default().insert("tag".into(), first);
            rows.entry(200).or_default().entry(7).or_default().insert("tag".into(), second);

            assert_eq!(long_of(Some(rows[&100][&7]["tag"])), Some(1));
            assert_eq!(long_of(Some(rows[&200][&7]["tag"])), Some(2), "the second scope has its own row");
            assert_eq!(rows.len(), 2, "one object, two scopes, two rows");
        });
    }
}
