//! The reactor: the only bridge between a PHP thread and the tokio runtime.
//!
//! Design (ADR-0001/0002): a PHP thread never touches tokio. It `submit()`s
//! plain-data ops over a channel and `poll()`s a completion channel with a
//! timeout. HTTP requests arrive on the same completion channel as timer
//! completions, so the PHP loop has exactly one wait point.
use std::collections::HashMap;
use std::sync::atomic::{AtomicU64, Ordering};
use std::sync::{Arc, Mutex};
use std::time::Duration;

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
    Slept { late_us: u64 },
    /// A new HTTP request; PHP must eventually call `respond(id, ..)`.
    Request(HttpRequest),
    /// The watched fd is ready.
    Ready,
    /// The client of request `id` went away (ADR-0009); `dropped_at` is when hyper dropped it.
    Cancelled { dropped_at: std::time::Instant },
    /// PHP-facing result of a `Custom` op: a JSON document (delivered as a string payload).
    Json(String),
    /// PHP-facing failure of a `Custom` op: `['kind' => 'error', 'message' => ..]`.
    Failed(String),
    /// PHP-facing binary result of a `Custom` op (E10 gRPC): a string, or null for end-of-stream.
    Blob(Option<Bytes>),
    /// E16: an offload worker asks this thread to run callback `cb` of job `job` with serialized `args`.
    OffloadCallback { job: u64, seq: u64, cb: u64, args: Bytes },
    Error(String),
}

/// One message of a gRPC response stream (E10): bytes, or a terminal `(code, message)` status.
pub type GrpcMsg = Result<Bytes, (i32, String)>;

#[derive(Debug)]
pub struct Completion {
    pub id: u64,
    pub outcome: Outcome,
}

