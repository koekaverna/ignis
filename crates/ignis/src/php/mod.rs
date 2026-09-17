#[cfg(feature = "universal-park")]
pub mod park;

/// The off build (H35, `--no-default-features`): nothing is interposed, so nothing can fail to park
/// and the policy table is empty. Both symbols exist in either build, because a scrape whose series
/// appear and disappear with a build flag is worse than a constant zero.
#[cfg(not(feature = "universal-park"))]
pub mod park {
    use std::sync::atomic::AtomicU64;

    pub static PARK_FAILED: AtomicU64 = AtomicU64::new(0);

    pub fn policy_summary() -> String {
        "off".to_string()
    }
}
pub mod embed;
pub mod locklib;
pub mod module;
pub mod output;
pub mod route;
pub mod tsrm;
pub mod superglobals;
pub mod wait;
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
