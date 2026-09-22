//! Minimal, tested re-implementations of the Zend `ZVAL_*` / `RETVAL_*`
//! macros that bindgen cannot express. Kept tiny on purpose: every helper is
//! a one-liner over a documented struct layout from `ignis-sys`.
//!
//! Soundness notes are on each function. All functions are `unsafe` because
//! they dereference raw VM pointers; callers must be on a PHP thread.
use ignis_sys as sys;

/// `ZVAL_LONG(zv, v)`.
///
/// # Safety
/// `zv` must point to writable zval storage owned by the VM (e.g.
/// `return_value`) that currently holds no refcounted value we would leak.
#[inline]
pub unsafe fn set_long(zv: *mut sys::zval, v: i64) {
    // SAFETY: the caller upholds `# Safety` above. Both fields are plain integers in the zval
    // union, so writing them cannot leak or free anything.
    unsafe {
        (*zv).value.lval = v;
        (*zv).u1.type_info = sys::IS_LONG;
    }
}

/// `ZVAL_NULL(zv)`.
///
/// # Safety
/// Same as [`set_long`].
#[inline]
pub unsafe fn set_null(zv: *mut sys::zval) {
    // SAFETY: the caller upholds `# Safety` above; only the type tag is written.
    unsafe { (*zv).u1.type_info = sys::IS_NULL }
}

/// `ZVAL_BOOL(zv, b)`.
///
/// # Safety
/// Same as [`set_long`].
#[inline]
pub unsafe fn set_bool(zv: *mut sys::zval, b: bool) {
    // SAFETY: as `set_null` -- the caller upholds `# Safety`, only the type tag is written.
    unsafe { (*zv).u1.type_info = if b { sys::IS_TRUE } else { sys::IS_FALSE } }
}

/// `array_init(zv)`: allocates a fresh empty HashTable from the request
/// allocator and stores it in `zv` as a refcounted, collectable array.
///
/// # Safety
/// Same as [`set_long`]; additionally must run inside an active request
/// (between `php_request_startup` and `php_request_shutdown`) because the
/// table is emalloc'd. Ownership of the table moves to `zv`.
#[inline]
pub unsafe fn set_new_array(zv: *mut sys::zval) -> *mut sys::HashTable {
    // SAFETY: the caller upholds `# Safety` above, so a request is active and the emalloc'd table
    // is valid until request shutdown. Ownership moves into `zv` in the same breath, so the table
    // is never left unreferenced.
    unsafe {
        let ht = sys::_zend_new_array_0();
        (*zv).value.arr = ht;
        (*zv).u1.type_info = sys::IGNIS_IS_ARRAY_EX;
        ht
    }
}

/// `Z_TYPE_P(zv)` (low byte of type_info).
///
/// # Safety
/// `zv` must point to an initialised zval.
#[inline]
pub unsafe fn type_of(zv: *const sys::zval) -> u32 {
    // SAFETY: the caller guarantees an initialised zval; this only reads the tag byte.
    unsafe { (*zv).u1.type_info & 0xff }
}

/// `ZEND_NUM_ARGS()` for an internal function frame.
///
/// # Safety
/// `ex` must be the `execute_data` passed to a `zif_handler`.
#[inline]
pub unsafe fn num_args(ex: *const sys::zend_execute_data) -> u32 {
    // SAFETY: the caller guarantees a live zif_handler frame, where This.u2.num_args is the
    // argument count the VM wrote before the call.
    unsafe { (*ex).This.u2.num_args }
}

/// `ZEND_CALL_ARG(ex, n)` with `n` 1-based, as in the C macro.
///
/// # Safety
/// `ex` must be a live internal-function frame and `n <= num_args(ex)`.
/// The returned pointer is VM-owned; do not free or hold past the call.
#[inline]
pub unsafe fn arg(ex: *mut sys::zend_execute_data, n: u32) -> *mut sys::zval {
    // SAFETY: the caller guarantees `n <= num_args(ex)`, so the offset stays inside the frame the
    // VM allocated -- execute_data and its arguments are one allocation, which is what makes this
    // pointer arithmetic (rather than a field access) correct. The frame-slot constant is asserted
    // against the C header by the test below.
    unsafe { (ex as *mut sys::zval).add(sys::IGNIS_ZEND_CALL_FRAME_SLOT as usize + n as usize - 1) }
}

/// Reads a `zend_long` argument, or `None` if it is not IS_LONG (the caller
/// then raises a TypeError). No juggling on purpose: the userland wrapper in
/// php/packages/runtime/src/ignis.php declares `int` types, so anything else is an Ignis bug.
///
/// # Safety
/// Same as [`arg`].
#[inline]
pub unsafe fn arg_long(ex: *mut sys::zend_execute_data, n: u32) -> Option<i64> {
    // SAFETY: the caller upholds `arg`'s contract; lval is only read once the tag says IS_LONG, so
    // the union is read as what it holds.
    unsafe {
        let zv = arg(ex, n);
        if type_of(zv) == sys::IS_LONG { Some((*zv).value.lval) } else { None }
    }
}

