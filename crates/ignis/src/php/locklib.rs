//! H36 (ADR-0020 acceptance 5, ADR-0037 §5): the harness for the lock-hazard test, and nothing
//! else. The claim under test is that a library holding a non-recursive mutex across a blocking
//! syscall **deadlocks** under a `park` policy and **passes** under `block` — the reason every
//! `park` row in the policy table needs a source audit first (research 27, 30).
//!
//! No such library is available: research 27 found libcurl, libpq and OpenSSL all lock-free on
//! their blocking paths, which is why the shim `bench/e18/locklib.c` exists. This build has no
//! `ext/ffi` and PHP hands out no raw file descriptors, so three internal functions bridge the
//! gap. They are registered **only** when `IGNIS_LOCKLIB` names the shared object, so a normal
//! build has no trace of them:
//!
//! - `ignis_locklib_pipe(): array` — a fresh empty pipe, `[read fd, write fd]`.
//! - `ignis_locklib_write(int $fd, string $data): int` — write from the PHP thread.
//! - `ignis_locklib_feed(int $fd, int $ms, int $times): bool` — write from a plain OS thread, so a
//!   `block` arm (where the PHP thread is stuck in `read`) still terminates.
//! - `ignis_locklib_call(int $fd, int $len, bool $trylock): int` — the shim's `locklib_call`:
//!   lock, `read(fd)`, unlock. With `$trylock` it reports `-2` instead of waiting for a mutex
//!   another fiber holds, which is how a deadlock is *observed* rather than waited on.
//!
//! The shim is `dlopen`ed once, globally: it must resolve `read` to the interposed symbol in the
//! executable, which it does because the executable is first in the lookup scope (research 28).

use std::ffi::{CStr, c_char, c_int};
use std::sync::OnceLock;

use ignis_sys as sys;

use super::zval;

type LocklibCall = unsafe extern "C" fn(c_int, *mut c_char, usize, c_int) -> i64;

static CALL: OnceLock<usize> = OnceLock::new();

/// `dlopen` the shim named by `IGNIS_LOCKLIB` and resolve `locklib_call`. `None` if either fails.
fn locklib_call() -> Option<LocklibCall> {
    let addr = *CALL.get_or_init(|| {
        let Ok(path) = std::env::var("IGNIS_LOCKLIB") else { return 0 };
        let Ok(c_path) = std::ffi::CString::new(path.clone()) else { return 0 };
        // SAFETY: dlopen/dlsym take C strings and return opaque pointers; nothing is dereferenced
        // here. RTLD_GLOBAL is deliberate: the shim's `read` must bind to our interposer.
        unsafe {
            let h = libc::dlopen(c_path.as_ptr(), libc::RTLD_NOW | libc::RTLD_GLOBAL);
            if h.is_null() {
                tracing::warn!(path, "IGNIS_LOCKLIB: dlopen failed");
                return 0;
            }
            let sym = libc::dlsym(h, c"locklib_call".as_ptr());
            if sym.is_null() {
                tracing::warn!(path, "IGNIS_LOCKLIB: locklib_call not found");
                return 0;
            }
            sym as usize
        }
    });
    // SAFETY: the address came from dlsym on a symbol whose C signature is the one in
    // bench/e18/locklib.c, which this type mirrors.
    (addr != 0).then(|| unsafe { std::mem::transmute::<usize, LocklibCall>(addr) })
}

unsafe extern "C" fn zif_pipe(_ex: *mut sys::zend_execute_data, rv: *mut sys::zval) {
    // SAFETY: VM frame on the PHP thread; `rv` is the return slot Zend owns.
    unsafe {
        let mut fds = [0 as c_int; 2];
        if libc::pipe(fds.as_mut_ptr()) != 0 {
            zval::set_bool(rv, false);
            return;
        }
        zval::set_new_array(rv);
        sys::add_next_index_long(rv, fds[0] as i64);
        sys::add_next_index_long(rv, fds[1] as i64);
    }
}

/// `ignis_locklib_feed(int $wfd, int $ms, int $times): bool` — a detached OS thread writes one
/// byte every `$ms` ms, `$times` times. The feeder must NOT be a fiber: under a `block` policy the
/// PHP thread is stuck inside `read`, so a fiber writer could never run and the control arm would
/// hang for a reason that has nothing to do with the lock.
unsafe extern "C" fn zif_feed(ex: *mut sys::zend_execute_data, rv: *mut sys::zval) {
    // SAFETY: VM frame on the PHP thread; the thread below touches only the fd and libc.
    unsafe {
        let (mut fd, mut ms, mut times): (sys::zend_long, sys::zend_long, sys::zend_long) = (0, 0, 1);
        if sys::zend_parse_parameters(zval::num_args(ex), c"lll".as_ptr(), &mut fd, &mut ms, &mut times) != sys::SUCCESS {
            return;
        }
        let (fd, ms, times) = (fd as c_int, ms.max(0) as u64, times.clamp(1, 16));
        std::thread::spawn(move || {
            for _ in 0..times {
                std::thread::sleep(std::time::Duration::from_millis(ms));
                // SAFETY: a raw write of one byte to a pipe the harness owns for the run.
                libc::syscall(libc::SYS_write, fd, c"x".as_ptr(), 1usize);
            }
        });
        zval::set_bool(rv, true);
    }
}

