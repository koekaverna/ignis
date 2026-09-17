//! M1 (product): `ignis.toml`, the one configuration file, and the `serve` front-end.
//!
//! Precedence, highest first: CLI flag > environment variable > `ignis.toml` > product default.
//! The PHP side (`php/packages/runtime/src/ignis.php`, the examples, the Symfony runtime) already reads `IGNIS_*`
//! environment variables, so the file is bridged into the environment once, before any other
//! thread exists — the scheduler needed no change to gain a config file. `serve` then rewrites
//! itself into the legacy `[--supervise] [--threads N] [--offload N] <entry.php>` form, so the
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
    /// PHP worker threads. Default: the machine's available parallelism.
    pub threads: Option<usize>,
    /// Synchronous offload workers (E16) for `curl_*`/`PDO`/`SQLite3`. Default 0: each costs a PHP thread.
    pub offload: Option<usize>,
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
    /// Path prefixes admitted regardless of the budget (ADR-0019 §5). Default `["/_ignis/"]`.
    pub exempt: Option<Vec<String>>,
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
    let cores = std::thread::available_parallelism().map(|n| n.get()).unwrap_or(1);
    default_env("IGNIS_THREADS", &cores.to_string());
    if let Some(v) = cfg.offload {
        default_env("IGNIS_OFFLOAD", &v.to_string());
    }
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

    let mut out = Vec::new();
    if cfg.supervise.unwrap_or(true) {
        out.push("--supervise".to_string());
    }
    out.push(entry.to_string_lossy().into_owned());
    out.extend(args.into_iter().skip(1)); // anything after the entry goes to `$argv`
    Ok(out)
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
    fn supervise_is_on_by_default_and_can_be_turned_off() {
        let on = serve_with(&format!("entry = {EXISTING_FILE:?}\n"), &[]);
        assert_eq!(on.first().unwrap(), "--supervise");
        let off = serve_with(&format!("supervise = false\nentry = {EXISTING_FILE:?}\n"), &[]);
        assert_ne!(off.first().unwrap(), "--supervise");
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
    fn arguments_after_the_entry_reach_argv() {
        let out = serve_with("", &[EXISTING_FILE, "--verbose", "seven"]);
        assert_eq!(&out[out.len() - 2..], &["--verbose".to_string(), "seven".to_string()]);
    }
}
