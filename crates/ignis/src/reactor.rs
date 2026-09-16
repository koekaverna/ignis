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

/// TLS options for a hooked `ssl://`/`tls://` stream or a STARTTLS upgrade (ADR-0017), from PHP's
/// stream context (`verify_peer`, `verify_peer_name`, `allow_self_signed`, `cafile`, `peer_name`).
#[derive(Clone, Debug)]
pub struct TlsOpts {
    pub server_name: String,
    pub verify_peer: bool,
    pub verify_peer_name: bool,
    pub allow_self_signed: bool,
    pub cafile: Option<String>,
}

/// An I/O request submitted by PHP. Plain data only: no Zend pointers.
pub enum Op {
    /// Complete after `ms` milliseconds (tokio timer wheel).
    /// Sleep for `us` microseconds (sub-ms precision for the usleep hook, E15c).
    Sleep { us: u64 },
    /// Open a TCP connection (ADR-0007), with a TLS handshake when `tls` is set (ADR-0017). Completes with `Connected { conn }`.
    Connect { host: String, port: u16, tls: Option<TlsOpts> },
    /// STARTTLS: wrap an open connection in TLS in place. Completes with `Ready` or `Error`.
    Upgrade { conn: u64, tls: TlsOpts },
    /// Adopt an already-connected socket (a dup'd fd from `stream_socket_accept`, E6''). Completes with `Connected`.
    Adopt { fd: i32 },
    /// A4 (ADR-0018): connect a `unix://` stream socket by path. Completes with `Connected`.
    /// Everything after the connect — the actor, reads, writes, close — is the tcp path unchanged,
    /// because `UnixStream` is just another `AsyncRead + AsyncWrite`.
    ConnectUnix { path: String },
    /// Read up to `max` bytes from `conn`. Completes with `Data` (empty = EOF).
    Read { conn: u64, max: usize },
    /// Read whatever `conn` holds right now without waiting (a non-blocking PHP stream, E6'').
    /// Completes with `Data` or `WouldBlock`.
    TryRead { conn: u64, max: usize },
    /// Write all of `data` to `conn`. Completes with `Written`.
    Write { conn: u64, data: Bytes },
    /// Close `conn`. Completes with `Closed`.
    Close { conn: u64 },
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
            Op::TryRead { conn, max } => write!(f, "TryRead(conn={conn}, max={max})"),
            Op::Connect { host, port, tls } => write!(f, "Connect({host}:{port},tls={})", tls.is_some()),
            Op::Upgrade { conn, tls } => write!(f, "Upgrade({conn},{})", tls.server_name),
            Op::Adopt { fd } => write!(f, "Adopt({fd})"),
            Op::ConnectUnix { path } => write!(f, "ConnectUnix({path})"),
            Op::Read { conn, max } => write!(f, "Read({conn},{max})"),
            Op::Write { conn, data } => write!(f, "Write({conn},{} bytes)", data.len()),
            Op::Close { conn } => write!(f, "Close({conn})"),
            Op::Watch { fd, write } => write!(f, "Watch({fd},write={write})"),
            Op::CancelWatch { target } => write!(f, "CancelWatch({target})"),
            Op::Custom(_) => write!(f, "Custom"),
        }
    }
}

/// Command to a connection actor (tokio side only).
enum ConnCmd {
    Read { id: u64, max: usize },
    TryRead { id: u64, max: usize },
    Write { id: u64, data: Bytes },
    Close { id: u64 },
    Upgrade { id: u64, tls: TlsOpts },
}

/// A connection's byte stream: plain TCP or TLS over it (ADR-0017).
trait AsyncStream: tokio::io::AsyncRead + tokio::io::AsyncWrite + Send + Unpin {}
impl<T: tokio::io::AsyncRead + tokio::io::AsyncWrite + Send + Unpin> AsyncStream for T {}
type BoxStream = Box<dyn AsyncStream>;

