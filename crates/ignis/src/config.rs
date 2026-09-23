//! M1 (product): `ignis.toml`, the one configuration file, and the `serve` front-end.
//!
//! Precedence, highest first: CLI flag > environment variable > `ignis.toml` > product default.
//! The PHP side (`php/packages/runtime/src/ignis.php`, the examples, the Symfony runtime) already reads `IGNIS_*`
//! environment variables, so the file is bridged into the environment once, before any other
//! thread exists — the scheduler needed no change to gain a config file. `serve` then rewrites
//! itself into the legacy `[--supervise] [--threads N] <entry.php>` form, so the
//! rest of `main` is untouched too.
use std::path::{Path, PathBuf};

use anyhow::Context;
use serde::Deserialize;

#[derive(Deserialize, Default, Debug)]
#[serde(deny_unknown_fields)] // a misspelled key must fail loudly, not silently keep a default
pub struct Config {
    /// PHP entry script run by every worker thread (`public/index.php` behind a runtime, or a
    /// script that calls `Ignis\serve()`). Also accepted as the positional argument of `serve`.
    pub entry: Option<PathBuf>,
    /// `host:port` the listener binds. Default `127.0.0.1:8080`.
    pub listen: Option<String>,
    /// PHP worker threads per process. Default: the machine's available parallelism on the
    /// thread-safe build, 1 on the non-thread-safe one.
    pub threads: Option<usize>,
    /// Worker processes forked by a master (ADR-0044). Default: 1 on the thread-safe build, the
    /// machine's available parallelism on the non-thread-safe one, where a process is the only
    /// way to use a second core.
    pub workers: Option<usize>,
    /// Respawn a worker whose script ends (ADR-0012). Default true under `serve`.
    pub supervise: Option<bool>,
    /// Extra php.ini; the embed SAPI has no `-c`/`-d`.
    pub php_ini: Option<PathBuf>,
    /// Log filter in `RUST_LOG` syntax. Default `warn` — a worker respawn is never silent.
    pub log: Option<String>,
    #[serde(default)]
    pub budget: Budget,
    #[serde(default)]
    pub limits: Limits,
    #[serde(default)]
    pub watch: Watch,
    /// Path prefixes admitted regardless of the budget (ADR-0019 §5). Default `["/_ignis/"]`.
    pub exempt: Option<Vec<String>>,
    #[serde(default)]
    pub recovery: Recovery,
    #[serde(default)]
    pub blocking: Blocking,
    #[serde(default)]
    pub alerts: Alerts,
}

/// ADR-0043 §8: stuck-fiber recovery. Every key has a profile default (`recovery.rs`); a key
/// written here beats the profile, an `IGNIS_*` variable beats this file.
#[derive(Deserialize, Default, Debug)]
#[serde(deny_unknown_fields)]
pub struct Recovery {
    /// `production` (default), `load-test` or `test` — sets every default below.
    pub profile: Option<String>,
    /// L0: wall-clock ceiling per request, inherited by children; 0 = off.
    pub fiber_timeout_ms: Option<u64>,
    /// Warn when a worker runs PHP or a blocking forward this long without yielding.
    pub busy_warn_ms: Option<u64>,
    /// L3/L4: kill the fiber a stalled worker is running; 0 = off.
    pub stall_kill_ms: Option<u64>,
    /// L5: abandon a worker that did not react to the kill; 0 = off.
    pub stall_abandon_ms: Option<u64>,
    /// Health answers 503 past this many abandoned workers.
    pub leaked_workers_max: Option<u64>,
    /// `graceful` (uncatchable engine exit, default) or `exception` (`Ignis\KilledException`).
    pub kill: Option<String>,
    /// `force-close` (default) or `log`: what happens to a fiber that parks again after a cancellation.
    pub on_swallowed_cancel: Option<String>,
    /// Per-route overrides, longest prefix wins: `[recovery.routes."/export/"]`.
    #[serde(default)]
    pub routes: std::collections::BTreeMap<String, RecoveryRoute>,
}

