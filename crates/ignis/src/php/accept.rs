//! E6'': `stream_socket_accept()` inside a fiber parks on the listening socket's readiness
//! instead of blocking the thread, and the accepted connection is adopted by the reactor so
//! its reads/writes park too. Outside fibers the original runs. The handler is swapped at
//! MINIT like sleep.rs. `IGNIS_NO_ACCEPT_HOOK=1` disables it. The timeout argument is honoured
//! by the original accept only once the socket is readable (a readable listener accepts at once).

use std::ffi::c_void;
use std::sync::OnceLock;

use ignis_sys as sys;

use super::zval;
use crate::reactor::Op;

type Handler = unsafe extern "C" fn(*mut sys::zend_execute_data, *mut sys::zval);
static ORIG_ACCEPT: OnceLock<Handler> = OnceLock::new();

unsafe fn cg() -> *mut sys::zend_compiler_globals {
    unsafe { (sys::tsrm_get_ls_cache() as *mut u8).add(sys::compiler_globals_offset) as *mut sys::zend_compiler_globals }
}
unsafe fn eg() -> *mut sys::zend_executor_globals {
    unsafe { (sys::tsrm_get_ls_cache() as *mut u8).add(sys::executor_globals_offset) as *mut sys::zend_executor_globals }
}

static ORIG_SELECT: OnceLock<Handler> = OnceLock::new();

/// `FG(default_socket_timeout)` (ini `default_socket_timeout`, seconds) on this thread.
/// `FG()` is a plain (not fast-offset) TSRM global: `ZEND_TSRMG(file_globals_id, ..)` indexes the
/// thread's storage vector `(*(void ***) tsrm_get_ls_cache())[id - 1]`.
unsafe fn fg() -> *mut sys::php_file_globals {
    unsafe {
        let storage = *(sys::tsrm_get_ls_cache() as *mut *mut *mut c_void);
        *storage.add((sys::file_globals_id - 1) as usize) as *mut sys::php_file_globals
    }
}
unsafe fn default_socket_timeout() -> i64 {
    unsafe { (*fg()).default_socket_timeout as i64 }
}
/// Sets it and returns the previous value (used to make the original accept return at once).
unsafe fn set_default_socket_timeout(v: i64) -> i64 {
    unsafe {
        let old = (*fg()).default_socket_timeout;
        (*fg()).default_socket_timeout = v as sys::zend_long;
        old as i64
    }
}

pub unsafe fn install() {
    if std::env::var_os("IGNIS_NO_ACCEPT_HOOK").is_some() {
        return;
    }
    unsafe {
        let zv = sys::zend_hash_str_find((*cg()).function_table, c"stream_select".as_ptr(), 13);
        if !zv.is_null() {
            let func = (*zv).value.func;
            if let Some(orig) = (*func).internal_function.handler {
                let _ = ORIG_SELECT.set(orig);
                (*func).internal_function.handler = Some(hooked_select);
            }
        }
        let zv = sys::zend_hash_str_find((*cg()).function_table, c"stream_socket_accept".as_ptr(), 20);
        if zv.is_null() {
            return;
        }
        let func = (*zv).value.func;
        if let Some(orig) = (*func).internal_function.handler {
            let _ = ORIG_ACCEPT.set(orig);
            (*func).internal_function.handler = Some(hooked_accept);
        }
    }
}

unsafe fn stream_fd(zv: *mut sys::zval, castas: u32) -> Option<i32> {
    unsafe {
        if zval::type_of(zv) != sys::IS_RESOURCE {
            return None;
        }
        let stream = sys::zend_fetch_resource2_ex(zv, std::ptr::null(), sys::php_file_le_stream(), sys::php_file_le_pstream()) as *mut sys::php_stream;
        if stream.is_null() {
            return None;
        }
        let mut fd: std::ffi::c_int = -1;
        // PHP_STREAM_CAST_INTERNAL (0x20000000): a probe, not a conversion — no "buffered data lost" warning.
        let castas = castas | 0x2000_0000;
        if sys::_php_stream_cast(stream, castas as std::ffi::c_int, &mut fd as *mut std::ffi::c_int as *mut *mut c_void, 0) != sys::SUCCESS || fd < 0 {
            return None;
        }
        Some(fd)
    }
}

