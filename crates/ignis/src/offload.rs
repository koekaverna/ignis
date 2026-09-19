//! E16 (ADR-0016): offload pool — synchronous PHP worker threads with their own TSRM context.
//!
//! A job is `(function name, serialized args)`; it runs on a worker and answers with serialized
//! bytes. Nothing but bytes and integer ids crosses between PHP threads. A worker may call back
//! into the calling thread (`callback`): the request is injected into the caller's reactor as a
//! completion, PHP runs the closure there, and the worker blocks on the answer.

use std::collections::HashMap;
use std::sync::atomic::{AtomicU64, AtomicUsize, Ordering};
use std::sync::{Arc, Mutex, OnceLock};
use std::time::Duration;

use bytes::Bytes;
use crossbeam_channel::{Receiver, Sender, TrySendError, bounded};

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

/// The reactor that submitted a job, the op id to complete on it, and which worker (if any) is
/// currently running it — `None` until `next()` hands the job to a worker.
struct RunningJob {
    caller: Arc<Reactor>,
    op: u64,
    worker: Option<usize>,
}

static POOL: OnceLock<Pool> = OnceLock::new();
static NEXT: AtomicU64 = AtomicU64::new(1);
/// (job id, callback seq) → worker waiting for the caller's answer.
static CALLBACKS: OnceLock<Mutex<HashMap<(u64, u64), PendingCallback>>> = OnceLock::new();

/// Jobs in flight, by id (for `done` to find the caller, and for `worker_gone` to find what a dead
/// worker was holding).
static JOBS: OnceLock<Mutex<HashMap<u64, RunningJob>>> = OnceLock::new();

/// Depth of each offload queue (shared and per-worker pinned). Headroom over the heaviest measured
/// burst (E16's 100-worker arm submits 100 concurrent jobs), not a tuned ceiling.
/// ponytail: revisit with a real number if `submit` ever refuses "queue is full" in practice.
const QUEUE_CAPACITY: usize = 4096;

/// How long a worker waits for the calling thread to answer a callback before giving up. The
/// calling thread can die (fatal, drained shutdown) between `inject` and its answer; unbounded
/// meant the worker — and the job it is running — blocked forever.
const CALLBACK_TIMEOUT: Duration = Duration::from_secs(30);

fn callbacks() -> &'static Mutex<HashMap<(u64, u64), PendingCallback>> {
    CALLBACKS.get_or_init(|| Mutex::new(HashMap::new()))
}
fn jobs() -> &'static Mutex<HashMap<u64, RunningJob>> {
    JOBS.get_or_init(|| Mutex::new(HashMap::new()))
}