#[derive(Deserialize, Default, Debug)]
#[serde(deny_unknown_fields)]
pub struct RecoveryRoute {
    pub fiber_timeout_ms: Option<u64>,
    pub busy_warn_ms: Option<u64>,
    pub stall_kill_ms: Option<u64>,
    pub stall_abandon_ms: Option<u64>,
}

/// ADR-0043 §5: the blocking detector.
#[derive(Deserialize, Default, Debug)]
#[serde(deny_unknown_fields)]
pub struct Blocking {
    /// `off`, `warn` (production default), `strict` (tests), `fatal` (CI benches).
    pub mode: Option<String>,
    /// A blocking forward inside a fiber longer than this is reported.
    pub threshold_us: Option<u64>,
    /// Attach a PHP backtrace to the first occurrence per site.
    pub trace: Option<bool>,
    /// JSON report path, written at shutdown and on SIGUSR2.
    pub report: Option<PathBuf>,
    /// `library[:symbol][@route-prefix]` patterns reported at info instead of the mode's level.
    pub allow: Option<Vec<String>>,
}

/// ADR-0043 §6: deduplication and rate limiting of the alert lines.
#[derive(Deserialize, Default, Debug)]
#[serde(deny_unknown_fields)]
pub struct Alerts {
    pub window_s: Option<u64>,
    pub escalate_count: Option<u64>,
    pub recover_windows: Option<u64>,
    pub max_lines_per_s: Option<u64>,
}

/// ADR-0019: admitted request fibers per thread, and how many requests may wait as data.
#[derive(Deserialize, Default, Debug)]
#[serde(deny_unknown_fields)]
pub struct Budget {
    /// Default 1024 per thread. V-37 measured the marginal cost of a held request: 47.7 kB with a
    /// fiber, 33.0 kB queued as data — so the budget saves **14.7 kB per held request** (the fiber
    /// itself); the remaining 33 kB is the connection, which only ADR-0025's connection cap bounds.
    /// 0 = unlimited.
    pub fibers: Option<usize>,
    /// Default 4096; past it the answer is 503 + `retry-after`. 0 = unbounded.
    pub queue: Option<usize>,
}

/// The front door's bounds. Each was an environment variable with no key in this file, promised by a
/// `// Future ignis.toml key:` comment in `http.rs` that nobody tracked (R-LIMITS-CONFIG).
#[derive(Deserialize, Default, Debug)]
#[serde(deny_unknown_fields)]
pub struct Limits {
    /// Largest request body accepted, in bytes. Default 8 MiB; a bigger one is answered 413.
    pub max_body_bytes: Option<usize>,
    /// Connections held at once. Default 8192. ADR-0025: V-37 measured ~33 kB of RSS per held
    /// connection, so this is what bounds memory under load — a fiber budget cannot.
    pub max_connections: Option<usize>,
    /// How long a connection may take to send its request head. Default 10 s (slowloris).
    pub header_timeout_ms: Option<u64>,
    /// How long an idle keep-alive connection is kept. Default 60 s.
    pub idle_timeout_ms: Option<u64>,
    /// How long in-flight requests get after SIGTERM before the process exits. Default 10 s.
    pub drain_timeout_ms: Option<u64>,
}

/// Development reload (research 40, V-90): a runtime setting, so it belongs here beside the others
/// rather than only in the environment.
#[derive(Deserialize, Default, Debug)]
#[serde(deny_unknown_fields)]
pub struct Watch {
    /// Watch the files PHP loads and reload the workers when one changes. Default off, and it needs
    /// `supervise` — nothing else would bring a reloading worker back.
    pub enabled: Option<bool>,
    /// Quiet time before a change counts, in milliseconds. Default 300: one `composer install`
    /// writes for minutes and must still be one reload.
    pub settle_ms: Option<u64>,
    /// Workers that may be away reloading at once. Default 1, which is what keeps a reload invisible
    /// to clients (0 non-2xx of 283,066 requests under load, V-90).
    pub reload_parallel: Option<u64>,
}

