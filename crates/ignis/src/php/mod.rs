pub mod embed;
pub mod module;
pub mod route;
pub mod sleep;
pub mod stream;
pub mod superglobals;
pub mod zval;

/// Removes the calling thread's reactor from HTTP dispatch (ADR-0010 follow-up:
/// a worker whose script ended must not receive requests nobody will answer).
pub fn http_unregister_current() {
    crate::http::unregister(&module::reactor());
}
