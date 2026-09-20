//! The reactor: the only bridge between a PHP thread and the tokio runtime.
//!
//! Design (ADR-0001/0002): a PHP thread never touches tokio. It `submit()`s
//! plain-data ops over a channel and `poll()`s a completion channel with a
//! timeout. HTTP requests arrive on the same completion channel as timer
//! completions, so the PHP loop has exactly one wait point.
use std::collections::HashMap;
use std::sync::atomic::{AtomicBool, AtomicU64, Ordering};
use std::sync::{Arc, Mutex};
use std::time::Duration;

use crate::lock::LockUnpoisoned;
use bytes::Bytes;
use crossbeam_channel::{Receiver, RecvTimeoutError, Sender};
use tokio::sync::{mpsc, oneshot};

/// An I/O request submitted by PHP. Plain data only: no Zend pointers.
pub enum Op {
    /// Complete after `ms` milliseconds (tokio timer wheel).
    /// Sleep for `us` microseconds (sub-ms precision for the usleep hook, E15c).
    Sleep { us: u64 },
    /// Wait until a raw fd (dup'd by the reactor) is readable (`write=false`) or
    /// writable. One-shot. Completes with `Ready` (ADR-0008).
    Watch { fd: i32, write: bool },
    /// Cancel a pending `Watch` or `Sleep` (E15b: a cancelled watch leaked its dup'd fd); both ops complete with `Error("cancelled")`.
    CancelWatch { target: u64 },
    /// Any tokio future producing a PHP-facing outcome (`Json` or `Failed`);
    /// used by feature-gated backends (Temporal, ADR-0013) without touching
    /// this file. Plain data in, plain data out.
    Custom(std::pin::Pin<Box<dyn Future<Output = Outcome> + Send>>),
}

impl std::fmt::Debug for Op {
    fn fmt(&self, f: &mut std::fmt::Formatter<'_>) -> std::fmt::Result {
        match self {
            Op::Sleep { us } => write!(f, "Sleep({us}us)"),
            Op::Watch { fd, write } => write!(f, "Watch({fd},write={write})"),
            Op::CancelWatch { target } => write!(f, "CancelWatch({target})"),
            Op::Custom(_) => write!(f, "Custom"),
        }
    }
}

/// An HTTP request handed to PHP. Plain data only.
#[derive(Debug, Clone)]
pub struct HttpRequest {
    pub method: String,
    /// Path and query exactly as received (`/a/b?x=1`).
    pub uri: String,
    pub headers: Vec<(String, String)>,
    pub body: Bytes,
}

/// An HTTP response produced by PHP.
#[derive(Debug)]
pub struct HttpResponse {
    pub status: u16,
    pub headers: Vec<(String, String)>,
    pub body: ResponseBody,
}

/// What PHP answered with. A whole body is one `ignis_respond()`; a stream is
/// `ignis_respond_start()` followed by chunks, and the front door starts writing before PHP has
/// finished producing (R-STREAM).
#[derive(Debug)]
pub enum ResponseBody {
    Full(Bytes),
    /// Chunks as PHP produces them. The channel is small on purpose: a full channel is what makes
    /// `ignis_respond_chunk()` park its fiber, which is back-pressure from the client's TCP window
    /// all the way into the handler.
    Stream(mpsc::Receiver<Bytes>),
}

/// Result of an op. Plain data only.
#[derive(Debug)]
pub enum Outcome {
    /// Sleep finished (payload: how many µs late the timer fired, for tuning).
    Slept {
        late_us: u64,
    },
    /// A new HTTP request; PHP must eventually call `respond(id, ..)`.
    Request(HttpRequest),
    /// The watched fd is ready.
    Ready,
    /// The client of request `id` went away (ADR-0009); `dropped_at` is when hyper dropped it.
    Cancelled {
        dropped_at: std::time::Instant,
    },
    /// PHP-facing result of a `Custom` op: a JSON document (delivered as a string payload).
    Json(String),
    /// PHP-facing failure of a `Custom` op: `['kind' => 'error', 'message' => ..]`.
    Failed(String),
    /// PHP-facing binary result of a `Custom` op (E10 gRPC): a string, or null for end-of-stream.
    Blob(Option<Bytes>),
    /// E16: an offload worker asks this thread to run callback `cb` of job `job` with serialized `args`.
    OffloadCallback {
        job: u64,
        seq: u64,
        cb: u64,
        args: Bytes,
    },
    Error(String),
}

/// One message of a gRPC response stream (E10): bytes, or a terminal `(code, message)` status.
pub type GrpcMsg = Result<Bytes, (i32, String)>;