impl Config {
    pub fn load(path: &Path) -> anyhow::Result<Config> {
        let text = std::fs::read_to_string(path).with_context(|| format!("reading {}", path.display()))?;
        Self::parse(&text).with_context(|| format!("parsing {}", path.display()))
    }

    /// Reading the file and understanding it are separate jobs; this is the second, so the
    /// `deny_unknown_fields` contract can be tested without one.
    pub fn parse(text: &str) -> anyhow::Result<Config> {
        Ok(toml::from_str(text)?)
    }
}

/// Sets `name` only when the environment does not already have it, so `IGNIS_THREADS=8 ignis serve`
/// beats the file. Must be called before any other thread exists.
fn default_env(name: &str, value: &str) {
    if std::env::var_os(name).is_none() {
        // SAFETY: called from `main` before the tokio runtime, the PHP engine or any worker thread
        // is created, so no other thread can be reading the environment concurrently — the only
        // hazard `set_var` has.
        unsafe { std::env::set_var(name, value) };
    }
}

/// `ignis serve [--config PATH] [entry.php]` → the legacy argument vector, with the file and the
/// product defaults bridged into the environment. Returns `None` after printing a usage error.
pub fn serve_to_legacy_args(mut args: Vec<String>) -> anyhow::Result<Vec<String>> {
    let mut path: Option<PathBuf> = None;
    if args.len() >= 2 && args[0] == "--config" {
        path = Some(PathBuf::from(&args[1]));
        args.drain(0..2);
    }
    let cfg = match path {
        Some(p) => Config::load(&p)?,
        None if Path::new("ignis.toml").is_file() => Config::load(Path::new("ignis.toml"))?,
        None => Config::default(),
    };
    let entry = args
        .first()
        .map(PathBuf::from)
        .or(cfg.entry.clone())
        .context("serve needs an entry script: `ignis serve app.php` or `entry = \"app.php\"` in ignis.toml")?;
    if !entry.is_file() {
        anyhow::bail!("entry script {} does not exist", entry.display());
    }

    // File values, then product defaults, each only where the environment is silent.
    if let Some(v) = &cfg.listen {
        default_env("IGNIS_LISTEN", v);
    }
    default_env("IGNIS_LISTEN", "127.0.0.1:8080");
    if let Some(v) = cfg.threads {
        default_env("IGNIS_THREADS", &v.to_string());
    }
    if let Some(v) = cfg.workers {
        default_env("IGNIS_WORKERS", &v.to_string());
    }
    let cores = std::thread::available_parallelism().map(|n| n.get()).unwrap_or(1);
    default_env("IGNIS_THREADS", &default_threads(cores).to_string());
    default_env("IGNIS_WORKERS", &default_workers(cores).to_string());
    if let Some(v) = &cfg.php_ini {
        default_env("IGNIS_PHP_INI", &v.to_string_lossy());
    }
    if let Some(v) = &cfg.log {
        default_env("RUST_LOG", v);
    }
    if let Some(v) = cfg.budget.fibers {
        default_env("IGNIS_FIBER_BUDGET", &v.to_string());
    }
    default_env("IGNIS_FIBER_BUDGET", "1024");
    if let Some(v) = cfg.budget.queue {
        default_env("IGNIS_QUEUE_DEPTH", &v.to_string());
    }
    default_env("IGNIS_QUEUE_DEPTH", "4096");
    if let Some(v) = &cfg.exempt {
        default_env("IGNIS_BUDGET_EXEMPT", &v.join(","));
    }
    default_env("IGNIS_BUDGET_EXEMPT", "/_ignis/");
    if let Some(v) = cfg.limits.max_body_bytes {
        default_env("IGNIS_MAX_BODY_BYTES", &v.to_string());
    }
    if let Some(v) = cfg.limits.max_connections {
        default_env("IGNIS_MAX_CONNECTIONS", &v.to_string());
    }
    if let Some(v) = cfg.limits.header_timeout_ms {
        default_env("IGNIS_HEADER_TIMEOUT_MS", &v.to_string());
    }
    if let Some(v) = cfg.limits.idle_timeout_ms {
        default_env("IGNIS_IDLE_TIMEOUT_MS", &v.to_string());
    }
    if let Some(v) = cfg.limits.drain_timeout_ms {
        default_env("IGNIS_DRAIN_TIMEOUT_MS", &v.to_string());
    }
    if cfg.watch.enabled == Some(true) {
        default_env("IGNIS_WATCH", "1");
    }
    if let Some(v) = cfg.watch.settle_ms {
        default_env("IGNIS_WATCH_SETTLE_MS", &v.to_string());
    }
    if let Some(v) = cfg.watch.reload_parallel {
        default_env("IGNIS_WATCH_RELOAD_PARALLEL", &v.to_string());
    }
    bridge_recovery(&cfg)?;

    let mut out = Vec::new();
    if cfg.supervise.unwrap_or(supervises_threads_by_default()) {
        out.push("--supervise".to_string());
    }
    out.push(entry.to_string_lossy().into_owned());
    out.extend(args.into_iter().skip(1)); // anything after the entry goes to `$argv`
    Ok(out)
}

