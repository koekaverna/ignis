//! Taking a `Mutex` without letting one panic take the rest of the process with it.
//!
//! Poisoning exists to stop a second thread observing data a panicking one left half-updated. None
//! of the maps in this crate are that: they hold abort handles, oneshot senders, function pointers
//! and `Instant`s — values with no invariant spanning two of them, where a panic mid-update leaves
//! a map that is merely missing an entry.
//!
//! What poisoning cost instead was measured by reading the dispatcher: `reactor.rs` takes its
//! cancellable-task map with `.lock().unwrap()` inside spawned tokio tasks, so a panic anywhere in
//! one of those critical sections poisons the mutex, and from then on **every** `Op::Sleep`,
//! `Op::Watch` and `Op::CancelWatch` on that reactor panics on the lock. Inside a task, which means
//! a dispatcher that has silently stopped rather than an error anybody sees. `watch.rs` already did
//! the right thing at two sites and nothing else did.
//!
//! A `Mutex` that genuinely guards a multi-field invariant must not use this; there is none today,
//! and the day one appears it should say so where it is declared.

use std::sync::{Mutex, MutexGuard};

pub(crate) trait LockUnpoisoned<T> {
    /// The guard, whether or not a previous holder panicked.
    fn lock_unpoisoned(&self) -> MutexGuard<'_, T>;
}

impl<T> LockUnpoisoned<T> for Mutex<T> {
    fn lock_unpoisoned(&self) -> MutexGuard<'_, T> {
        self.lock().unwrap_or_else(|poisoned| poisoned.into_inner())
    }
}

#[cfg(test)]
mod tests {
    use super::LockUnpoisoned;
    use std::sync::{Arc, Mutex};

    /// The whole point: after a panic under the lock, the next taker still gets the data.
    #[test]
    fn a_poisoned_mutex_still_hands_over_its_data() {
        let shared = Arc::new(Mutex::new(vec![1, 2, 3]));
        let poisoner = Arc::clone(&shared);
        let _ = std::thread::spawn(move || {
            let _guard = poisoner.lock().unwrap();
            panic!("poison it");
        })
        .join();

        assert!(shared.lock().is_err(), "the mutex really is poisoned");
        assert_eq!(*shared.lock_unpoisoned(), vec![1, 2, 3]);
    }
}
