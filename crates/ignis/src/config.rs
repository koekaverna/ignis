//! M1 (product): `ignis.toml`, the one configuration file, and the `serve` front-end.
//!
//! Precedence, highest first: CLI flag > environment variable > `ignis.toml` > product default.
//! The PHP side (`php/ignis.php`, the examples, the Symfony runtime) already reads `IGNIS_*`
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

impl Config {
    pub fn load(path: &Path) -> anyhow::Result<Config> {
        let text = std::fs::read_to_string(path).with_context(|| format!("reading {}", path.display()))?;
        toml::from_str(&text).with_context(|| format!("parsing {}", path.display()))
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

    let mut out = Vec::new();
    if cfg.supervise.unwrap_or(true) {
        out.push("--supervise".to_string());
    }
    out.push(entry.to_string_lossy().into_owned());
    out.extend(args.into_iter().skip(1)); // anything after the entry goes to `$argv`
    Ok(out)
}
