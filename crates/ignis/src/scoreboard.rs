//! ADR-0043 §3: the scoreboard — one slot of plain atomics per worker, written only by that
//! worker, read by the ticker (`watchdog.rs`). Under ZTS the slots are this static array; the NTS
//! prefork model maps the same struct into a `MAP_SHARED` page before the first fork, which is why
//! nothing here is a pointer the reader dereferences: a fiber is an address compared for equality,
//! a request is an id, a site is an index into a string table the events carry by name.
use std::cell::Cell;
use std::sync::atomic::{AtomicU8, AtomicU32, AtomicU64, AtomicUsize, Ordering};

pub const STATE_IDLE: u8 = 0;
pub const STATE_PHP: u8 = 1;
pub const STATE_BLOCKING: u8 = 2;
pub const STATE_ABANDONED: u8 = 3;

pub fn state_name(state: u8) -> &'static str {
    match state {
        STATE_IDLE => "idle",
        STATE_PHP => "php",
        STATE_BLOCKING => "blocking_forward",
        STATE_ABANDONED => "abandoned",
        _ => "unknown",
    }
}

/// Enough for `--threads` on any box this runs on plus the replacements L5 spawns; a slot is 128
/// bytes, so the whole board is 32 KiB.
pub const MAX_SLOTS: usize = 256;

#[repr(C, align(128))]
pub struct WorkerSlot {
    /// 1 while a worker owns the slot.
    pub live: AtomicU8,
    pub state: AtomicU8,
    /// 1 while the ticker has asked this worker to kill `kill_fiber`; cleared by the worker when it
    /// acted or when the episode ended (the next `enter_poll`).
    pub kill_pending: AtomicU8,
    pub pid: AtomicU32,
    pub tid: AtomicU32,
    /// The supervisor's worker number (1..=N under `--supervise`), 0 for the main thread.
    pub worker_no: AtomicU32,
    /// Index into the site table of the blocking forward in progress, 0 = none.
    pub site: AtomicU32,
    /// CLOCK_MONOTONIC when the worker last left `ignis_poll` — the stall clock.
    pub php_since_ns: AtomicU64,
    /// CLOCK_MONOTONIC when the current state began.
    pub state_since_ns: AtomicU64,
    /// CLOCK_MONOTONIC of the last `ignis_poll` entry or exit.
    pub heartbeat_ns: AtomicU64,
    pub request_id: AtomicU64,
    /// `EG(active_fiber)` at the last switch, as an address; never dereferenced by a reader.
    pub fiber: AtomicUsize,
    pub kill_fiber: AtomicUsize,
    /// The stall episode (`php_since_ns`) and request the kill was asked for: a kill applies only
    /// while both still match, so a fiber that yielded, or a pooled fiber that took the next
    /// request, is never killed for the previous one.
    pub kill_episode: AtomicU64,
    pub kill_request: AtomicU64,
    /// `&EG(vm_interrupt)` of this worker: the one byte the kill signal's handler stores to.
    pub interrupt_flag: AtomicUsize,
    pub pthread: AtomicU64,
    pub interrupt_acks: AtomicU64,
    pub kills: AtomicU64,
    pub signals: AtomicU64,
    pub signals_redelivered: AtomicU64,
    pub blocking_calls: AtomicU64,
    pub blocking_us_max: AtomicU64,
}

impl WorkerSlot {
    /// A state store that never overwrites `STATE_ABANDONED`: once the ticker gave a worker up,
    /// nothing the worker itself does brings it back into the board.
    fn move_to(&self, state: u8) {
        let _ = self.state.fetch_update(Ordering::AcqRel, Ordering::Acquire, |current| (current != STATE_ABANDONED).then_some(state));
    }

    pub fn is_abandoned(&self) -> bool {
        self.state.load(Ordering::Acquire) == STATE_ABANDONED
    }

