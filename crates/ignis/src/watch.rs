//! Development reload: watch the files PHP actually loaded (research 40).
//!
//! Node's model — `--watch` follows "the entry point and any required or imported module" rather
//! than directory trees — because the loaded set *is* the dependency graph: measured on the E21
//! fixture at 283 included files against 4,621 PHP files on disk under `vendor/`, with Symfony's
//! compiled container among them because the application loaded it. There is no ignore list here
//! for the same reason there is no glob.
//!
//! What is watched is the **parent directories** of those files, not the files: an editor saves by
//! writing a temporary file and renaming it over the original, which leaves a per-file inotify watch
//! pointing at an inode nobody will write to again. Directories survive that, and 283 files live in
//! far fewer of them. Events are then filtered back through the known file set, so a neighbouring
//! file the application never loaded changes nothing.
//!
//! Not a fourth mechanism (ADR-0037): this produces a flag the userland loop reads at its own turn,
//! exactly as it reads `Ignis\stop()`. No fiber ever waits on a file event.

use std::collections::HashSet;
use std::path::{Path, PathBuf};
use std::sync::atomic::{AtomicBool, AtomicU64, Ordering};
use std::sync::{Mutex, OnceLock};
use std::time::{SystemTime, UNIX_EPOCH};

use crate::lock::LockUnpoisoned;
use notify::{Event, RecursiveMode, Watcher};

/// Milliseconds of quiet before a change counts. One `composer install` writes for minutes; without
/// a settle window it would ask for a reload thousands of times.
fn settle_ms() -> u64 {
    std::env::var("IGNIS_WATCH_SETTLE_MS").ok().and_then(|v| v.parse().ok()).unwrap_or(300)
}

struct State {
    watcher: notify::RecommendedWatcher,
    files: HashSet<PathBuf>,
    dirs: HashSet<PathBuf>,
}

static STATE: OnceLock<Mutex<State>> = OnceLock::new();
/// Milliseconds since the epoch of the last event on a watched file; 0 means "nothing has changed".
static LAST_EVENT: AtomicU64 = AtomicU64::new(0);
/// One waker task at a time: events arrive in bursts and each one must not spawn its own.
static WAKE_SCHEDULED: AtomicBool = AtomicBool::new(false);
/// Reloading is only a reload when something respawns the worker; without `--supervise` a worker
/// that returns is simply gone, and the process with it. Watching is refused rather than left to
/// take the server down on the first save (review, 2026-09-19).
static SUPERVISED: AtomicBool = AtomicBool::new(false);
/// Workers currently away reloading. One at a time, so the front door always has somewhere to send
/// a request: reloading every thread at once left a gap with no reactor registered, measured at
/// 1,713 non-2xx of 387,355 under `wrk -t4 -c32` (review, 2026-09-19).
static RELOADING: AtomicU64 = AtomicU64::new(0);
/// The generation whose `opcache_reset()` has already been claimed, so N threads noticing the same
/// change reset the shared cache once between them rather than once each.
static RESET_GENERATION: AtomicU64 = AtomicU64::new(0);
/// Bumped once per settled change. A flag would be consumed by whichever worker read it first and
/// the others would keep serving the old code; a generation is read by all of them and consumed by
/// none.
static GENERATION: AtomicU64 = AtomicU64::new(0);

fn now_ms() -> u64 {
    SystemTime::now().duration_since(UNIX_EPOCH).map(|d| d.as_millis() as u64).unwrap_or(0)
}

/// Whether development reload is on at all: `IGNIS_WATCH` set, and something to respawn workers.
pub fn enabled() -> bool {
    let watch = std::env::var("IGNIS_WATCH").unwrap_or_default();
    !watch.is_empty() && watch != "0" && SUPERVISED.load(Ordering::SeqCst)
}

/// Told by `main` whether a worker that returns will come back.
pub fn set_supervised(supervised: bool) {
    SUPERVISED.store(supervised, Ordering::SeqCst);
}

fn state() -> Option<&'static Mutex<State>> {
    if !SUPERVISED.load(Ordering::SeqCst) {
        static WARNED: AtomicBool = AtomicBool::new(false);
        if !WARNED.swap(true, Ordering::SeqCst) {
            tracing::warn!("IGNIS_WATCH needs --supervise: without it a reloading worker would not come back, so watching is off");
        }
        return None;
    }
    if STATE.get().is_none() {
        let watcher = notify::recommended_watcher(|event: notify::Result<Event>| {
            let Ok(event) = event else { return };
            if !matches!(event.kind, notify::EventKind::Modify(_) | notify::EventKind::Create(_) | notify::EventKind::Remove(_)) {
                return;
            }
            let Some(state) = STATE.get() else { return };
            let known = {
                let state = state.lock_unpoisoned();
                event.paths.iter().any(|p| state.files.contains(p))
            };
            if known {
                LAST_EVENT.store(now_ms(), Ordering::Relaxed);
                schedule_wake();
            }
        });
        let Ok(watcher) = watcher else {
            tracing::warn!("watch: no file watcher available on this platform; reload on change is off");
            return None;
        };
        let _ = STATE.set(Mutex::new(State { watcher, files: HashSet::new(), dirs: HashSet::new() }));
    }
    STATE.get()
}