/// How an HTTP failure status reads as a gRPC status, for the refusals the scheduler issues before
/// it knows the transport. The three rows are the ones `Ignis\Loop` can actually produce; anything
/// else a caller invents is `UNKNOWN`, which is what a gRPC client shows for a status it cannot
/// place, rather than a guess dressed up as a mapping.
fn grpc_refusal(status: u16) -> (i32, String) {
    match status {
        503 => (14, "unavailable: the server is at its request budget".to_string()),
        504 => (4, "deadline exceeded".to_string()),
        500 => (13, "internal".to_string()),
        other => (2, format!("the server refused the call with HTTP {other}")),
    }
}

#[derive(Debug)]
pub struct Completion {
    pub id: u64,
    pub outcome: Outcome,
}

/// Handle owned by one PHP thread (plus clones on tokio for producing events).
/// What a request id still owes, and the only three shapes it can take.
///
/// `respond_start` is a *transition* from `Whole` to `Streaming` rather than a delete from one map
/// and an insert into another, so the invariant "an id is in exactly one state" is expressed rather
/// than remembered.
///
/// The payloads stay different on purpose: gRPC's terminal value carries trailers HTTP has no
/// analogue for, and its channel is unbounded while the HTTP stream's bound *is* the back-pressure.
enum Answer {
    /// A whole-body response PHP has not sent yet.
    Whole(oneshot::Sender<HttpResponse>),
    /// R-STREAM: the headers went out, the body is still being written. Dropping the sender ends it.
    Streaming(mpsc::Sender<Bytes>),
    /// A gRPC response stream PHP is still filling (E10, ADR-0014).
    Grpc(mpsc::UnboundedSender<GrpcMsg>),
}

pub struct Reactor {
    next_id: AtomicU64,
    /// Ops submitted or requests delivered that PHP has not yet consumed via poll.
    inflight: AtomicU64,
    /// Number of listening servers: while > 0, `poll` blocks even with nothing in flight.
    servers: AtomicU64,
    to_tokio: mpsc::UnboundedSender<(u64, Op)>,
    done_tx: Sender<Completion>,
    from_tokio: Receiver<Completion>,
    /// Everything this thread still owes a client, keyed by request id. One map rather than three,
    /// because an id is in exactly one of `Answer`'s states and three maps could not say so --
    /// which is how `pending_requests` came to count two of them and let a graceful shutdown
    /// truncate a live download (V-75).
    answers: Mutex<HashMap<u64, Answer>>,
    /// Microseconds (monotonic, since reactor creation) of the last `poll` by the PHP thread.
    last_active_us: AtomicU64,
    created: std::time::Instant,
    /// Microseconds `poll` spins on `try_recv` before sleeping the thread (H30, `IGNIS_POLL_SPIN_US`).
    /// 0 = off. Read once here so the hot path never touches the environment.
    spin_us: u64,
    /// What this thread's PHP loop publishes about itself for `/_ignis/metrics` (M4-4). Per
    /// reactor, not global: one loop per thread, and a single set of counters would be
    /// last-writer-wins.
    pub published: crate::metrics::Published,
}

/// A regular file cannot be registered with epoll, which refuses it with EPERM. That is not a
/// failure: a regular file is always ready, which is exactly what `select()` reports for one.
fn epoll_refused_a_regular_file(error: &std::io::Error) -> bool {
    error.raw_os_error() == Some(libc::EPERM)
}

async fn watch_fd(fd: i32, write: bool) -> Outcome {
    use std::os::fd::FromRawFd;
    use tokio::io::Interest;
    // SAFETY: dup(2) takes an int and validates it itself; a closed or invalid fd returns -1.
    let dup = unsafe { libc::dup(fd) };
    if dup < 0 {
        return Outcome::Error(format!("dup({fd}) failed: {}", std::io::Error::last_os_error()));
    }
    // SAFETY: `dup` is a descriptor this call just created and has not handed to anyone, so OwnedFd
    // is its only owner and closes it exactly once. The caller's `fd` is untouched.
    let owned = unsafe { std::os::fd::OwnedFd::from_raw_fd(dup) };
    let interest = if write { Interest::WRITABLE } else { Interest::READABLE };
    let afd = match tokio::io::unix::AsyncFd::with_interest(owned, interest) {
        Ok(a) => a,
        Err(e) if epoll_refused_a_regular_file(&e) => return Outcome::Ready,
        Err(e) => return Outcome::Error(format!("AsyncFd({fd}): {e}")),
    };
    let r = if write { afd.writable().await.map(|mut g| g.retain_ready()) } else { afd.readable().await.map(|mut g| g.retain_ready()) };
    match r {
        Ok(()) => Outcome::Ready,
        Err(e) => Outcome::Error(format!("watch({fd}): {e}")),
    }
}

/// A timer or fd watch that `Op::CancelWatch` can still call off, and the claim that decides which
/// of the two announces it.
///
/// `AbortHandle::abort()` stops a task only at its next await point, so a task that has already
/// produced its outcome runs on and sends it — a cancel that arrived in that window announced the
/// same op a second time. Two completions for one submission drove `Reactor::inflight` below zero,
/// where it wrapped: `poll()` then never saw an empty reactor again, so an idle thread blocked for
/// ever and `ignis_inflight()` never reached the zero a graceful drain waits for. Whoever wins the
/// swap sends; the loser stays quiet.
struct CancellableTask {
    abort: tokio::task::AbortHandle,
    completed: Arc<AtomicBool>,
}

