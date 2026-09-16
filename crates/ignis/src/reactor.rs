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
#[derive(Debug)]
pub enum Op {
    /// Complete after `ms` milliseconds (tokio timer wheel).
    Sleep { ms: u64 },
    /// Open a TCP connection (ADR-0007). Completes with `Connected { conn }`.
    Connect { host: String, port: u16 },
    /// Read up to `max` bytes from `conn`. Completes with `Data` (empty = EOF).
    Read { conn: u64, max: usize },
    /// Write all of `data` to `conn`. Completes with `Written`.
    Write { conn: u64, data: Bytes },
    /// Close `conn`. Completes with `Closed`.
    Close { conn: u64 },
    /// Wait until a raw fd (dup'd by the reactor) is readable (`write=false`) or
    /// writable. One-shot. Completes with `Ready` (ADR-0008).
    Watch { fd: i32, write: bool },
}

/// Command to a connection actor (tokio side only).
enum ConnCmd {
    Read { id: u64, max: usize },
    Write { id: u64, data: Bytes },
    Close { id: u64 },
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
    pub body: Bytes,
}

/// Result of an op. Plain data only.
#[derive(Debug)]
pub enum Outcome {
    /// Sleep finished (payload: how many µs late the timer fired, for tuning).
    Slept { late_us: u64 },
    /// A new HTTP request; PHP must eventually call `respond(id, ..)`.
    Request(HttpRequest),
    Connected { conn: u64 },
    /// Bytes read; empty means EOF.
    Data(Bytes),
    Written(usize),
    Closed,
    /// The watched fd is ready.
    Ready,
    /// The client of request `id` went away (ADR-0009); `dropped_at` is when hyper dropped it.
    Cancelled { dropped_at: std::time::Instant },
    Error(String),
}

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
    /// Microseconds (monotonic, since reactor creation) of the last `poll` by the PHP thread.
    last_active_us: AtomicU64,
    created: std::time::Instant,
}

type ConnMap = Arc<Mutex<HashMap<u64, mpsc::UnboundedSender<ConnCmd>>>>;

/// One actor per TCP connection: owns the socket, serves commands in order.
async fn conn_actor(mut stream: tokio::net::TcpStream, mut rx: mpsc::UnboundedReceiver<ConnCmd>, done: Sender<Completion>) {
    use tokio::io::{AsyncReadExt, AsyncWriteExt};
    while let Some(cmd) = rx.recv().await {
        match cmd {
            ConnCmd::Read { id, max } => {
                let mut buf = vec![0u8; max.clamp(1, 1 << 20)];
                let outcome = match stream.read(&mut buf).await {
                    Ok(n) => {
                        buf.truncate(n);
                        Outcome::Data(Bytes::from(buf))
                    }
                    Err(e) => Outcome::Error(e.to_string()),
                };
                let _ = done.send(Completion { id, outcome });
            }
            ConnCmd::Write { id, data } => {
                let outcome = match stream.write_all(&data).await {
                    Ok(()) => Outcome::Written(data.len()),
                    Err(e) => Outcome::Error(e.to_string()),
                };
                let _ = done.send(Completion { id, outcome });
            }
            ConnCmd::Close { id } => {
                let _ = stream.shutdown().await;
                let _ = done.send(Completion { id, outcome: Outcome::Closed });
                break;
            }
        }
    }
}

/// Waits for readiness on a duplicate of `fd`. The dup keeps the descriptor
/// alive even if PHP closes its stream meanwhile (the watch then fires or is
/// dropped with the runtime).
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

fn forward(conns: &ConnMap, conn: u64, cmd: ConnCmd, id: u64, done: &Sender<Completion>) {
    let tx = conns.lock().unwrap().get(&conn).cloned();
    match tx {
        Some(tx) if tx.send(cmd).is_ok() => {}
        _ => {
            let _ = done.send(Completion { id, outcome: Outcome::Error(format!("connection {conn} is closed")) });
        }
    }
}