/// Accepts any server certificate (PHP's `verify_peer=false` / `allow_self_signed=true`).
#[derive(Debug)]
struct NoVerify(rustls::crypto::CryptoProvider);
impl rustls::client::danger::ServerCertVerifier for NoVerify {
    fn verify_server_cert(
        &self,
        _end_entity: &rustls::pki_types::CertificateDer<'_>,
        _intermediates: &[rustls::pki_types::CertificateDer<'_>],
        _server_name: &rustls::pki_types::ServerName<'_>,
        _ocsp: &[u8],
        _now: rustls::pki_types::UnixTime,
    ) -> Result<rustls::client::danger::ServerCertVerified, rustls::Error> {
        Ok(rustls::client::danger::ServerCertVerified::assertion())
    }
    fn verify_tls12_signature(&self, message: &[u8], cert: &rustls::pki_types::CertificateDer<'_>, dss: &rustls::DigitallySignedStruct) -> Result<rustls::client::danger::HandshakeSignatureValid, rustls::Error> {
        rustls::crypto::verify_tls12_signature(message, cert, dss, &self.0.signature_verification_algorithms)
    }
    fn verify_tls13_signature(&self, message: &[u8], cert: &rustls::pki_types::CertificateDer<'_>, dss: &rustls::DigitallySignedStruct) -> Result<rustls::client::danger::HandshakeSignatureValid, rustls::Error> {
        rustls::crypto::verify_tls13_signature(message, cert, dss, &self.0.signature_verification_algorithms)
    }
    fn supported_verify_schemes(&self) -> Vec<rustls::SignatureScheme> {
        self.0.signature_verification_algorithms.supported_schemes()
    }
}

/// Verifies the chain but ignores a host-name mismatch (PHP's `verify_peer_name=false`).
#[derive(Debug)]
struct NoNameCheck(std::sync::Arc<rustls::client::WebPkiServerVerifier>);
impl rustls::client::danger::ServerCertVerifier for NoNameCheck {
    fn verify_server_cert(
        &self,
        end_entity: &rustls::pki_types::CertificateDer<'_>,
        intermediates: &[rustls::pki_types::CertificateDer<'_>],
        server_name: &rustls::pki_types::ServerName<'_>,
        ocsp: &[u8],
        now: rustls::pki_types::UnixTime,
    ) -> Result<rustls::client::danger::ServerCertVerified, rustls::Error> {
        match self.0.verify_server_cert(end_entity, intermediates, server_name, ocsp, now) {
            Err(rustls::Error::InvalidCertificate(rustls::CertificateError::NotValidForName)) => Ok(rustls::client::danger::ServerCertVerified::assertion()),
            Err(rustls::Error::InvalidCertificate(rustls::CertificateError::NotValidForNameContext { .. })) => Ok(rustls::client::danger::ServerCertVerified::assertion()),
            other => other,
        }
    }
    fn verify_tls12_signature(&self, m: &[u8], c: &rustls::pki_types::CertificateDer<'_>, d: &rustls::DigitallySignedStruct) -> Result<rustls::client::danger::HandshakeSignatureValid, rustls::Error> {
        self.0.verify_tls12_signature(m, c, d)
    }
    fn verify_tls13_signature(&self, m: &[u8], c: &rustls::pki_types::CertificateDer<'_>, d: &rustls::DigitallySignedStruct) -> Result<rustls::client::danger::HandshakeSignatureValid, rustls::Error> {
        self.0.verify_tls13_signature(m, c, d)
    }
    fn supported_verify_schemes(&self) -> Vec<rustls::SignatureScheme> {
        self.0.supported_verify_schemes()
    }
}

fn tls_config(opts: &TlsOpts) -> Result<std::sync::Arc<rustls::ClientConfig>, String> {
    let provider = std::sync::Arc::new(rustls::crypto::ring::default_provider());
    let builder = rustls::ClientConfig::builder_with_provider(provider.clone())
        .with_safe_default_protocol_versions()
        .map_err(|e| e.to_string())?;
    let mut roots = rustls::RootCertStore::empty();
    roots.extend(webpki_roots::TLS_SERVER_ROOTS.iter().cloned());
    if let Some(path) = &opts.cafile {
        let pem = std::fs::read(path).map_err(|e| format!("cafile {path}: {e}"))?;
        for cert in rustls_pemfile::certs(&mut &pem[..]) {
            let cert = cert.map_err(|e| format!("cafile {path}: {e}"))?;
            roots.add(cert).map_err(|e| format!("cafile {path}: {e}"))?;
        }
    }
    let cfg = if !opts.verify_peer || opts.allow_self_signed {
        builder.dangerous().with_custom_certificate_verifier(std::sync::Arc::new(NoVerify((*provider).clone()))).with_no_client_auth()
    } else if !opts.verify_peer_name {
        let inner = rustls::client::WebPkiServerVerifier::builder_with_provider(std::sync::Arc::new(roots), provider).build().map_err(|e| e.to_string())?;
        builder.dangerous().with_custom_certificate_verifier(std::sync::Arc::new(NoNameCheck(inner))).with_no_client_auth()
    } else {
        builder.with_root_certificates(roots).with_no_client_auth()
    };
    Ok(std::sync::Arc::new(cfg))
}

async fn tls_wrap(stream: BoxStream, opts: &TlsOpts) -> Result<BoxStream, String> {
    let cfg = tls_config(opts)?;
    let name = rustls::pki_types::ServerName::try_from(opts.server_name.clone()).map_err(|e| format!("peer name '{}': {e}", opts.server_name))?;
    let tls = tokio_rustls::TlsConnector::from(cfg).connect(name, stream).await.map_err(|e| format!("tls handshake with {}: {e}", opts.server_name))?;
    Ok(Box::new(tls))
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
    /// `fd` is a dup of the socket for `stream_select()`/`socket_import_stream` (owned by PHP's stream,
    /// closed with it); `local`/`peer` are "ip:port" for `stream_socket_get_name()`.
    Connected { conn: u64, fd: i32, local: String, peer: String },
    /// Bytes read; empty means EOF.
    Data(Bytes),
    /// `TryRead`: nothing available yet (not EOF).
    WouldBlock,
    Written(usize),
    Closed,
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
    /// gRPC response streams PHP is still filling (E10, ADR-0014).
    streams: Mutex<HashMap<u64, mpsc::UnboundedSender<GrpcMsg>>>,
    /// Microseconds (monotonic, since reactor creation) of the last `poll` by the PHP thread.
    last_active_us: AtomicU64,
    created: std::time::Instant,
    /// Microseconds `poll` spins on `try_recv` before sleeping the thread (H30, `IGNIS_POLL_SPIN_US`).
    /// 0 = off. Read once here so the hot path never touches the environment.
    spin_us: u64,
}

type ConnMap = Arc<Mutex<HashMap<u64, mpsc::UnboundedSender<ConnCmd>>>>;

/// One actor per TCP connection: owns the socket, serves commands in order.
/// (dup'd fd for select, local "ip:port", peer "ip:port") of a connected socket.
fn socket_meta(stream: &tokio::net::TcpStream) -> (i32, String, String) {
    use std::os::fd::AsRawFd;
    // SAFETY: dup of a valid descriptor; the PHP stream owns and closes the copy.
    let fd = unsafe { libc::dup(stream.as_raw_fd()) };
    let local = stream.local_addr().map(|a| a.to_string()).unwrap_or_default();
    let peer = stream.peer_addr().map(|a| a.to_string()).unwrap_or_default();
    (fd, local, peer)
}

/// Same, for a `unix://` socket. A connected unix stream usually has no peer path of its own, so
/// PHP is given the path it dialled — which is what `stream_socket_get_name()` reports there.
fn unix_meta(stream: &tokio::net::UnixStream, path: &str) -> (i32, String, String) {
    use std::os::fd::AsRawFd;
    // SAFETY: dup of a valid descriptor; the PHP stream owns and closes the copy.
    let fd = unsafe { libc::dup(stream.as_raw_fd()) };
    let name = |a: Result<tokio::net::unix::SocketAddr, std::io::Error>| {
        a.ok().and_then(|a| a.as_pathname().map(|p| p.to_string_lossy().into_owned())).unwrap_or_default()
    };
    let local = name(stream.local_addr());
    let peer = {
        let p = name(stream.peer_addr());
        if p.is_empty() { path.to_string() } else { p }
    };
    (fd, local, peer)
}

/// Register a connected stream with an actor and describe it to PHP.
fn adopt(conns: &ConnMap, next_conn: &Arc<AtomicU64>, stream: BoxStream, meta: (i32, String, String), done: Sender<Completion>) -> Outcome {
    let conn = next_conn.fetch_add(1, Ordering::Relaxed);
    let (ctx, crx) = mpsc::unbounded_channel();
    conns.lock().unwrap().insert(conn, ctx);
    tokio::spawn(conn_actor(stream, crx, done));
    Outcome::Connected { conn, fd: meta.0, local: meta.1, peer: meta.2 }
}

async fn conn_actor(stream: BoxStream, mut rx: mpsc::UnboundedReceiver<ConnCmd>, done: Sender<Completion>) {
    use tokio::io::{AsyncReadExt, AsyncWriteExt};
    let mut stream = stream;
    while let Some(cmd) = rx.recv().await {
        match cmd {
            ConnCmd::Upgrade { id, tls } => {
                // Swap the byte stream for a TLS session over it; a placeholder holds the slot meanwhile.
                let plain = std::mem::replace(&mut stream, Box::new(tokio::io::empty()) as BoxStream);
                let outcome = match tls_wrap(plain, &tls).await {
                    Ok(s) => {
                        stream = s;
                        Outcome::Ready
                    }
                    Err(e) => Outcome::Error(e),
                };
                let _ = done.send(Completion { id, outcome });
            }
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
            ConnCmd::TryRead { id, max } => {
                // One poll with a no-op waker: data already in the socket (or the TLS session) comes
                // back; Pending means "would block" and registers no interest.
                let mut buf = vec![0u8; max.clamp(1, 1 << 20)];
                let mut fut = std::pin::pin!(stream.read(&mut buf));
                let mut cx = std::task::Context::from_waker(std::task::Waker::noop());
                let outcome = match fut.as_mut().poll(&mut cx) {
                    std::task::Poll::Ready(Ok(n)) => {
                        drop(fut);
                        buf.truncate(n);
                        Outcome::Data(Bytes::from(buf))
                    }
                    std::task::Poll::Ready(Err(e)) => Outcome::Error(e.to_string()),
                    std::task::Poll::Pending => Outcome::WouldBlock,
                };
                let _ = done.send(Completion { id, outcome });
            }
            ConnCmd::Write { id, data } => {
                // flush: a TLS session buffers records; plain TCP flush is a no-op.
                let outcome = match stream.write_all(&data).await.and(stream.flush().await) {
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
                    Op::Connect { host, port, tls } => {
                        let conns = conns.clone();
                        let next_conn = next_conn.clone();
                        tokio::spawn(async move {
                            let outcome = match tokio::net::TcpStream::connect((host.as_str(), port)).await {
                                Ok(stream) => {
                                    let _ = stream.set_nodelay(true);
                                    let meta = socket_meta(&stream);
                                    let boxed: Result<BoxStream, String> = match &tls {
                                        Some(opts) => tls_wrap(Box::new(stream), opts).await,
                                        None => Ok(Box::new(stream)),
                                    };
                                    match boxed {
                                        Ok(stream) => adopt(&conns, &next_conn, stream, meta, done_tx.clone()),
                                        Err(e) => Outcome::Error(format!("connect {host}:{port}: {e}")),
                                    }
                                }
                                Err(e) => Outcome::Error(format!("connect {host}:{port}: {e}")),
                            };
                            let _ = done_tx.send(Completion { id, outcome });
                        });
                    }
                    Op::ConnectUnix { path } => {
                        let conns = conns.clone();
                        let next_conn = next_conn.clone();
                        tokio::spawn(async move {
                            let outcome = match tokio::net::UnixStream::connect(&path).await {
                                Ok(stream) => {
                                    let meta = unix_meta(&stream, &path);
                                    adopt(&conns, &next_conn, Box::new(stream), meta, done_tx.clone())
                                }
                                Err(e) => Outcome::Error(format!("connect unix://{path}: {e}")),
                            };
                            let _ = done_tx.send(Completion { id, outcome });
                        });
                    }
                    Op::Upgrade { conn, tls } => forward(&conns, conn, ConnCmd::Upgrade { id, tls }, id, &done_tx),
                    Op::Adopt { fd } => {
                        // SAFETY: the fd was dup'd by the PHP side for us; we own it from here.
                        let std = unsafe { <std::net::TcpStream as std::os::fd::FromRawFd>::from_raw_fd(fd) };
                        let outcome = match std.set_nonblocking(true).and_then(|_| tokio::net::TcpStream::from_std(std)) {
                            Ok(stream) => {
                                let _ = stream.set_nodelay(true);
                                let meta = socket_meta(&stream);
                                adopt(&conns, &next_conn, Box::new(stream), meta, done_tx.clone())
                            }
                            Err(e) => Outcome::Error(format!("adopt fd {fd}: {e}")),
                        };
                        let _ = done_tx.send(Completion { id, outcome });
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
                    Op::Read { conn, max } => forward(&conns, conn, ConnCmd::Read { id, max }, id, &done_tx),
                    Op::TryRead { conn, max } => forward(&conns, conn, ConnCmd::TryRead { id, max }, id, &done_tx),
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
            streams: Mutex::new(HashMap::new()),
            last_active_us: AtomicU64::new(0),
            created: std::time::Instant::now(),
            spin_us: std::env::var("IGNIS_POLL_SPIN_US").ok().and_then(|v| v.parse().ok()).unwrap_or(0),
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
        let known = self.responders.lock().unwrap().remove(&id).is_some() || self.streams.lock().unwrap().remove(&id).is_some();
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

    /// The owning PHP thread is going away (script ended, fatal): every request it has not
    /// answered gets its responder dropped, so hyper answers 500 / tonic answers an error now
    /// instead of holding the connection until the client gives up (E12').
    pub fn fail_pending(&self) -> usize {
        let dropped = self.responders.lock().unwrap().drain().count() + self.streams.lock().unwrap().drain().count();
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
    pub fn pending_requests(&self) -> usize {
        self.responders.lock().unwrap().len() + self.streams.lock().unwrap().len()
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
        let id = r.submit(Op::Connect { host: "127.0.0.1".into(), port, tls: None });
        let Outcome::Connected { conn, fd, .. } = wait(&r, id) else { panic!("connect failed") };
        assert!(fd >= 0);
        unsafe { libc::close(fd) };
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
