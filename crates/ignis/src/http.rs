//! hyper 1.x front door. Runs entirely on tokio; hands each request to the
//! reactor as plain data and awaits the PHP answer on a oneshot.
use std::net::SocketAddr;
use std::sync::atomic::{AtomicBool, AtomicUsize, Ordering};
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

use crate::lock::LockUnpoisoned;
use crate::reactor::{HttpRequest, Reactor};

/// Unset and unparsable both mean "keep the default": a typo in an env var must not move a limit.
fn parse_or<T: std::str::FromStr>(value: Option<String>, default: T) -> T {
    value.and_then(|v| v.parse().ok()).unwrap_or(default)
}

/// Reads an env var as an integer, falling back to `default` when unset or unparsable.
fn env_usize(name: &str, default: usize) -> usize {
    parse_or(std::env::var(name).ok(), default)
}

/// Reads an env var as milliseconds, falling back to `default_ms` when unset or unparsable.
fn env_ms(name: &str, default_ms: u64) -> Duration {
    Duration::from_millis(parse_or(std::env::var(name).ok(), default_ms))
}

/// Body cap, checked per request in `handle`; read once and cached (pg.rs's `warn_ms` does the
/// same for a value read on every request).
fn max_body_bytes() -> usize {
    static V: OnceLock<usize> = OnceLock::new();
    *V.get_or_init(|| env_usize("IGNIS_MAX_BODY_BYTES", 8 * 1024 * 1024))
}

