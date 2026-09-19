//! E16 (ADR-0016): offload pool — synchronous PHP worker threads with their own TSRM context.
//!
//! A job is `(function name, serialized args)`; it runs on a worker and answers with serialized
//! bytes. Nothing but bytes and integer ids crosses between PHP threads. A worker may call back
//! into the calling thread (`callback`): the request is injected into the caller's reactor as a
//! completion, PHP runs the closure there, and the worker blocks on the answer.

use std::collections::HashMap;
use std::sync::atomic::{AtomicU64, AtomicUsize, Ordering};
use std::sync::{Arc, Mutex, OnceLock};

use bytes::Bytes;
use crossbeam_channel::{Receiver, Sender, bounded, unbounded};

use crate::lock::LockUnpoisoned;
use crate::reactor::{Outcome, Reactor};

pub struct Job {
    pub id: u64,
    pub func: String,
    pub args: Bytes,
}

struct Pool {
    shared_tx: Sender<Job>,
    shared_rx: Receiver<Job>,
    /// Per-worker queues for jobs pinned to a worker (proxy affinity).
    pinned: Vec<(Sender<Job>, Receiver<Job>)>,
    busy: AtomicUsize,
    done: AtomicU64,
}

struct PendingCallback {
    reply: Sender<Bytes>,
}

static POOL: OnceLock<Pool> = OnceLock::new();
static NEXT: AtomicU64 = AtomicU64::new(1);
/// (job id, callback seq) → worker waiting for the caller's answer.
static CALLBACKS: OnceLock<Mutex<HashMap<(u64, u64), PendingCallback>>> = OnceLock::new();
/// The reactor that submitted a job, and the op id to complete on it.
type JobCaller = (Arc<Reactor>, u64);

/// Jobs in flight, by id (for `done` to find the caller).
static JOBS: OnceLock<Mutex<HashMap<u64, JobCaller>>> = OnceLock::new();

fn callbacks() -> &'static Mutex<HashMap<(u64, u64), PendingCallback>> {
    CALLBACKS.get_or_init(|| Mutex::new(HashMap::new()))
}
fn jobs() -> &'static Mutex<HashMap<u64, JobCaller>> {
    JOBS.get_or_init(|| Mutex::new(HashMap::new()))
}

/// Create the pool with `n` worker slots (threads are started by main.rs).
pub fn initialize(n: usize) {
    let (shared_tx, shared_rx) = unbounded();
    let pinned = (0..n).map(|_| unbounded()).collect();
    let _ = POOL.set(Pool { shared_tx, shared_rx, pinned, busy: AtomicUsize::new(0), done: AtomicU64::new(0) });
}

/// Calling thread: submit a job; the returned op id completes with `Outcome::Blob(result)`.
/// Everything refusable is refused before an op is reserved — reserving first leaked the entry in
/// `JOBS` and left `Reactor::inflight` permanently raised on every rejected submit, which also
/// costs `poll()` its empty-reactor fast path for the rest of the thread's life.
pub fn submit(caller: Arc<Reactor>, func: String, args: Bytes, affinity: Option<usize>) -> Result<u64, &'static str> {
    let pool = POOL.get().ok_or("no offload pool (start ignis with --offload N)")?;
    let sender = match affinity {
        Some(worker) if worker < pool.pinned.len() => &pool.pinned[worker].0,
        Some(_) => return Err("no such offload worker"),
        None => &pool.shared_tx,
    };
    let op = caller.reserve_op();
    let id = NEXT.fetch_add(1, Ordering::Relaxed);
    jobs().lock_unpoisoned().insert(id, (caller.clone(), op));
    if sender.send(Job { id, func, args }).is_err() {
        jobs().lock_unpoisoned().remove(&id);
        caller.complete(op, Outcome::Failed("offload worker gone".into()));
        return Err("worker gone");
    }
    Ok(op)
}

/// Worker thread: block until a job is available (shared queue or this worker's pinned queue).
/// A job with id 0 is the shutdown poison: the worker loop ends and the thread detaches from PHP.
pub fn next(worker: usize) -> Option<Job> {
    let pool = POOL.get()?;
    let mine = &pool.pinned.get(worker)?.1;
    let job = crossbeam_channel::select! {
        recv(mine) -> j => j.ok()?,
        recv(pool.shared_rx) -> j => j.ok()?,
    };
    if job.id == 0 {
        return None;
    }
    pool.busy.fetch_add(1, Ordering::Relaxed);
    Some(job)
}

/// Main thread, before php_embed_shutdown: stop every worker (they must leave their PHP request
/// before the engine goes away, or the heap is torn down under them).
pub fn shutdown() {
    if let Some(pool) = POOL.get() {
        for (tx, _) in &pool.pinned {
            let _ = tx.send(Job { id: 0, func: String::new(), args: Bytes::new() });
        }
    }
}