impl Reactor {
    /// Spawns the dispatcher task on `rt` and returns the PHP-side handle.
    pub fn new(rt: &tokio::runtime::Handle) -> Arc<Reactor> {
        let (to_tokio, mut rx) = mpsc::unbounded_channel::<(u64, Op)>();
        let (done_tx, from_tokio) = crossbeam_channel::unbounded::<Completion>();
        let done_for_task = done_tx.clone();
        rt.spawn(async move {
            let cancellable_tasks_by_op: Arc<Mutex<HashMap<u64, CancellableTask>>> = Arc::new(Mutex::new(HashMap::new()));
            while let Some((id, op)) = rx.recv().await {
                let done_tx: Sender<Completion> = done_for_task.clone();
                match op {
                    // `Ignis\sleep(0)` is a yield to the loop: completed here, with no timer task and
                    // no cancel bookkeeping, because E2' measures the pooled-fiber round trip with it.
                    Op::Sleep { us: 0 } => {
                        let _ = done_tx.send(Completion { id, outcome: Outcome::Slept { late_us: 0 } });
                    }
                    Op::Sleep { us } => {
                        let deadline = tokio::time::Instant::now() + Duration::from_micros(us);
                        // Cancellable like a watch (E6'': a stream_select timeout that lost the race
                        // must not keep the loop alive until it lapses).
                        let cancellable_tasks_by_op2 = cancellable_tasks_by_op.clone();
                        let completed = Arc::new(AtomicBool::new(false));
                        let completed_in_task = completed.clone();
                        let handle = tokio::spawn(async move {
                            tokio::time::sleep_until(deadline).await;
                            let late_us = deadline.elapsed().as_micros() as u64;
                            cancellable_tasks_by_op2.lock_unpoisoned().remove(&id);
                            if !completed_in_task.swap(true, Ordering::AcqRel) {
                                let _ = done_tx.send(Completion { id, outcome: Outcome::Slept { late_us } });
                            }
                        });
                        cancellable_tasks_by_op.lock_unpoisoned().insert(id, CancellableTask { abort: handle.abort_handle(), completed });
                    }
                    Op::Custom(fut) => {
                        tokio::spawn(async move {
                            let outcome = fut.await;
                            let _ = done_tx.send(Completion { id, outcome });
                        });
                    }
                    Op::Watch { fd, write } => {
                        let cancellable_tasks_by_op2 = cancellable_tasks_by_op.clone();
                        let completed = Arc::new(AtomicBool::new(false));
                        let completed_in_task = completed.clone();
                        let handle = tokio::spawn(async move {
                            let outcome = watch_fd(fd, write).await;
                            cancellable_tasks_by_op2.lock_unpoisoned().remove(&id);
                            if !completed_in_task.swap(true, Ordering::AcqRel) {
                                let _ = done_tx.send(Completion { id, outcome });
                            }
                        });
                        cancellable_tasks_by_op.lock_unpoisoned().insert(id, CancellableTask { abort: handle.abort_handle(), completed });
                    }
                    Op::CancelWatch { target } => {
                        if let Some(task) = cancellable_tasks_by_op.lock_unpoisoned().remove(&target) {
                            task.abort.abort(); // drops the AsyncFd → closes the dup'd fd
                            if !task.completed.swap(true, Ordering::AcqRel) {
                                let _ = done_tx.send(Completion { id: target, outcome: Outcome::Error("cancelled".into()) });
                            }
                        }
                        let _ = done_tx.send(Completion { id, outcome: Outcome::Error("cancelled".into()) });
                    }
                }
            }
        });
        Arc::new(Reactor {
            next_id: AtomicU64::new(1),
            inflight: AtomicU64::new(0),
            servers: AtomicU64::new(0),
            to_tokio,
            done_tx,
            from_tokio,
            answers: Mutex::new(HashMap::new()),
            last_active_us: AtomicU64::new(0),
            created: std::time::Instant::now(),
            spin_us: std::env::var("IGNIS_POLL_SPIN_US").ok().and_then(|v| v.parse().ok()).unwrap_or(0),
            published: crate::metrics::Published::default(),
        })
    }

    /// Submit an op; returns its id. Never blocks. PHP-thread side.
    /// A send error means the runtime is shutting down: the op is dropped and `poll` simply never
    /// sees it. Logged rather than returned, because there is no caller left to tell.
    pub fn submit(&self, op: Op) -> u64 {
        let id = self.next_id.fetch_add(1, Ordering::Relaxed);
        self.inflight.fetch_add(1, Ordering::Relaxed);

        if self.to_tokio.send((id, op)).is_err() {
            tracing::error!(id, "reactor dispatcher is gone; op dropped");
            self.inflight.fetch_sub(1, Ordering::Relaxed);
        }
        id
    }

