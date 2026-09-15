//! The reactor: the only bridge between a PHP thread and the tokio runtime.
//!
//! Design (ADR-0001): a PHP thread never touches tokio. It `submit()`s plain
//! data ops over a channel and `poll()`s a completion channel with a timeout.
//! Every op carries a monotonically increasing `id` the PHP side uses to map
//! completions back to fibers.
use std::sync::Arc;
use std::sync::atomic::{AtomicU64, Ordering};
use std::time::Duration;

use crossbeam_channel::{Receiver, RecvTimeoutError, Sender};
use tokio::sync::mpsc;

/// An I/O request submitted by PHP. Plain data only: no Zend pointers.
#[derive(Debug)]
pub enum Op {
    /// Complete after `ms` milliseconds (tokio timer wheel).
    Sleep { ms: u64 },
}

/// Result of an op. Plain data only.
#[derive(Debug, Clone)]
pub enum Outcome {
    /// Sleep finished (payload: how many µs late the timer fired, for tuning).
    Slept { late_us: u64 },
}

#[derive(Debug, Clone)]
pub struct Completion {
    pub id: u64,
    pub outcome: Outcome,
}

/// Handle owned by one PHP thread.
pub struct Reactor {
    next_id: AtomicU64,
    inflight: AtomicU64,
    to_tokio: mpsc::UnboundedSender<(u64, Op)>,
    from_tokio: Receiver<Completion>,
}

impl Reactor {
    /// Spawns the dispatcher task on `rt` and returns the PHP-side handle.
    pub fn new(rt: &tokio::runtime::Handle) -> Arc<Reactor> {
        let (to_tokio, mut rx) = mpsc::unbounded_channel::<(u64, Op)>();
        let (done_tx, from_tokio) = crossbeam_channel::unbounded::<Completion>();
        rt.spawn(async move {
            while let Some((id, op)) = rx.recv().await {
                let done_tx: Sender<Completion> = done_tx.clone();
                match op {
                    Op::Sleep { ms } => {
                        let start = tokio::time::Instant::now();
                        let deadline = start + Duration::from_millis(ms);
                        tokio::spawn(async move {
                            tokio::time::sleep_until(deadline).await;
                            let late_us = deadline.elapsed().as_micros() as u64;
                            // Receiver dropped => PHP thread is gone; nothing to do.
                            let _ = done_tx.send(Completion { id, outcome: Outcome::Slept { late_us } });
                        });
                    }
                }
            }
        });
        Arc::new(Reactor { next_id: AtomicU64::new(1), inflight: AtomicU64::new(0), to_tokio, from_tokio })
    }

    /// Submit an op; returns its id. Never blocks.
    pub fn submit(&self, op: Op) -> u64 {
        let id = self.next_id.fetch_add(1, Ordering::Relaxed);
        self.inflight.fetch_add(1, Ordering::Relaxed);
        // The dispatcher task lives as long as the runtime; a send error means
        // the runtime is shutting down, which we surface as an immediate
        // completion-less id (poll will then return empty and PHP sees a stall
        // rather than UB). Logged for visibility.
        if self.to_tokio.send((id, op)).is_err() {
            tracing::error!(id, "reactor dispatcher is gone; op dropped");
            self.inflight.fetch_sub(1, Ordering::Relaxed);
        }
        id
    }

    pub fn inflight(&self) -> u64 {
        self.inflight.load(Ordering::Relaxed)
    }

    /// Block up to `timeout` (None = forever) for at least one completion, then
    /// drain everything that is ready. Returns immediately if nothing is in
    /// flight so a userland loop can never deadlock on an empty reactor.
    pub fn poll(&self, timeout: Option<Duration>) -> Vec<Completion> {
        let mut out = Vec::new();
        if self.inflight() == 0 {
            return out;
        }
        let first = match timeout {
            Some(t) => match self.from_tokio.recv_timeout(t) {
                Ok(c) => Some(c),
                Err(RecvTimeoutError::Timeout) | Err(RecvTimeoutError::Disconnected) => None,
            },
            None => self.from_tokio.recv().ok(),
        };
        if let Some(c) = first {
            out.push(c);
            while let Ok(c) = self.from_tokio.try_recv() {
                out.push(c);
            }
            self.inflight.fetch_sub(out.len() as u64, Ordering::Relaxed);
        }
        out
    }
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn sleep_completes_and_poll_drains() {
        let rt = tokio::runtime::Builder::new_multi_thread().worker_threads(1).enable_all().build().unwrap();
        let r = Reactor::new(rt.handle());
        let ids: Vec<u64> = (0..100).map(|_| r.submit(Op::Sleep { ms: 20 })).collect();
        assert_eq!(r.inflight(), 100);
        let t0 = std::time::Instant::now();
        let mut got = Vec::new();
        while got.len() < 100 {
            got.extend(r.poll(Some(Duration::from_secs(2))).into_iter().map(|c| c.id));
        }
        assert!(t0.elapsed() < Duration::from_millis(500), "took {:?}", t0.elapsed());
        got.sort();
        assert_eq!(got, ids);
        assert_eq!(r.inflight(), 0);
        assert!(r.poll(Some(Duration::from_millis(1))).is_empty());
    }

    #[test]
    fn poll_on_empty_reactor_does_not_block() {
        let rt = tokio::runtime::Builder::new_multi_thread().worker_threads(1).enable_all().build().unwrap();
        let r = Reactor::new(rt.handle());
        let t0 = std::time::Instant::now();
        assert!(r.poll(None).is_empty());
        assert!(t0.elapsed() < Duration::from_millis(50));
    }
}
