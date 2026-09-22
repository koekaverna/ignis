//! Optional backends behind features. The production path is the userland loop in
//! php/packages/runtime/src/ignis.php; `temporal` is sdk-core in-process (ADR-0013, ADR-0040).
#[cfg(feature = "temporal")]
pub mod temporal;

#[cfg(all(test, feature = "temporal"))]
mod completion_json;
