//! `--workers N` (ADR-0044): one master process and N forked workers, the shape php-fpm made
//! familiar. The master initialises the engine (opcache's shared segment is allocated in MINIT,
//! so every worker inherits one), binds the listener, forks, and then only supervises: it reaps,
//! respawns, and forwards the signals an operator sends it. A worker is what a single-process
//! `ignis` is today — its own tokio runtime and reactor, created after the fork — so this holds
//! for both engine ABIs; `--threads` inside a worker stays the thread-safe build's extra.
//!
//! FFI contract: `fork` is called while the process has exactly one thread. Nothing here touches
//! the engine after the fork; the master keeps it alive and idle so the shared segment outlives
//! every worker.

use std::net::TcpListener;
use std::sync::atomic::{AtomicI32, Ordering};
use std::time::{Duration, Instant};

use anyhow::{Context, Result};

/// The last signal the master received and has not acted on yet; `0` is none.
static PENDING_SIGNAL: AtomicI32 = AtomicI32::new(0);

/// Restarts allowed per minute before a slot is paused, the same budget the thread supervisor has.
const RESTARTS_PER_MINUTE: u32 = 10;
const RESTART_PAUSE: Duration = Duration::from_secs(5);

/// Binds the address every worker will accept on, before any fork, so the socket is inherited.
pub fn bind_shared_listener(address: &str) -> Result<TcpListener> {
    TcpListener::bind(address).with_context(|| format!("bind {address}"))
}

/// Forks `count` workers and supervises them until they are gone. In each child, `worker` runs
/// once and its return value is that process's exit status; the parent never calls it. With
/// `respawn`, a worker that ends comes back (a crash loop is paused, never dropped); without it,
/// the master waits for every worker and answers with the worst status.
pub fn run(count: usize, respawn: bool, mut worker: impl FnMut(usize) -> i32) -> i32 {
    install_signal_handlers();
    let mut children: Vec<Child> = Vec::with_capacity(count);
    for slot in 0..count {
        match spawn(slot, &mut worker) {
            Ok(child) => children.push(child),
            Err(error) => {
                tracing::error!(slot, %error, "could not fork a worker");
                return 1;
            }
        }
    }
    supervise(children, respawn, &mut worker)
}

struct Child {
    slot: usize,
    pid: libc::pid_t,
    spawned_at: Instant,
    retry_at: Option<Instant>,
}

/// `fork` for one slot: the child runs the worker and exits with its status; the parent records it.
fn spawn(slot: usize, worker: &mut impl FnMut(usize) -> i32) -> Result<Child> {
    // SAFETY: the process is single-threaded here (the master never creates a runtime or a PHP
    // thread), so the child inherits a consistent heap, engine and listener. The child leaves
    // through `process::exit` and never returns into the parent's supervision loop.
    let pid = unsafe { libc::fork() };
    if pid < 0 {
        return Err(std::io::Error::last_os_error()).context("fork");
    }
    if pid == 0 {
        std::process::exit(worker(slot).clamp(0, 255));
    }
    Ok(Child { slot, pid, spawned_at: Instant::now(), retry_at: None })
}

fn supervise(mut children: Vec<Child>, respawn: bool, worker: &mut impl FnMut(usize) -> i32) -> i32 {
    let mut worst_status = 0;
    let mut restarts_this_minute = 0u32;
    let mut minute = Instant::now();
    let mut shutdown_deadline: Option<Instant> = None;
    loop {
        std::thread::sleep(Duration::from_millis(50));
        while let Some((pid, status)) = reap_one() {
            let Some(index) = children.iter().position(|child| child.pid == pid) else { continue };
            let child = children.remove(index);
            worst_status = worst_status.max(status);
            if shutdown_deadline.is_some() || !respawn {
                continue;
            }
            if minute.elapsed() > Duration::from_secs(60) {
                minute = Instant::now();
                restarts_this_minute = 0;
            }
            let mut back = child;
            if restarts_this_minute >= RESTARTS_PER_MINUTE {
                back.retry_at = Some(Instant::now() + RESTART_PAUSE);
                tracing::error!(slot = back.slot, status, "worker keeps ending; restart budget exhausted (10/min), retrying in 5s");
                children.push(back);
                continue;
            }
            restarts_this_minute += 1;
            crate::RESTARTS.fetch_add(1, Ordering::Relaxed);
            tracing::warn!(slot = back.slot, status, lived_ms = back.spawned_at.elapsed().as_millis() as u64, "worker ended; respawning");
            match spawn(back.slot, worker) {
                Ok(fresh) => children.push(fresh),
                Err(error) => tracing::error!(slot = back.slot, %error, "could not respawn the worker"),
            }
        }
        retry_paused_slots(&mut children, worker);
        match PENDING_SIGNAL.swap(0, Ordering::SeqCst) {
            0 => {}
            libc::SIGHUP => reload_one_at_a_time(&mut children, worker),
            signal => {
                forward(&children, signal);
                shutdown_deadline.get_or_insert_with(|| Instant::now() + drain_timeout() + Duration::from_secs(2));
            }
        }
        if children.is_empty() {
            return if shutdown_deadline.is_some() { 0 } else { worst_status };
        }
        if shutdown_deadline.is_some_and(|deadline| Instant::now() > deadline) {
            tracing::warn!(remaining = children.len(), "workers did not drain in time; killing them");
            forward(&children, libc::SIGKILL);
            return 0;
        }
    }
}