    /// Reserve a completion id that another thread will complete later (`complete`); counts as in flight.
    pub fn reserve_op(&self) -> u64 {
        let id = self.next_id.fetch_add(1, Ordering::Relaxed);
        self.inflight.fetch_add(1, Ordering::Relaxed);
        id
    }

    /// Any thread: complete a reserved op.
    pub fn complete(&self, id: u64, outcome: Outcome) {
        let _ = self.done_tx.send(Completion { id, outcome });
    }

    /// Any thread: inject a completion with a fresh id (nobody waits on it by id; PHP routes it by kind).
    pub fn inject(&self, outcome: Outcome) -> u64 {
        let id = self.reserve_op();
        self.complete(id, outcome);
        id
    }

    /// Tokio side: deliver an HTTP request to PHP and get a channel for the answer.
    #[cfg(test)]
    pub fn deliver_request(&self, req: HttpRequest) -> oneshot::Receiver<HttpResponse> {
        self.deliver_request_with_id(req).1
    }

    /// Deliver an HTTP request, returning the request id (needed for cancel-on-drop, ADR-0009).
    /// A send error here means the PHP thread is gone; the responder is dropped, which is what
    /// turns the connection into a 500.
    pub fn deliver_request_with_id(&self, req: HttpRequest) -> (u64, oneshot::Receiver<HttpResponse>) {
        let id = self.next_id.fetch_add(1, Ordering::Relaxed);
        let (tx, rx) = oneshot::channel();
        self.answers.lock_unpoisoned().insert(id, Answer::Whole(tx));
        self.inflight.fetch_add(1, Ordering::Relaxed);
        if self.done_tx.send(Completion { id, outcome: Outcome::Request(req) }).is_err() {
            self.answers.lock_unpoisoned().remove(&id);
        }
        (id, rx)
    }

    /// Tokio side (E10): deliver a gRPC request; PHP fills the returned stream via `stream_send`/`stream_end`.
    pub fn deliver_stream_request(&self, req: HttpRequest) -> (u64, mpsc::UnboundedReceiver<GrpcMsg>) {
        let id = self.next_id.fetch_add(1, Ordering::Relaxed);
        let (tx, rx) = mpsc::unbounded_channel();
        self.answers.lock_unpoisoned().insert(id, Answer::Grpc(tx));
        self.inflight.fetch_add(1, Ordering::Relaxed);
        if self.done_tx.send(Completion { id, outcome: Outcome::Request(req) }).is_err() {
            self.answers.lock_unpoisoned().remove(&id);
        }
        (id, rx)
    }

    /// PHP-thread side (E10): one response message. False if the stream is unknown or the client is gone.
    pub fn stream_send(&self, id: u64, msg: Bytes) -> bool {
        match self.answers.lock_unpoisoned().get(&id) {
            Some(Answer::Grpc(tx)) => tx.send(Ok(msg)).is_ok(),
            _ => false,
        }
    }

    /// Removes the answer for `id` only if it is in the state the caller expects.
    ///
    /// Checking before removing is the whole point: a `respond()` on a streaming id, or a
    /// `stream_end()` on a whole-body one, must be refused -- not silently destroy an answer the
    /// caller was not entitled to. The first version of this map did remove first and cost a test.
    fn take_answer(&self, id: u64, expected: fn(&Answer) -> bool) -> Option<Answer> {
        let mut answers = self.answers.lock_unpoisoned();
        if answers.get(&id).is_some_and(expected) { answers.remove(&id) } else { None }
    }

    /// PHP-thread side (E10): finish the stream with a gRPC status (0 = OK).
    pub fn stream_end(&self, id: u64, code: i32, message: String) -> bool {
        match self.take_answer(id, |answer| matches!(answer, Answer::Grpc(_))) {
            Some(Answer::Grpc(tx)) => {
                if code != 0 {
                    let _ = tx.send(Err((code, message)));
                }
                true
            }
            _ => false,
        }
    }

    /// Tokio side: the response future for `id` was dropped before PHP answered.
    ///
    /// One remove covers every state, which is the point of the single map: a new kind of answer
    /// cannot be forgotten here the way `stream_out` was forgotten in `pending_requests` (V-75).
    ///
    /// The loop takes delivery first, exactly as a PHP thread would: the cancellation is a second
    /// completion on the same id, not a replacement for the first.
    pub fn cancel_request(&self, id: u64) {
        let known = self.answers.lock_unpoisoned().remove(&id).is_some();
        if known {
            self.inflight.fetch_add(1, Ordering::Relaxed);
            let _ = self.done_tx.send(Completion { id, outcome: Outcome::Cancelled { dropped_at: std::time::Instant::now() } });
        }
    }