/// Worker thread: deliver the serialized result to the calling fiber.
pub fn done(job_id: u64, result: Bytes) -> bool {
    let Some((caller, op)) = jobs().lock_unpoisoned().remove(&job_id) else { return false };
    if let Some(pool) = POOL.get() {
        pool.busy.fetch_sub(1, Ordering::Relaxed);
        pool.done.fetch_add(1, Ordering::Relaxed);
    }
    caller.complete(op, Outcome::Blob(Some(result)));
    true
}

/// Worker thread: ask the calling thread to run callback `cb` with `args`; blocks for the answer.
pub fn callback(job_id: u64, cb: u64, args: Bytes) -> Result<Bytes, &'static str> {
    let (caller, _) = jobs().lock_unpoisoned().get(&job_id).cloned().ok_or("unknown job")?;
    let (tx, rx) = bounded(1);
    let seq = NEXT.fetch_add(1, Ordering::Relaxed);
    callbacks().lock_unpoisoned().insert((job_id, seq), PendingCallback { reply: tx });
    caller.inject(Outcome::OffloadCallback { job: job_id, seq, cb, args });
    rx.recv().map_err(|_| "caller gone")
}

/// Calling thread: answer a callback request.
pub fn callback_result(job_id: u64, seq: u64, result: Bytes) -> bool {
    match callbacks().lock_unpoisoned().remove(&(job_id, seq)) {
        Some(p) => p.reply.send(result).is_ok(),
        None => false,
    }
}

pub fn stats() -> (usize, usize, u64, usize) {
    match POOL.get() {
        Some(p) => (p.pinned.len(), p.busy.load(Ordering::Relaxed), p.done.load(Ordering::Relaxed), p.shared_rx.len()),
        None => (0, 0, 0, 0),
    }
}

#[cfg(test)]
mod tests {
    use super::*;
    use std::time::Duration;

    fn caller() -> (tokio::runtime::Runtime, Arc<Reactor>) {
        let runtime = tokio::runtime::Builder::new_multi_thread().worker_threads(1).enable_all().build().unwrap();
        let reactor = Reactor::new(runtime.handle());
        (runtime, reactor)
    }

    /// The whole E16 mechanism with no PHP: submit, take the job off the queue, answer it.
    #[test]
    fn a_job_travels_from_caller_to_worker_and_back() {
        initialize(1);
        let (_runtime, reactor) = caller();

        let op = submit(reactor.clone(), "strtoupper".into(), Bytes::from_static(b"hi"), None).unwrap();
        assert_eq!(stats(), (1, 0, 0, 1), "one worker, nothing busy, one job queued");

        let job = next(0).expect("a queued job");
        assert_eq!(job.func, "strtoupper");
        assert_eq!(job.args, Bytes::from_static(b"hi"));
        assert_eq!(stats(), (1, 1, 0, 0), "the worker is busy and the queue is empty");

        assert!(done(job.id, Bytes::from_static(b"HI")));
        assert!(!done(job.id, Bytes::from_static(b"HI")), "a job is answered once");
        assert_eq!(stats(), (1, 0, 1, 0));

        let completions = reactor.poll(Some(Duration::from_secs(2)));
        assert_eq!(completions.len(), 1);
        assert_eq!(completions[0].id, op);
        let Outcome::Blob(Some(result)) = &completions[0].outcome else { panic!("{:?}", completions[0].outcome) };
        assert_eq!(result, &Bytes::from_static(b"HI"));
    }

    #[test]
    fn submitting_without_a_pool_names_the_flag() {
        let (_runtime, reactor) = caller();
        assert_eq!(submit(reactor, "f".into(), Bytes::new(), None), Err("no offload pool (start ignis with --offload N)"));
    }

    #[test]
    fn affinity_outside_the_worker_range_is_rejected() {
        initialize(2);
        let (_runtime, reactor) = caller();
        assert!(submit(reactor.clone(), "f".into(), Bytes::new(), Some(1)).is_ok());
        let inflight_after_the_accepted_job = reactor.inflight();

        assert_eq!(submit(reactor.clone(), "f".into(), Bytes::new(), Some(2)), Err("no such offload worker"));
        assert_eq!(reactor.inflight(), inflight_after_the_accepted_job, "a refused submit reserved an op");

        assert_eq!(stats().3, 0, "a pinned job never reaches the shared queue");
        assert_eq!(next(1).expect("the pinned job").func, "f");
    }

    #[test]
    fn a_worker_stops_at_the_shutdown_poison() {
        initialize(1);
        shutdown();
        assert!(next(0).is_none());
    }

    #[test]
    fn unknown_ids_are_rejected_rather_than_panicking() {
        initialize(1);
        assert!(!done(404, Bytes::new()));
        assert!(!callback_result(404, 1, Bytes::new()));
        assert!(next(7).is_none(), "there is no worker 7");
    }
}
