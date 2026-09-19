//! ignis — Rust host for PHP 8.5 ZTS with fiber-based concurrency.
//!
//! Cycle 0 binary: `ignis <script.php>` runs one script on the main thread
//! with a tokio runtime on the side owning all timers/I/O.
mod backend;
mod config;
mod grpc;
mod http;
mod lock;
mod metrics;
mod offload;
mod php;
mod reactor;
mod watch;

use std::path::{Path, PathBuf};
use std::process::ExitCode;

/// Worker thread restarts performed by the supervisor (ADR-0012).
pub static RESTARTS: std::sync::atomic::AtomicU64 = std::sync::atomic::AtomicU64::new(0);

/// mimalloc is the production allocator; miri cannot execute its C code, so tests under miri fall
/// back to the system allocator.
#[cfg(not(miri))]
#[global_allocator]
static GLOBAL: mimalloc::MiMalloc = mimalloc::MiMalloc;

const USAGE: &str = "usage: ignis serve [--config ignis.toml] [entry.php]\n       ignis [--threads N] [--offload N] [--supervise] (<script.php> | -r <code> | --) [args...]\n       ignis --version";

fn main() -> ExitCode {
    let raw: Vec<String> = std::env::args().skip(1).collect();
    if raw.first().is_some_and(|a| a == "--version" || a == "-V") {
        println!("ignis {}", env!("CARGO_PKG_VERSION"));
        return ExitCode::SUCCESS;
    }
    let serving = raw.first().is_some_and(|a| a == "serve");
    let mut args = match if serving { bridge_serve_config(raw) } else { Ok(raw) } {
        Ok(v) => v,
        Err(code) => return code,
    };
    initialize_logging();

    let flags = parse_runtime_flags(&mut args);
    let inline = match take_inline_code(&mut args) {
        Ok(v) => v,
        Err(code) => return code,
    };
    let Some(script) = inline.as_ref().map(|(_, name)| PathBuf::from(name)).or_else(|| args.first().map(PathBuf::from)) else {
        eprintln!("{USAGE}");
        return ExitCode::from(2);
    };
    let script = script.canonicalize().unwrap_or(script);

    let rt = tokio_runtime();
    php::module::install_runtime(rt.handle().clone());
    install_signal_drain(&rt);
    php::module::install_thread_reactor(reactor::Reactor::new(rt.handle()));

    // Before the engine: `Engine::start` adds an ini entry when development reload is on, and an
    // ini entry has to exist before anything is compiled.
    watch::set_supervised(flags.supervise);
    install_reload_signal(&rt, flags.supervise);
    metrics::mark_start();
    let mut engine = match initialize_php_engine(&args) {
        Ok(e) => e,
        Err(code) => return code,
    };
    if let Err(code) = check_park_interposers() {
        return code;
    }
    if serving {
        print_ready_banner();
    }

    if let Some((code, name)) = inline {
        return run_inline_code(engine, rt, &code, &name);
    }

    let offload_handles = spawn_offload_workers(flags.offload);
    spawn_watchdog(&rt);
    let worst = if flags.supervise {
        supervise_workers(flags.threads, &script, rt.handle())
    } else {
        run_workers(flags.threads, &script, rt.handle(), &mut engine)
    };
    stop_offload_workers(offload_handles);
    drop(engine);
    rt.shutdown_background();
    ExitCode::from(worst.clamp(0, 255) as u8)
}

/// M1: `ignis serve` is handled before anything else, because it bridges ignis.toml into the
/// environment and that has to happen before the log filter reads RUST_LOG and before any thread
/// exists (config.rs explains the precedence).
fn bridge_serve_config(raw: Vec<String>) -> Result<Vec<String>, ExitCode> {
    config::serve_to_legacy_args(raw[1..].to_vec()).map_err(|e| {
        eprintln!("ignis serve: {e:#}");
        ExitCode::from(2)
    })
}

/// `warn` is the log floor when RUST_LOG is unset: EnvFilter's own default is `error`, which hid
/// the watchdog's "php threads busy for > 1 s" and the supervisor's "worker script ended;
/// respawning" — a worker could die and respawn with the operator seeing nothing (H31, and H-10's
/// reason a respawn must never be silent; it also cost three wrong conclusions from probes whose
/// output was being discarded). `info` and below stay opt-in. The phpt harness sets RUST_LOG=error
/// itself, because run-tests compares output byte for byte and a single warning fails a test —
/// raising the floor without that cost fibers main 108 -> 72 before it was caught.
fn initialize_logging() {
    tracing_subscriber::fmt()
        .with_env_filter(
            tracing_subscriber::EnvFilter::try_from_default_env().unwrap_or_else(|_| tracing_subscriber::EnvFilter::new("warn")),
        )
        .with_writer(std::io::stderr)
        .init();
}