/// The header pairs and the path+query (`/a/b?x=1`, `/` when the URI carries neither) that the
/// reactor hands to PHP. Shared by the HTTP and the gRPC front doors, which see the same request.
pub fn request_parts<B>(request: &Request<B>) -> (Vec<(String, String)>, String) {
    let headers =
        request.headers().iter().map(|(k, v)| (k.as_str().to_string(), String::from_utf8_lossy(v.as_bytes()).into_owned())).collect();
    let uri = request.uri().path_and_query().map(|p| p.as_str().to_string()).unwrap_or_else(|| "/".into());
    (headers, uri)
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
/// A listener the master bound before forking (ADR-0044); `start` adopts it instead of binding.
static INHERITED: Mutex<Option<std::net::TcpListener>> = Mutex::new(None);

/// Hands `start` the socket every worker inherited, so N processes accept on one port.
pub fn adopt_listener(listener: std::net::TcpListener) {
    *INHERITED.lock_unpoisoned() = Some(listener);
}

impl Registry {
    fn pick(&self) -> Option<Arc<Reactor>> {
        let rs = self.reactors.lock_unpoisoned();
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
pub fn stalled_threads(limit: Duration) -> (usize, usize) {
    match REGISTRY.get() {
        Some(r) => {
            let rs = r.reactors.lock_unpoisoned();
            (rs.iter().filter(|x| x.pending_requests() > 0 && x.idle_in_php() > limit).count(), rs.len())
        }
        None => (0, 0),
    }
}

/// Set once when a shutdown signal arrives; the accept loop stops and `drain()` waits out the
/// requests already in flight (M4-5).
static SHUTDOWN: tokio::sync::Notify = tokio::sync::Notify::const_new();
/// Phase 1: health answers "draining" while the listener still accepts.
static SHUTTING_DOWN: AtomicBool = AtomicBool::new(false);
/// Phase 2: the listener stops. Separate from `SHUTTING_DOWN` on purpose — one flag made the
/// grace window useless, because the accept loop saw the drain the instant health did.
static LISTENER_STOP: AtomicBool = AtomicBool::new(false);

/// Resolves when the drain reaches phase 2 (M4-5): the listener stops first, so a load balancer
/// sees the port close while in-flight requests are still answered on the connections already open.
async fn shutdown_signalled() {
    if LISTENER_STOP.load(Ordering::Relaxed) {
        return;
    }
    SHUTDOWN.notified().await;
}

/// True once a drain has started: `/_ignis/health` answers 503 from here on, so a load balancer
/// takes this instance out of rotation before the socket actually closes.
pub fn is_draining() -> bool {
    SHUTTING_DOWN.load(Ordering::Relaxed)
}

/// Starts the drain: stop accepting, then wait until no request is in flight, bounded by
/// `IGNIS_DRAIN_TIMEOUT_MS` (default 10 s). Returns how long it took and what was still pending.
///
/// Two phases, because a load balancer needs to be told before the socket goes away. First health
/// answers "draining" (503) while the listener is STILL accepting, for `IGNIS_DRAIN_DELAY_MS` — set
/// that to a little more than the balancer's check interval and a rolling deploy loses nothing;
/// default 0, because a single instance has nobody to tell. Then the listener closes and the
/// requests already in flight are given `IGNIS_DRAIN_TIMEOUT_MS`.
pub async fn drain() -> (Duration, usize) {
    SHUTTING_DOWN.store(true, Ordering::Relaxed);
    let delay = env_ms("IGNIS_DRAIN_DELAY_MS", 0);
    if !delay.is_zero() {
        tracing::info!(ms = delay.as_millis() as u64, "draining: health reports unavailable, still accepting");
        tokio::time::sleep(delay).await;
    }
    LISTENER_STOP.store(true, Ordering::Relaxed);
    SHUTDOWN.notify_waiters();
    let limit = env_ms("IGNIS_DRAIN_TIMEOUT_MS", 10_000);
    let started = Instant::now();
    loop {
        let pending = totals().pending;
        if pending == 0 || started.elapsed() >= limit {
            return (started.elapsed(), pending);
        }
        tokio::time::sleep(Duration::from_millis(5)).await;
    }
}

/// Everything `/_ignis/metrics` sums over the registered threads, in one pass under one lock.
pub fn totals() -> crate::metrics::Totals {
    let mut t = crate::metrics::Totals::default();
    if let Some(r) = REGISTRY.get() {
        for reactor in r.reactors.lock_unpoisoned().iter() {
            t.add(reactor);
        }
    }
    t
}

/// Removes a PHP thread's reactor from dispatch (its script ended or died). E12': the requests it
/// still had in flight fail fast with a 500 instead of hanging until the client gives up.
/// Breaks every PHP thread out of `ignis_poll`, so a loop parked with no traffic notices something
/// that did not arrive as a request — a file changed under the development watcher, say. The
/// completion carries `Outcome::Ready` with an id nobody awaits, which the userland loop drops on
/// the floor; waking is the whole point of it.
pub fn wake_all() {
    if let Some(registry) = REGISTRY.get() {
        for reactor in registry.reactors.lock_unpoisoned().iter() {
            reactor.inject(crate::reactor::Outcome::Ready);
        }
    }
}

/// Takes a thread out of dispatch without touching its in-flight work: a reload stops accepting
/// first and finishes what it already has, where a dying thread has nothing left to finish.
pub fn leave_dispatch(reactor: &Arc<Reactor>) {
    if let Some(registry) = REGISTRY.get() {
        registry.reactors.lock_unpoisoned().retain(|r| !Arc::ptr_eq(r, reactor));
    }
}

pub fn unregister(reactor: &Arc<Reactor>) {
    if let Some(registry) = REGISTRY.get() {
        registry.reactors.lock_unpoisoned().retain(|r| !Arc::ptr_eq(r, reactor));
    }
    let n = reactor.fail_pending();
    if n > 0 {
        tracing::warn!(pending = n, "php thread ended with requests in flight; answered 500");
    }
}

/// Registers the calling thread's reactor as a request target and, on the
/// first call, binds `address` and serves it forever on the runtime. Returns the
/// bound address. Later calls (other PHP threads) only register.
pub fn start(rt: &tokio::runtime::Handle, reactor: Arc<Reactor>, address: &str) -> Result<SocketAddr> {
    let registry = REGISTRY.get_or_init(|| Arc::new(Registry { reactors: Mutex::new(Vec::new()), next: AtomicUsize::new(0) }));
    let mut bound = BOUND.lock_unpoisoned();
    if let Some(b) = *bound {
        reactor.server_started();
        registry.reactors.lock_unpoisoned().push(reactor);
        return Ok(b);
    }
    let address: SocketAddr = address.parse().with_context(|| format!("bad listen address {address:?}"))?;
    let listener = match INHERITED.lock_unpoisoned().take() {
        Some(inherited) => adopt_inherited(rt, inherited, address)?,
        None => rt.block_on(TcpListener::bind(address)).with_context(|| format!("bind {address}"))?,
    };
    let local = listener.local_addr()?;
    *bound = Some(local);
    drop(bound);
    reactor.server_started();
    registry.reactors.lock_unpoisoned().push(reactor);
    rt.spawn(accept_loop(listener, registry.clone()));
    Ok(local)
}

/// The inherited socket is already bound, so the address PHP asks for can only be checked against
/// it: a mismatch is the operator's mistake, named rather than silently overridden.
fn adopt_inherited(rt: &tokio::runtime::Handle, inherited: std::net::TcpListener, asked: SocketAddr) -> Result<TcpListener> {
    let bound = inherited.local_addr()?;
    if bound != asked {
        anyhow::bail!("the workers' listener is bound to {bound} but the script asked to serve on {asked}");
    }
    inherited.set_nonblocking(true)?;
    let _enter = rt.enter();
    TcpListener::from_std(inherited).context("adopting the inherited listener")
}

/// The connection cap is this process's RSS bound, so the number has to be honest (ADR-0025):
/// V-37 measured a held connection's marginal cost at 33 kB queued, on top of a fiber's own ~34 kB
/// (V-5).
fn max_connections() -> usize {
    env_usize("IGNIS_MAX_CONNECTIONS", 8192)
}

/// Accepts until the drain closes the listener, giving every connection a semaphore permit it holds
/// for its whole life.
async fn accept_loop(listener: TcpListener, registry: Arc<Registry>) {
    let connection_permits = Arc::new(Semaphore::new(max_connections()));
    let header_timeout = env_ms("IGNIS_HEADER_TIMEOUT_MS", 10_000);
    let idle_timeout = env_ms("IGNIS_IDLE_TIMEOUT_MS", 60_000);
    loop {
        let accepted = tokio::select! {
            biased;
            _ = shutdown_signalled() => {
                tracing::info!("draining: listener closed, finishing in-flight requests");
                return;
            }
            r = listener.accept() => r,
        };
        let (stream, _peer) = match accepted {
            Ok(x) => x,
            Err(e) => {
                tracing::warn!(error = %e, "accept failed");
                continue;
            }
        };
        if let Err(error) = stream.set_nodelay(true) {
            tracing::warn!(%error, "set_nodelay failed; this connection may see Nagle-related latency");
        }
        let Ok(permit) = connection_permits.clone().try_acquire_owned() else {
            refuse_over_capacity(stream);
            continue;
        };
        tokio::spawn(serve_connection(stream, permit, registry.clone(), header_timeout, idle_timeout));
    }
}

/// Over the cap: answer inline and drop the socket rather than queue it — an accepted-but-unserved
/// connection is exactly the unbounded queueing the cap guards against. Reached through a
/// non-blocking `try_acquire_owned`, so the accept loop never stalls on the cap either.
fn refuse_over_capacity(mut stream: tokio::net::TcpStream) {
    tokio::spawn(async move {
        let _ = stream.write_all(b"HTTP/1.1 503 Service Unavailable\r\ncontent-length: 0\r\nconnection: close\r\n\r\n").await;
    });
}

/// One connection, for as long as it lives — `permit` is released when this returns. Each *request*
/// picks its own PHP thread, not each connection, so keep-alive connections still spread across
/// threads. Two timers guard it: `header_timeout` drops a client that never finishes sending
/// headers (slowloris, which would otherwise hold the connection and its permit forever), and
/// `idle_timeout` shuts a silent keep-alive connection down gracefully — `auto::Builder` has no
/// idle timer of its own, `header_read_timeout` covering only one request's header read.
async fn serve_connection(
    stream: tokio::net::TcpStream,
    permit: tokio::sync::OwnedSemaphorePermit,
    registry: Arc<Registry>,
    header_timeout: Duration,
    idle_timeout: Duration,
) {
    let _permit = permit;
    let last_activity = Arc::new(Mutex::new(Instant::now()));
    let activity = last_activity.clone();
    let service = service_fn(move |request| {
        *activity.lock_unpoisoned() = Instant::now();
        let r = registry.pick();
        async move {
            match r {
                Some(r) => handle(r, request).await,
                None => Ok(simple(StatusCode::SERVICE_UNAVAILABLE, "no php thread registered\n")),
            }
        }
    });
    let mut builder = auto::Builder::new(TokioExecutor::new());
    builder.http1().timer(TokioTimer::new()).header_read_timeout(header_timeout);
    let connection = builder.serve_connection(TokioIo::new(stream), service);
    tokio::pin!(connection);
    loop {
        let idle_for = last_activity.lock_unpoisoned().elapsed();
        if idle_for >= idle_timeout {
            connection.as_mut().graceful_shutdown();
            if let Err(e) = connection.as_mut().await {
                tracing::debug!(error = %e, "connection ended with error");
            }
            return;
        }
        tokio::select! {
            res = connection.as_mut() => {
                if let Err(e) = res {
                    tracing::debug!(error = %e, "connection ended with error");
                }
                return;
            }
            _ = tokio::time::sleep(idle_timeout - idle_for) => {}
        }
    }
}

/// M1: `/_ignis/health`, answered by the runtime and never by PHP. 200 while at least one PHP
/// thread is registered and not every one of them is stalled; 503 otherwise — so a load balancer
/// stops sending to a process whose workers are all wedged, which `/` from PHP could never report.
fn health() -> Response<tonic::body::Body> {
    let (stalled, total) = stalled_threads(Duration::from_secs(1));
    let restarts = crate::RESTARTS.load(Ordering::Relaxed);
    let ok = total > 0 && stalled < total && !is_draining();
    let body = format!(
        "{{\"status\":\"{}\",\"threads\":{total},\"stalled\":{stalled},\"restarts\":{restarts}}}\n",
        if ok {
            "ok"
        } else if is_draining() {
            "draining"
        } else {
            "unavailable"
        }
    );
    Response::builder()
        .status(if ok { StatusCode::OK } else { StatusCode::SERVICE_UNAVAILABLE })
        .header("content-type", "application/json")
        .header("cache-control", "no-store")
        .body(crate::grpc::plain_body(Bytes::from(body)))
        .unwrap()
}

/// M4-4: `/_ignis/metrics`, answered by the runtime and never by PHP, so it keeps answering when
/// every PHP thread is wedged — which is when an operator needs it most (ADR-0022).
fn metrics() -> Response<tonic::body::Body> {
    Response::builder()
        .status(StatusCode::OK)
        .header("content-type", "text/plain; version=0.0.4; charset=utf-8")
        .header("cache-control", "no-store")
        .body(crate::grpc::plain_body(Bytes::from(crate::metrics::render())))
        .unwrap()
}

/// If this future is dropped (client disconnect, ADR-0009) before PHP answers, the guard tells the
/// reactor to cancel the request's fiber.
struct CancelOnDrop {
    reactor: Arc<Reactor>,
    id: u64,
    answered: bool,
}

impl Drop for CancelOnDrop {
    fn drop(&mut self) {
        if !self.answered {
            tracing::debug!(id = self.id, "client left before the answer was complete; cancelling");
            self.reactor.cancel_request(self.id);
        }
    }
}

/// One request: the runtime's own `/_ignis/*` routes first, then gRPC (E10, ADR-0014 — it shares
/// this listener, tonic frames it and PHP serves it), then PHP. A responder dropped without an
/// answer means the handler crashed hard, and the client gets a 500 rather than a hung connection.
async fn handle(reactor: Arc<Reactor>, request: Request<Incoming>) -> Result<Response<tonic::body::Body>, hyper::Error> {
    match request.uri().path() {
        "/_ignis/health" => return Ok(health()),
        "/_ignis/metrics" => return Ok(metrics()),
        _ => {}
    }
    if crate::grpc::is_grpc(&request) {
        return Ok(crate::grpc::serve(reactor, request).await);
    }
    let (headers, uri) = request_parts(&request);
    let method = request.method().as_str().to_string();
    let body = match collect_body(request.into_body()).await {
        Ok(b) => b,
        Err((status, message)) => return Ok(simple(status, message)),
    };
    let (request_id, rx) = reactor.deliver_request_with_id(HttpRequest { method, uri, headers, body });
    let mut guard = CancelOnDrop { reactor: reactor.clone(), id: request_id, answered: false };
    let out = rx.await;
    match out {
        Ok(r) => {
            let streamed = matches!(r.body, crate::reactor::ResponseBody::Stream(_));
            guard.answered = disarmed_for_this_answer(streamed);
            Ok(php_response(r, guard))
        }
        Err(_) => {
            guard.answered = true;
            Ok(simple(StatusCode::INTERNAL_SERVER_ERROR, "no response from php\n"))
        }
    }
}

/// A streamed body outlives this match arm — the guard travels with it (see `ChannelBody`) and
/// disarms here only for a whole-body answer.
fn disarmed_for_this_answer(streamed: bool) -> bool {
    !streamed
}

/// Reads the request body, capped at `max_body_bytes()`. `Limited` errors as soon as one frame
/// would push the running total past the cap, so an over-cap body is never buffered in full — only
/// up to the frame that tripped it. The error is the status and text to answer with.
async fn collect_body(body: Incoming) -> Result<Bytes, (StatusCode, &'static str)> {
    match Limited::new(body, max_body_bytes()).collect().await {
        Ok(c) => Ok(c.to_bytes()),
        Err(e) => {
            if e.downcast_ref::<http_body_util::LengthLimitError>().is_some() {
                return Err((StatusCode::PAYLOAD_TOO_LARGE, "request body too large\n"));
            }
            tracing::debug!(error = %e, "body read failed");
            Err((StatusCode::BAD_REQUEST, "bad request body\n"))
        }
    }
}

/// Turns PHP's answer into hyper's. A streamed body (R-STREAM) starts writing now and is framed as
/// the rest arrives, so the client gets the first bytes while PHP is still producing the last.
fn php_response(r: crate::reactor::HttpResponse, guard: CancelOnDrop) -> Response<tonic::body::Body> {
    let mut b = Response::builder().status(r.status);
    for (k, v) in r.headers {
        b = b.header(k, v);
    }
    let body = match r.body {
        crate::reactor::ResponseBody::Full(bytes) => crate::grpc::plain_body(bytes),
        crate::reactor::ResponseBody::Stream(rx) => tonic::body::Body::new(ChannelBody { rx, guard: Some(guard) }),
    };
    b.body(body).unwrap_or_else(|_| simple(StatusCode::INTERNAL_SERVER_ERROR, "bad response headers\n"))
}

/// A response body PHP is still producing. Each `ignis_respond_chunk()` is one frame; the body ends
/// when the runtime drops the sender, which `ignis_respond_end()` does.
struct ChannelBody {
    rx: tokio::sync::mpsc::Receiver<Bytes>,
    /// R-STREAM-CANCEL: for a streamed answer the oneshot resolves when the *headers* go out, so
    /// disarming the guard there left the rest of the body uncancellable. It travels with the body
    /// instead, and a client that leaves mid-stream still cancels the producing fiber.
    guard: Option<CancelOnDrop>,
}

impl hyper::body::Body for ChannelBody {
    type Data = Bytes;
    type Error = std::convert::Infallible;

    fn poll_frame(
        mut self: std::pin::Pin<&mut Self>,
        cx: &mut std::task::Context<'_>,
    ) -> std::task::Poll<Option<Result<hyper::body::Frame<Bytes>, Self::Error>>> {
        let frame = self.rx.poll_recv(cx);
        if matches!(frame, std::task::Poll::Ready(None))
            && let Some(guard) = self.guard.as_mut()
        {
            disarm_on_stream_end(guard);
        }

        frame.map(|o| o.map(|b| Ok(hyper::body::Frame::data(b))))
    }
}

/// The stream ended because PHP finished it, not because the client left — nothing left to cancel.
fn disarm_on_stream_end(guard: &mut CancelOnDrop) {
    guard.answered = true;
}

fn simple(status: StatusCode, message: &'static str) -> Response<tonic::body::Body> {
    Response::builder()
        .status(status)
        .header("content-type", "text/plain")
        .body(crate::grpc::plain_body(Bytes::from_static(message.as_bytes())))
        .unwrap()
}

#[cfg(test)]
mod tests {
    use super::*;
    use crate::reactor::HttpRequest;

    fn runtime() -> tokio::runtime::Runtime {
        tokio::runtime::Builder::new_multi_thread().worker_threads(1).enable_all().build().unwrap()
    }

    fn registry_of(reactors: Vec<Arc<Reactor>>) -> Registry {
        Registry { reactors: Mutex::new(reactors), next: AtomicUsize::new(0) }
    }

    /// Keeps the receiver alive, so the request stays unanswered and counts as pending.
    fn deliver(reactor: &Arc<Reactor>) -> tokio::sync::oneshot::Receiver<crate::reactor::HttpResponse> {
        reactor.deliver_request_with_id(HttpRequest { method: "GET".into(), uri: "/".into(), headers: Vec::new(), body: Bytes::new() }).1
    }

    #[test]
    fn a_missing_or_unparsable_value_keeps_the_default() {
        assert_eq!(parse_or(None, 7usize), 7);
        assert_eq!(parse_or(Some("42".to_string()), 7usize), 42);
        assert_eq!(parse_or(Some("eight".to_string()), 7usize), 7);
        assert_eq!(parse_or(Some(String::new()), 7usize), 7);
        assert_eq!(env_usize("IGNIS_TEST_VARIABLE_THAT_IS_NEVER_SET", 5), 5);
        assert_eq!(env_ms("IGNIS_TEST_VARIABLE_THAT_IS_NEVER_SET", 250), Duration::from_millis(250));
    }

    #[test]
    fn request_parts_carries_the_headers_and_the_path_with_its_query() {
        let request = Request::builder().uri("/a/b?x=1").header("x-one", "1").header("x-two", "2").body(()).unwrap();
        let (headers, uri) = request_parts(&request);
        assert_eq!(uri, "/a/b?x=1");
        assert_eq!(headers, vec![("x-one".to_string(), "1".to_string()), ("x-two".to_string(), "2".to_string())]);
    }

    #[test]
    fn a_uri_without_a_path_becomes_a_slash() {
        let request = Request::builder().method("CONNECT").uri("example.com:443").body(()).unwrap();
        let (_, uri) = request_parts(&request);
        assert_eq!(uri, "/");
    }

    #[test]
    fn picking_from_an_empty_registry_gives_nothing() {
        assert!(registry_of(Vec::new()).pick().is_none());
    }

    #[test]
    fn an_idle_reactor_is_preferred_over_a_loaded_one() {
        let rt = runtime();
        let busy = Reactor::new(rt.handle());
        let idle = Reactor::new(rt.handle());
        let _pending = deliver(&busy);
        assert_eq!(busy.pending_requests(), 1);
        let registry = registry_of(vec![busy.clone(), idle.clone()]);
        for _ in 0..4 {
            assert!(Arc::ptr_eq(&registry.pick().unwrap(), &idle), "the loaded reactor was picked");
        }
    }

    #[test]
    fn equally_loaded_reactors_rotate() {
        let rt = runtime();
        let reactors: Vec<Arc<Reactor>> = (0..3).map(|_| Reactor::new(rt.handle())).collect();
        let registry = registry_of(reactors.clone());
        let picked: Vec<Arc<Reactor>> = (0..3).map(|_| registry.pick().unwrap()).collect();
        for reactor in &reactors {
            assert_eq!(picked.iter().filter(|p| Arc::ptr_eq(p, reactor)).count(), 1, "three calls must visit all three reactors");
        }
    }

    #[tokio::test]
    async fn health_is_unavailable_while_no_php_thread_is_registered() {
        let response = health();
        assert_eq!(response.status(), StatusCode::SERVICE_UNAVAILABLE);
        let body = response.into_body().collect().await.unwrap().to_bytes();
        let body = String::from_utf8(body.to_vec()).unwrap();
        assert!(body.contains("\"status\":\"unavailable\""), "{body}");
        assert!(body.contains("\"threads\":0"), "{body}");
    }

    /// No request is in flight, so `drain` returns on its first pass and never reaches
    /// `IGNIS_DRAIN_TIMEOUT_MS` — which a test cannot set under edition 2024.
    #[tokio::test]
    async fn drain_flips_the_flag_and_returns_with_nothing_pending() {
        assert!(!is_draining());
        let (took, pending) = drain().await;
        assert_eq!(pending, 0);
        assert!(took < Duration::from_secs(1), "drain took {took:?}");
        assert!(is_draining());
    }
}