fn retry_paused_slots(children: &mut [Child], worker: &mut impl FnMut(usize) -> i32) {
    for child in children.iter_mut() {
        let Some(retry_at) = child.retry_at else { continue };
        if Instant::now() < retry_at {
            continue;
        }
        tracing::warn!(slot = child.slot, "retrying the worker after its restart budget ran out");
        match spawn(child.slot, worker) {
            Ok(fresh) => *child = fresh,
            Err(error) => tracing::error!(slot = child.slot, %error, "could not respawn the worker"),
        }
    }
}

/// `SIGHUP`: each worker is asked to drain and comes back fresh, one at a time, so the listener
/// is never without a worker — what `nginx -s reload` and php-fpm's `SIGUSR2` mean by the word.
fn reload_one_at_a_time(children: &mut [Child], worker: &mut impl FnMut(usize) -> i32) {
    for child in children.iter_mut() {
        // SAFETY: a plain kill(2) on a pid this process forked and has not reaped.
        unsafe { libc::kill(child.pid, libc::SIGTERM) };
        let status = wait_for(child.pid, drain_timeout() + Duration::from_secs(2));
        tracing::info!(slot = child.slot, status, "worker reloaded");
        match spawn(child.slot, worker) {
            Ok(fresh) => *child = fresh,
            Err(error) => tracing::error!(slot = child.slot, %error, "could not respawn the worker"),
        }
    }
}

fn forward(children: &[Child], signal: i32) {
    for child in children {
        // SAFETY: kill(2) on a pid this process forked and has not reaped.
        unsafe { libc::kill(child.pid, signal) };
    }
}

/// One finished child, if any: `(pid, exit status)`, a signal death counted as 128 + the signal.
fn reap_one() -> Option<(libc::pid_t, i32)> {
    let mut raw = 0;
    // SAFETY: waitpid with WNOHANG never blocks and writes only into `raw`.
    let pid = unsafe { libc::waitpid(-1, &mut raw, libc::WNOHANG) };
    if pid <= 0 {
        return None;
    }
    Some((pid, exit_status(raw)))
}

fn wait_for(pid: libc::pid_t, limit: Duration) -> i32 {
    let deadline = Instant::now() + limit;
    loop {
        let mut raw = 0;
        // SAFETY: as `reap_one`, for one pid.
        let reaped = unsafe { libc::waitpid(pid, &mut raw, libc::WNOHANG) };
        if reaped == pid {
            return exit_status(raw);
        }
        if Instant::now() > deadline {
            // SAFETY: kill(2) on a pid this process forked and has not reaped.
            unsafe { libc::kill(pid, libc::SIGKILL) };
            // SAFETY: a blocking waitpid on a pid that was just killed returns promptly.
            unsafe { libc::waitpid(pid, &mut raw, 0) };
            return 137;
        }
        std::thread::sleep(Duration::from_millis(20));
    }
}

fn exit_status(raw: i32) -> i32 {
    if libc::WIFEXITED(raw) {
        libc::WEXITSTATUS(raw)
    } else if libc::WIFSIGNALED(raw) {
        128 + libc::WTERMSIG(raw)
    } else {
        1
    }
}

fn drain_timeout() -> Duration {
    Duration::from_millis(std::env::var("IGNIS_DRAIN_TIMEOUT_MS").ok().and_then(|v| v.parse().ok()).unwrap_or(10_000))
}

/// The engine's RINIT wraps `SIGTERM`/`SIGINT`/`SIGHUP` in Zend's deferring handler, which re-raises
/// the default action when nothing was registered before it — and nothing was, because the
/// runtime that owns those signals is built after the engine (a fork needs a single-threaded
/// process). Hand them back to the default so tokio's handler is the only one (E23 on NTS).
pub fn reclaim_shutdown_signals() {
    for signal in [libc::SIGTERM, libc::SIGINT, libc::SIGHUP] {
        // SAFETY: restoring the default disposition of a signal this process owns, on one thread,
        // before any runtime or worker exists.
        unsafe { libc::signal(signal, libc::SIG_DFL) };
    }
}

extern "C" fn remember_signal(signal: libc::c_int) {
    PENDING_SIGNAL.store(signal, Ordering::SeqCst);
}

fn install_signal_handlers() {
    for signal in [libc::SIGTERM, libc::SIGINT, libc::SIGHUP] {
        // SAFETY: the handler only stores an integer into an atomic, which is async-signal-safe;
        // `signal` is installed before any worker exists.
        unsafe { libc::signal(signal, remember_signal as extern "C" fn(libc::c_int) as libc::sighandler_t) };
    }
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn a_normal_exit_is_its_status_and_a_signal_death_is_128_plus_the_signal() {
        assert_eq!(exit_status(3 << 8), 3);
        assert_eq!(exit_status(libc::SIGKILL), 128 + libc::SIGKILL);
    }

    #[test]
    fn workers_run_once_each_and_the_master_answers_with_the_worst_status() {
        let status = run(3, false, |slot| slot as i32);
        assert_eq!(status, 2);
    }
}