/// `ignis [--threads N] [--offload N] [--supervise] <script.php>`; the IGNIS_THREADS and
/// IGNIS_OFFLOAD environment variables are the defaults.
struct RuntimeFlags {
    threads: usize,
    offload: usize,
    supervise: bool,
}

/// Takes the leading runtime flags off `args`, leaving the script and its own arguments.
fn parse_runtime_flags(args: &mut Vec<String>) -> RuntimeFlags {
    let mut flags = RuntimeFlags {
        threads: std::env::var("IGNIS_THREADS").ok().and_then(|v| v.parse().ok()).unwrap_or(1),
        offload: std::env::var("IGNIS_OFFLOAD").ok().and_then(|v| v.parse().ok()).unwrap_or(0),
        supervise: false,
    };
    loop {
        if args.len() >= 2 && args[0] == "--threads" {
            flags.threads = args[1].parse().unwrap_or(1);
            args.drain(0..2);
        } else if args.len() >= 2 && args[0] == "--offload" {
            flags.offload = args[1].parse().unwrap_or(0);
            args.drain(0..2);
        } else if !args.is_empty() && args[0] == "--supervise" {
            flags.supervise = true;
            args.remove(0);
        } else {
            break;
        }
    }
    flags.threads = flags.threads.max(1);
    flags
}

/// A5: php-cli's `-r <code>` and `--` (script on stdin), returned as the code and the name PHP
/// reports for it. Tests that re-exec PHP_BINARY use both and the embed SAPI has no such flags, so
/// `scripts/ignis-php` had to hand those invocations to the stock CLI. Like php-cli they run one
/// script on this thread: no worker threads, no offload pool, no supervisor.
fn take_inline_code(args: &mut Vec<String>) -> Result<Option<(String, String)>, ExitCode> {
    if args.len() >= 2 && args[0] == "-r" {
        let code = args[1].clone();
        args.drain(0..2);
        return Ok(Some((code, "Command line code".to_string())));
    }
    if args.first().is_some_and(|a| a == "--") {
        args.remove(0);
        let mut code = String::new();
        if let Err(e) = std::io::Read::read_to_string(&mut std::io::stdin(), &mut code) {
            eprintln!("reading the script from stdin: {e}");
            return Err(ExitCode::from(2));
        }
        return Ok(Some((code, "Standard input code".to_string())));
    }
    Ok(None)
}

/// M4-5: SIGTERM (a container stop, a systemd restart) drains instead of dropping. The listener
/// closes first and `/_ignis/health` answers "draining", so a load balancer takes this instance out
/// of rotation; then in-flight requests are given IGNIS_DRAIN_TIMEOUT_MS to finish. SIGINT does the
/// same, so Ctrl-C in a terminal behaves like a stop rather than a kill. The process then exits
/// outright: the PHP threads own their engines and cannot be unwound from here (ADR-0012), so once
/// no request is in flight, leaving is the honest end of the process.
/// `SIGHUP` reloads instead of stopping: the workers go down one at a time and come back with a
/// fresh engine, which is what `nginx -s reload` and `rr reset` mean by the word. The listener never
/// closes, so nothing in flight is dropped (M4-5, research 40).
fn install_reload_signal(rt: &tokio::runtime::Runtime, supervised: bool) {
    rt.spawn(async move {
        let Ok(mut hup) = tokio::signal::unix::signal(tokio::signal::unix::SignalKind::hangup()) else {
            tracing::warn!("SIGHUP handler not installed; reload on signal is off");
            return;
        };
        while hup.recv().await.is_some() {
            if !supervised {
                tracing::warn!("SIGHUP ignored: reloading needs --supervise, or the workers would not come back");
                continue;
            }
            tracing::info!("SIGHUP: reloading workers");
            watch::request();
        }
    });
}

