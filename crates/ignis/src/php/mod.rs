pub mod park;
pub mod embed;
pub mod module;
pub mod route;
pub mod stream;
pub mod superglobals;
pub mod zval;

/// Removes the calling thread's reactor from HTTP dispatch (ADR-0010 follow-up:
/// a worker whose script ended must not receive requests nobody will answer).
use std::sync::Arc;

pub fn http_unregister_current() {
    let r = module::reactor();
    crate::http::unregister(&r);
    // M4-11 (V-42): leases this thread still held would otherwise stay orphaned with their permits.
    if let Some(rt) = module::RUNTIME.get() {
        crate::pg::release_owned_by(Arc::as_ptr(&r) as usize, rt);
    }
}
