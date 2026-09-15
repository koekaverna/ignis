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
}

impl Reactor {
    /// Spawns the dispatcher task on `rt` and returns the PHP-side handle.
    pub fn new(rt: &tokio::runtime::Handle) -> Arc<Reactor> {
        let (to_tokio, mut rx) = mpsc::unbounded_channel::<(u64, Op)>();
        let (done_tx, from_tokio) = crossbeam_channel::unbounded::<Completion>();
        let done_for_task = done_tx.clone();
        rt.spawn(async move {
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
    pub fn deliver_request(&self, req: HttpRequest) -> oneshot::Receiver<HttpResponse> {
        let id = self.next_id.fetch_add(1, Ordering::Relaxed);
        let (tx, rx) = oneshot::channel();
        self.responders.lock().unwrap().insert(id, tx);
        self.inflight.fetch_add(1, Ordering::Relaxed);
        if self.done_tx.send(Completion { id, outcome: Outcome::Request(req) }).is_err() {
            // PHP thread gone: drop the responder so the connection gets a 500.
            self.responders.lock().unwrap().remove(&id);
        }
        rx
    }

    /// PHP-thread side: answer request `id`. Returns false if unknown/already answered.
    pub fn respond(&self, id: u64, resp: HttpResponse) -> bool {
        match self.responders.lock().unwrap().remove(&id) {
            Some(tx) => tx.send(resp).is_ok(),
            None => false,
        }
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
