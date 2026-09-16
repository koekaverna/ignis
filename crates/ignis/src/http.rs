//! hyper 1.x front door. Runs entirely on tokio; hands each request to the
//! reactor as plain data and awaits the PHP answer on a oneshot.
use std::net::SocketAddr;
use std::sync::atomic::{AtomicUsize, Ordering};
use std::sync::{Arc, Mutex, OnceLock};
use std::time::{Duration, Instant};

use anyhow::{Context, Result};
use bytes::Bytes;
use http_body_util::{BodyExt, Limited};
use hyper::body::Incoming;
use hyper::service::service_fn;
use hyper::{Request, Response, StatusCode};
use hyper_util::rt::{TokioExecutor, TokioIo, TokioTimer};
use hyper_util::server::conn::auto;
use tokio::io::AsyncWriteExt;
use tokio::net::TcpListener;
use tokio::sync::Semaphore;

use crate::reactor::{HttpRequest, Reactor};

/// Reads an env var as an integer, falling back to `default` when unset or unparsable.
fn env_usize(name: &str, default: usize) -> usize {
    std::env::var(name).ok().and_then(|v| v.parse().ok()).unwrap_or(default)
}

/// Reads an env var as milliseconds, falling back to `default_ms` when unset or unparsable.
fn env_ms(name: &str, default_ms: u64) -> Duration {
    Duration::from_millis(std::env::var(name).ok().and_then(|v| v.parse().ok()).unwrap_or(default_ms))
}

/// Body cap, checked per request in `handle`; read once and cached (pg.rs's `warn_ms` does the
/// same for a value read on every request). Future ignis.toml key: `limits.max_body_bytes`.
fn max_body_bytes() -> usize {
    static V: OnceLock<usize> = OnceLock::new();
    *V.get_or_init(|| env_usize("IGNIS_MAX_BODY_BYTES", 8 * 1024 * 1024))
}

/// Reactors of every PHP thread that called `ignis_serve`; each request goes
/// to the thread with the fewest unanswered requests, ties rotating (ADR-0010).
struct Registry {
    reactors: Mutex<Vec<Arc<Reactor>>>,
    next: AtomicUsize,
}

static REGISTRY: OnceLock<Arc<Registry>> = OnceLock::new();
/// Bind-once state, held under a mutex so concurrent `ignis_serve` calls from
/// several PHP threads cannot race (the second would fail with EADDRINUSE).
static BOUND: Mutex<Option<SocketAddr>> = Mutex::new(None);

impl Registry {
    fn pick(&self) -> Option<Arc<Reactor>> {
        let rs = self.reactors.lock().unwrap();
        if rs.is_empty() {
            return None;
        }
        let n = rs.len();
        let start = self.next.fetch_add(1, Ordering::Relaxed) % n;
        let mut best = start;
        let mut best_load = usize::MAX;
        for k in 0..n {
            let i = (start + k) % n;
            let load = rs[i].pending_requests();
            if load < best_load {
                best_load = load;
                best = i;
                if load == 0 {
                    break;
                }
            }
        }
        Some(rs[best].clone())
    }
}

/// Number of registered PHP threads that have not polled for longer than `limit`
/// (a thread busy in PHP code for that long), and the total registered.
pub fn stalled_threads(limit: std::time::Duration) -> (usize, usize) {
    match REGISTRY.get() {
        Some(r) => {
            let rs = r.reactors.lock().unwrap();
            (rs.iter().filter(|x| x.pending_requests() > 0 && x.idle_in_php() > limit).count(), rs.len())
        }
        None => (0, 0),
    }
}

/// Everything `/_ignis/metrics` sums over the registered threads, in one pass under one lock.
pub fn totals() -> crate::metrics::Totals {
    let mut t = crate::metrics::Totals::default();
    if let Some(r) = REGISTRY.get() {
        for reactor in r.reactors.lock().unwrap().iter() {
            t.add(reactor);
        }
    }
    t
}

/// Removes a PHP thread's reactor from dispatch (its script ended or died).
pub fn unregister(reactor: &Arc<Reactor>) {
    if let Some(registry) = REGISTRY.get() {
        registry.reactors.lock().unwrap().retain(|r| !Arc::ptr_eq(r, reactor));
    }
    // E12': in-flight requests of a dying thread fail fast (500) instead of hanging.
    let n = reactor.fail_pending();
    if n > 0 {
        tracing::warn!(pending = n, "php thread ended with requests in flight; answered 500");
    }
}