    const fn empty() -> WorkerSlot {
        WorkerSlot {
            live: AtomicU8::new(0),
            state: AtomicU8::new(STATE_IDLE),
            kill_pending: AtomicU8::new(0),
            pid: AtomicU32::new(0),
            tid: AtomicU32::new(0),
            worker_no: AtomicU32::new(0),
            site: AtomicU32::new(0),
            php_since_ns: AtomicU64::new(0),
            state_since_ns: AtomicU64::new(0),
            heartbeat_ns: AtomicU64::new(0),
            request_id: AtomicU64::new(0),
            fiber: AtomicUsize::new(0),
            kill_fiber: AtomicUsize::new(0),
            kill_episode: AtomicU64::new(0),
            kill_request: AtomicU64::new(0),
            interrupt_flag: AtomicUsize::new(0),
            pthread: AtomicU64::new(0),
            interrupt_acks: AtomicU64::new(0),
            kills: AtomicU64::new(0),
            signals: AtomicU64::new(0),
            signals_redelivered: AtomicU64::new(0),
            blocking_calls: AtomicU64::new(0),
            blocking_us_max: AtomicU64::new(0),
        }
    }

    pub fn is_live(&self) -> bool {
        self.live.load(Ordering::Acquire) == 1
    }
}

static SLOTS: [WorkerSlot; MAX_SLOTS] = [const { WorkerSlot::empty() }; MAX_SLOTS];

thread_local! {
    /// This thread's slot index; `usize::MAX` = not a worker.
    static SLOT: Cell<usize> = const { Cell::new(usize::MAX) };
}

pub fn slots() -> &'static [WorkerSlot; MAX_SLOTS] {
    &SLOTS
}

pub fn slot(index: usize) -> Option<&'static WorkerSlot> {
    SLOTS.get(index)
}

/// Nanoseconds of CLOCK_MONOTONIC. vDSO, ~20 ns, async-signal-safe.
pub fn monotonic_ns() -> u64 {
    let mut ts = libc::timespec { tv_sec: 0, tv_nsec: 0 };
    // SAFETY: `ts` is a live local the kernel fills; CLOCK_MONOTONIC always exists.
    unsafe { libc::clock_gettime(libc::CLOCK_MONOTONIC, &mut ts) };
    (ts.tv_sec as u64).saturating_mul(1_000_000_000).saturating_add(ts.tv_nsec as u64)
}

/// Claims a free slot for the calling thread. `interrupt_flag` is the address of this worker's
/// `EG(vm_interrupt)` byte, or 0 when the caller has no engine (a test).
pub fn register_current_thread(worker_no: u32, interrupt_flag: usize) -> Option<usize> {
    let index = SLOTS.iter().position(|s| s.live.compare_exchange(0, 1, Ordering::AcqRel, Ordering::Relaxed).is_ok())?;
    let s = &SLOTS[index];
    // SAFETY: all three take no pointer and cannot fail.
    let (pid, tid, pthread) = unsafe { (libc::getpid(), libc::syscall(libc::SYS_gettid), libc::pthread_self()) };
    s.pid.store(pid as u32, Ordering::Relaxed);
    s.tid.store(tid as u32, Ordering::Relaxed);
    s.pthread.store(pthread, Ordering::Relaxed);
    s.worker_no.store(worker_no, Ordering::Relaxed);
    s.interrupt_flag.store(interrupt_flag, Ordering::Relaxed);
    s.kill_pending.store(0, Ordering::Relaxed);
    s.kill_fiber.store(0, Ordering::Relaxed);
    s.site.store(0, Ordering::Relaxed);
    s.request_id.store(0, Ordering::Relaxed);
    s.fiber.store(0, Ordering::Relaxed);
    let now = monotonic_ns();
    s.php_since_ns.store(now, Ordering::Relaxed);
    s.state_since_ns.store(now, Ordering::Relaxed);
    s.heartbeat_ns.store(now, Ordering::Relaxed);
    s.state.store(STATE_PHP, Ordering::Release);
    SLOT.with(|c| c.set(index));
    Some(index)
}

/// Releases the calling thread's slot; an abandoned slot is left as it is (its thread may still be
/// running somewhere in C) and is reported by the ticker as leaked.
pub fn unregister_current_thread() {
    let index = SLOT.with(|c| c.replace(usize::MAX));
    if let Some(s) = SLOTS.get(index)
        && s.state.load(Ordering::Acquire) != STATE_ABANDONED
    {
        s.state.store(STATE_IDLE, Ordering::Release);
        s.live.store(0, Ordering::Release);
    }
}