/// Adds files to the watched set. Returns how many directories became watched as a result, which is
/// the number worth logging: the file count is the application's, the directory count is ours.
pub fn watch(paths: &[String]) -> usize {
    let Some(state) = state() else { return 0 };
    let mut state = state.lock_unpoisoned();
    let mut added = 0;
    for path in paths {
        let path = PathBuf::from(path);
        let Some(parent) = path.parent().map(Path::to_path_buf) else { continue };
        state.files.insert(path);
        if state.dirs.contains(&parent) {
            continue;
        }
        match state.watcher.watch(&parent, RecursiveMode::NonRecursive) {
            Ok(()) => {
                state.dirs.insert(parent);
                added += 1;
            }
            Err(e) => tracing::debug!(dir = %parent.display(), "watch: {e}"),
        }
    }
    added
}

/// A loop with no traffic is asleep in `ignis_poll(-1)`, so the change has to come and get it —
/// otherwise the next request would be served by the old code and only then trigger the reload.
/// The wake waits out the settle window first, and re-arms while events are still arriving.
fn schedule_wake() {
    if WAKE_SCHEDULED.swap(true, Ordering::SeqCst) {
        return;
    }
    let Some(rt) = crate::php::module::RUNTIME.get() else {
        WAKE_SCHEDULED.store(false, Ordering::SeqCst);
        return;
    };
    rt.spawn(async {
        loop {
            tokio::time::sleep(std::time::Duration::from_millis(settle_ms() + 20)).await;
            let last = LAST_EVENT.load(Ordering::Relaxed);
            if last == 0 {
                break;
            }
            if now_ms().saturating_sub(last) >= settle_ms() {
                // Compare-and-swap, not a store: an event landing between the read above and this
                // line would otherwise have its timestamp overwritten and its save silently skipped.
                if LAST_EVENT.compare_exchange(last, 0, Ordering::SeqCst, Ordering::SeqCst).is_err() {
                    continue;
                }
                GENERATION.fetch_add(1, Ordering::SeqCst);
                tracing::info!(generation = GENERATION.load(Ordering::SeqCst), "watch: a loaded file changed; workers will reload");
                crate::http::wake_all();
                break;
            }
        }
        WAKE_SCHEDULED.store(false, Ordering::SeqCst);
        // Released before the re-check: an event that arrived while this task was finishing found
        // the flag still set and did not arm a task of its own, so its save needs picking up here.
        if LAST_EVENT.load(Ordering::SeqCst) != 0 {
            schedule_wake();
        }
    });
}

/// How many settled changes have been seen. A worker records this when it boots and reloads when it
/// grows, so every thread reloads exactly once per change — including the ones that were busy when
/// it happened.
pub fn generation() -> u64 {
    GENERATION.load(Ordering::SeqCst)
}

/// One thread per generation gets to reset the compiled-code cache; the others skip it. The reset
/// is process-wide, so doing it once is both enough and less disruptive to the threads still
/// finishing their requests.
pub fn claim_reset() -> bool {
    let generation = GENERATION.load(Ordering::SeqCst);
    RESET_GENERATION.fetch_max(generation, Ordering::SeqCst) < generation
}

/// Asks to be the worker that goes down next. False means someone else is already away and this
/// one should keep serving and ask again on its next turn.
pub fn begin_reload() -> bool {
    let limit = reload_parallel();
    RELOADING.fetch_update(Ordering::SeqCst, Ordering::SeqCst, |away| (away < limit).then_some(away + 1)).is_ok()
}

/// How many workers may be away at once. One keeps the reload invisible to clients and makes it
/// take `threads × boot` to finish; a larger number trades that for a shorter reload, and the
/// ceiling is the point where too few reactors are left to answer.
fn reload_parallel() -> u64 {
    static LIMIT: OnceLock<u64> = OnceLock::new();
    *LIMIT.get_or_init(|| std::env::var("IGNIS_WATCH_RELOAD_PARALLEL").ok().and_then(|v| v.parse().ok()).filter(|n| *n > 0).unwrap_or(1))
}

/// Called by every worker as it boots: the fresh incarnation is up, so the next one may go.
///
/// The wake matters as much as the release. A worker that lost the race for the slot went back to
/// sleep in `ignis_poll(-1)`, and nothing else would wake it — measured, it then served one request
/// with the old code before noticing its turn had come.
pub fn end_reload() {
    let _ = RELOADING.fetch_update(Ordering::SeqCst, Ordering::SeqCst, |n| Some(n.saturating_sub(1)));
    if GENERATION.load(Ordering::SeqCst) > 0 {
        crate::http::wake_all();
    }
}

/// Asks for a reload without a file event: `SIGHUP`, and whatever else comes to mean "reload".
pub fn request() {
    GENERATION.fetch_add(1, Ordering::SeqCst);
    crate::http::wake_all();
}