/// The thread-safe build fills the cores with threads in one process; the non-thread-safe one
/// cannot have a second PHP thread, so it fills them with worker processes (ADR-0044).
#[cfg(not(php_nts))]
fn default_threads(cores: usize) -> usize {
    cores
}
#[cfg(php_nts)]
fn default_threads(_cores: usize) -> usize {
    1
}
#[cfg(not(php_nts))]
fn default_workers(_cores: usize) -> usize {
    1
}
#[cfg(php_nts)]
fn default_workers(cores: usize) -> usize {
    cores
}

/// `--supervise` respawns threads, which the non-thread-safe build refuses; there the master
/// process is the supervisor, so `serve` must not ask for the thread one.
#[cfg(not(php_nts))]
fn supervises_threads_by_default() -> bool {
    true
}
#[cfg(php_nts)]
fn supervises_threads_by_default() -> bool {
    false
}

/// ADR-0043 §8: the three tables reach the runtime and the PHP loop as `IGNIS_*` variables, the
/// profile by name.
fn bridge_recovery(cfg: &Config) -> anyhow::Result<()> {
    let r = &cfg.recovery;
    if let Some(p) = &r.profile {
        crate::recovery::Profile::parse(p).with_context(|| format!("recovery.profile must be production, load-test or test, not {p:?}"))?;
        default_env("IGNIS_PROFILE", p);
    }
    for (name, value) in [
        ("IGNIS_FIBER_TIMEOUT_MS", r.fiber_timeout_ms),
        ("IGNIS_BUSY_WARN_MS", r.busy_warn_ms),
        ("IGNIS_STALL_KILL_MS", r.stall_kill_ms),
        ("IGNIS_STALL_ABANDON_MS", r.stall_abandon_ms),
        ("IGNIS_LEAKED_WORKERS_MAX", r.leaked_workers_max),
        ("IGNIS_BLOCKING_US", cfg.blocking.threshold_us),
        ("IGNIS_ALERT_WINDOW_S", cfg.alerts.window_s),
        ("IGNIS_ALERT_ESCALATE_COUNT", cfg.alerts.escalate_count),
        ("IGNIS_ALERT_RECOVER_WINDOWS", cfg.alerts.recover_windows),
        ("IGNIS_ALERT_MAX_LINES_PER_S", cfg.alerts.max_lines_per_s),
    ] {
        if let Some(v) = value {
            default_env(name, &v.to_string());
        }
    }
    if let Some(k) = &r.kill {
        if k != "graceful" && k != "exception" {
            anyhow::bail!("recovery.kill must be graceful or exception, not {k:?}");
        }
        default_env("IGNIS_KILL", k);
    }
    if let Some(v) = &r.on_swallowed_cancel {
        if v != "force-close" && v != "log" {
            anyhow::bail!("recovery.on_swallowed_cancel must be force-close or log, not {v:?}");
        }
        default_env("IGNIS_ON_SWALLOWED_CANCEL", v);
    }
    if !r.routes.is_empty() {
        let routes: Vec<(String, crate::recovery::RouteOverride)> = r
            .routes
            .iter()
            .map(|(prefix, o)| {
                (
                    prefix.clone(),
                    crate::recovery::RouteOverride {
                        fiber_timeout_ms: o.fiber_timeout_ms,
                        stall_kill_ms: o.stall_kill_ms,
                        stall_abandon_ms: o.stall_abandon_ms,
                        busy_warn_ms: o.busy_warn_ms,
                    },
                )
            })
            .collect();
        default_env("IGNIS_RECOVERY_ROUTES", &crate::recovery::format_routes(&routes));
    }
    if let Some(m) = &cfg.blocking.mode {
        crate::recovery::BlockingMode::parse(m).with_context(|| format!("blocking.mode must be off, warn, strict or fatal, not {m:?}"))?;
        default_env("IGNIS_BLOCKING", m);
    }
    if let Some(t) = cfg.blocking.trace {
        default_env("IGNIS_BLOCKING_TRACE", if t { "1" } else { "0" });
    }
    if let Some(p) = &cfg.blocking.report {
        default_env("IGNIS_BLOCKING_REPORT", &p.to_string_lossy());
    }
    if let Some(a) = &cfg.blocking.allow {
        default_env("IGNIS_BLOCKING_ALLOW", &a.join(","));
    }
    Ok(())
}