pub fn current_index() -> Option<usize> {
    let index = SLOT.with(|c| c.get());
    (index != usize::MAX).then_some(index)
}

pub fn current() -> Option<&'static WorkerSlot> {
    current_index().and_then(slot)
}

/// The worker is about to block in `ignis_poll`: the stall episode, if any, is over.
pub fn enter_poll() {
    if let Some(s) = current() {
        let now = monotonic_ns();
        s.heartbeat_ns.store(now, Ordering::Relaxed);
        s.state_since_ns.store(now, Ordering::Relaxed);
        s.move_to(STATE_IDLE);
        s.kill_pending.store(0, Ordering::Relaxed);
        s.kill_fiber.store(0, Ordering::Relaxed);
    }
}

/// The worker got completions and is about to run PHP.
pub fn leave_poll() {
    if let Some(s) = current() {
        let now = monotonic_ns();
        s.heartbeat_ns.store(now, Ordering::Relaxed);
        s.php_since_ns.store(now, Ordering::Relaxed);
        s.state_since_ns.store(now, Ordering::Relaxed);
        s.move_to(STATE_PHP);
    }
}

#[cfg_attr(not(feature = "universal-park"), allow(dead_code))]
/// The shim is about to forward a call that will block this thread (inside a fiber).
pub fn enter_blocking(site: u32) -> u64 {
    let now = monotonic_ns();
    if let Some(s) = current() {
        s.site.store(site, Ordering::Relaxed);
        s.state_since_ns.store(now, Ordering::Relaxed);
        s.move_to(STATE_BLOCKING);
    }
    now
}

#[cfg_attr(not(feature = "universal-park"), allow(dead_code))]
/// The blocking forward returned; `started` is what `enter_blocking` returned. Returns the
/// duration in microseconds.
pub fn leave_blocking(started: u64) -> u64 {
    let now = monotonic_ns();
    let us = now.saturating_sub(started) / 1000;
    if let Some(s) = current() {
        s.site.store(0, Ordering::Relaxed);
        s.state_since_ns.store(now, Ordering::Relaxed);
        s.move_to(STATE_PHP);
        s.blocking_calls.fetch_add(1, Ordering::Relaxed);
        s.blocking_us_max.fetch_max(us, Ordering::Relaxed);
    }
    us
}

/// Written by the fiber-switch observer: which fiber and request the worker is running now.
pub fn set_current_fiber(fiber: usize, request_id: u64) {
    if let Some(s) = current() {
        s.fiber.store(fiber, Ordering::Relaxed);
        s.request_id.store(request_id, Ordering::Relaxed);
    }
}

pub fn set_current_request(request_id: u64) {
    if let Some(s) = current() {
        s.request_id.store(request_id, Ordering::Relaxed);
    }
}

/// The kill the ticker asked for is answered: either the fiber was killed (`killed`) or the
/// request no longer applies. Clears the pending flag either way.
pub fn acknowledge_kill(killed: bool) {
    if let Some(s) = current() {
        s.kill_pending.store(0, Ordering::Relaxed);
        s.kill_fiber.store(0, Ordering::Relaxed);
        s.interrupt_acks.fetch_add(1, Ordering::Relaxed);
        if killed {
            s.kills.fetch_add(1, Ordering::Relaxed);
        }
    }
}

/// True while the ticker wants the fiber at `fiber` killed on this worker — for the stall
/// episode and the request it was asked for, no other.
pub fn kill_wanted_for(fiber: usize) -> bool {
    current().is_some_and(|s| {
        s.kill_pending.load(Ordering::Acquire) == 1
            && s.kill_fiber.load(Ordering::Relaxed) == fiber
            && s.kill_episode.load(Ordering::Relaxed) == s.php_since_ns.load(Ordering::Relaxed)
            && s.kill_request.load(Ordering::Relaxed) == s.request_id.load(Ordering::Relaxed)
    })
}

/// Blocking-forward sites, named `library:symbol`, indexed from 1.
static SITE_NAMES: std::sync::Mutex<Vec<String>> = std::sync::Mutex::new(Vec::new());