unsafe extern "C" fn zif_write(ex: *mut sys::zend_execute_data, rv: *mut sys::zval) {
    // SAFETY: as above; the string argument is borrowed for the duration of the call.
    unsafe {
        let mut fd: sys::zend_long = 0;
        let mut s: *mut sys::zend_string = std::ptr::null_mut();
        if sys::zend_parse_parameters(zval::num_args(ex), c"lS".as_ptr(), &mut fd, &mut s) != sys::SUCCESS {
            return;
        }
        let len = (*s).len;
        let n = libc::syscall(libc::SYS_write, fd as c_int, (*s).val.as_ptr(), len);
        zval::set_long(rv, n);
    }
}

unsafe extern "C" fn zif_call(ex: *mut sys::zend_execute_data, rv: *mut sys::zval) {
    // SAFETY: as above. The buffer is a local of `len` bytes and outlives the call.
    unsafe {
        let mut fd: sys::zend_long = 0;
        let mut len: sys::zend_long = 0;
        let mut trylock: bool = false;
        if sys::zend_parse_parameters(zval::num_args(ex), c"llb".as_ptr(), &mut fd, &mut len, &mut trylock) != sys::SUCCESS {
            return;
        }
        let Some(call) = locklib_call() else {
            zval::set_long(rv, -3); // no shim: the test says so instead of pretending
            return;
        };
        let len = len.clamp(1, 65536) as usize;
        let mut buf = vec![0 as c_char; len];
        zval::set_long(rv, call(fd as c_int, buf.as_mut_ptr(), len, c_int::from(trylock)));
    }
}

const fn arg(name: &'static CStr) -> sys::zend_internal_arg_info {
    sys::zend_internal_arg_info {
        name: name.as_ptr(),
        type_: sys::zend_type { ptr: std::ptr::null_mut(), type_mask: 0 },
        default_value: std::ptr::null(),
    }
}

const fn head(required: usize) -> sys::zend_internal_arg_info {
    sys::zend_internal_arg_info {
        name: required as *const c_char,
        type_: sys::zend_type { ptr: std::ptr::null_mut(), type_mask: 0 },
        default_value: std::ptr::null(),
    }
}

struct Sync3<T>(T);
// SAFETY: the arrays are read-only after construction and live for the process lifetime, which is
// what `zend_register_functions` requires of them.
unsafe impl<T> Sync for Sync3<T> {}

static AI_NONE: Sync3<[sys::zend_internal_arg_info; 1]> = Sync3([head(0)]);
static AI_WRITE: Sync3<[sys::zend_internal_arg_info; 3]> = Sync3([head(2), arg(c"fd"), arg(c"data")]);
static AI_FEED: Sync3<[sys::zend_internal_arg_info; 4]> = Sync3([head(3), arg(c"fd"), arg(c"ms"), arg(c"times")]);
static AI_CALL: Sync3<[sys::zend_internal_arg_info; 4]> = Sync3([head(3), arg(c"fd"), arg(c"len"), arg(c"trylock")]);

const fn fe(
    name: &'static CStr,
    handler: unsafe extern "C" fn(*mut sys::zend_execute_data, *mut sys::zval),
    arg_info: *const sys::zend_internal_arg_info,
    num_args: u32,
) -> sys::zend_function_entry {
    sys::zend_function_entry {
        fname: name.as_ptr(),
        handler: Some(handler),
        arg_info,
        num_args,
        flags: 0,
        frameless_function_infos: std::ptr::null(),
        doc_comment: std::ptr::null(),
    }
}

static FUNCTIONS: Sync3<[sys::zend_function_entry; 5]> = Sync3([
    fe(c"ignis_locklib_pipe", zif_pipe, AI_NONE.0.as_ptr(), 0),
    fe(c"ignis_locklib_write", zif_write, AI_WRITE.0.as_ptr(), 2),
    fe(c"ignis_locklib_feed", zif_feed, AI_FEED.0.as_ptr(), 3),
    fe(c"ignis_locklib_call", zif_call, AI_CALL.0.as_ptr(), 3),
    // zero terminator
    fe(unsafe { CStr::from_bytes_with_nul_unchecked(b"\0") }, zif_pipe, std::ptr::null(), 0),
]);

/// Registers the three functions at MINIT, but only when `IGNIS_LOCKLIB` is set.
///
/// # Safety
/// MINIT on the main thread, before `zend_post_startup` copies the function table.
pub unsafe fn install() {
    if std::env::var_os("IGNIS_LOCKLIB").is_none() {
        return;
    }
    // SAFETY: MINIT; a null scope and function table mean "the global one", and the entries live
    // for the process lifetime. MODULE_PERSISTENT because the module is built into the binary.
    let rc = unsafe {
        let mut fns = FUNCTIONS.0;
        fns[4].fname = std::ptr::null(); // Zend's end marker
        sys::zend_register_functions(std::ptr::null_mut(), fns.as_ptr(), std::ptr::null_mut(), sys::MODULE_PERSISTENT as c_int)
    };
    if rc == sys::SUCCESS {
        tracing::info!("locklib harness registered (H36)");
    } else {
        tracing::warn!("locklib harness: zend_register_functions failed");
    }
}