    /// PHP-thread side: answer request `id`. Returns false if unknown/already answered.
    ///
    /// A whole-body answer on an id that has already started streaming is refused: that id's old
    /// state is gone, and the two shapes cannot both be the answer.
    ///
    /// A **failure** status on a gRPC id is the exception, and it is not a courtesy. The scheduler
    /// refuses a request before it knows what transport it arrived on -- admission control answers
    /// `503`, a deadline answers `504`, an unanswerable request answers `500` -- and a gRPC call is
    /// delivered here as `Answer::Grpc`. Refusing those outright, which is what this did, left the
    /// stream open with nobody owing it trailers: measured on `examples/grpc_server.php` at
    /// `IGNIS_FIBER_BUDGET=1 IGNIS_QUEUE_DEPTH=1`, three of five concurrent calls hung for ever and
    /// the client timed out with no answer of any kind (V-107). The transport knows what a refusal
    /// looks like on its own wire, and it is the only layer that does.
    pub fn respond(&self, id: u64, resp: HttpResponse) -> bool {
        if resp.status >= 400 && self.is_grpc(id) {
            let (code, message) = grpc_refusal(resp.status);
            return self.stream_end(id, code, message);
        }
        match self.take_answer(id, |answer| matches!(answer, Answer::Whole(_))) {
            Some(Answer::Whole(tx)) => tx.send(resp).is_ok(),
            _ => false,
        }
    }

    fn is_grpc(&self, id: u64) -> bool {
        self.answers.lock_unpoisoned().get(&id).is_some_and(|answer| matches!(answer, Answer::Grpc(_)))
    }

    /// PHP-thread side: begin a streamed answer. The status and headers go out now, the body
    /// follows chunk by chunk. `cap` is the number of chunks that may sit between PHP and the
    /// socket — small, because that queue is the back-pressure.
    pub fn respond_start(&self, id: u64, status: u16, headers: Vec<(String, String)>, cap: usize) -> bool {
        let (tx, rx) = mpsc::channel::<Bytes>(cap.max(1));
        let mut answers = self.answers.lock_unpoisoned();
        match answers.remove(&id) {
            Some(Answer::Whole(responder)) => {
                if responder.send(HttpResponse { status, headers, body: ResponseBody::Stream(rx) }).is_err() {
                    return false; // client already gone, and the id stays removed
                }
                answers.insert(id, Answer::Streaming(tx));
                true
            }
            Some(other) => {
                answers.insert(id, other); // not a whole-body answer; leave it as it was
                false
            }
            None => false,
        }
    }

    /// The sending end of a streamed answer, if one is open.
    pub fn stream_sender(&self, id: u64) -> Option<mpsc::Sender<Bytes>> {
        match self.answers.lock_unpoisoned().get(&id) {
            Some(Answer::Streaming(tx)) => Some(tx.clone()),
            _ => None,
        }
    }

    /// Ends a streamed answer: dropping the last sender closes the body and completes the response.
    pub fn respond_end(&self, id: u64) -> bool {
        self.take_answer(id, |answer| matches!(answer, Answer::Streaming(_))).is_some()
    }

    /// The owning PHP thread is going away (script ended, fatal): every request it has not
    /// answered gets its responder dropped, so hyper answers 500 / tonic answers an error now
    /// instead of holding the connection until the client gives up (E12').
    /// A half-written stream is dropped with the rest: the client sees a truncated body rather than
    /// a connection that never finishes.
    pub fn fail_pending(&self) -> usize {
        self.answers.lock_unpoisoned().drain().count()
    }

    /// Marks the owning PHP thread as alive (watchdog, ADR-0012).
    pub fn touch(&self) {
        self.last_active_us.store(self.created.elapsed().as_micros() as u64, Ordering::Relaxed);
    }

    /// Time since the PHP thread last entered `poll`, or last left it with work (ADR-0012 watchdog).
    pub fn idle_in_php(&self) -> Duration {
        let now = self.created.elapsed().as_micros() as u64;
        Duration::from_micros(now.saturating_sub(self.last_active_us.load(Ordering::Relaxed)))
    }

    /// Requests this thread owes an answer for, of any kind (ADR-0010).
    ///
    /// It is one `len()` because there is one map. When it was three, this counted two of them, so a
    /// streamed response was invisible to dispatch, to `ignis_requests_inflight` and to `drain()` --
    /// which ended a graceful shutdown while a client was still receiving, at 2 of 5 chunks (V-75).
    /// The type is what prevents that now, not the reader remembering a third map.
    pub fn pending_requests(&self) -> usize {
        self.answers.lock_unpoisoned().len()
    }

    pub fn server_started(&self) {
        self.servers.fetch_add(1, Ordering::Relaxed);
    }

    pub fn inflight(&self) -> u64 {
        self.inflight.load(Ordering::Relaxed)
    }