#[cfg_attr(not(feature = "universal-park"), allow(dead_code))]
/// The index of `name` in the site table, adding it if new. Called on the cache-miss path only.
pub fn site_index(name: &str) -> u32 {
    let mut names = crate::lock::LockUnpoisoned::lock_unpoisoned(&SITE_NAMES);
    if let Some(i) = names.iter().position(|n| n == name) {
        return i as u32 + 1;
    }
    names.push(name.to_string());
    names.len() as u32
}

pub fn site_name(index: u32) -> String {
    if index == 0 {
        return String::new();
    }
    crate::lock::LockUnpoisoned::lock_unpoisoned(&SITE_NAMES).get(index as usize - 1).cloned().unwrap_or_default()
}

/// What `/proc` says about a task: `running`, or the syscall it is blocked in; the kernel wait
/// channel; the scheduler state (`R`, `S`, `D`); CPU ticks so far (ADR-0043 §4).
#[derive(Clone, Debug, Default, PartialEq, Eq)]
pub struct ProcState {
    pub syscall: String,
    pub wchan: String,
    pub state: char,
    pub cpu_ticks: u64,
}

impl ProcState {
    pub fn read(pid: u32, tid: u32) -> ProcState {
        let base = format!("/proc/{pid}/task/{tid}");
        let syscall = std::fs::read_to_string(format!("{base}/syscall")).unwrap_or_default();
        let wchan = std::fs::read_to_string(format!("{base}/wchan")).unwrap_or_default();
        let stat = std::fs::read_to_string(format!("{base}/stat")).unwrap_or_default();
        Self::parse(&syscall, &wchan, &stat)
    }

    pub fn parse(syscall: &str, wchan: &str, stat: &str) -> ProcState {
        let syscall = syscall.trim();
        let syscall = if syscall == "running" || syscall.is_empty() {
            syscall.to_string()
        } else {
            let mut parts = syscall.split_whitespace();
            let number = parts.next().unwrap_or("");
            match parts.next() {
                Some(arg0) => format!("{number} arg0={arg0}"),
                None => number.to_string(),
            }
        };
        let after_comm = stat.rsplit_once(')').map(|(_, rest)| rest.trim()).unwrap_or("");
        let fields: Vec<&str> = after_comm.split_whitespace().collect();
        let state = fields.first().and_then(|s| s.chars().next()).unwrap_or('?');
        let ticks = |i: usize| fields.get(i).and_then(|v| v.parse::<u64>().ok()).unwrap_or(0);
        ProcState { syscall, wchan: wchan.trim().trim_matches('\0').to_string(), state, cpu_ticks: ticks(11) + ticks(12) }
    }

    pub fn is_running(&self) -> bool {
        self.syscall == "running"
    }

    pub fn is_uninterruptible(&self) -> bool {
        self.state == 'D'
    }

    pub fn is_lock_wait(&self) -> bool {
        self.wchan.starts_with("futex")
    }

    pub fn describe(&self) -> String {
        format!(
            "proc={} wchan={} state={} cpu_ticks={}",
            if self.syscall.is_empty() { "?" } else { &self.syscall },
            self.wchan,
            self.state,
            self.cpu_ticks
        )
    }
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn a_slot_is_claimed_once_and_released_for_the_next_thread() {
        let a = register_current_thread(7, 0).unwrap();
        assert!(SLOTS[a].is_live());
        assert_eq!(current_index(), Some(a));
        assert_eq!(SLOTS[a].worker_no.load(Ordering::Relaxed), 7);
        unregister_current_thread();
        assert!(!SLOTS[a].is_live());
        assert_eq!(current_index(), None);
        let b = register_current_thread(1, 0).unwrap();
        assert_eq!(a, b, "the freed slot is reused");
        unregister_current_thread();
    }

    #[test]
    fn poll_transitions_reset_the_stall_clock_and_the_kill() {
        let i = register_current_thread(1, 0).unwrap();
        let s = &SLOTS[i];
        s.kill_pending.store(1, Ordering::Relaxed);
        s.kill_fiber.store(42, Ordering::Relaxed);
        s.kill_episode.store(s.php_since_ns.load(Ordering::Relaxed), Ordering::Relaxed);
        s.kill_request.store(s.request_id.load(Ordering::Relaxed), Ordering::Relaxed);
        assert!(kill_wanted_for(42));
        set_current_request(7);
        assert!(!kill_wanted_for(42), "a kill asked for another request never applies");
        set_current_request(0);
        assert!(kill_wanted_for(42));
        enter_poll();
        assert_eq!(s.state.load(Ordering::Relaxed), STATE_IDLE);
        assert!(!kill_wanted_for(42));
        let before = s.php_since_ns.load(Ordering::Relaxed);
        std::thread::sleep(std::time::Duration::from_millis(2));
        leave_poll();
        assert_eq!(s.state.load(Ordering::Relaxed), STATE_PHP);
        assert!(s.php_since_ns.load(Ordering::Relaxed) > before);
        unregister_current_thread();
    }