impl Reactor {
    /// Spawns the dispatcher task on `rt` and returns the PHP-side handle.
    pub fn new(rt: &tokio::runtime::Handle) -> Arc<Reactor> {
        let (to_tokio, mut rx) = mpsc::unbounded_channel::<(u64, Op)>();
        let (done_tx, from_tokio) = crossbeam_channel::unbounded::<Completion>();
        let done_for_task = done_tx.clone();
        rt.spawn(async move {
            let conns: ConnMap = Arc::new(Mutex::new(HashMap::new()));
            let next_conn = Arc::new(AtomicU64::new(1));
            while let Some((id, op)) = rx.recv().await {
                let done_tx: Sender<Completion> = done_for_task.clone();
                match op {
                    Op::Sleep { ms } => {
                        let deadline = tokio::time::Instant::now() + Duration::from_millis(ms);
                        tokio::spawn(async move {
                            tokio::time::sleep_until(deadline).await;
                            let late_us = deadline.elapsed().as_micros() as u64;
                            // Receiver dropped => PHP thread is gone; nothing to do.
                            let _ = done_tx.send(Completion { id, outcome: Outcome::Slept { late_us } });
                        });
                    }
                    Op::Connect { host, port } => {
                        let conns = conns.clone();
                        let next_conn = next_conn.clone();
                        tokio::spawn(async move {
                            let outcome = match tokio::net::TcpStream::connect((host.as_str(), port)).await {
                                Ok(stream) => {
                                    let _ = stream.set_nodelay(true);
                                    let conn = next_conn.fetch_add(1, Ordering::Relaxed);
                                    let (ctx, crx) = mpsc::unbounded_channel();
                                    conns.lock().unwrap().insert(conn, ctx);
                                    tokio::spawn(conn_actor(stream, crx, done_tx.clone()));
                                    Outcome::Connected { conn }
                                }
                                Err(e) => Outcome::Error(format!("connect {host}:{port}: {e}")),
                            };
                            let _ = done_tx.send(Completion { id, outcome });
                        });
                    }
                    Op::Watch { fd, write } => {
                        tokio::spawn(async move {
                            let outcome = watch_fd(fd, write).await;
                            let _ = done_tx.send(Completion { id, outcome });
                        });
                    }
                    Op::Read { conn, max } => forward(&conns, conn, ConnCmd::Read { id, max }, id, &done_tx),
                    Op::Write { conn, data } => forward(&conns, conn, ConnCmd::Write { id, data }, id, &done_tx),
                    Op::Close { conn } => {
                        let tx = conns.lock().unwrap().remove(&conn);
                        match tx {
                            Some(tx) => {
                                let _ = tx.send(ConnCmd::Close { id });
                            }
                            None => {
                                let _ = done_tx.send(Completion { id, outcome: Outcome::Closed });
                            }
                        }
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
            last_active_us: AtomicU64::new(0),
            created: std::time::Instant::now(),
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

    /// Tokio side: the response future for `id` was dropped before PHP answered.
    pub fn cancel_request(&self, id: u64) {
        if self.responders.lock().unwrap().remove(&id).is_some() {
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

    /// Marks the owning PHP thread as alive (watchdog, ADR-0012).
    pub fn touch(&self) {
        self.last_active_us.store(self.created.elapsed().as_micros() as u64, Ordering::Relaxed);
    }

    /// Time since the PHP thread last entered `poll` (ADR-0012 watchdog).
    pub fn idle_in_php(&self) -> Duration {
        let now = self.created.elapsed().as_micros() as u64;
        Duration::from_micros(now.saturating_sub(self.last_active_us.load(Ordering::Relaxed)))
    }

    /// Requests delivered to this thread's loop and not yet answered (ADR-0010).
    pub fn pending_requests(&self) -> usize {
        self.responders.lock().unwrap().len()
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

    fn rt() -> tokio::runtime::Runtime {
        tokio::runtime::Builder::new_multi_thread().worker_threads(1).enable_all().build().unwrap()
    }

    #[test]
    fn sleep_completes_and_poll_drains() {
        let rt = rt();
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
        let rt = rt();
        let r = Reactor::new(rt.handle());
        let t0 = std::time::Instant::now();
        assert!(r.poll(None).is_empty());
        assert!(t0.elapsed() < Duration::from_millis(50));
    }

    #[test]
    fn tcp_connect_write_read_close() {
        let rt = rt();
        let r = Reactor::new(rt.handle());
        // Echo server on tokio.
        let listener = rt.block_on(tokio::net::TcpListener::bind("127.0.0.1:0")).unwrap();
        let port = listener.local_addr().unwrap().port();
        rt.spawn(async move {
            let (mut s, _) = listener.accept().await.unwrap();
            let mut b = [0u8; 16];
            let n = tokio::io::AsyncReadExt::read(&mut s, &mut b).await.unwrap();
            tokio::io::AsyncWriteExt::write_all(&mut s, &b[..n]).await.unwrap();
        });
        let wait = |r: &Reactor, id: u64| -> Outcome {
            loop {
                for c in r.poll(Some(Duration::from_secs(2))) {
                    if c.id == id {
                        return c.outcome;
                    }
                }
            }
        };
        let id = r.submit(Op::Connect { host: "127.0.0.1".into(), port });
        let Outcome::Connected { conn } = wait(&r, id) else { panic!("connect failed") };
        let id = r.submit(Op::Write { conn, data: Bytes::from_static(b"ping") });
        assert!(matches!(wait(&r, id), Outcome::Written(4)));
        let id = r.submit(Op::Read { conn, max: 64 });
        let Outcome::Data(d) = wait(&r, id) else { panic!("read failed") };
        assert_eq!(&d[..], b"ping");
        let id = r.submit(Op::Close { conn });
        assert!(matches!(wait(&r, id), Outcome::Closed));
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
        assert!(r.respond(got[0].id, HttpResponse { status: 204, headers: vec![], body: Bytes::new() }));
        assert!(!r.respond(got[0].id, HttpResponse { status: 204, headers: vec![], body: Bytes::new() }));
        let resp = rt.block_on(rx).unwrap();
        assert_eq!(resp.status, 204);
    }
}