    /// Block up to `timeout` (None = forever) for at least one completion, then
    /// drain everything that is ready. Returns immediately if nothing is in
    /// flight and no server is listening, so a userland loop can never
    /// deadlock on an empty reactor.
    /// The cost of a round trip is a futex wakeup pair, not the work (H30). On this box a bare
    /// two-thread ping-pong is 57 us; the reactor round trip is 93 us at one fiber in flight and
    /// 0.58 us at 128 — the same fixed cost divided by the batch one `poll` drains. Spinning briefly
    /// catches a completion already on its way without sleeping the thread. Off by default, because
    /// an idle thread would burn the spin on every wakeup for nothing.
    fn should_spin_before_sleeping(&self) -> bool {
        self.spin_us > 0 && self.inflight() > 0
    }

    /// The "time in PHP" clock starts when the thread *leaves* the reactor with work, not when it
    /// entered. Touching only at entry made a thread that had slept 3 s in `recv_timeout` count as
    /// stalled for its whole first request: `/stats` read `stalled=1` on an idle server while
    /// `/_ignis/health`, with nothing pending, read 0 (M1, V-38).
    fn start_the_time_in_php_clock(&self) {
        self.touch();
    }

    pub fn poll(&self, timeout: Option<Duration>) -> Vec<Completion> {
        self.touch();
        let mut out = Vec::new();
        if self.inflight() == 0 && self.servers.load(Ordering::Relaxed) == 0 {
            return out;
        }
        let mut first = None;
        if self.should_spin_before_sleeping() {
            let deadline = std::time::Instant::now() + Duration::from_micros(self.spin_us);
            loop {
                match self.from_tokio.try_recv() {
                    Ok(c) => {
                        first = Some(c);
                        break;
                    }
                    Err(_) if std::time::Instant::now() >= deadline => break,
                    Err(_) => std::hint::spin_loop(),
                }
            }
        }
        let first = match first {
            Some(c) => Some(c),
            None => match timeout {
                Some(t) => match self.from_tokio.recv_timeout(t) {
                    Ok(c) => Some(c),
                    Err(RecvTimeoutError::Timeout) | Err(RecvTimeoutError::Disconnected) => None,
                },
                None => self.from_tokio.recv().ok(),
            },
        };
        if let Some(c) = first {
            out.push(c);
            while let Ok(c) = self.from_tokio.try_recv() {
                out.push(c);
            }
            self.inflight.fetch_update(Ordering::Relaxed, Ordering::Relaxed, |n| Some(n.saturating_sub(out.len() as u64))).ok();
            self.start_the_time_in_php_clock();
        }
        out
    }
}

#[cfg(test)]
mod tests {
    use super::*;

    /// A tripwire for the consumers of `Outcome` that no build here compiles.
    ///
    /// `backend/async_core.rs` matches on this enum and is gated behind `cfg(php_async_abi)`, which
    /// needs the true-async engine: no CI job and no developer box builds it. So when `Outcome` grew
    /// from two variants to nine, that file stopped compiling and nothing said so. Adding a variant
    /// breaks this match, here, where everything is compiled -- and the fix is to teach
    /// `async_core::outcome_payload` about it as well.
    fn every_variant_is_accounted_for(outcome: &Outcome) {
        match outcome {
            Outcome::Slept { .. }
            | Outcome::Request(_)
            | Outcome::Ready
            | Outcome::Cancelled { .. }
            | Outcome::Json(_)
            | Outcome::Failed(_)
            | Outcome::Blob(_)
            | Outcome::OffloadCallback { .. }
            | Outcome::Error(_) => {}
        }
    }

    #[test]
    fn a_new_outcome_variant_has_to_be_taught_to_backend_b() {
        every_variant_is_accounted_for(&Outcome::Ready);
    }

    fn rt() -> tokio::runtime::Runtime {
        tokio::runtime::Builder::new_multi_thread().worker_threads(1).enable_all().build().unwrap()
    }

    #[test]
    fn sleep_completes_and_poll_drains() {
        let rt = rt();
        let r = Reactor::new(rt.handle());
        let ids: Vec<u64> = (0..100).map(|_| r.submit(Op::Sleep { us: 20_000 })).collect();
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
        let rt = rt();
        let r = Reactor::new(rt.handle());
        let t0 = std::time::Instant::now();
        assert!(r.poll(None).is_empty());
        assert!(t0.elapsed() < Duration::from_millis(50));
    }