fn install_signal_drain(rt: &tokio::runtime::Runtime) {
    rt.spawn(async {
        let mut term = match tokio::signal::unix::signal(tokio::signal::unix::SignalKind::terminate()) {
            Ok(s) => s,
            Err(e) => {
                tracing::warn!(error = %e, "SIGTERM handler not installed; shutdown will not drain");
                return;
            }
        };
        let mut int = match tokio::signal::unix::signal(tokio::signal::unix::SignalKind::interrupt()) {
            Ok(s) => s,
            Err(_) => return,
        };
        let sig = tokio::select! {
            _ = term.recv() => "SIGTERM",
            _ = int.recv() => "SIGINT",
        };
        let (took, pending) = http::drain().await;
        if pending == 0 {
            tracing::info!(signal = sig, took_ms = took.as_millis() as u64, "drained; exiting");
        } else {
            tracing::warn!(
                signal = sig,
                took_ms = took.as_millis() as u64,
                pending,
                "drain timed out; exiting with requests still in flight"
            );
        }
        std::process::exit(0);
    });
}

/// Main thread = PHP thread 0 (`php_embed_init` runs here). PHP's argv is `[script, args...]` like
/// php-cli, so `$argv[0]` is the script (the E15 harnesses rely on it).
fn initialize_php_engine(args: &[String]) -> Result<php::embed::Engine, ExitCode> {
    php::embed::Engine::init(args).map_err(|e| {
        eprintln!("{e:#}");
        ExitCode::from(1)
    })
}

/// ADR-0037 §4(a): universal park's failure mode is a hang, not an exception, so prove the
/// interposers really bind inside the policy's libraries before anything can depend on them —
/// after MINIT (extensions loaded, so RTLD_NOLOAD sees them), before any worker thread exists.
fn check_park_interposers() -> Result<(), ExitCode> {
    #[cfg(feature = "universal-park")]
    if let Err(e) = php::park::selfcheck() {
        eprintln!("ignis: {e}");
        return Err(ExitCode::from(2));
    }
    Ok(())
}

/// `serve` is the production entry, so it gets a one-line startup banner on stderr. A plain script
/// run gets nothing, because the phpt harness treats a single stderr line as a test failure and the
/// default log floor is `warn` — which is why a clean start was otherwise invisible to an operator
/// (found while rewriting docs/operate.md).
fn print_ready_banner() {
    eprintln!(
        "ignis {} — threads={} listen={} park={} — ready",
        env!("CARGO_PKG_VERSION"),
        std::env::var("IGNIS_THREADS").unwrap_or_else(|_| std::thread::available_parallelism().map_or("?".into(), |n| n.to_string())),
        std::env::var("IGNIS_LISTEN").unwrap_or_else(|_| "127.0.0.1:8080".into()),
        php::park::policy_summary(),
    );
}

/// Two tokio worker threads beside the PHP ones: this side owns every timer and socket and never
/// runs PHP code.
fn tokio_runtime() -> tokio::runtime::Runtime {
    tokio::runtime::Builder::new_multi_thread().worker_threads(2).thread_name("ignis-tokio").enable_all().build().expect("tokio runtime")
}

/// A5: `-r` / stdin code is a single script on this thread, like php-cli, and the process is over
/// when it returns.
fn run_inline_code(mut engine: php::embed::Engine, rt: tokio::runtime::Runtime, code: &str, name: &str) -> ExitCode {
    let status = match engine.eval(code, name) {
        Ok(s) => s,
        Err(e) => {
            eprintln!("{e:#}");
            255
        }
    };
    drop(engine);
    rt.shutdown_background();
    ExitCode::from(status.clamp(0, 255) as u8)
}

/// E16: offload workers — synchronous PHP threads (own TSRM context, no reactor) running the
/// embedded worker loop; jobs arrive over channels, answers go back to the caller's reactor.
///
/// No reactor is deliberate (ADR-0016: these threads are the place blocking code is allowed to
/// block) and two other mechanisms read the absence as the marker of such a thread — `route.rs`
/// refuses to route from one, and `park.rs` falls through to the blocking call. The runtime
/// functions that need a reactor therefore refuse in PHP here rather than reaching for one; see
/// `module::reactor_or_throw`.
fn spawn_offload_workers(offload: usize) -> Vec<std::thread::JoinHandle<()>> {
    if offload == 0 {
        return Vec::new();
    }
    offload::initialize(offload);
    (0..offload)
        .map(|i| {
            std::thread::Builder::new()
                .name(format!("ignis-offload-{i}"))
                .spawn(move || {
                    php::module::OFFLOAD_WORKER.with(|c| c.set(Some(i)));
                    let mut w = match php::embed::WorkerThread::attach() {
                        Ok(w) => w,
                        Err(e) => {
                            eprintln!("offload thread {i}: {e:#}");
                            return;
                        }
                    };
                    if let Err(e) = w.eval(include_str!("../../../php/packages/offload/src/worker.php"), "ignis-offload-worker") {
                        eprintln!("offload thread {i}: {e:#}");
                    }
                })
                .expect("spawn offload thread")
        })
        .collect()
}

