//! M4-4: the numbers `/_ignis/metrics` serves, in Prometheus text format.
//!
//! Two sources, one renderer. What the Rust side already knows (threads, stalls, restarts,
//! in-flight requests and ops, failed parks, pool leases) is read straight from the registry when
//! the request arrives — no bookkeeping in between. What only the PHP loop knows (the fiber
//! budget, the wait queue, rejections, the fiber pool) is **published** by each loop into its own
//! reactor's slots, once per loop turn, next to the `ignis_poll()` it was already about to make.
//! Per-reactor and not global because there is one loop per thread: a single set of counters would
//! be last-writer-wins, i.e. wrong under load, which is exactly when it is read.
//!
//! A stalled thread simply stops publishing, and `ignis_stats_published_age_seconds` says so —
//! better than a plausible-looking stale number. ADR-0022: the endpoint is answered by the
//! runtime, never by PHP, so it keeps answering while every PHP thread is wedged.

use std::fmt::Write;
use std::sync::atomic::{AtomicU64, Ordering};
use std::time::Instant;

use crate::php::park::PARK_FAILED;

/// What one PHP loop publishes about itself. Lives on that thread's `Reactor`.
#[derive(Default, Debug)]
pub struct Published {
    pub budget: AtomicU64,
    pub queue_depth: AtomicU64,
    pub queued: AtomicU64,
    pub queued_peak: AtomicU64,
    pub queued_admitted: AtomicU64,
    pub rejected: AtomicU64,
    pub fibers_idle: AtomicU64,
    pub fibers_created: AtomicU64,
    pub resumes: AtomicU64,
    pub handled: AtomicU64,
    /// Wall-clock ms of the last publish; 0 = this loop has never published.
    pub at_ms: AtomicU64,
}

impl Published {
    pub fn set(&self, field: &str, v: u64) {
        let slot = match field {
            "budget" => &self.budget,
            "queue_depth" => &self.queue_depth,
            "queued" => &self.queued,
            "queued_peak" => &self.queued_peak,
            "queued_admitted" => &self.queued_admitted,
            "rejected" => &self.rejected,
            "fibers_idle" => &self.fibers_idle,
            "fibers_created" => &self.fibers_created,
            "resumes" => &self.resumes,
            "handled" => &self.handled,
            _ => return,
        };
        slot.store(v, Ordering::Relaxed);
    }

    pub fn stamp(&self) {
        self.at_ms.store(now_ms(), Ordering::Relaxed);
    }
}

static START: std::sync::OnceLock<Instant> = std::sync::OnceLock::new();

pub fn mark_start() {
    let _ = START.set(Instant::now());
}

fn now_ms() -> u64 {
    std::time::SystemTime::now().duration_since(std::time::UNIX_EPOCH).map_or(0, |d| d.as_millis() as u64)
}

fn metric(out: &mut String, name: &str, kind: &str, help: &str, value: impl std::fmt::Display) {
    let _ = writeln!(out, "# HELP {name} {help}");
    let _ = writeln!(out, "# TYPE {name} {kind}");
    let _ = writeln!(out, "{name} {value}");
}

