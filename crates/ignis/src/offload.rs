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
/// Jobs in flight, by id (for `done` to find the caller).
static JOBS: OnceLock<Mutex<HashMap<u64, (Arc<Reactor>, u64)>>> = OnceLock::new();

fn callbacks() -> &'static Mutex<HashMap<(u64, u64), PendingCallback>> {
    CALLBACKS.get_or_init(|| Mutex::new(HashMap::new()))
}
fn jobs() -> &'static Mutex<HashMap<u64, (Arc<Reactor>, u64)>> {
    JOBS.get_or_init(|| Mutex::new(HashMap::new()))
}

/// Create the pool with `n` worker slots (threads are started by main.rs).
pub fn init(n: usize) {
    let (shared_tx, shared_rx) = unbounded();
    let pinned = (0..n).map(|_| unbounded()).collect();
    let _ = POOL.set(Pool { shared_tx, shared_rx, pinned, busy: AtomicUsize::new(0), done: AtomicU64::new(0) });
}

/// Calling thread: submit a job; the returned op id completes with `Outcome::Blob(result)`.
pub fn submit(caller: Arc<Reactor>, func: String, args: Bytes, affinity: Option<usize>) -> Result<u64, &'static str> {
    let pool = POOL.get().ok_or("no offload pool (start ignis with --offload N)")?;
    let op = caller.reserve_op();
    let id = NEXT.fetch_add(1, Ordering::Relaxed);
    jobs().lock().unwrap().insert(id, (caller, op));
    let job = Job { id, func, args };
    match affinity {
        Some(w) if w < pool.pinned.len() => pool.pinned[w].0.send(job).map_err(|_| "worker gone")?,
        Some(_) => return Err("no such offload worker"),
        None => pool.shared_tx.send(job).map_err(|_| "pool gone")?,
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
    let Some((caller, op)) = jobs().lock().unwrap().remove(&job_id) else { return false };
    if let Some(pool) = POOL.get() {
        pool.busy.fetch_sub(1, Ordering::Relaxed);
        pool.done.fetch_add(1, Ordering::Relaxed);
    }
    caller.complete(op, Outcome::Blob(Some(result)));
    true
}

/// Worker thread: ask the calling thread to run callback `cb` with `args`; blocks for the answer.
pub fn callback(job_id: u64, cb: u64, args: Bytes) -> Result<Bytes, &'static str> {
    let (caller, _) = jobs().lock().unwrap().get(&job_id).cloned().ok_or("unknown job")?;
    let (tx, rx) = bounded(1);
    let seq = NEXT.fetch_add(1, Ordering::Relaxed);
    callbacks().lock().unwrap().insert((job_id, seq), PendingCallback { reply: tx });
    caller.inject(Outcome::OffloadCallback { job: job_id, seq, cb, args });
    rx.recv().map_err(|_| "caller gone")
}

/// Calling thread: answer a callback request.
pub fn callback_result(job_id: u64, seq: u64, result: Bytes) -> bool {
    match callbacks().lock().unwrap().remove(&(job_id, seq)) {
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