    #[test]
    fn a_blocking_forward_is_timed_and_counted() {
        let i = register_current_thread(1, 0).unwrap();
        let site = site_index("libphp.so:read");
        assert_eq!(site_index("libphp.so:read"), site);
        assert_eq!(site_name(site), "libphp.so:read");
        let t = enter_blocking(site);
        assert_eq!(SLOTS[i].state.load(Ordering::Relaxed), STATE_BLOCKING);
        std::thread::sleep(std::time::Duration::from_millis(3));
        let us = leave_blocking(t);
        assert!(us >= 3_000, "{us}");
        assert_eq!(SLOTS[i].state.load(Ordering::Relaxed), STATE_PHP);
        assert_eq!(SLOTS[i].blocking_calls.load(Ordering::Relaxed), 1);
        assert!(SLOTS[i].blocking_us_max.load(Ordering::Relaxed) >= 3_000);
        unregister_current_thread();
    }

    #[test]
    fn proc_state_parses_a_blocked_and_a_running_task() {
        let blocked = ProcState::parse(
            "230 0x0 0x0 0x7fff 0x7fff 0x0 0x0 0x7fff 0x7f9a\n",
            "hrtimer_nanosleep\n",
            "123 (sleep) S 1 1 1 0 -1 4194560 100 0 0 0 3 4 0 0 20 0 1 0 5 1000 100 18446744073709551615\n",
        );
        assert_eq!(blocked.syscall, "230 arg0=0x0");
        assert_eq!(blocked.wchan, "hrtimer_nanosleep");
        assert_eq!(blocked.state, 'S');
        assert_eq!(blocked.cpu_ticks, 7);
        assert!(!blocked.is_running());
        let running = ProcState::parse("running\n", "0\n", "5 (a b) R 1 1 1 0 -1 0 0 0 0 0 40 2 0 0 20 0 1 0 5 1 1 1\n");
        assert!(running.is_running());
        assert_eq!(running.state, 'R');
        assert_eq!(running.cpu_ticks, 42);
        assert!(ProcState::parse("202 0x1", "futex_wait_queue", "1 (x) S 0 0 0 0 0 0 0 0 0 0 0 0").is_lock_wait());
        assert!(ProcState::parse("", "", "1 (x) D 0 0 0 0 0 0 0 0 0 0 0 0").is_uninterruptible());
    }

    /// Read from another thread, because a task that reads its own `syscall` file is, at that
    /// instant, inside `read(2)` — which is what the file then reports.
    #[test]
    fn proc_state_of_a_spinning_thread_is_readable_from_another() {
        use std::sync::atomic::AtomicBool;
        let stop = std::sync::Arc::new(AtomicBool::new(false));
        let tid = std::sync::Arc::new(AtomicU32::new(0));
        let (stop2, tid2) = (stop.clone(), tid.clone());
        let spinner = std::thread::spawn(move || {
            // SAFETY: gettid takes no pointer and cannot fail.
            tid2.store(unsafe { libc::syscall(libc::SYS_gettid) } as u32, Ordering::Release);
            while !stop2.load(Ordering::Relaxed) {
                std::hint::spin_loop();
            }
        });
        while tid.load(Ordering::Acquire) == 0 {
            std::thread::yield_now();
        }
        std::thread::sleep(std::time::Duration::from_millis(20));
        // SAFETY: getpid takes nothing and cannot fail.
        let me = ProcState::read(unsafe { libc::getpid() } as u32, tid.load(Ordering::Relaxed));
        stop.store(true, Ordering::Relaxed);
        spinner.join().unwrap();
        assert!(me.is_running(), "{me:?}");
        assert_eq!(me.state, 'R');
    }
}