unsafe extern "C" fn hooked_accept(ex: *mut sys::zend_execute_data, rv: *mut sys::zval) {
    // SAFETY: VM frame on the PHP thread; the server stream is arg 0 and stays alive for the call.
    unsafe {
        let orig = ORIG_ACCEPT.get().copied().expect("original stream_socket_accept");
        let in_fiber = !(*eg()).active_fiber.is_null() && !sys::zend_fiber_switch_blocked();
        if !in_fiber || super::module::try_reactor().is_none() || zval::num_args(ex) < 1 {
            orig(ex, rv);
            return;
        }
        let slot = std::mem::size_of::<sys::zend_execute_data>() / std::mem::size_of::<sys::zval>();
        let server = (ex as *mut sys::zval).add(slot);
        let n = zval::num_args(ex) as usize;
        // Timeout as PHP computes it: null → default_socket_timeout; < 0 → forever; non-finite → ValueError.
        let tmo = if n >= 2 { (ex as *mut sys::zval).add(slot + 1) } else { std::ptr::null_mut() };
        let timeout: f64 = if tmo.is_null() || zval::type_of(tmo) == sys::IS_NULL {
            default_socket_timeout() as f64
        } else if zval::type_of(tmo) == sys::IS_DOUBLE {
            let v = (*tmo).value.dval;
            if !v.is_finite() {
                orig(ex, rv); // throws the ValueError
                return;
            }
            v
        } else if zval::type_of(tmo) == sys::IS_LONG {
            (*tmo).value.lval as f64
        } else {
            orig(ex, rv); // let the original report the type error
            return;
        };
        let timeout_us: Option<u64> = if timeout < 0.0 || timeout >= 1.8e13 { None } else { Some((timeout * 1_000_000.0) as u64) };
        // 1. Park until the listener is readable (a connection is queued) or the timeout lapses.
        if let Some(fd) = stream_fd(server, sys::PHP_STREAM_AS_FD_FOR_SELECT) {
            let reactor = super::module::reactor();
            let watch = reactor.submit(Op::Watch { fd, write: false });
            let timer = timeout_us.map(|us| reactor.submit(Op::Sleep { us }));
            let ids: Vec<u64> = std::iter::once(watch).chain(timer).collect();
            let woke = super::stream::await_any(&ids);
            for id in &ids {
                if woke.as_ref().map(|w| w.0) != Some(*id) {
                    reactor.submit(Op::CancelWatch { target: *id });
                }
            }
            let Some((woke_id, _)) = woke else {
                return; // fiber unwound while parked
            };
            if woke_id != watch {
                // Timed out: the original with a zero timeout reports it the stock way
                // (false + "Accept failed: Connection timed out") without blocking the thread.
                let saved_default = set_default_socket_timeout(0);
                let saved_arg: Option<sys::zval> = if tmo.is_null() { None } else { Some(*tmo) };
                if !tmo.is_null() {
                    zval::set_double(tmo, 0.0);
                }
                orig(ex, rv);
                if let Some(v) = saved_arg {
                    *tmo = v;
                }
                set_default_socket_timeout(saved_default);
                return;
            }
        }
        // 2. The original accept returns at once now.
        orig(ex, rv);
        // 3. Adopt the accepted socket so its I/O parks too.
        if zval::type_of(rv) == sys::IS_RESOURCE {
            if let Some(fd) = stream_fd(rv, sys::PHP_STREAM_AS_SOCKETD) {
                let hooked = super::stream::adopt_fd(fd);
                if !hooked.is_null() {
                    sys::zval_ptr_dtor(rv); // closes the stock stream (our dup stays open)
                    (*rv).value.res = (*hooked).res;
                    (*rv).u1.type_info = sys::IS_RESOURCE_EX;
                }
            }
        }
    }
}

/// Streams of a by-reference array argument (`$read`/`$write` of stream_select) → selectable fds,
/// and whether any hooked stream in it already holds read-ahead bytes (ready without parking).
unsafe fn fds_of(arg: *mut sys::zval) -> (Vec<i32>, bool) {
    unsafe {
        let mut zv = arg;
        if zval::type_of(zv) == sys::IS_REFERENCE {
            zv = &raw mut (*(*zv).value.ref_).val;
        }
        if zval::type_of(zv) != sys::IS_ARRAY {
            return (Vec::new(), false);
        }
        let ht = (*zv).value.arr;
        let mut out = Vec::new();
        let mut buffered = false;
        let mut pos: sys::HashPosition = 0;
        sys::zend_hash_internal_pointer_reset_ex(ht, &mut pos);
        loop {
            let v = sys::zend_hash_get_current_data_ex(ht, &pos);
            if v.is_null() {
                break;
            }
            buffered |= super::stream::has_buffered(v);
            if let Some(fd) = stream_fd(v, sys::PHP_STREAM_AS_FD_FOR_SELECT) {
                out.push(fd);
            }
            sys::zend_hash_move_forward_ex(ht, &mut pos);
        }
        (out, buffered)
    }
}

