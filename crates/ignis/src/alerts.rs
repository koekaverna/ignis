//! ADR-0043 §6: every emitter hands events here and this decides what becomes a log line — first
//! per key, one summary per window, escalation once, recovery, a token bucket. Time is injected.
use std::collections::{BTreeMap, HashMap};
use std::sync::Mutex;
use std::time::{Duration, Instant};

use crate::lock::LockUnpoisoned;

#[derive(Clone, Copy, PartialEq, Eq, PartialOrd, Ord, Debug, Hash)]
pub enum Level {
    Info,
    Warn,
    Error,
    Critical,
}

impl Level {
    pub fn name(self) -> &'static str {
        match self {
            Level::Info => "info",
            Level::Warn => "warn",
            Level::Error => "error",
            Level::Critical => "critical",
        }
    }
}

/// What deduplicates: the kind of event, its subject (a `library:symbol`, a worker, a fiber
/// level) and the route it happened on.
#[derive(Clone, PartialEq, Eq, Hash, Debug, PartialOrd, Ord)]
pub struct Key {
    pub kind: &'static str,
    pub subject: String,
    pub route: String,
}

impl Key {
    pub fn new(kind: &'static str, subject: impl Into<String>, route: impl Into<String>) -> Key {
        Key { kind, subject: subject.into(), route: route.into() }
    }
}

#[derive(Clone, Debug)]
pub struct Event {
    pub key: Key,
    pub level: Level,
    /// The event's magnitude in microseconds (a blocking call's duration, a stall's age); 0 when
    /// there is none. Summaries report its max and median.
    pub value_us: u64,
    pub fields: Vec<(&'static str, String)>,
}

/// A line the module decided to log.
#[derive(Clone, Debug, PartialEq, Eq)]
pub struct Line {
    pub level: Level,
    pub what: &'static str,
    pub key: Key,
    pub fields: Vec<(String, String)>,
}

#[derive(Clone, Copy, Debug)]
pub struct Policy {
    pub window: Duration,
    pub escalate_count: u64,
    pub recover_windows: u32,
    pub max_lines_per_s: u32,
}

impl Default for Policy {
    fn default() -> Policy {
        Policy { window: Duration::from_secs(60), escalate_count: 100, recover_windows: 5, max_lines_per_s: 20 }
    }
}

/// The reservoir a summary's median comes from: the last `MEDIAN_SAMPLES` magnitudes of the window.
const MEDIAN_SAMPLES: usize = 64;

struct KeyState {
    level: Level,
    window_start: Instant,
    count_in_window: u64,
    max_in_window_us: u64,
    samples: Vec<u64>,
    escalated_by_count: bool,
    silent_windows: u32,
    total: u64,
    max_total_us: u64,
    last_fields: Vec<(&'static str, String)>,
}

struct Inner {
    keys: HashMap<Key, KeyState>,
    /// Since boot, never forgotten: a key that recovers leaves `keys` but not the scrape, because a
    /// counter that resets when its subject goes quiet is not a counter.
    lifetime: HashMap<Key, Lifetime>,
    bucket: f64,
    bucket_at: Instant,
    dropped: u64,
    unhealthy_workers: BTreeMap<String, Level>,
}

pub struct Alerts {
    policy: Policy,
    inner: Mutex<Inner>,
}

struct Lifetime {
    level: Level,
    count: u64,
    max_us: u64,
}

/// A key's lifetime totals, for the scrape.
#[derive(Clone, Debug, PartialEq, Eq)]
pub struct Total {
    pub key: Key,
    pub level: Level,
    pub count: u64,
    pub max_us: u64,
}

impl Alerts {
    pub fn new(policy: Policy, now: Instant) -> Alerts {
        Alerts {
            policy,
            inner: Mutex::new(Inner {
                keys: HashMap::new(),
                lifetime: HashMap::new(),
                bucket: policy.max_lines_per_s as f64,
                bucket_at: now,
                dropped: 0,
                unhealthy_workers: BTreeMap::new(),
            }),
        }
    }