/// Registers the calling thread's reactor as a request target and, on the
/// first call, binds `addr` and serves it forever on the runtime. Returns the
/// bound address. Later calls (other PHP threads) only register.
pub fn start(rt: &tokio::runtime::Handle, reactor: Arc<Reactor>, addr: &str) -> Result<SocketAddr> {
    let registry = REGISTRY.get_or_init(|| Arc::new(Registry { reactors: Mutex::new(Vec::new()), next: AtomicUsize::new(0) }));
    let mut bound = BOUND.lock().unwrap();
    if let Some(b) = *bound {
        reactor.server_started();
        registry.reactors.lock().unwrap().push(reactor);
        return Ok(b);
    }
    let addr: SocketAddr = addr.parse().with_context(|| format!("bad listen address {addr:?}"))?;
    let listener = rt.block_on(TcpListener::bind(addr)).with_context(|| format!("bind {addr}"))?;
    let local = listener.local_addr()?;
    *bound = Some(local);
    drop(bound);
    reactor.server_started();
    registry.reactors.lock().unwrap().push(reactor);
    let registry = registry.clone();
    // ADR-0025: the connection cap is the RSS bound, so this number must be honest — V-37
    // measured a held connection's marginal cost at 33 kB queued, on top of a fiber's own
    // ~34 kB (V-5). Future ignis.toml key: `limits.max_connections`.
    let max_connections = env_usize("IGNIS_MAX_CONNECTIONS", 8192);
    let connection_permits = Arc::new(Semaphore::new(max_connections));
    // Future ignis.toml key: `limits.header_timeout_ms`.
    let header_timeout = env_ms("IGNIS_HEADER_TIMEOUT_MS", 10_000);
    // Future ignis.toml key: `limits.idle_timeout_ms`.
    let idle_timeout = env_ms("IGNIS_IDLE_TIMEOUT_MS", 60_000);
    rt.spawn(async move {
        loop {
            let (stream, _peer) = match listener.accept().await {
                Ok(x) => x,
                Err(e) => {
                    tracing::warn!(error = %e, "accept failed");
                    continue;
                }
            };
            let _ = stream.set_nodelay(true);
            let registry = registry.clone();
            // Over the cap: answer inline and drop the socket rather than queue it — an
            // accepted-but-unserved connection is exactly the unbounded queueing this guards
            // against. try_acquire_owned is non-blocking so the accept loop never stalls on it.
            let Ok(permit) = connection_permits.clone().try_acquire_owned() else {
                tokio::spawn(async move {
                    let mut stream = stream;
                    let _ = stream.write_all(b"HTTP/1.1 503 Service Unavailable\r\ncontent-length: 0\r\nconnection: close\r\n\r\n").await;
                });
                continue;
            };
            tokio::spawn(async move {
                let _permit = permit; // held for the connection's lifetime; drop releases it
                // One target per request (not per connection) so keep-alive
                // connections still spread across PHP threads.
                let last_activity = Arc::new(Mutex::new(Instant::now()));
                let la = last_activity.clone();
                let svc = service_fn(move |req| {
                    *la.lock().unwrap() = Instant::now();
                    let r = registry.pick();
                    async move {
                        match r {
                            Some(r) => handle(r, req).await,
                            None => Ok(simple(StatusCode::SERVICE_UNAVAILABLE, "no php thread registered\n")),
                        }
                    }
                });
                let mut builder = auto::Builder::new(TokioExecutor::new());
                // Slowloris: a client that never finishes sending headers is dropped instead of
                // holding the connection (and its semaphore permit) forever.
                builder.http1().timer(TokioTimer::new()).header_read_timeout(header_timeout);
                let conn = builder.serve_connection(TokioIo::new(stream), svc);
                tokio::pin!(conn);
                // auto::Builder has no built-in idle timer (header_read_timeout only covers one
                // request's header read), so an idle keep-alive connection is timed out here:
                // graceful_shutdown once `idle_timeout` passes since the last request started.
                loop {
                    let idle_for = last_activity.lock().unwrap().elapsed();
                    if idle_for >= idle_timeout {
                        conn.as_mut().graceful_shutdown();
                        if let Err(e) = conn.as_mut().await {
                            tracing::debug!(error = %e, "connection ended with error");
                        }
                        break;
                    }
                    tokio::select! {
                        res = conn.as_mut() => {
                            if let Err(e) = res {
                                tracing::debug!(error = %e, "connection ended with error");
                            }
                            break;
                        }
                        _ = tokio::time::sleep(idle_timeout - idle_for) => {}
                    }
                }
            });
        }
    });
    Ok(local)
}