    #[test]
    fn request_round_trip() {
        let rt = rt();
        let r = Reactor::new(rt.handle());
        let rx = r.deliver_request(HttpRequest {
            method: "GET".into(),
            uri: "/x?y=1".into(),
            headers: vec![("host".into(), "h".into())],
            body: Bytes::new(),
        });
        let got = r.poll(Some(Duration::from_secs(1)));
        assert_eq!(got.len(), 1);
        let Outcome::Request(req) = &got[0].outcome else { panic!("not a request") };
        assert_eq!(req.uri, "/x?y=1");
        assert!(r.respond(got[0].id, HttpResponse { status: 204, headers: vec![], body: ResponseBody::Full(Bytes::new()) }));
        assert!(!r.respond(got[0].id, HttpResponse { status: 204, headers: vec![], body: ResponseBody::Full(Bytes::new()) }));
        let resp = rt.block_on(rx).unwrap();
        assert_eq!(resp.status, 204);
    }

    fn a_request() -> HttpRequest {
        HttpRequest { method: "GET".into(), uri: "/".into(), headers: vec![], body: Bytes::new() }
    }

    /// V-75: `pending_requests` counted two of the three maps, so a streamed answer was invisible
    /// to dispatch, to `ignis_requests_inflight` and to `drain()` -- which ended a graceful
    /// shutdown while a client was still receiving, at 2 of 5 chunks.
    #[test]
    fn a_streamed_answer_is_still_a_pending_request() {
        let rt = rt();
        let r = Reactor::new(rt.handle());
        let (id, _rx) = r.deliver_request_with_id(a_request());
        assert_eq!(r.pending_requests(), 1, "delivered but unanswered");
        assert!(r.respond_start(id, 200, vec![], 4));
        assert_eq!(r.pending_requests(), 1, "a stream that has sent headers still owes a body");
        assert!(r.respond_end(id));
        assert_eq!(r.pending_requests(), 0);
    }

    #[test]
    fn cancelling_a_request_completes_it_once_and_forgets_it() {
        let rt = rt();
        let r = Reactor::new(rt.handle());
        let (id, _rx) = r.deliver_request_with_id(a_request());

        let delivered = r.poll(Some(Duration::from_secs(1)));
        assert_eq!(delivered.len(), 1);
        assert!(matches!(delivered[0].outcome, Outcome::Request(_)));

        r.cancel_request(id);
        let got = r.poll(Some(Duration::from_secs(1)));
        assert_eq!(got.len(), 1);
        assert_eq!(got[0].id, id);
        assert!(matches!(got[0].outcome, Outcome::Cancelled { .. }));
        assert_eq!(r.pending_requests(), 0);
        assert!(!r.respond(id, HttpResponse { status: 200, headers: vec![], body: ResponseBody::Full(Bytes::new()) }));
        r.cancel_request(id);
        assert!(r.poll(Some(Duration::from_millis(10))).is_empty(), "an unknown id must not complete again");
    }

    /// The invariant the single map exists to express: an id is in exactly one state, and
    /// `respond_start` moves it rather than adding a second entry.
    #[test]
    fn respond_start_moves_an_id_from_whole_to_streaming() {
        let rt = rt();
        let r = Reactor::new(rt.handle());
        let (id, _rx) = r.deliver_request_with_id(a_request());

        assert!(r.stream_sender(id).is_none(), "not streaming until respond_start");
        assert!(r.respond_start(id, 200, vec![], 4));
        assert_eq!(r.pending_requests(), 1, "moved, not duplicated");
        assert!(r.stream_sender(id).is_some());

        assert!(!r.respond(id, HttpResponse { status: 200, headers: vec![], body: ResponseBody::Full(Bytes::new()) }));
        assert!(!r.stream_send(id, Bytes::from_static(b"x")), "nor is it a gRPC stream");

        assert!(r.respond_end(id));
        assert_eq!(r.pending_requests(), 0);
    }

    #[test]
    fn fail_pending_drains_every_kind_of_owed_answer() {
        let rt = rt();
        let r = Reactor::new(rt.handle());
        let (whole, _a) = r.deliver_request_with_id(a_request());
        let (streaming, _b) = r.deliver_request_with_id(a_request());
        let (_grpc, _c) = r.deliver_stream_request(a_request());
        assert!(r.respond_start(streaming, 200, vec![], 4));
        assert_eq!(r.pending_requests(), 3);
        assert_eq!(r.fail_pending(), 3, "one whole-body, one streamed, one gRPC");
        assert_eq!(r.pending_requests(), 0);
        assert!(!r.respond(whole, HttpResponse { status: 200, headers: vec![], body: ResponseBody::Full(Bytes::new()) }));
    }

    /// A refusal the scheduler issues before it knows the transport must still end a gRPC call.
    /// `Ignis\Loop` answers admission-control rejection with HTTP 503 and does not look at the
    /// return value, so a refused `respond()` was a stream nobody would ever close: three of five
    /// concurrent calls hung for ever against a real server (V-107).
    #[test]
    fn a_failure_status_on_a_grpc_id_ends_the_call_instead_of_being_refused() {
        let rt = rt();
        let r = Reactor::new(rt.handle());
        let (id, mut messages) = r.deliver_stream_request(a_request());

        let answered = r.respond(id, HttpResponse { status: 503, headers: vec![], body: ResponseBody::Full(Bytes::new()) });

        assert!(answered, "the call is answered, not refused");
        match messages.try_recv() {
            Ok(Err((code, message))) => {
                assert_eq!(code, 14, "503 is UNAVAILABLE on this wire");
                assert!(message.contains("budget"), "and says why: {message}");
            }
            other => panic!("expected a gRPC status, got {other:?}"),
        }
        assert!(
            !r.respond(id, HttpResponse { status: 503, headers: vec![], body: ResponseBody::Full(Bytes::new()) }),
            "and the id is gone, so a second answer is refused"
        );
    }

