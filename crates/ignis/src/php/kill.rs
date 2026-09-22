//! ADR-0043 §7, L3/L4: the delivery of a kill to a worker, and what the worker does with it.
//!
//! The ticker (`watchdog.rs`) records the fiber it wants dead in the worker's scoreboard slot and
//! sends `KILL_SIGNAL` to that thread (`pthread_kill` under ZTS; the NTS prefork master will
//! `kill(pid)` the same signal). The handler stores one byte — `EG(vm_interrupt)` — and counts;
//! nothing else is async-signal-safe and nothing else is needed. The engine then calls
//! `on_interrupt` at the next opcode boundary (research 49 E2), where it is a normal PHP thread
//! again: if the running fiber is still the one asked for, it is force-closed exactly the way the
//! engine force-closes a destroyed fiber (research 49 E3), or thrown `Ignis\KilledException` when
//! configured so. A fiber blocked in a shimmed syscall never reaches an opcode: the same signal
//! interrupts the wait with `EINTR` and the interposer answers `ECANCELED` (L4, `park.rs`).
//!
//! FFI contract: `zend_interrupt_function` is process-global and chained; the handler is installed
//! once per process with `SA_RESTART`, so nothing outside the shim's waits observes it.
use std::ffi::c_int;
use std::ptr;
use std::sync::OnceLock;
use std::sync::atomic::{AtomicU8, Ordering};

use ignis_sys as sys;

use super::tsrm;
use crate::recovery::{KillKind, Settings};
use crate::scoreboard;

/// `SIGRTMIN` is PHP's own timer signal (research 49 H4); this stays clear of it.
pub fn kill_signal() -> c_int {
    libc::SIGRTMIN() + 2
}

static PREVIOUS_INTERRUPT: OnceLock<Option<unsafe extern "C" fn(*mut sys::zend_execute_data)>> = OnceLock::new();

/// The signal handler: the worker's own `EG(vm_interrupt)` byte, whose address the slot holds.
extern "C" fn on_kill_signal(_sig: c_int, _info: *mut libc::siginfo_t, _ctx: *mut std::ffi::c_void) {
    let Some(slot) = scoreboard::current() else { return };
    slot.signals.fetch_add(1, Ordering::Relaxed);
    let flag = slot.interrupt_flag.load(Ordering::Relaxed);
    if flag != 0 {
        // SAFETY: the address was taken from this thread's live executor globals at registration
        // and the globals outlive the thread's PHP work; zend_atomic_bool is one byte the engine
        // itself stores to from a signal handler (zend_timeout_handler).
        unsafe { AtomicU8::from_ptr(flag as *mut u8).store(1, Ordering::Release) };
    }
}

/// Installs the handler and chains the interrupt function. MINIT, main thread, once per process.
///
/// # Safety
/// MINIT on the main thread.
pub unsafe fn install() {
    // SAFETY: sigaction on a real-time signal nothing else in the process uses; the handler only
    // stores to atomics. The interrupt function pointer is a plain global the engine reads at
    // opcode boundaries, written here before any worker thread exists.
    unsafe {
        let mut action: libc::sigaction = std::mem::zeroed();
        action.sa_sigaction = on_kill_signal as *const () as usize;
        action.sa_flags = libc::SA_SIGINFO | libc::SA_RESTART | libc::SA_ONSTACK;
        libc::sigemptyset(&mut action.sa_mask);
        if libc::sigaction(kill_signal(), &action, ptr::null_mut()) != 0 {
            tracing::error!(error = %std::io::Error::last_os_error(), "kill signal handler not installed; L3/L4 recovery is off");
            return;
        }
        let _ = PREVIOUS_INTERRUPT.set(sys::zend_interrupt_function);
        sys::zend_interrupt_function = Some(on_interrupt);
    }
}

