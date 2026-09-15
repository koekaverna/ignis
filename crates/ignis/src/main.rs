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
    if args.len() >= 2 && args[0] == "--threads" {
        threads = args[1].parse().unwrap_or(1);
        args.drain(0..2);
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

    // Threads 1..N: each attaches to TSRM, gets its own reactor, runs the same script.
    let mut handles = Vec::new();
    for i in 1..threads {
        let script = script.clone();
        let rt_handle = rt.handle().clone();
        handles.push(
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
                    w.run_file(&script).unwrap_or(1)
                })
                .expect("spawn php thread"),
        );
    }
    let status = match engine.run_file(&script) {
        Ok(s) => s,
        Err(e) => {
            eprintln!("{e:#}");
            1
        }
    };
    let mut worst = status;
    for h in handles {
        worst = worst.max(h.join().unwrap_or(1));
    }
    drop(engine);
    rt.shutdown_background();
    ExitCode::from(worst.clamp(0, 255) as u8)
}