/// E16: the offload workers leave their PHP requests before the engine shuts down.
fn stop_offload_workers(handles: Vec<std::thread::JoinHandle<()>>) {
    offload::shutdown();
    for h in handles {
        let _ = h.join();
    }
}

/// One PHP worker thread: it attaches to TSRM, gets its own reactor and runs the same script. When
/// the script is over (normally or by fatal) the thread stops routing requests to itself.
fn spawn_worker(index: usize, script: &Path, rt: &tokio::runtime::Handle) -> std::thread::JoinHandle<i32> {
    let script = script.to_path_buf();
    let rt_handle = rt.clone();
    std::thread::Builder::new()
        .name(format!("ignis-php-{index}"))
        .spawn(move || -> i32 {
            php::module::install_thread_reactor(reactor::Reactor::new(&rt_handle));
            let mut w = match php::embed::WorkerThread::attach() {
                Ok(w) => w,
                Err(e) => {
                    eprintln!("php thread {index}: {e:#}");
                    return 1;
                }
            };
            let status = w.run_file(&script).unwrap_or(1);
            php::http_unregister_current();
            status
        })
        .expect("spawn php thread")
}

/// Watchdog: report threads stuck in PHP code (ADR-0012).
fn spawn_watchdog(rt: &tokio::runtime::Runtime) {
    rt.spawn(async {
        let mut last = 0usize;
        loop {
            tokio::time::sleep(std::time::Duration::from_millis(250)).await;
            let (stalled, total) = http::stalled_threads(std::time::Duration::from_secs(1));
            if stalled != last {
                tracing::warn!(stalled, total, "php threads busy for > 1 s without polling");
                last = stalled;
            }
        }
    });
}

/// `--supervise` (ADR-0012): thread 0 stays idle, because it owns the SAPI and cannot be respawned;
/// workers 1..=N run the script and are respawned when their script ends, up to ten restarts a
/// minute. Returns when every worker is gone for good.
fn supervise_workers(threads: usize, script: &Path, rt: &tokio::runtime::Handle) -> i32 {
    let mut workers: Vec<Worker> = (1..=threads).map(|slot| Worker::spawn(slot, script, rt)).collect();
    let mut restarts_this_minute = 0u32;
    let mut minute = std::time::Instant::now();
    loop {
        std::thread::sleep(std::time::Duration::from_millis(50));
        for worker in &mut workers {
            if let Some(retry_at) = worker.retry_at {
                if std::time::Instant::now() < retry_at {
                    continue;
                }
                worker.retry_at = None;
                tracing::warn!(slot = worker.slot, "retrying the worker after its restart budget ran out");
                worker.respawn(script, rt);
                continue;
            }
            if !worker.finished() {
                continue;
            }
            let status = worker.join();
            if minute.elapsed() > std::time::Duration::from_secs(60) {
                minute = std::time::Instant::now();
                restarts_this_minute = 0;
            }
            // A reload is not a crash: the watcher bumped its generation and the worker returned on
            // purpose, so it must not spend the budget that exists to stop a crash loop. Charging it
            // meant one save cost N restarts, and `--threads 12` exhausted ten of them on the first
            // edit (review, 2026-09-19).
            if worker.ended_for_reload() {
                tracing::info!(slot = worker.slot, "worker reloaded");
            } else {
                if restarts_this_minute >= 10 {
                    // The slot is kept and retried after a pause, not dropped: it used to be removed
                    // from the list and never put back, so an exhausted budget lost that worker for
                    // the life of the process and losing every worker ended it. The pause is what
                    // bounds a crash loop; waiting out the whole minute instead would leave a
                    // development server unusable for a minute after the file that broke it is fixed.
                    if worker.retry_at.is_none() {
                        worker.retry_at = Some(std::time::Instant::now() + std::time::Duration::from_secs(5));
                        tracing::error!(
                            slot = worker.slot,
                            status,
                            "worker keeps ending; restart budget exhausted (10/min), retrying in 5s"
                        );
                    }
                    continue;
                }
                restarts_this_minute += 1;
                RESTARTS.fetch_add(1, std::sync::atomic::Ordering::Relaxed);
                tracing::warn!(slot = worker.slot, status, "worker script ended; respawning (opcache SHM untouched)");
            }
            worker.respawn(script, rt);
        }
    }
}