/// The address of this thread's `EG(vm_interrupt)`, for the scoreboard slot.
///
/// # Safety
/// PHP thread with a live TSRM context.
pub unsafe fn interrupt_flag_address() -> usize {
    // SAFETY: the caller upholds the contract; the field address is taken, not read.
    unsafe { (&raw mut (*tsrm::executor_globals()).vm_interrupt.value) as usize }
}

/// Runs at an opcode boundary on the PHP thread after `vm_interrupt` was stored (by our signal
/// handler, or by anyone else — hence the chain and the check).
unsafe extern "C" fn on_interrupt(execute_data: *mut sys::zend_execute_data) {
    // SAFETY: called by the engine on a PHP thread at an opcode boundary with EG valid.
    unsafe {
        if let Some(Some(previous)) = PREVIOUS_INTERRUPT.get() {
            previous(execute_data);
        }
        let fiber = (*tsrm::executor_globals()).active_fiber;
        if fiber.is_null() || !scoreboard::kill_wanted_for(fiber as usize) {
            if scoreboard::current().is_some_and(|s| s.kill_pending.load(Ordering::Acquire) == 1) {
                scoreboard::acknowledge_kill(false);
            }
            return;
        }
        force_close(fiber);
        scoreboard::acknowledge_kill(true);
    }
}

/// Force-closes the running fiber: the engine's own DESTROYED flag plus its graceful exit, so
/// `finally` runs, `catch (Throwable)` cannot intercept, a further suspend throws `FiberError`, and
/// the resumer sees a quiet termination — or `Ignis\KilledException` when configured.
///
/// # Safety
/// PHP thread at an opcode boundary, `fiber` = `EG(active_fiber)`.
unsafe fn force_close(fiber: *mut sys::zend_fiber) {
    // SAFETY: the caller upholds the contract; the flag is the engine's own and the throw is the
    // engine's own, both exactly as zend_fiber_object_destroy uses them (research 49 E1/E3).
    unsafe {
        let meta = super::fibermeta::of(fiber);
        if !meta.is_null() {
            (*meta).kill_pending = true;
        }
        match Settings::global().kill {
            KillKind::Graceful => {
                (*fiber).flags |= sys::ZEND_FIBER_FLAG_DESTROYED as u8;
                sys::zend_throw_graceful_exit();
            }
            KillKind::Exception => {
                let ce = killed_exception_class();
                sys::zend_throw_exception(ce, c"Ignis: fiber killed by the stall watchdog".as_ptr(), 0);
            }
        }
    }
}

/// `Ignis\KilledException` when the runtime package is loaded, else `\Error`.
unsafe fn killed_exception_class() -> *mut sys::zend_class_entry {
    // SAFETY: on the PHP thread at an opcode boundary; a class lookup may autoload, which is
    // permitted there. The interned name lives for the request.
    unsafe {
        if let Some(intern) = sys::zend_string_init_interned {
            let name = intern(c"Ignis\\KilledException".as_ptr(), 21, false);
            let ce = sys::zend_lookup_class(name);
            if !ce.is_null() {
                return ce;
            }
        }
        sys::zend_ce_error
    }
}

/// L3/L4 delivery from the ticker: record the fiber and signal the worker.
pub fn deliver(slot: &scoreboard::WorkerSlot) -> bool {
    slot.kill_fiber.store(slot.fiber.load(Ordering::Relaxed), Ordering::Relaxed);
    slot.kill_pending.store(1, Ordering::Release);
    let pid = slot.pid.load(Ordering::Relaxed);
    // SAFETY: getpid takes nothing; pthread_kill/kill are given a thread handle or pid the worker
    // published about itself, and a signal to a thread that is gone returns ESRCH, not UB.
    unsafe {
        let rc = if pid == libc::getpid() as u32 {
            libc::pthread_kill(slot.pthread.load(Ordering::Relaxed) as libc::pthread_t, kill_signal())
        } else {
            libc::kill(pid as libc::pid_t, kill_signal())
        };
        rc == 0
    }
}