/// Handle owned by one PHP thread (plus clones on tokio for producing events).
pub struct Reactor {
    next_id: AtomicU64,
    /// Ops submitted or requests delivered that PHP has not yet consumed via poll.
    inflight: AtomicU64,
    /// Number of listening servers: while > 0, `poll` blocks even with nothing in flight.
    servers: AtomicU64,
    to_tokio: mpsc::UnboundedSender<(u64, Op)>,
    done_tx: Sender<Completion>,
    from_tokio: Receiver<Completion>,
    responders: Mutex<HashMap<u64, oneshot::Sender<HttpResponse>>>,
    /// R-STREAM: for a response PHP is still producing, the end hyper is draining. Present only
    /// between `respond_start` and `respond_end`; dropping the sender is what ends the body.
    stream_out: Mutex<HashMap<u64, mpsc::Sender<Bytes>>>,
    /// gRPC response streams PHP is still filling (E10, ADR-0014).
    streams: Mutex<HashMap<u64, mpsc::UnboundedSender<GrpcMsg>>>,
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

async fn watch_fd(fd: i32, write: bool) -> Outcome {
    use std::os::fd::FromRawFd;
    use tokio::io::Interest;
    // SAFETY: dup() returns a fresh descriptor we own; OwnedFd closes it.
    let dup = unsafe { libc::dup(fd) };
    if dup < 0 {
        return Outcome::Error(format!("dup({fd}) failed: {}", std::io::Error::last_os_error()));
    }
    let owned = unsafe { std::os::fd::OwnedFd::from_raw_fd(dup) };
    let interest = if write { Interest::WRITABLE } else { Interest::READABLE };
    let afd = match tokio::io::unix::AsyncFd::with_interest(owned, interest) {
        Ok(a) => a,
        // epoll refuses regular files (EPERM): they are always ready, which is
        // exactly what select() reports for them.
        Err(e) if e.raw_os_error() == Some(libc::EPERM) => return Outcome::Ready,
        Err(e) => return Outcome::Error(format!("AsyncFd({fd}): {e}")),
    };
    let r = if write { afd.writable().await.map(|mut g| g.retain_ready()) } else { afd.readable().await.map(|mut g| g.retain_ready()) };
    match r {
        Ok(()) => Outcome::Ready,
        Err(e) => Outcome::Error(format!("watch({fd}): {e}")),
    }
}

impl Reactor {
    /// Spawns the dispatcher task on `rt` and returns the PHP-side handle.
    pub fn new(rt: &tokio::runtime::Handle) -> Arc<Reactor> {
        let (to_tokio, mut rx) = mpsc::unbounded_channel::<(u64, Op)>();
        let (done_tx, from_tokio) = crossbeam_channel::unbounded::<Completion>();
        let done_for_task = done_tx.clone();
        rt.spawn(async move {
            // Pending fd watches and sleeps by op id, so a cancel can abort the task (closing the dup'd fd).
            let watches: Arc<Mutex<HashMap<u64, tokio::task::AbortHandle>>> = Arc::new(Mutex::new(HashMap::new()));
            while let Some((id, op)) = rx.recv().await {
                let done_tx: Sender<Completion> = done_for_task.clone();
                match op {
                    // `Ignis\sleep(0)` is a yield to the loop: complete it here, no timer task, no
                    // cancel bookkeeping (E2': the pooled-fiber round trip is measured with it).
                    Op::Sleep { us: 0 } => {
                        let _ = done_tx.send(Completion { id, outcome: Outcome::Slept { late_us: 0 } });
                    }
                    Op::Sleep { us } => {
                        let deadline = tokio::time::Instant::now() + Duration::from_micros(us);
                        // Cancellable like a watch (E6'': a stream_select timeout that lost the race
                        // must not keep the loop alive until it lapses).
                        let watches2 = watches.clone();
                        let handle = tokio::spawn(async move {
                            tokio::time::sleep_until(deadline).await;
                            let late_us = deadline.elapsed().as_micros() as u64;
                            watches2.lock().unwrap().remove(&id);
                            // Receiver dropped => PHP thread is gone; nothing to do.
                            let _ = done_tx.send(Completion { id, outcome: Outcome::Slept { late_us } });
                        });
                        watches.lock().unwrap().insert(id, handle.abort_handle());
                    }
                    Op::Custom(fut) => {
                        tokio::spawn(async move {
                            let outcome = fut.await;
                            let _ = done_tx.send(Completion { id, outcome });
                        });
                    }
                    Op::Watch { fd, write } => {
                        let watches2 = watches.clone();
                        let handle = tokio::spawn(async move {
                            let outcome = watch_fd(fd, write).await;
                            watches2.lock().unwrap().remove(&id);
                            let _ = done_tx.send(Completion { id, outcome });
                        });
                        watches.lock().unwrap().insert(id, handle.abort_handle());
                    }
                    Op::CancelWatch { target } => {
                        if let Some(h) = watches.lock().unwrap().remove(&target) {
                            h.abort(); // drops the AsyncFd → closes the dup'd fd
                            let _ = done_tx.send(Completion { id: target, outcome: Outcome::Error("cancelled".into()) });
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
            responders: Mutex::new(HashMap::new()),
            stream_out: Mutex::new(HashMap::new()),
            streams: Mutex::new(HashMap::new()),
            last_active_us: AtomicU64::new(0),
            created: std::time::Instant::now(),
            spin_us: std::env::var("IGNIS_POLL_SPIN_US").ok().and_then(|v| v.parse().ok()).unwrap_or(0),
            published: crate::metrics::Published::default(),
        })
    }

    /// Submit an op; returns its id. Never blocks. PHP-thread side.
    pub fn submit(&self, op: Op) -> u64 {
        let id = self.next_id.fetch_add(1, Ordering::Relaxed);
        self.inflight.fetch_add(1, Ordering::Relaxed);
        // A send error means the runtime is shutting down; the op is dropped
        // and poll will simply never see it. Logged for visibility.
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
    pub fn deliver_request_with_id(&self, req: HttpRequest) -> (u64, oneshot::Receiver<HttpResponse>) {
        let id = self.next_id.fetch_add(1, Ordering::Relaxed);
        let (tx, rx) = oneshot::channel();
        self.responders.lock().unwrap().insert(id, tx);
        self.inflight.fetch_add(1, Ordering::Relaxed);
        if self.done_tx.send(Completion { id, outcome: Outcome::Request(req) }).is_err() {
            // PHP thread gone: drop the responder so the connection gets a 500.
            self.responders.lock().unwrap().remove(&id);
        }
        (id, rx)
    }

    /// Tokio side (E10): deliver a gRPC request; PHP fills the returned stream via `stream_send`/`stream_end`.
    pub fn deliver_stream_request(&self, req: HttpRequest) -> (u64, mpsc::UnboundedReceiver<GrpcMsg>) {
        let id = self.next_id.fetch_add(1, Ordering::Relaxed);
        let (tx, rx) = mpsc::unbounded_channel();
        self.streams.lock().unwrap().insert(id, tx);
        self.inflight.fetch_add(1, Ordering::Relaxed);
        if self.done_tx.send(Completion { id, outcome: Outcome::Request(req) }).is_err() {
            self.streams.lock().unwrap().remove(&id);
        }
        (id, rx)
    }

    /// PHP-thread side (E10): one response message. False if the stream is unknown or the client is gone.
    pub fn stream_send(&self, id: u64, msg: Bytes) -> bool {
        match self.streams.lock().unwrap().get(&id) {
            Some(tx) => tx.send(Ok(msg)).is_ok(),
            None => false,
        }
    }

    /// PHP-thread side (E10): finish the stream with a gRPC status (0 = OK).
    pub fn stream_end(&self, id: u64, code: i32, message: String) -> bool {
        match self.streams.lock().unwrap().remove(&id) {
            Some(tx) => {
                if code != 0 {
                    let _ = tx.send(Err((code, message)));
                }
                true
            }
            None => false,
        }
    }

    /// Tokio side: the response future for `id` was dropped before PHP answered.
    pub fn cancel_request(&self, id: u64) {
        let known = self.responders.lock().unwrap().remove(&id).is_some()
            || self.streams.lock().unwrap().remove(&id).is_some()
            || self.stream_out.lock().unwrap().remove(&id).is_some();
        if known {
            self.inflight.fetch_add(1, Ordering::Relaxed);
            let _ = self.done_tx.send(Completion { id, outcome: Outcome::Cancelled { dropped_at: std::time::Instant::now() } });
        }
    }

    /// PHP-thread side: answer request `id`. Returns false if unknown/already answered.
    pub fn respond(&self, id: u64, resp: HttpResponse) -> bool {
        match self.responders.lock().unwrap().remove(&id) {
            Some(tx) => tx.send(resp).is_ok(),
            None => false,
        }
    }

    /// PHP-thread side: begin a streamed answer. The status and headers go out now, the body
    /// follows chunk by chunk. `cap` is the number of chunks that may sit between PHP and the
    /// socket — small, because that queue is the back-pressure.
    pub fn respond_start(&self, id: u64, status: u16, headers: Vec<(String, String)>, cap: usize) -> bool {
        let (tx, rx) = mpsc::channel::<Bytes>(cap.max(1));
        match self.responders.lock().unwrap().remove(&id) {
            Some(responder) => {
                if responder.send(HttpResponse { status, headers, body: ResponseBody::Stream(rx) }).is_err() {
                    return false; // client already gone
                }
                self.stream_out.lock().unwrap().insert(id, tx);
                true
            }
            None => false,
        }
    }

    /// The sending end of a streamed answer, if one is open.
    pub fn stream_sender(&self, id: u64) -> Option<mpsc::Sender<Bytes>> {
        self.stream_out.lock().unwrap().get(&id).cloned()
    }

    /// Ends a streamed answer: dropping the last sender closes the body and completes the response.
    pub fn respond_end(&self, id: u64) -> bool {
        self.stream_out.lock().unwrap().remove(&id).is_some()
    }

    /// The owning PHP thread is going away (script ended, fatal): every request it has not
    /// answered gets its responder dropped, so hyper answers 500 / tonic answers an error now
    /// instead of holding the connection until the client gives up (E12').
    pub fn fail_pending(&self) -> usize {
        // A half-written stream is dropped too: the client sees a truncated body rather than a
        // connection that never finishes.
        let dropped = self.responders.lock().unwrap().drain().count()
            + self.streams.lock().unwrap().drain().count()
            + self.stream_out.lock().unwrap().drain().count();
        dropped
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

    /// Requests delivered to this thread's loop and not yet answered (ADR-0010).
    /// Requests this thread owes an answer for. **Every** kind counts: a whole-body response not yet
    /// sent, a gRPC stream, and a streamed HTTP response still being written. Leaving the last one
    /// out made a live stream invisible to the three things that read this — dispatch
    /// (`http::Registry::pick`), `ignis_requests_inflight`, and `drain()`, which then ended a
    /// graceful shutdown while a client was still receiving (measured: 2 of 5 chunks, V-75).
    pub fn pending_requests(&self) -> usize {
        self.responders.lock().unwrap().len() + self.streams.lock().unwrap().len() + self.stream_out.lock().unwrap().len()
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
    pub fn poll(&self, timeout: Option<Duration>) -> Vec<Completion> {
        self.touch();
        let mut out = Vec::new();
        if self.inflight() == 0 && self.servers.load(Ordering::Relaxed) == 0 {
            return out;
        }
        // H30: the cost of a round trip is a futex wakeup pair, not the work. On this box a bare
        // two-thread ping-pong is 57 us, and the reactor round trip is 93 us at one fiber in flight
        // but 0.58 us at 128 — the same fixed cost divided by the batch one `poll` drains. When a
        // completion is already on its way, spinning briefly catches it without sleeping the thread.
        // Off by default because an idle thread would burn the spin every wakeup for nothing.
        let mut first = None;
        if self.spin_us > 0 && self.inflight() > 0 {
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
            self.inflight.fetch_sub(out.len() as u64, Ordering::Relaxed);
            // The "time in PHP" clock starts when the thread LEAVES the reactor with work, not when
            // it entered. Touching only at entry made a thread that had slept 3 s in recv_timeout
            // count as stalled for its whole first request — /stats read stalled=1 on an idle
            // server while /_ignis/health, with no request pending, read 0 (M1, V-38).
            self.touch();
        }
        out
    }
}

#[cfg(test)]
mod tests {
    use super::*;

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
}