/// One supervised worker slot. It keeps the generation the thread was spawned with, which is what
/// separates "returned because a file changed" from "died".
struct Worker {
    slot: usize,
    handle: Option<std::thread::JoinHandle<i32>>,
    generation: u64,
    /// Set while a slot is waiting out a crash loop; the slot stays in the list either way.
    retry_at: Option<std::time::Instant>,
}

impl Worker {
    fn spawn(slot: usize, script: &Path, rt: &tokio::runtime::Handle) -> Self {
        Self { slot, handle: Some(spawn_worker(slot, script, rt)), generation: watch::generation(), retry_at: None }
    }

    fn finished(&self) -> bool {
        self.handle.as_ref().is_some_and(std::thread::JoinHandle::is_finished)
    }

    fn join(&mut self) -> i32 {
        self.handle.take().map_or(1, |h| h.join().unwrap_or(1))
    }

    fn ended_for_reload(&self) -> bool {
        watch::generation() > self.generation
    }

    fn respawn(&mut self, script: &Path, rt: &tokio::runtime::Handle) {
        self.generation = watch::generation();
        self.retry_at = None;
        self.handle = Some(spawn_worker(self.slot, script, rt));
    }
}

/// The unsupervised run: threads 1..N run the script beside thread 0, and the worst exit status of
/// all of them is the process's.
fn run_workers(threads: usize, script: &Path, rt: &tokio::runtime::Handle, engine: &mut php::embed::Engine) -> i32 {
    let handles: Vec<_> = (1..threads).map(|i| spawn_worker(i, script, rt)).collect();
    let status = match engine.run_file(script) {
        Ok(s) => s,
        Err(e) => {
            eprintln!("{e:#}");
            1
        }
    };
    handles.into_iter().fold(status, |worst, h| worst.max(h.join().unwrap_or(1)))
}

#[cfg(test)]
mod tests {
    use super::*;

    fn args(v: &[&str]) -> Vec<String> {
        v.iter().map(|s| s.to_string()).collect()
    }

    #[test]
    fn runtime_flags_are_taken_in_any_order() {
        let mut a = args(&["--supervise", "--offload", "3", "--threads", "4", "app.php", "--verbose"]);
        let flags = parse_runtime_flags(&mut a);
        assert_eq!(flags.threads, 4);
        assert_eq!(flags.offload, 3);
        assert!(flags.supervise);
        assert_eq!(a, args(&["app.php", "--verbose"]));
    }

    #[test]
    fn a_non_numeric_thread_count_falls_back_to_one() {
        let mut a = args(&["--threads", "lots", "app.php"]);
        let flags = parse_runtime_flags(&mut a);
        assert_eq!(flags.threads, 1);
        assert_eq!(a, args(&["app.php"]));
    }

    #[test]
    fn supervise_stands_alone() {
        let mut a = args(&["--supervise", "app.php"]);
        let flags = parse_runtime_flags(&mut a);
        assert!(flags.supervise);
        assert_eq!(a, args(&["app.php"]));
    }

    #[test]
    fn a_script_argument_that_looks_like_a_flag_is_left_alone() {
        let mut a = args(&["app.php", "--threads", "4"]);
        let flags = parse_runtime_flags(&mut a);
        assert!(!flags.supervise);
        assert_eq!(a, args(&["app.php", "--threads", "4"]));
    }

    #[test]
    fn dash_r_consumes_the_code_and_its_argument() {
        let mut a = args(&["-r", "echo 1;", "extra"]);
        let inline = take_inline_code(&mut a).expect("-r is accepted");
        assert_eq!(inline, Some(("echo 1;".to_string(), "Command line code".to_string())));
        assert_eq!(a, args(&["extra"]));
    }

    #[test]
    fn a_plain_script_is_not_inline_code() {
        let mut a = args(&["app.php", "-r"]);
        assert_eq!(take_inline_code(&mut a).expect("no inline code"), None);
        assert_eq!(a, args(&["app.php", "-r"]));
    }

    #[test]
    fn dash_dash_reads_the_script_from_stdin() {
        let mut a = args(&["--", "extra"]);
        let inline = take_inline_code(&mut a).expect("-- is accepted").expect("-- means inline code");
        assert_eq!(inline.1, "Standard input code");
        assert_eq!(a, args(&["extra"]));
    }
}
