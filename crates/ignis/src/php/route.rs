//! E16 (ADR-0016) auto-routing: inside a fiber, configured internal functions (`curl_*`) and
//! classes (`PDO`, `SQLite3`) are redirected to `Ignis\Offload\Router` in PHP, which runs them on
//! an offload worker and hands the fiber a proxy. Outside fibers, or on the worker threads
//! themselves, the original handlers run, so the workers can execute the real calls.
//!
//! Functions: the internal handler is swapped at MINIT (like sleep.rs); the trampoline reads the
//! function name from the frame and calls `Router::dispatch(name, args)`. Classes: the class
//! entry's `create_object` is swapped; inside a fiber it instantiates the userland proxy class
//! (`Ignis\Offload\Proxy\<Name>`, a subclass of the real class) instead.

use std::collections::HashMap;
use std::ffi::c_char;
use std::sync::atomic::{AtomicBool, Ordering};
use std::sync::{Mutex, OnceLock};

use ignis_sys as sys;

use super::zval;

type Handler = unsafe extern "C" fn(*mut sys::zend_execute_data, *mut sys::zval);
type CreateObject = unsafe extern "C" fn(*mut sys::zend_class_entry) -> *mut sys::zend_object;

static ENABLED: AtomicBool = AtomicBool::new(false);
static ORIG_FN: OnceLock<Mutex<HashMap<String, Handler>>> = OnceLock::new();
static ORIG_CREATE: OnceLock<Mutex<HashMap<usize, (Option<CreateObject>, String)>>> = OnceLock::new();

thread_local! {
    /// Set by `ignis_route_pass()` from the router: "run the original handler for this call".
    static PASS: std::cell::Cell<bool> = const { std::cell::Cell::new(false) };
}

/// Empty by default since 2026-09-17 (owner decision, V-59): `curl_*` used to be here, from before
/// universal park existed. Park is better on every axis for curl — measured at 100 x 200 ms on one
/// thread: **328 ms parked against 2,697 ms through an 8-worker offload pool** (and 20,346 ms with
/// neither), and `CURLOPT_WRITEFUNCTION` stays in the calling fiber instead of running on a worker
/// (`same_fiber=yes` against `no`). Offload costs a thread per concurrent call and copies the
/// arguments; park costs a readiness wait. Restore the old behaviour with
/// `IGNIS_OFFLOAD_FUNCTIONS=curl_init,curl_exec,...` if a build ever has a libcurl that must not
/// park (research 27 cleared this one).
const DEFAULT_FUNCTIONS: &str = "";
/// `SQLite3` only, since 2026-09-17 (V-59 addendum). `PDO` used to be here as well, and that sent
/// **every** driver to a worker — including `pgsql` and `mysql`, which talk over a socket and park
/// perfectly well: measured at 100 × 200 ms on one thread, `pdo_pgsql` is **303 ms parked against
/// 2,753 ms through an 8-worker pool**. The routing is by class name and the driver is in the DSN,
/// which `create_object` cannot see (the VM calls it before the constructor's arguments exist), so
/// the honest default is to route only the class that is always file-backed.
///
/// A `pdo_sqlite` user therefore blocks the thread for the length of a local file read — the same
/// rule that already governs `file_get_contents`, opcache and sessions (ADR-0024: a regular file is
/// not epoll-able and no mechanism here makes it asynchronous). Set
/// `IGNIS_OFFLOAD_CLASSES=PDO,SQLite3` to get the old behaviour back for it.
const DEFAULT_CLASSES: &str = "SQLite3";

unsafe fn cg() -> *mut sys::zend_compiler_globals {
    unsafe { (sys::tsrm_get_ls_cache() as *mut u8).add(sys::compiler_globals_offset) as *mut sys::zend_compiler_globals }
}
unsafe fn eg() -> *mut sys::zend_executor_globals {
    unsafe { (sys::tsrm_get_ls_cache() as *mut u8).add(sys::executor_globals_offset) as *mut sys::zend_executor_globals }
}

fn routing_here() -> bool {
    // Inside a fiber on a thread with a reactor (never on offload workers: they have no reactor).
    ENABLED.load(Ordering::Relaxed) && unsafe { !(*eg()).active_fiber.is_null() } && super::module::try_reactor().is_some()
}

/// MINIT (main thread): swap handlers and create_object for the configured names.
pub unsafe fn install() {
    if std::env::var_os("IGNIS_NO_OFFLOAD_ROUTE").is_some() {
        return;
    }
    let functions = std::env::var("IGNIS_OFFLOAD_FUNCTIONS").unwrap_or_else(|_| DEFAULT_FUNCTIONS.to_string());
    let classes = std::env::var("IGNIS_OFFLOAD_CLASSES").unwrap_or_else(|_| DEFAULT_CLASSES.to_string());
    let fns = ORIG_FN.get_or_init(|| Mutex::new(HashMap::new()));
    let creates = ORIG_CREATE.get_or_init(|| Mutex::new(HashMap::new()));
    unsafe {
        for name in functions.split(',').map(str::trim).filter(|s| !s.is_empty()) {
            let zv = sys::zend_hash_str_find((*cg()).function_table, name.as_ptr() as *const c_char, name.len());
            if zv.is_null() {
                continue; // extension not built: nothing to route
            }
            let func = (*zv).value.func;
            if let Some(orig) = (*func).internal_function.handler {
                fns.lock().unwrap().insert(name.to_string(), orig);
                (*func).internal_function.handler = Some(trampoline);
            }
        }
        for name in classes.split(',').map(str::trim).filter(|s| !s.is_empty()) {
            let lc = name.to_ascii_lowercase();
            let zv = sys::zend_hash_str_find((*cg()).class_table, lc.as_ptr() as *const c_char, lc.len());
            if zv.is_null() {
                continue;
            }
            let ce = (*zv).value.ce;
            creates.lock().unwrap().insert(ce as usize, ((*ce).__bindgen_anon_2.create_object, name.to_string()));
            (*ce).__bindgen_anon_2.create_object = Some(create_proxy);
        }
    }
}

