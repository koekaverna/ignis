//! ignis — Rust host for PHP 8.5 ZTS with fiber-based concurrency.
//!
//! Cycle 0 binary: `ignis <script.php>` runs one script on the main thread
//! with a tokio runtime on the side owning all timers/I/O.
mod backend;
mod http;
mod php;
mod reactor;

use std::path::PathBuf;
use std::process::ExitCode;

/// Worker thread restarts performed by the supervisor (ADR-0012).
pub static RESTARTS: std::sync::atomic::AtomicU64 = std::sync::atomic::AtomicU64::new(0);

// mimalloc is the production allocator; miri cannot execute its C code, so
// tests under miri fall back to the system allocator.
#[cfg(not(miri))]
#[global_allocator]
static GLOBAL: mimalloc::MiMalloc = mimalloc::MiMalloc;

fn main() -> ExitCode {
    tracing_subscriber::fmt()
        .with_env_filter(tracing_subscriber::EnvFilter::from_default_env())
        .with_writer(std::io::stderr)
        .init();

    // `ignis [--threads N] <script.php>`; env IGNIS_THREADS is the fallback.
    let mut args: Vec<String> = std::env::args().skip(1).collect();
    let mut threads: usize = std::env::var("IGNIS_THREADS").ok().and_then(|v| v.parse().ok()).unwrap_or(1);
    let mut supervise = false;
    loop {
        if args.len() >= 2 && args[0] == "--threads" {
            threads = args[1].parse().unwrap_or(1);
            args.drain(0..2);
        } else if !args.is_empty() && args[0] == "--supervise" {
            // Thread 0 stays idle (it owns the SAPI and cannot be respawned); workers 1..=N run the
            // script and are respawned by the supervisor when their script ends (ADR-0012).
            supervise = true;
            args.remove(0);
        } else {
            break;
        }
    }
    let Some(script) = args.first().map(PathBuf::from) else {
        eprintln!("usage: ignis [--threads N] <script.php>");
        return ExitCode::from(2);
    };
    let threads = threads.max(1);
    let script = script.canonicalize().unwrap_or(script);

    let rt = tokio::runtime::Builder::new_multi_thread()
        .worker_threads(2)
        .thread_name("ignis-tokio")
        .enable_all()
        .build()
        .expect("tokio runtime");
    php::module::install_runtime(rt.handle().clone());
    php::module::install_thread_reactor(reactor::Reactor::new(rt.handle()));

    // Main thread = PHP thread 0 (php_embed_init runs here).
    let mut engine = match php::embed::Engine::init("ignis") {
        Ok(e) => e,
        Err(e) => {
            eprintln!("{e:#}");
            return ExitCode::from(1);
        }
    };

    // Worker threads: each attaches to TSRM, gets its own reactor, runs the same script.
    let spawn_worker = |i: usize| {
        let script = script.clone();
        let rt_handle = rt.handle().clone();
        std::thread::Builder::new()
            .name(format!("ignis-php-{i}"))
            .spawn(move || -> i32 {
                php::module::install_thread_reactor(reactor::Reactor::new(&rt_handle));
                let mut w = match php::embed::WorkerThread::attach() {
                    Ok(w) => w,
                    Err(e) => {
                        eprintln!("php thread {i}: {e:#}");
                        return 1;
                    }
                };
                let status = w.run_file(&script).unwrap_or(1);
                // The script is over (normally or by fatal): stop routing requests here.
                php::http_unregister_current();
                status
            })
            .expect("spawn php thread")
    };

    // Watchdog: report threads stuck in PHP code (ADR-0012).
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

    let worst;
    if supervise {
        // Supervisor: workers 1..=N, respawned when their script ends; thread 0 only supervises.
        let mut handles: Vec<(usize, std::thread::JoinHandle<i32>)> = (1..=threads).map(|i| (i, spawn_worker(i))).collect();
        let mut restarts_this_minute = 0u32;
        let mut minute = std::time::Instant::now();
        loop {
            std::thread::sleep(std::time::Duration::from_millis(50));
            let mut i = 0;
            while i < handles.len() {
                if handles[i].1.is_finished() {
                    let (slot, h) = handles.remove(i);
                    let status = h.join().unwrap_or(1);
                    if minute.elapsed() > std::time::Duration::from_secs(60) {
                        minute = std::time::Instant::now();
                        restarts_this_minute = 0;
                    }
                    if restarts_this_minute >= 10 {
                        tracing::error!(slot, status, "worker ended; restart budget exhausted (10/min), not respawning");
                        continue;
                    }
                    restarts_this_minute += 1;
                    RESTARTS.fetch_add(1, std::sync::atomic::Ordering::Relaxed);
                    tracing::warn!(slot, status, "worker script ended; respawning (opcache SHM untouched)");
                    handles.push((slot, spawn_worker(slot)));
                } else {
                    i += 1;
                }
            }
            if handles.is_empty() {
                break;
            }
        }
        worst = 1;
    } else {
        let handles: Vec<_> = (1..threads).map(spawn_worker).collect();
        let status = match engine.run_file(&script) {
            Ok(s) => s,
            Err(e) => {
                eprintln!("{e:#}");
                1
            }
        };
        let mut w = status;
        for h in handles {
            w = w.max(h.join().unwrap_or(1));
        }
        worst = w;
    }
    drop(engine);
    rt.shutdown_background();
    ExitCode::from(worst.clamp(0, 255) as u8)
}
