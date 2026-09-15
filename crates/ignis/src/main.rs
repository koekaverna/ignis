//! ignis — Rust host for PHP 8.5 ZTS with fiber-based concurrency.
//!
//! Cycle 0 binary: `ignis <script.php>` runs one script on the main thread
//! with a tokio runtime on the side owning all timers/I/O.
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

    let script = match std::env::args().nth(1) {
        Some(s) => PathBuf::from(s),
        None => {
            eprintln!("usage: ignis <script.php>");
            return ExitCode::from(2);
        }
    };

    let rt = tokio::runtime::Builder::new_multi_thread()
        .worker_threads(2)
        .thread_name("ignis-tokio")
        .enable_all()
        .build()
        .expect("tokio runtime");
    let reactor = reactor::Reactor::new(rt.handle());
    php::module::install_reactor(reactor);

    let mut engine = match php::embed::Engine::init("ignis") {
        Ok(e) => e,
        Err(e) => {
            eprintln!("{e:#}");
            return ExitCode::from(1);
        }
    };
    let status = match engine.run_file(&script) {
        Ok(s) => s,
        Err(e) => {
            eprintln!("{e:#}");
            1
        }
    };
    drop(engine);
    rt.shutdown_background();
    ExitCode::from(status.clamp(0, 255) as u8)
}