/// Create the pool with `n` worker slots (threads are started by main.rs).
///
/// A second call is a configuration mistake, not a reason to start a second pool: the existing one
/// keeps serving with its original width, and this says so rather than pretending `n` took effect.
pub fn initialize(n: usize) {
    let (shared_tx, shared_rx) = bounded(QUEUE_CAPACITY);
    let pinned = (0..n).map(|_| bounded(QUEUE_CAPACITY)).collect();
    let pool = Pool { shared_tx, shared_rx, pinned, busy: AtomicUsize::new(0), done: AtomicU64::new(0) };
    if POOL.set(pool).is_err() {
        let existing_width = POOL.get().map_or(0, |pool| pool.pinned.len());
        tracing::warn!(requested = n, existing_width, "offload pool already initialized; --offload N ignored");
    }
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
    jobs().lock_unpoisoned().insert(id, RunningJob { caller: caller.clone(), op, worker: None });
    if let Err(error) = sender.try_send(Job { id, func, args }) {
        jobs().lock_unpoisoned().remove(&id);
        let reason = match error {
            TrySendError::Full(_) => "offload queue is full",
            TrySendError::Disconnected(_) => "offload worker gone",
        };
        caller.complete(op, Outcome::Failed(reason.into()));
        return Err(reason);
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
    if let Some(running) = jobs().lock_unpoisoned().get_mut(&job.id) {
        running.worker = Some(worker);
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
    let Some(running) = jobs().lock_unpoisoned().remove(&job_id) else { return false };
    if let Some(pool) = POOL.get() {
        pool.busy.fetch_sub(1, Ordering::Relaxed);
        pool.done.fetch_add(1, Ordering::Relaxed);
    }
    running.caller.complete(running.op, Outcome::Blob(Some(result)));
    true
}

/// A worker's thread has ended while `JOBS` still shows it running one — a PHP fatal unwound the
/// whole worker loop before its own `try`/`catch` (`WorkerRuntime::run`) or `done()` ever ran. Fails
/// the job's caller the same way a rejected `submit` already does, and releases the op `poll()`
/// would otherwise wait on forever. A no-op if the worker held nothing (the ordinary case: it left
/// the loop only after its last job was already `done()`).
///
/// Returns how many jobs were failed — 0 or 1, since a worker runs one job at a time.
pub fn worker_gone(worker: usize) -> usize {
    let stuck: Vec<u64> =
        jobs().lock_unpoisoned().iter().filter(|(_, running)| running.worker == Some(worker)).map(|(&id, _)| id).collect();
    for id in &stuck {
        if let Some(running) = jobs().lock_unpoisoned().remove(id) {
            running.caller.complete(running.op, Outcome::Failed("offload worker gone".into()));
        }
    }
    stuck.len()
}

/// Worker thread: ask the calling thread to run callback `cb` with `args`; blocks for the answer.
pub fn callback(job_id: u64, cb: u64, args: Bytes) -> Result<Bytes, &'static str> {
    callback_with_timeout(job_id, cb, args, CALLBACK_TIMEOUT)
}

/// `callback`'s real work, with the wait bounded by `timeout` instead of the production constant —
/// a test gets to exercise the timeout without waiting out the real one.
fn callback_with_timeout(job_id: u64, cb: u64, args: Bytes, timeout: Duration) -> Result<Bytes, &'static str> {
    let caller = jobs().lock_unpoisoned().get(&job_id).map(|running| running.caller.clone()).ok_or("unknown job")?;
    let (tx, rx) = bounded(1);
    let seq = NEXT.fetch_add(1, Ordering::Relaxed);
    callbacks().lock_unpoisoned().insert((job_id, seq), PendingCallback { reply: tx });
    caller.inject(Outcome::OffloadCallback { job: job_id, seq, cb, args });
    let result = rx.recv_timeout(timeout);
    callbacks().lock_unpoisoned().remove(&(job_id, seq));
    result.map_err(|_| "caller gone")
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

    /// A-LEAKS-RUST (b): a worker that dies mid-job (a PHP fatal unwinding the whole worker loop)
    /// must not leave its job's caller waiting forever with `Reactor::inflight` stuck raised.
    #[test]
    fn a_worker_that_dies_mid_job_fails_its_caller_and_releases_the_op() {
        initialize(1);
        let (_runtime, reactor) = caller();

        let op = submit(reactor.clone(), "slow".into(), Bytes::new(), None).unwrap();
        let job = next(0).expect("the queued job");

        assert_eq!(worker_gone(0), 1, "the worker was holding exactly the job it died with");
        assert!(!done(job.id, Bytes::new()), "the job was already resolved as failed, not merely abandoned");
        assert_eq!(worker_gone(0), 0, "a worker holding nothing fails nothing");

        let completions = reactor.poll(Some(Duration::from_secs(2)));
        assert_eq!(completions.len(), 1);
        assert_eq!(completions[0].id, op);
        let Outcome::Failed(reason) = &completions[0].outcome else { panic!("{:?}", completions[0].outcome) };
        assert_eq!(reason.as_str(), "offload worker gone");
        assert_eq!(reactor.inflight(), 0, "the reserved op was released, not left permanently raised");
    }

    /// A-LEAKS-RUST (c): a full queue is refused explicitly, through the same reserve-then-release
    /// path a disconnected worker already used, rather than blocking the submitting thread.
    #[test]
    fn a_full_queue_is_refused_explicitly_and_releases_the_op() {
        initialize(1);
        let (_runtime, reactor) = caller();
        for _ in 0..QUEUE_CAPACITY {
            submit(reactor.clone(), "f".into(), Bytes::new(), None).unwrap();
        }

        assert_eq!(submit(reactor.clone(), "f".into(), Bytes::new(), None), Err("offload queue is full"));

        let completions = reactor.poll(Some(Duration::from_secs(2)));
        assert_eq!(completions.len(), 1, "only the refusal completed; the accepted jobs are still queued");
        let Outcome::Failed(reason) = &completions[0].outcome else { panic!("{:?}", completions[0].outcome) };
        assert_eq!(reason.as_str(), "offload queue is full", "distinct from a disconnected worker");
        assert_eq!(reactor.inflight(), QUEUE_CAPACITY as u64, "the accepted jobs' ops are still reserved");
    }

    /// A-LEAKS-RUST (b): `CALLBACKS` leaks the same way `JOBS` does when the calling thread dies
    /// before answering; the worker must not block on `rx.recv()` forever.
    #[test]
    fn a_callback_the_caller_never_answers_times_out_and_is_cleaned_up() {
        initialize(1);
        let (_runtime, reactor) = caller();
        let op = submit(reactor.clone(), "f".into(), Bytes::new(), None).unwrap();
        let job = next(0).expect("the queued job");

        let answer = callback_with_timeout(job.id, 1, Bytes::new(), Duration::from_millis(50));
        assert_eq!(answer, Err("caller gone"));
        assert!(callbacks().lock_unpoisoned().is_empty(), "a timed-out callback does not linger in CALLBACKS");

        assert!(done(job.id, Bytes::new()), "the job itself is unaffected by its own callback timing out");

        let completions = reactor.poll(Some(Duration::from_secs(2)));
        assert_eq!(completions.len(), 2, "the injected callback request, and the job's own result");
        assert!(completions.iter().any(|c| matches!(c.outcome, Outcome::OffloadCallback { .. })));
        assert!(completions.iter().any(|c| c.id == op && matches!(c.outcome, Outcome::Blob(_))));
    }
}
