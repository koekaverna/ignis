//! ADR-0043 §4 and §7: the ticker. Every `watch_tick` it reads every registered worker's
//! scoreboard slot, turns the age of the current stall episode into `stall` events by the route's
//! thresholds, classifies the stuck worker from `/proc`, and escalates: a kill delivered by signal
//! (L3/L4, `php/kill.rs`), then abandonment (L5) — the slot is marked, the reactor leaves dispatch
//! with its in-flight requests failed fast (E12'), the supervisor spawns a replacement, the thread
//! is leaked and counted. It also closes the alert module's windows once a second and writes the
//! blocking report on `SIGUSR2`. Runs on tokio; never touches PHP memory.
use std::collections::HashMap;
use std::sync::Mutex;
use std::sync::atomic::{AtomicU64, AtomicUsize, Ordering};
use std::time::Duration;

use crate::alerts::{Event, Key, Level};
use crate::lock::LockUnpoisoned;
use crate::recovery::Settings;
use crate::scoreboard::{self, ProcState, STATE_ABANDONED, STATE_BLOCKING, STATE_IDLE, STATE_PHP};

static LEAKED: AtomicU64 = AtomicU64::new(0);
static BLOCKED: AtomicUsize = AtomicUsize::new(0);
static ABANDONED: Mutex<Vec<usize>> = Mutex::new(Vec::new());

pub fn leaked_workers() -> u64 {
    LEAKED.load(Ordering::Relaxed)
}

/// Workers past `busy_warn_ms` at the last tick.
pub fn blocked_workers() -> usize {
    BLOCKED.load(Ordering::Relaxed)
}

/// Scoreboard slots abandoned since the last call; the supervisor replaces their workers.
pub fn take_abandoned() -> Vec<usize> {
    std::mem::take(&mut *ABANDONED.lock_unpoisoned())
}

/// `(kills, blocking_calls)` summed over every slot that ever registered.
pub fn slot_totals() -> (u64, u64) {
    scoreboard::slots().iter().fold((0, 0), |(k, b), s| (k + s.kills.load(Ordering::Relaxed), b + s.blocking_calls.load(Ordering::Relaxed)))
}

/// One stall episode of one worker: what the ticker has already done about it.
#[derive(Default)]
struct Episode {
    since_ns: u64,
    warned: bool,
    killed: bool,
    abandoned: bool,
}

pub fn spawn(rt: &tokio::runtime::Runtime) {
    let settings = Settings::global();
    rt.spawn(async move {
        let mut episodes: HashMap<usize, Episode> = HashMap::new();
        loop {
            tokio::time::sleep(settings.watch_tick).await;
            tick(settings, &mut episodes);
        }
    });
    rt.spawn(async {
        loop {
            tokio::time::sleep(Duration::from_secs(1)).await;
            crate::alerts::close_windows();
        }
    });
    rt.spawn(async {
        let Ok(mut signals) = tokio::signal::unix::signal(tokio::signal::unix::SignalKind::user_defined2()) else { return };
        while signals.recv().await.is_some() {
            crate::php::detector::write_report();
        }
    });
}