    /// Records `event` at `now`; returns the lines to log (already rate-limited).
    pub fn emit(&self, event: Event, now: Instant) -> Vec<Line> {
        let mut inner = self.inner.lock_unpoisoned();
        let mut lines = Vec::new();
        let policy = self.policy;
        let is_new = !inner.keys.contains_key(&event.key);
        let state = inner.keys.entry(event.key.clone()).or_insert_with(|| KeyState {
            level: event.level,
            window_start: now,
            count_in_window: 0,
            max_in_window_us: 0,
            samples: Vec::new(),
            escalated_by_count: false,
            silent_windows: 0,
            total: 0,
            max_total_us: 0,
            last_fields: Vec::new(),
        });
        state.count_in_window += 1;
        state.total += 1;
        state.max_in_window_us = state.max_in_window_us.max(event.value_us);
        state.max_total_us = state.max_total_us.max(event.value_us);
        state.silent_windows = 0;
        if state.samples.len() == MEDIAN_SAMPLES {
            state.samples.remove(0);
        }
        state.samples.push(event.value_us);
        state.last_fields = event.fields.clone();
        if is_new {
            lines.push(Line { level: event.level, what: "first", key: event.key.clone(), fields: owned(&event.fields) });
        } else if event.level > state.level {
            state.level = event.level;
            let mut fields = owned(&event.fields);
            fields.push(("escalated".into(), format!("level {}", event.level.name())));
            lines.push(Line { level: event.level, what: "escalated", key: event.key.clone(), fields });
        } else if !state.escalated_by_count && state.count_in_window >= policy.escalate_count && state.level < Level::Error {
            state.escalated_by_count = true;
            state.level = Level::Error;
            let mut fields = owned(&event.fields);
            fields.push(("escalated".into(), format!("{} in one window", state.count_in_window)));
            lines.push(Line { level: Level::Error, what: "escalated", key: event.key.clone(), fields });
        }
        let level = state.level;
        let lifetime = inner.lifetime.entry(event.key.clone()).or_insert(Lifetime { level, count: 0, max_us: 0 });
        lifetime.count += 1;
        lifetime.max_us = lifetime.max_us.max(event.value_us);
        lifetime.level = lifetime.level.max(level);
        if event.level == Level::Critical
            && let Some(worker) = event.fields.iter().find(|(k, _)| *k == "worker")
        {
            inner.unhealthy_workers.insert(worker.1.clone(), Level::Critical);
        }
        Self::rate_limit(&mut inner, policy, now, lines)
    }

    /// Closes every window that has elapsed at `now`: summaries, recoveries.
    pub fn tick(&self, now: Instant) -> Vec<Line> {
        let mut inner = self.inner.lock_unpoisoned();
        let policy = self.policy;
        let mut lines = Vec::new();
        let mut forgotten = Vec::new();
        for (key, state) in inner.keys.iter_mut() {
            if now.duration_since(state.window_start) < policy.window {
                continue;
            }
            if state.count_in_window >= 2 {
                let fields = vec![
                    ("count".to_string(), state.count_in_window.to_string()),
                    ("max_us".to_string(), state.max_in_window_us.to_string()),
                    ("p50_us".to_string(), median(&state.samples).to_string()),
                    ("total".to_string(), state.total.to_string()),
                ];
                lines.push(Line { level: state.level, what: "summary", key: key.clone(), fields });
            }
            if state.count_in_window == 0 {
                state.silent_windows += 1;
                if state.silent_windows >= policy.recover_windows {
                    let fields =
                        vec![("total".to_string(), state.total.to_string()), ("max_us".to_string(), state.max_total_us.to_string())];
                    lines.push(Line { level: Level::Info, what: "recovered", key: key.clone(), fields });
                    forgotten.push(key.clone());
                }
            }
            state.window_start = now;
            state.count_in_window = 0;
            state.max_in_window_us = 0;
            state.samples.clear();
            state.escalated_by_count = false;
        }
        for key in forgotten {
            inner.keys.remove(&key);
        }
        Self::rate_limit(&mut inner, policy, now, lines)
    }