/// M1: `/_ignis/health`, answered by the runtime and never by PHP. 200 while at least one PHP
/// thread is registered and not every one of them is stalled; 503 otherwise — so a load balancer
/// stops sending to a process whose workers are all wedged, which `/` from PHP could never report.
fn health() -> Response<tonic::body::Body> {
    let (stalled, total) = stalled_threads(std::time::Duration::from_secs(1));
    let restarts = crate::RESTARTS.load(std::sync::atomic::Ordering::Relaxed);
    let ok = total > 0 && stalled < total;
    let body = format!(
        "{{\"status\":\"{}\",\"threads\":{total},\"stalled\":{stalled},\"restarts\":{restarts}}}\n",
        if ok { "ok" } else { "unavailable" }
    );
    Response::builder()
        .status(if ok { StatusCode::OK } else { StatusCode::SERVICE_UNAVAILABLE })
        .header("content-type", "application/json")
        .header("cache-control", "no-store")
        .body(crate::grpc::plain_body(Bytes::from(body)))
        .unwrap()
}

async fn handle(reactor: Arc<Reactor>, req: Request<Incoming>) -> Result<Response<tonic::body::Body>, hyper::Error> {
    if req.uri().path() == "/_ignis/health" {
        return Ok(health());
    }
    if req.uri().path() == "/_ignis/metrics" {
        // M4-4: answered by the runtime, never by PHP, so it keeps answering when every PHP
        // thread is wedged — which is when an operator needs it most (ADR-0022).
        return Ok(Response::builder()
            .status(StatusCode::OK)
            .header("content-type", "text/plain; version=0.0.4; charset=utf-8")
            .header("cache-control", "no-store")
            .body(crate::grpc::plain_body(Bytes::from(crate::metrics::render())))
            .unwrap());
    }
    // E10 (ADR-0014): gRPC shares the listener; tonic frames it, PHP serves it.
    if crate::grpc::is_grpc(&req) {
        return Ok(crate::grpc::serve(reactor, req).await);
    }
    let (parts, body) = req.into_parts();
    // `Limited` errors as soon as one frame would push the running total past the cap, so an
    // over-cap body is never buffered in full — just up to the frame that tripped it.
    let body = match Limited::new(body, max_body_bytes()).collect().await {
        Ok(c) => c.to_bytes(),
        Err(e) => {
            if e.downcast_ref::<http_body_util::LengthLimitError>().is_some() {
                return Ok(simple(StatusCode::PAYLOAD_TOO_LARGE, "request body too large\n"));
            }
            tracing::debug!(error = %e, "body read failed");
            return Ok(simple(StatusCode::BAD_REQUEST, "bad request body\n"));
        }
    };
    let headers = parts
        .headers
        .iter()
        .map(|(k, v)| (k.as_str().to_string(), String::from_utf8_lossy(v.as_bytes()).into_owned()))
        .collect();
    let uri = parts.uri.path_and_query().map(|p| p.as_str().to_string()).unwrap_or_else(|| "/".into());
    let rx = reactor.deliver_request_with_id(HttpRequest { method: parts.method.as_str().to_string(), uri, headers, body });
    // If this future is dropped (client disconnect, ADR-0009) before PHP
    // answers, the guard tells the reactor to cancel the request's fiber.
    struct CancelOnDrop {
        reactor: Arc<Reactor>,
        id: u64,
        answered: bool,
    }
    impl Drop for CancelOnDrop {
        fn drop(&mut self) {
            if !self.answered {
                self.reactor.cancel_request(self.id);
            }
        }
    }
    let mut guard = CancelOnDrop { reactor: reactor.clone(), id: rx_id(&rx), answered: false };
    let out = rx.1.await;
    guard.answered = true;
    match out {
        Ok(r) => {
            let mut b = Response::builder().status(r.status);
            for (k, v) in r.headers {
                b = b.header(k, v);
            }
            Ok(b.body(crate::grpc::plain_body(r.body)).unwrap_or_else(|_| simple(StatusCode::INTERNAL_SERVER_ERROR, "bad response headers\n")))
        }
        // PHP dropped the responder without answering (handler crashed hard).
        Err(_) => Ok(simple(StatusCode::INTERNAL_SERVER_ERROR, "no response from php\n")),
    }
}

/// The request id is the oneshot's key in the reactor; deliver_request hands it
/// back through the receiver's paired id (see Reactor::deliver_request_with_id).
fn rx_id(rx: &(u64, tokio::sync::oneshot::Receiver<crate::reactor::HttpResponse>)) -> u64 {
    rx.0
}

fn simple(status: StatusCode, msg: &'static str) -> Response<tonic::body::Body> {
    Response::builder()
        .status(status)
        .header("content-type", "text/plain")
        .body(crate::grpc::plain_body(Bytes::from_static(msg.as_bytes())))
        .unwrap()
}