/// Copies a `zend_string` into an owned Rust `String` (lossy on invalid UTF-8).
///
/// # Safety
/// `zs` must point to a live `zend_string`.
pub unsafe fn zstr_to_string(zs: *const sys::zend_string) -> String {
    // SAFETY: the caller guarantees a live zend_string, whose `len` describes its own `val` buffer.
    // The bytes are copied before returning, so the String never borrows VM memory.
    unsafe {
        let len = (*zs).len;
        let ptr = (*zs).val.as_ptr() as *const u8;
        String::from_utf8_lossy(std::slice::from_raw_parts(ptr, len)).into_owned()
    }
}

/// A fresh (non-interned, refcounted) string zval owned by the caller.
///
/// # Safety
/// PHP thread inside an active request: the string is allocated by the request allocator.
pub(super) unsafe fn string_zval(bytes: &[u8]) -> sys::zval {
    // No zend_string_init binding (static inline): build it through a temporary array element.
    // SAFETY: the caller upholds `# Safety` above. The element is stolen rather than copied -- its
    // slot is retagged IS_NULL before the temporary array is destroyed, so the refcount the caller
    // receives is the one add_index_stringl created, and zval_ptr_dtor frees only the array.
    unsafe {
        let mut tmp: sys::zval = std::mem::zeroed();
        set_new_array(&mut tmp);
        sys::add_index_stringl(&mut tmp, 0, bytes.as_ptr() as *const std::ffi::c_char, bytes.len());
        let el = sys::zend_hash_index_find(tmp.value.arr, 0);
        let out = *el;
        (*el).u1.type_info = sys::IS_NULL;
        sys::zval_ptr_dtor(&mut tmp);
        out
    }
}

#[cfg(test)]
mod tests {
    //! These tests need no PHP runtime: they check layout assumptions
    //! (`ZEND_CALL_FRAME_SLOT`, zval size) that the helpers rely on. They run
    //! under miri because they touch no FFI.
    use super::*;
    use std::mem::{align_of, size_of};

    #[test]
    fn zval_is_16_bytes_and_frame_slot_matches_c() {
        assert_eq!(size_of::<sys::zval>(), 16);
        assert_eq!(align_of::<sys::zval>(), 8);
        let slot = size_of::<sys::zend_execute_data>().div_ceil(size_of::<sys::zval>());
        assert_eq!(slot as u32, sys::IGNIS_ZEND_CALL_FRAME_SLOT);
    }

    #[test]
    fn set_and_read_scalar_zvals() {
        // SAFETY: a zval is a union of integers and pointers with no niche, so all-zero is the
        // valid IS_UNDEF representation.
        let mut zv: sys::zval = unsafe { std::mem::zeroed() };
        // SAFETY: `zv` is this test's own stack storage, initialised above and holding no
        // refcounted value -- exactly what the helpers' `# Safety` asks for.
        unsafe {
            set_long(&mut zv, 42);
            assert_eq!(type_of(&zv), sys::IS_LONG);
            assert_eq!(zv.value.lval, 42);
            set_bool(&mut zv, true);
            assert_eq!(type_of(&zv), sys::IS_TRUE);
            set_null(&mut zv);
            assert_eq!(type_of(&zv), sys::IS_NULL);
        }
    }

    #[test]
    fn arg_pointer_arithmetic() {
        // Build a fake frame: execute_data followed by two arg zvals.
        #[repr(C)]
        struct Frame {
            ex: sys::zend_execute_data,
            args: [sys::zval; 2],
        }
        // SAFETY: zeroed is a valid representation for both members, as above.
        let mut f: Frame = unsafe { std::mem::zeroed() };
        // SAFETY: `f` is this test's own #[repr(C)] allocation laid out the way the VM lays out a
        // frame -- execute_data followed by its argument zvals -- which is the layout `arg()`
        // assumes.
        unsafe {
            set_long(&mut f.args[0], 7);
            set_long(&mut f.args[1], 9);
            f.ex.This.u2.num_args = 2;
            // Derive the frame pointer from the whole allocation, exactly as the
            // VM does (execute_data and its args are one allocation). Deriving it
            // from `&mut f.ex` would give provenance over the header only, which
            // miri (Stacked Borrows) correctly rejects.
            let ex = (&raw mut f) as *mut sys::zend_execute_data;
            assert_eq!(num_args(ex), 2);
            assert_eq!(arg_long(ex, 1), Some(7));
            assert_eq!(arg_long(ex, 2), Some(9));
        }
    }
}