/// The whole document. A few dozen lines and two mutex reads — cheap enough per request that no
/// caching is worth the staleness it would add.
pub fn render() -> String {
    let mut out = String::with_capacity(4096);

    let _ = writeln!(out, "# HELP ignis_build_info Always 1; the label carries the version.");
    let _ = writeln!(out, "# TYPE ignis_build_info gauge");
    let _ = writeln!(out, "ignis_build_info{{version=\"{}\"}} 1", env!("CARGO_PKG_VERSION"));

    metric(&mut out, "ignis_uptime_seconds", "gauge", "Seconds since the runtime started.", START.get().map_or(0, |t| t.elapsed().as_secs()));

    let (stalled, threads) = crate::http::stalled_threads(std::time::Duration::from_secs(1));
    metric(&mut out, "ignis_threads", "gauge", "PHP worker threads registered for dispatch.", threads);
    metric(&mut out, "ignis_threads_stalled", "gauge", "Threads with pending requests that have not entered the reactor for 1 s.", stalled);
    metric(&mut out, "ignis_thread_restarts_total", "counter", "Worker threads respawned by the supervisor (ADR-0012).", crate::RESTARTS.load(Ordering::Relaxed));
    metric(&mut out, "ignis_park_failed_total", "counter", "Calls whose policy says park that could not park and blocked the thread (ADR-0037 §4).", PARK_FAILED.load(Ordering::Relaxed));

    let t = crate::http::totals();
    metric(&mut out, "ignis_requests_inflight", "gauge", "Requests dispatched to a PHP thread and not yet answered.", t.pending);
    metric(&mut out, "ignis_ops_inflight", "gauge", "Reactor operations submitted and not yet completed.", t.ops);
    metric(&mut out, "ignis_stats_published_age_seconds", "gauge", "Age of the oldest PHP loop's published numbers; grows without bound while a loop is wedged.", t.oldest_publish_age_ms as f64 / 1000.0);
    metric(&mut out, "ignis_fiber_budget", "gauge", "Concurrent request fibers allowed per thread (ADR-0019); 0 = unlimited.", t.budget);
    metric(&mut out, "ignis_queue_depth_limit", "gauge", "Waiting requests allowed past the budget before a 503, per thread.", t.queue_depth);
    metric(&mut out, "ignis_requests_queued", "gauge", "Requests waiting for a fiber right now.", t.queued);
    metric(&mut out, "ignis_requests_queued_peak", "gauge", "High-water mark of the wait queue, summed over threads.", t.queued_peak);
    metric(&mut out, "ignis_requests_queued_admitted_total", "counter", "Requests that waited in the queue and were then admitted.", t.queued_admitted);
    metric(&mut out, "ignis_requests_rejected_total", "counter", "Requests answered 503 because the queue was full.", t.rejected);
    metric(&mut out, "ignis_requests_handled_total", "counter", "Requests the PHP side has finished answering.", t.handled);
    metric(&mut out, "ignis_fibers_idle", "gauge", "Parked fibers in the pool, reusable without allocation (V-4).", t.fibers_idle);
    metric(&mut out, "ignis_fibers_created_total", "counter", "Fibers the pool has had to allocate.", t.fibers_created);
    metric(&mut out, "ignis_fiber_resumes_total", "counter", "Fiber starts and resumes performed by the loops.", t.resumes);

    let (oldest_ms, over_warn, leases) = crate::pg::lease_metrics();
    metric(&mut out, "ignis_pg_leases", "gauge", "PostgreSQL connections leased to a fiber right now (ADR-0015).", leases);
    metric(&mut out, "ignis_pg_lease_age_seconds_max", "gauge", "Age of the oldest live lease; older than IGNIS_PG_LEASE_WARN_MS means a held connection.", oldest_ms as f64 / 1000.0);
    metric(&mut out, "ignis_pg_leases_over_warn", "gauge", "Live leases older than IGNIS_PG_LEASE_WARN_MS.", over_warn);

    out
}

/// Everything summed over the registered threads, gathered in one pass.
#[derive(Default)]
pub struct Totals {
    pub pending: usize,
    pub ops: u64,
    pub oldest_publish_age_ms: u64,
    pub budget: u64,
    pub queue_depth: u64,
    pub queued: u64,
    pub queued_peak: u64,
    pub queued_admitted: u64,
    pub rejected: u64,
    pub handled: u64,
    pub fibers_idle: u64,
    pub fibers_created: u64,
    pub resumes: u64,
}

impl Totals {
    pub fn add(&mut self, r: &crate::reactor::Reactor) {
        let p = &r.published;
        self.pending += r.pending_requests();
        self.ops += r.inflight();
        // budget and queue_depth are per-thread settings, identical across threads: report one.
        self.budget = self.budget.max(p.budget.load(Ordering::Relaxed));
        self.queue_depth = self.queue_depth.max(p.queue_depth.load(Ordering::Relaxed));
        self.queued += p.queued.load(Ordering::Relaxed);
        self.queued_peak += p.queued_peak.load(Ordering::Relaxed);
        self.queued_admitted += p.queued_admitted.load(Ordering::Relaxed);
        self.rejected += p.rejected.load(Ordering::Relaxed);
        self.handled += p.handled.load(Ordering::Relaxed);
        self.fibers_idle += p.fibers_idle.load(Ordering::Relaxed);
        self.fibers_created += p.fibers_created.load(Ordering::Relaxed);
        self.resumes += p.resumes.load(Ordering::Relaxed);
        let at = p.at_ms.load(Ordering::Relaxed);
        if at != 0 {
            self.oldest_publish_age_ms = self.oldest_publish_age_ms.max(now_ms().saturating_sub(at));
        }
    }
}