fn tick(settings: &'static Settings, episodes: &mut HashMap<usize, Episode>) {
    let now = scoreboard::monotonic_ns();
    let mut blocked = 0;
    for reactor in crate::http::reactors() {
        let Some(index) = reactor.slot() else { continue };
        let Some(slot) = scoreboard::slot(index) else { continue };
        let state = slot.state.load(Ordering::Acquire);
        if state == STATE_IDLE || state == STATE_ABANDONED || !slot.is_live() {
            episodes.remove(&index);
            continue;
        }
        let request_id = slot.request_id.load(Ordering::Relaxed);
        if reactor.pending_requests() == 0 && request_id == 0 {
            episodes.remove(&index);
            continue;
        }
        let since = slot.php_since_ns.load(Ordering::Relaxed);
        let episode = episodes.entry(index).or_default();
        if episode.since_ns != since {
            *episode = Episode { since_ns: since, ..Episode::default() };
        }
        let age_ms = now.saturating_sub(since) / 1_000_000;
        let uri = reactor.uri_of(request_id).unwrap_or_default();
        let route = uri.split_once('?').map_or(uri.as_str(), |(p, _)| p).to_string();
        let site = scoreboard::site_name(slot.site.load(Ordering::Relaxed));
        let subject = if state == STATE_BLOCKING && !site.is_empty() {
            format!("blocking_forward:{site}")
        } else {
            scoreboard::state_name(state).to_string()
        };
        let warn_ms = settings.busy_warn_ms_for(&uri);
        let kill_ms = settings.stall_kill_ms_for(&uri);
        let abandon_ms = settings.stall_abandon_ms_for(&uri);
        if warn_ms > 0 && age_ms >= warn_ms {
            blocked += 1;
        }
        let fields = |proc_state: &ProcState| {
            vec![
                ("worker", index.to_string()),
                ("worker_no", slot.worker_no.load(Ordering::Relaxed).to_string()),
                ("tid", slot.tid.load(Ordering::Relaxed).to_string()),
                ("state", scoreboard::state_name(state).to_string()),
                ("site", site.clone()),
                ("age_ms", age_ms.to_string()),
                ("request", request_id.to_string()),
                ("uri", uri.clone()),
                ("fiber", format!("{:#x}", slot.fiber.load(Ordering::Relaxed))),
                ("classification", proc_state.describe()),
                ("in_syscall_outside_shim", (state == STATE_PHP && !proc_state.is_running()).to_string()),
                ("fiber_timeout_ms", settings.fiber_timeout_ms_for(&uri).to_string()),
                ("acks", slot.interrupt_acks.load(Ordering::Relaxed).to_string()),
            ]
        };
        if !episode.warned && warn_ms > 0 && age_ms >= warn_ms {
            episode.warned = true;
            let proc_state = ProcState::read(slot.pid.load(Ordering::Relaxed), slot.tid.load(Ordering::Relaxed));
            crate::alerts::report(Event {
                key: Key::new("stall", subject.clone(), route.clone()),
                level: Level::Warn,
                value_us: age_ms * 1000,
                fields: fields(&proc_state),
            });
        }
        if !episode.killed && kill_ms > 0 && age_ms >= kill_ms {
            episode.killed = true;
            let proc_state = ProcState::read(slot.pid.load(Ordering::Relaxed), slot.tid.load(Ordering::Relaxed));
            let mut f = fields(&proc_state);
            if proc_state.is_uninterruptible() {
                f.push(("action", "none: uninterruptible sleep, waiting for stall_abandon_ms".into()));
            } else if proc_state.is_lock_wait() {
                f.push(("action", "none: lock wait, waiting for stall_abandon_ms".into()));
            } else {
                let delivered = crate::php::kill::deliver(slot);
                f.push(("action", format!("kill signal {}", if delivered { "delivered" } else { "NOT delivered" })));
                if state == STATE_BLOCKING {
                    f.push(("level", "L4".into()));
                } else {
                    f.push(("level", "L3".into()));
                }
            }
            crate::alerts::report(Event {
                key: Key::new("stall", subject.clone(), route.clone()),
                level: Level::Error,
                value_us: age_ms * 1000,
                fields: f,
            });
        }
        if !episode.abandoned && abandon_ms > 0 && age_ms >= abandon_ms {
            episode.abandoned = true;
            abandon(index, &reactor, &subject, &route, age_ms);
        }
    }
    BLOCKED.store(blocked, Ordering::Relaxed);
}

/// L5: the worker keeps running wherever it is; everything else moves on without it.
fn abandon(index: usize, reactor: &std::sync::Arc<crate::reactor::Reactor>, subject: &str, route: &str, age_ms: u64) {
    let Some(slot) = scoreboard::slot(index) else { return };
    slot.state.store(STATE_ABANDONED, Ordering::Release);
    let failed = reactor.fail_pending();
    crate::http::leave_dispatch(reactor);
    let leaked = LEAKED.fetch_add(1, Ordering::Relaxed) + 1;
    let fields = vec![
        ("worker", index.to_string()),
        ("worker_no", slot.worker_no.load(Ordering::Relaxed).to_string()),
        ("age_ms", age_ms.to_string()),
        ("failed_requests", failed.to_string()),
        ("leaked_workers", leaked.to_string()),
        ("acks", slot.interrupt_acks.load(Ordering::Relaxed).to_string()),
    ];
    crate::alerts::report(Event {
        key: Key::new("worker_abandoned", subject.to_string(), route.to_string()),
        level: Level::Critical,
        value_us: age_ms * 1000,
        fields,
    });
    if cfg!(php_nts) {
        tracing::error!(worker = index, "the only interpreter of this process is stuck; exiting 3 for the external supervisor");
        crate::php::detector::write_report();
        std::process::exit(3);
    }
    ABANDONED.lock_unpoisoned().push(index);
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn totals_start_at_zero_and_abandoned_queue_is_taken_once() {
        assert_eq!(leaked_workers(), 0);
        ABANDONED.lock_unpoisoned().push(3);
        assert_eq!(take_abandoned(), vec![3]);
        assert!(take_abandoned().is_empty());
    }

    #[test]
    fn an_idle_worker_is_no_episode() {
        let mut episodes = HashMap::new();
        tick(Settings::global(), &mut episodes);
        assert!(episodes.is_empty());
        assert_eq!(blocked_workers(), 0);
    }
}