    /// The exception is for refusals only. A success on a gRPC id is a caller using the wrong door
    /// -- the router answers a gRPC call through `stream_send`/`stream_end` and returns null -- and
    /// turning that into an empty OK would trade a loud nothing for a quiet wrong answer.
    #[test]
    fn a_success_status_on_a_grpc_id_is_still_refused() {
        let rt = rt();
        let r = Reactor::new(rt.handle());
        let (id, _messages) = r.deliver_stream_request(a_request());

        assert!(!r.respond(id, HttpResponse { status: 200, headers: vec![], body: ResponseBody::Full(Bytes::new()) }));
    }

    /// E15b: a cancelled watch leaked the descriptor the reactor had dup'd. Both ops must complete.
    /// A cancel that arrives after its target already completed must add its own completion and
    /// nothing else. Two completions for one submission drove `inflight` below zero, and an
    /// unsigned counter that wraps there never reads empty again -- an idle `poll(-1)` would block
    /// for ever and a graceful drain would never see the zero it waits for.
    #[test]
    fn cancelling_an_op_that_already_finished_counts_once() {
        let rt = rt();
        let r = Reactor::new(rt.handle());
        let sleep = r.submit(Op::Sleep { us: 1_000 });
        let mut seen = Vec::new();
        while seen.is_empty() {
            seen.extend(r.poll(Some(Duration::from_secs(2))).into_iter().map(|c| c.id));
        }
        assert_eq!(seen, vec![sleep]);
        assert_eq!(r.inflight(), 0);

        let cancel = r.submit(Op::CancelWatch { target: sleep });
        let mut after = Vec::new();
        while after.is_empty() {
            after.extend(r.poll(Some(Duration::from_secs(2))).into_iter().map(|c| c.id));
        }
        assert_eq!(after, vec![cancel], "the finished op must not complete a second time");
        assert_eq!(r.inflight(), 0);
        assert!(r.poll(Some(Duration::from_millis(50))).is_empty());
    }

    #[test]
    fn a_cancelled_watch_completes_both_ops() {
        use std::os::fd::AsRawFd;
        let rt = rt();
        let r = Reactor::new(rt.handle());
        let listener = std::net::TcpListener::bind("127.0.0.1:0").unwrap();
        let watch = r.submit(Op::Watch { fd: listener.as_raw_fd(), write: false });
        let cancel = r.submit(Op::CancelWatch { target: watch });
        let mut seen = Vec::new();
        while seen.len() < 2 {
            seen.extend(r.poll(Some(Duration::from_secs(2))).into_iter().map(|c| c.id));
        }
        seen.sort();
        let mut expected = vec![watch, cancel];
        expected.sort();
        assert_eq!(seen, expected);
        assert_eq!(r.inflight(), 0);
    }

    #[test]
    fn stream_calls_for_an_unknown_id_are_refused() {
        let rt = rt();
        let r = Reactor::new(rt.handle());
        assert!(!r.stream_send(4242, Bytes::from_static(b"x")));
        assert!(!r.stream_end(4242, 0, String::new()));
        assert!(!r.respond_start(4242, 200, vec![], 4));
        assert!(!r.respond_end(4242));
        assert!(r.stream_sender(4242).is_none());
    }

    /// H28: a zero sleep is answered inline rather than going through the timer wheel.
    #[test]
    fn a_zero_sleep_still_completes() {
        let rt = rt();
        let r = Reactor::new(rt.handle());
        let id = r.submit(Op::Sleep { us: 0 });
        let got = r.poll(Some(Duration::from_secs(1)));
        assert_eq!(got.len(), 1);
        assert_eq!(got[0].id, id);
        assert!(matches!(got[0].outcome, Outcome::Slept { .. }));
    }

    #[test]
    fn injected_outcomes_get_their_own_ids() {
        let rt = rt();
        let r = Reactor::new(rt.handle());
        let first = r.inject(Outcome::Ready);
        let second = r.inject(Outcome::Ready);
        assert_ne!(first, second);
        let mut seen = Vec::new();
        while seen.len() < 2 {
            seen.extend(r.poll(Some(Duration::from_secs(1))).into_iter().map(|c| c.id));
        }
        seen.sort();
        let mut expected = vec![first, second];
        expected.sort();
        assert_eq!(seen, expected);
    }
}