#[cfg(test)]
mod tests {
    //! These mutate the process environment, so they rely on nextest giving each test its own
    //! process. Under `cargo test` they would fight each other (DECISIONS, 2026-09-17).
    use super::*;

    /// A path that certainly exists, so `serve_to_legacy_args`'s `is_file` check passes without a
    /// fixture. The function only ever asks whether the entry exists.
    const EXISTING_FILE: &str = concat!(env!("CARGO_MANIFEST_DIR"), "/Cargo.toml");

    fn config_file(body: &str) -> PathBuf {
        let path = std::env::temp_dir().join(format!("ignis-config-test-{}.toml", std::process::id()));
        std::fs::write(&path, body).unwrap();
        path
    }

    fn serve_with(config_body: &str, extra: &[&str]) -> Vec<String> {
        let path = config_file(config_body);
        let mut args = vec!["--config".to_string(), path.to_string_lossy().into_owned()];
        args.extend(extra.iter().map(|s| s.to_string()));
        let out = serve_to_legacy_args(args).unwrap();
        std::fs::remove_file(&path).ok();
        out
    }

    #[test]
    fn a_misspelled_key_fails_loudly() {
        let err = Config::parse("threads = 2\nthredas = 4\n").unwrap_err().to_string();
        assert!(err.contains("thredas"), "the error must name the key: {err}");
    }

    #[test]
    fn an_empty_config_is_all_defaults() {
        let parsed = Config::parse("").unwrap();
        assert!(parsed.entry.is_none() && parsed.threads.is_none());
        assert!(parsed.budget.fibers.is_none() && parsed.budget.queue.is_none());
    }

    #[test]
    fn the_environment_beats_the_file() {
        // SAFETY: nextest gives this test its own process and no thread has been spawned in it.
        unsafe { std::env::set_var("IGNIS_THREADS", "8") };
        serve_with(&format!("threads = 2\nentry = {EXISTING_FILE:?}\n"), &[]);
        assert_eq!(std::env::var("IGNIS_THREADS").unwrap(), "8");
    }