    /// Clears a worker's critical mark (its replacement is serving).
    pub fn worker_recovered(&self, worker: &str) {
        self.inner.lock_unpoisoned().unhealthy_workers.remove(worker);
    }

    pub fn unhealthy_workers(&self) -> Vec<String> {
        self.inner.lock_unpoisoned().unhealthy_workers.keys().cloned().collect()
    }

    pub fn dropped(&self) -> u64 {
        self.inner.lock_unpoisoned().dropped
    }

    /// Every key seen since boot with its lifetime count, for `/metrics` and the report.
    pub fn totals(&self) -> Vec<Total> {
        let inner = self.inner.lock_unpoisoned();
        let mut out: Vec<Total> =
            inner.lifetime.iter().map(|(k, l)| Total { key: k.clone(), level: l.level, count: l.count, max_us: l.max_us }).collect();
        out.sort_by(|a, b| a.key.cmp(&b.key));
        out
    }

    fn rate_limit(inner: &mut Inner, policy: Policy, now: Instant, lines: Vec<Line>) -> Vec<Line> {
        let cap = policy.max_lines_per_s as f64;
        let elapsed = now.duration_since(inner.bucket_at).as_secs_f64();
        inner.bucket = (inner.bucket + elapsed * cap).min(cap);
        inner.bucket_at = now;
        let mut kept = Vec::with_capacity(lines.len());
        for line in lines {
            let must_pass = line.level == Level::Critical || line.what == "escalated";
            if must_pass || inner.bucket >= 1.0 {
                if !must_pass {
                    inner.bucket -= 1.0;
                }
                kept.push(line);
            } else {
                inner.dropped += 1;
            }
        }
        kept
    }
}

fn owned(fields: &[(&'static str, String)]) -> Vec<(String, String)> {
    fields.iter().map(|(k, v)| ((*k).to_string(), v.clone())).collect()
}

fn median(samples: &[u64]) -> u64 {
    if samples.is_empty() {
        return 0;
    }
    let mut sorted = samples.to_vec();
    sorted.sort_unstable();
    sorted[sorted.len() / 2]
}

/// The process-wide instance, configured from `recovery::Settings`.
pub fn global() -> &'static Alerts {
    static ALERTS: std::sync::OnceLock<Alerts> = std::sync::OnceLock::new();
    ALERTS.get_or_init(|| {
        let s = crate::recovery::Settings::global();
        Alerts::new(
            Policy {
                window: s.alert_window,
                escalate_count: s.alert_escalate_count,
                recover_windows: s.alert_recover_windows,
                max_lines_per_s: s.alert_max_lines_per_s,
            },
            Instant::now(),
        )
    })
}

/// Records an event on the global instance and logs whatever it decided.
pub fn report(event: Event) {
    let lines = global().emit(event, Instant::now());
    log_lines(&lines);
}

/// Closes elapsed windows on the global instance and logs summaries and recoveries.
pub fn close_windows() {
    let lines = global().tick(Instant::now());
    log_lines(&lines);
}

fn log_lines(lines: &[Line]) {
    for line in lines {
        let fields = line.fields.iter().map(|(k, v)| format!("{k}={v}")).collect::<Vec<_>>().join(" ");
        let kind = line.key.kind;
        let subject = line.key.subject.as_str();
        let route = line.key.route.as_str();
        let what = line.what;
        match line.level {
            Level::Info => tracing::info!(kind, subject, route, what, %fields, "ignis alert"),
            Level::Warn => tracing::warn!(kind, subject, route, what, %fields, "ignis alert"),
            Level::Error | Level::Critical => tracing::error!(kind, subject, route, what, %fields, "ignis alert"),
        }
    }
}

#[cfg(test)]
mod tests {
    use super::*;

    fn policy() -> Policy {
        Policy { window: Duration::from_secs(60), escalate_count: 100, recover_windows: 5, max_lines_per_s: 20 }
    }

    fn event(level: Level, value_us: u64) -> Event {
        Event { key: Key::new("blocking_call", "libphp:read", "/report"), level, value_us, fields: vec![("worker", "1".into())] }
    }

    fn at(t0: Instant, secs: u64) -> Instant {
        t0 + Duration::from_secs(secs)
    }

    #[test]
    fn the_first_event_logs_and_repeats_in_the_window_do_not() {
        let t0 = Instant::now();
        let a = Alerts::new(policy(), t0);
        let first = a.emit(event(Level::Warn, 1000), t0);
        assert_eq!(first.len(), 1);
        assert_eq!(first[0].what, "first");
        assert_eq!(first[0].level, Level::Warn);
        for i in 1..50 {
            assert!(a.emit(event(Level::Warn, 1000 + i), at(t0, i)).is_empty(), "repeat {i} logged");
        }
    }

    #[test]
    fn the_window_end_emits_one_summary_with_count_max_and_median() {
        let t0 = Instant::now();
        let a = Alerts::new(policy(), t0);
        for i in 0..10u64 {
            a.emit(event(Level::Warn, (i + 1) * 100), at(t0, i));
        }
        assert!(a.tick(at(t0, 59)).is_empty(), "no summary before the window ends");
        let lines = a.tick(at(t0, 60));
        assert_eq!(lines.len(), 1);
        assert_eq!(lines[0].what, "summary");
        let f = |k: &str| lines[0].fields.iter().find(|(kk, _)| kk == k).map(|(_, v)| v.clone()).unwrap();
        assert_eq!(f("count"), "10");
        assert_eq!(f("max_us"), "1000");
        assert_eq!(f("p50_us"), "600");
        assert!(a.tick(at(t0, 120)).is_empty(), "a quiet window has no summary");
    }

    #[test]
    fn a_single_event_in_a_window_gets_no_summary() {
        let t0 = Instant::now();
        let a = Alerts::new(policy(), t0);
        a.emit(event(Level::Warn, 5), t0);
        assert!(a.tick(at(t0, 60)).is_empty());
    }

    #[test]
    fn the_escalation_threshold_escalates_exactly_once_to_error() {
        let t0 = Instant::now();
        let a = Alerts::new(policy(), t0);
        let mut escalations = 0;
        for i in 0..300u64 {
            for line in a.emit(event(Level::Warn, 1), t0 + Duration::from_millis(i)) {
                if line.what == "escalated" {
                    escalations += 1;
                    assert_eq!(line.level, Level::Error);
                    assert_eq!(i, 99, "the 100th event escalates");
                }
            }
        }
        assert_eq!(escalations, 1);
        assert_eq!(a.totals()[0].level, Level::Error);
    }

    #[test]
    fn a_higher_level_on_a_known_key_logs_once_per_step() {
        let t0 = Instant::now();
        let a = Alerts::new(policy(), t0);
        a.emit(event(Level::Warn, 1), t0);
        let up = a.emit(event(Level::Error, 2), at(t0, 1));
        assert_eq!(up.len(), 1);
        assert_eq!((up[0].what, up[0].level), ("escalated", Level::Error));
        assert!(a.emit(event(Level::Error, 3), at(t0, 2)).is_empty());
        assert!(a.emit(event(Level::Warn, 3), at(t0, 3)).is_empty(), "a lower level never de-escalates inside a window");
        let crit = a.emit(event(Level::Critical, 4), at(t0, 4));
        assert_eq!(crit[0].level, Level::Critical);
        assert_eq!(a.unhealthy_workers(), vec!["1".to_string()]);
        a.worker_recovered("1");
        assert!(a.unhealthy_workers().is_empty());
    }

    #[test]
    fn a_key_silent_for_the_recover_windows_is_reported_once_and_forgotten() {
        let t0 = Instant::now();
        let a = Alerts::new(policy(), t0);
        a.emit(event(Level::Warn, 1), t0);
        a.emit(event(Level::Warn, 1), at(t0, 1));
        let mut recovered = 0;
        for w in 1..=8u64 {
            for line in a.tick(at(t0, 60 * w)) {
                if line.what == "recovered" {
                    recovered += 1;
                    assert_eq!(line.level, Level::Info);
                    assert_eq!(w, 6, "one summary window, then five silent ones");
                }
            }
        }
        assert_eq!(recovered, 1);
        assert_eq!(a.totals()[0].count, 2, "forgotten for dedup, kept for the scrape");
        let again = a.emit(event(Level::Warn, 1), at(t0, 600));
        assert_eq!(again[0].what, "first", "a forgotten key logs afresh");
        assert_eq!(a.totals()[0].count, 3, "a counter never restarts");
    }

    #[test]
    fn two_keys_differing_only_in_route_are_two_lines() {
        let t0 = Instant::now();
        let a = Alerts::new(policy(), t0);
        let mut other = event(Level::Warn, 1);
        other.key.route = "/other".into();
        assert_eq!(a.emit(event(Level::Warn, 1), t0).len(), 1);
        assert_eq!(a.emit(other, t0).len(), 1);
        assert_eq!(a.totals().len(), 2);
    }

    #[test]
    fn the_token_bucket_drops_the_twenty_first_line_in_a_second_and_counts_it() {
        let t0 = Instant::now();
        let a = Alerts::new(policy(), t0);
        let mut logged = 0;
        for i in 0..30u64 {
            let mut e = event(Level::Warn, 1);
            e.key.subject = format!("lib{i}:read");
            logged += a.emit(e, t0).len();
        }
        assert_eq!(logged, 20);
        assert_eq!(a.dropped(), 10);
        let mut e = event(Level::Warn, 1);
        e.key.subject = "later:read".into();
        assert_eq!(a.emit(e, at(t0, 1)).len(), 1, "the bucket refills");
    }

    /// The invariant research 50 S-4 states: lines per key per window ≤ 3 — first, one escalation,
    /// one summary — for any event sequence.
    #[test]
    fn lines_per_key_per_window_never_exceed_three() {
        let t0 = Instant::now();
        let mut seed = 0x9E3779B97F4A7C15u64;
        let mut next = || {
            seed ^= seed << 13;
            seed ^= seed >> 7;
            seed ^= seed << 17;
            seed
        };
        for _ in 0..50 {
            let a = Alerts::new(Policy { max_lines_per_s: 1_000_000, ..policy() }, t0);
            let mut per_window: HashMap<(Key, u64), usize> = HashMap::new();
            let events = 1 + (next() % 400);
            for _ in 0..events {
                let ms = next() % 180_000;
                let level = match next() % 3 {
                    0 => Level::Warn,
                    1 => Level::Error,
                    _ => Level::Warn,
                };
                let mut e = event(level, next() % 1000);
                e.key.route = format!("/{}", next() % 3);
                let when = t0 + Duration::from_millis(ms);
                for line in a.emit(e, when) {
                    *per_window.entry((line.key.clone(), ms / 60_000)).or_default() += 1;
                }
            }
            for w in 1..=4u64 {
                for line in a.tick(at(t0, 60 * w)) {
                    if line.what == "summary" {
                        *per_window.entry((line.key.clone(), w - 1)).or_default() += 1;
                    }
                }
            }
            for ((key, window), n) in per_window {
                assert!(n <= 3, "{key:?} window {window}: {n} lines");
            }
        }
    }
}