/// Run the original stream_select with a zero timeout (a non-blocking probe); restores the timeout args.
unsafe fn probe(orig: Handler, ex: *mut sys::zend_execute_data, rv: *mut sys::zval, n: usize) {
    unsafe {
        let slot = std::mem::size_of::<sys::zend_execute_data>() / std::mem::size_of::<sys::zval>();
        let arg = |i: usize| (ex as *mut sys::zval).add(slot + i);
        let saved3: sys::zval = *arg(3);
        let saved4: Option<sys::zval> = if n >= 5 { Some(*arg(4)) } else { None };
        zval::set_long(arg(3), 0);
        if n >= 5 {
            zval::set_long(arg(4), 0);
        }
        orig(ex, rv);
        *arg(3) = saved3;
        if let Some(v) = saved4 {
            *arg(4) = v;
        }
    }
}

/// `stream_select()` inside a fiber: park on readiness of every fd (and the timeout) instead of
/// blocking the thread, then let the original compute the ready sets with a zero timeout.
unsafe extern "C" fn hooked_select(ex: *mut sys::zend_execute_data, rv: *mut sys::zval) {
    // SAFETY: VM frame; the array arguments are read (not modified) before the original runs.
    unsafe {
        let orig = ORIG_SELECT.get().copied().expect("original stream_select");
        let in_fiber = !(*eg()).active_fiber.is_null() && !sys::zend_fiber_switch_blocked();
        let n = zval::num_args(ex) as usize;
        if !in_fiber || super::module::try_reactor().is_none() || n < 4 {
            orig(ex, rv);
            return;
        }
        let slot = std::mem::size_of::<sys::zend_execute_data>() / std::mem::size_of::<sys::zval>();
        let arg = |i: usize| (ex as *mut sys::zval).add(slot + i);
        let (reads, read_buffered) = fds_of(arg(0));
        let (writes, _) = fds_of(arg(1));
        // seconds (null = forever), microseconds
        let secs_zv = arg(3);
        let timeout_us: Option<u64> = if zval::type_of(secs_zv) == sys::IS_NULL {
            None
        } else {
            let secs = if zval::type_of(secs_zv) == sys::IS_LONG { (*secs_zv).value.lval.max(0) as u64 } else { 0 };
            let usec = if n >= 5 && zval::type_of(arg(4)) == sys::IS_LONG { (*arg(4)).value.lval.max(0) as u64 } else { 0 };
            Some(secs * 1_000_000 + usec)
        };
        if reads.is_empty() && writes.is_empty() {
            orig(ex, rv);
            return;
        }
        // 1. Non-blocking probe first: PHP's own buffered-data check and anything already ready
        //    answer at once (the original select with tv = 0 modifies the arrays in place).
        //    Read-ahead held by our hooked streams counts as ready too.
        if read_buffered || timeout_us == Some(0) {
            probe(orig, ex, rv, n);
            return;
        }
        // The probe consumes the arrays (it filters them); copy them first so the parked path can re-run.
        let mut copies: Vec<(usize, sys::zval)> = Vec::new();
        for i in 0..3usize.min(n) {
            let mut zv = arg(i);
            if zval::type_of(zv) == sys::IS_REFERENCE {
                zv = &raw mut (*(*zv).value.ref_).val;
            }
            if zval::type_of(zv) == sys::IS_ARRAY {
                let mut copy: sys::zval = *zv;
                sys::zval_add_ref(&mut copy);
                copies.push((i, copy));
            }
        }
        probe(orig, ex, rv, n);
        let ready = if zval::type_of(rv) == sys::IS_LONG { (*rv).value.lval } else { -1 };
        if ready != 0 {
            for (_, mut c) in copies {
                sys::zval_ptr_dtor(&mut c);
            }
            return; // something was ready (or the probe failed): that is the answer
        }
        // Nothing ready: restore the arrays and park on readiness + timeout.
        for (i, copy) in copies {
            let mut zv = arg(i);
            if zval::type_of(zv) == sys::IS_REFERENCE {
                zv = &raw mut (*(*zv).value.ref_).val;
            }
            sys::zval_ptr_dtor(zv);
            *zv = copy;
        }
        let reactor = super::module::reactor();
        let mut ids: Vec<u64> = Vec::new();
        for fd in &reads {
            ids.push(reactor.submit(Op::Watch { fd: *fd, write: false }));
        }
        for fd in &writes {
            ids.push(reactor.submit(Op::Watch { fd: *fd, write: true }));
        }
        let timer = timeout_us.map(|us| reactor.submit(Op::Sleep { us }));
        if let Some(t) = timer {
            ids.push(t);
        }
        let woke = super::stream::await_any(&ids);
        // Cancel the watches (closes their dup'd fds) and the timer that did not fire: a lapsing
        // timer would count as in flight and keep the userland loop alive (bug64438: 60 s).
        for id in &ids {
            if woke.as_ref().map(|w| w.0) != Some(*id) {
                reactor.submit(Op::CancelWatch { target: *id });
            }
        }
        if woke.is_none() {
            return; // unwound while parked
        }
        // Compute the ready sets now, without blocking.
        probe(orig, ex, rv, n);
    }
}