    #[test]
    fn the_file_beats_the_default() {
        serve_with(&format!("threads = 2\nentry = {EXISTING_FILE:?}\n"), &[]);
        assert_eq!(std::env::var("IGNIS_THREADS").unwrap(), "2");
        assert_eq!(std::env::var("IGNIS_FIBER_BUDGET").unwrap(), "1024");
        assert_eq!(std::env::var("IGNIS_QUEUE_DEPTH").unwrap(), "4096");
        assert_eq!(std::env::var("IGNIS_BUDGET_EXEMPT").unwrap(), "/_ignis/");
    }

    #[test]
    fn the_watch_table_reaches_the_watcher() {
        serve_with(&format!("entry = {EXISTING_FILE:?}\n[watch]\nenabled = true\nsettle_ms = 50\nreload_parallel = 2\n"), &[]);
        assert_eq!(std::env::var("IGNIS_WATCH").unwrap(), "1");
        assert_eq!(std::env::var("IGNIS_WATCH_SETTLE_MS").unwrap(), "50");
        assert_eq!(std::env::var("IGNIS_WATCH_RELOAD_PARALLEL").unwrap(), "2");
    }

    /// `enabled = false` must not set the variable at all: an empty `IGNIS_WATCH` is still "off",
    /// but writing one would defeat an operator who exports it themselves.
    #[test]
    fn watching_off_writes_nothing() {
        serve_with(&format!("entry = {EXISTING_FILE:?}\n[watch]\nenabled = false\n"), &[]);
        assert!(std::env::var("IGNIS_WATCH").is_err());
    }

    #[test]
    fn a_positional_entry_beats_the_files_entry() {
        let out = serve_with("entry = \"/nonexistent/from-the-file.php\"\n", &[EXISTING_FILE]);
        assert!(out.last().unwrap().ends_with("Cargo.toml"), "{out:?}");
    }

    #[test]
    fn without_an_entry_anywhere_it_says_so() {
        let path = config_file("threads = 1\n");
        let err = serve_to_legacy_args(vec!["--config".into(), path.to_string_lossy().into_owned()]).unwrap_err().to_string();
        std::fs::remove_file(&path).ok();
        assert!(err.contains("serve needs an entry script"), "{err}");
    }

    #[test]
    fn supervise_follows_the_engine_by_default_and_the_file_decides_otherwise() {
        let by_default = serve_with(&format!("entry = {EXISTING_FILE:?}\n"), &[]);
        assert_eq!(by_default.first().unwrap() == "--supervise", supervises_threads_by_default());
        let off = serve_with(&format!("supervise = false\nentry = {EXISTING_FILE:?}\n"), &[]);
        assert_ne!(off.first().unwrap(), "--supervise");
        let on = serve_with(&format!("supervise = true\nentry = {EXISTING_FILE:?}\n"), &[]);
        assert_eq!(on.first().unwrap(), "--supervise");
    }

    #[test]
    fn the_workers_key_reaches_the_master_and_the_engine_picks_the_default() {
        serve_with(&format!("workers = 3\nentry = {EXISTING_FILE:?}\n"), &[]);
        assert_eq!(std::env::var("IGNIS_WORKERS").unwrap(), "3");
        assert_eq!(default_workers(8) * default_threads(8), 8);
    }

    #[test]
    fn the_limits_table_reaches_the_front_door() {
        serve_with(
            &format!(
                "entry = {EXISTING_FILE:?}\n[limits]\nmax_connections = 512\nmax_body_bytes = 1048576\nheader_timeout_ms = 3000\nidle_timeout_ms = 15000\ndrain_timeout_ms = 2000\n"
            ),
            &[],
        );
        assert_eq!(std::env::var("IGNIS_MAX_CONNECTIONS").unwrap(), "512");
        assert_eq!(std::env::var("IGNIS_MAX_BODY_BYTES").unwrap(), "1048576");
        assert_eq!(std::env::var("IGNIS_HEADER_TIMEOUT_MS").unwrap(), "3000");
        assert_eq!(std::env::var("IGNIS_IDLE_TIMEOUT_MS").unwrap(), "15000");
        assert_eq!(std::env::var("IGNIS_DRAIN_TIMEOUT_MS").unwrap(), "2000");
    }

