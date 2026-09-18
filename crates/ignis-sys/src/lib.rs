//! Raw FFI bindings to libphp (ZTS embed build). Everything here is `unsafe`
//! by nature; the safe wrappers live in the `ignis` crate.
//!
//! # FFI boundary contract (read before touching)
//! - **Thread affinity.** Every function taking or returning a Zend pointer
//!   must be called on a thread that has called `ts_resource(0)` (or the main
//!   thread after `php_embed_init`). Zend globals are thread-local; calling
//!   from a tokio worker is undefined behaviour.
//! - **Ownership of `zval`s handed to us.** Argument zvals inside
//!   `execute_data` are owned by the VM; we only read them. `return_value`
//!   points at VM-owned storage that we may write exactly once.
//! - **Ownership of what we create.** `zend_string`/`HashTable` we allocate
//!   with `_emalloc`/`_zend_new_array_0` are owned by the request allocator
//!   and freed at request shutdown or by refcount, whichever first; once
//!   stored in a zval handed back to PHP we must not free them ourselves.
//! - **Lifetimes of C strings passed in.** `zend_function_entry.fname` and
//!   arg-info names must live for the whole process (`&'static CStr`).

/// Bindgen output. The lint levels are named one by one rather than by group, because
/// `clippy::all` does not contain `undocumented_unsafe_blocks` (it is a `restriction` lint) and 270
/// warnings from generated code would otherwise reach the gate. Naming them on the module also keeps
/// any hand-written code in this crate linted, which a crate-level blanket would not.
#[allow(non_upper_case_globals, non_camel_case_types, non_snake_case, dead_code)]
#[allow(unsafe_op_in_unsafe_fn, unused_qualifications)]
#[allow(clippy::all, clippy::undocumented_unsafe_blocks)]
mod bindings {
    include!(concat!(env!("OUT_DIR"), "/bindings.rs"));
}

pub use bindings::*;