/// `ignis_route_enable(bool)`: the PHP router is loaded and ready.
pub fn set_enabled(on: bool) {
    ENABLED.store(on, Ordering::Relaxed);
}

/// `ignis_route_pass()`: the router declines this call; run the original.
pub fn pass() {
    PASS.with(|p| p.set(true));
}

/// A fresh (non-interned, refcounted) string zval owned by the caller.
pub(super) unsafe fn string_zval(bytes: &[u8]) -> sys::zval {
    // No zend_string_init binding (static inline): build it through a temporary array element.
    unsafe {
        let mut tmp: sys::zval = std::mem::zeroed();
        zval::set_new_array(&mut tmp);
        sys::add_index_stringl(&mut tmp, 0, bytes.as_ptr() as *const c_char, bytes.len());
        let el = sys::zend_hash_index_find(tmp.value.arr, 0);
        let out = *el;
        (*el).u1.type_info = sys::IS_NULL;
        sys::zval_ptr_dtor(&mut tmp);
        out
    }
}

unsafe fn frame_name(ex: *mut sys::zend_execute_data) -> String {
    unsafe {
        let f = (*ex).func;
        let zs = (*f).common.function_name;
        if zs.is_null() { String::new() } else { zval::zstr_to_string(zs) }
    }
}

unsafe fn call_original(name: &str, ex: *mut sys::zend_execute_data, rv: *mut sys::zval) {
    let orig = ORIG_FN.get().and_then(|m| m.lock().unwrap().get(name).copied());
    match orig {
        Some(h) => unsafe { h(ex, rv) },
        None => unsafe { zval::set_null(rv) },
    }
}

/// Generic handler for every routed function.
unsafe extern "C" fn trampoline(ex: *mut sys::zend_execute_data, rv: *mut sys::zval) {
    // SAFETY: VM frame; argument zvals are read with an added reference and released after the call.
    unsafe {
        let name = frame_name(ex);
        if !routing_here() {
            call_original(&name, ex, rv);
            return;
        }
        // Build [name, [args...]] and call Ignis\Offload\Router::dispatch.
        let n = (*ex).This.u2.num_args as usize;
        let slot = std::mem::size_of::<sys::zend_execute_data>() / std::mem::size_of::<sys::zval>();
        let mut args: sys::zval = std::mem::zeroed();
        zval::set_new_array(&mut args);
        for i in 0..n {
            let src = (ex as *mut sys::zval).add(slot + i);
            let mut copy: sys::zval = *src;
            sys::zval_add_ref(&mut copy);
            sys::zend_hash_next_index_insert(args.value.arr, &mut copy);
        }
        let mut params: [sys::zval; 2] = std::mem::zeroed();
        params[0] = string_zval(name.as_bytes());
        params[1] = args;
        let mut fname = string_zval(b"Ignis\\Offload\\Router::dispatch");
        PASS.with(|p| p.set(false));
        let mut ret: sys::zval = std::mem::zeroed();
        let rc = sys::_call_user_function_impl(std::ptr::null_mut(), &mut fname, &mut ret, 2, params.as_mut_ptr(), std::ptr::null_mut());
        sys::zval_ptr_dtor(&mut fname);
        sys::zval_ptr_dtor(&mut params[0]);
        sys::zval_ptr_dtor(&mut params[1]);
        if PASS.with(|p| p.replace(false)) {
            sys::zval_ptr_dtor(&mut ret);
            call_original(&name, ex, rv);
            return;
        }
        if rc != sys::SUCCESS {
            zval::set_null(rv);
            return;
        }
        *rv = ret; // ownership moves to the return value
    }
}

/// create_object for routed classes: inside a fiber, instantiate `Ignis\Offload\Proxy\<Name>`.
unsafe extern "C" fn create_proxy(class_type: *mut sys::zend_class_entry) -> *mut sys::zend_object {
    // SAFETY: called by the VM's NEW; falls back to the saved original outside fibers.
    unsafe {
        let saved = ORIG_CREATE.get().and_then(|m| m.lock().unwrap().get(&(class_type as usize)).cloned());
        let Some((orig, name)) = saved else {
            // A userland subclass inherited this hook from the routed parent (the proxy itself, or
            // any user subclass): plain object creation, no internal payload.
            let obj = sys::zend_objects_new(class_type);
            // Not the parent's handlers: their free_obj expects the internal payload (sqlite3, pdo).
            (*obj).handlers = &raw const sys::std_object_handlers;
            sys::object_properties_init(obj, class_type);
            return obj;
        };
        if routing_here() && !name.is_empty() {
            let proxy_name = format!("Ignis\\Offload\\Proxy\\{name}");
            let mut pn = string_zval(proxy_name.as_bytes());
            let ce = sys::zend_lookup_class(pn.value.str_);
            sys::zval_ptr_dtor(&mut pn);
            if !ce.is_null() && ce != class_type {
                // The proxy is a userland subclass: standard object creation for it.
                let mut obj: sys::zval = std::mem::zeroed();
                if sys::object_init_ex(&mut obj, ce) == sys::SUCCESS {
                    return obj.value.obj;
                }
            }
        }
        match orig {
            Some(f) => f(class_type),
            None => sys::zend_objects_new(class_type),
        }
    }
}
