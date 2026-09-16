//! Backends (ADR-0003). `mainline85` is the production path (userland loop in
//! php/ignis.php); `async_core` is compiled only against the true-async fork.
#[cfg(php_async_abi)]
pub mod async_core;
#[cfg(feature = "temporal")]
pub mod temporal;