    #[test]
    fn the_environment_still_beats_the_limits_table() {
        // SAFETY: nextest gives this test its own process and no thread has been spawned in it.
        unsafe { std::env::set_var("IGNIS_MAX_CONNECTIONS", "99") };
        serve_with(&format!("entry = {EXISTING_FILE:?}\n[limits]\nmax_connections = 512\n"), &[]);
        assert_eq!(std::env::var("IGNIS_MAX_CONNECTIONS").unwrap(), "99");
    }

    #[test]
    fn a_misspelled_limits_key_fails_loudly() {
        let err = Config::parse("[limits]\nmax_connection = 1\n").unwrap_err().to_string();
        assert!(err.contains("max_connection"), "the error must name the key: {err}");
    }

    #[test]
    fn the_recovery_tables_reach_the_environment() {
        serve_with(
            &format!(
                "entry = {EXISTING_FILE:?}\n[recovery]\nprofile = \"test\"\nstall_kill_ms = 1500\nkill = \"exception\"\n[recovery.routes.\"/export/\"]\nfiber_timeout_ms = 120000\nstall_kill_ms = 0\n[blocking]\nmode = \"strict\"\ntrace = true\nallow = [\"libphp:read@/config\", \"sqlite3.so\"]\n[alerts]\nwindow_s = 30\n"
            ),
            &[],
        );
        assert_eq!(std::env::var("IGNIS_PROFILE").unwrap(), "test");
        assert_eq!(std::env::var("IGNIS_STALL_KILL_MS").unwrap(), "1500");
        assert_eq!(std::env::var("IGNIS_KILL").unwrap(), "exception");
        assert_eq!(std::env::var("IGNIS_RECOVERY_ROUTES").unwrap(), "/export/=fiber_timeout_ms:120000;stall_kill_ms:0");
        assert_eq!(std::env::var("IGNIS_BLOCKING").unwrap(), "strict");
        assert_eq!(std::env::var("IGNIS_BLOCKING_TRACE").unwrap(), "1");
        assert_eq!(std::env::var("IGNIS_BLOCKING_ALLOW").unwrap(), "libphp:read@/config,sqlite3.so");
        assert_eq!(std::env::var("IGNIS_ALERT_WINDOW_S").unwrap(), "30");
        assert!(std::env::var("IGNIS_BUSY_WARN_MS").is_err(), "a key the file does not set is left to the profile");
    }

    #[test]
    fn an_unknown_recovery_key_or_value_fails_loudly() {
        let err = Config::parse("[recovery]\nstall_kil_ms = 1\n").unwrap_err().to_string();
        assert!(err.contains("stall_kil_ms"), "{err}");
        let err = Config::parse("[recovery.routes.\"/x\"]\nkill = \"graceful\"\n").unwrap_err().to_string();
        assert!(err.contains("kill"), "{err}");
        let path = config_file(&format!("entry = {EXISTING_FILE:?}\n[recovery]\nprofile = \"staging\"\n"));
        let err = serve_to_legacy_args(vec!["--config".into(), path.to_string_lossy().into_owned()]).unwrap_err().to_string();
        std::fs::remove_file(&path).ok();
        assert!(err.contains("staging"), "{err}");
    }

    #[test]
    fn arguments_after_the_entry_reach_argv() {
        let out = serve_with("", &[EXISTING_FILE, "--verbose", "seven"]);
        assert_eq!(&out[out.len() - 2..], &["--verbose".to_string(), "seven".to_string()]);
    }
}
