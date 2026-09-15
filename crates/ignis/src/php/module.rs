//! The `ignis` internal PHP module: registers the C-level primitives the
//! userland scheduler (php/ignis.php) is built on.
//!
//! Functions exposed to PHP (all thread-affine, all cheap):
//! - `ignis_submit_sleep(int $ms): int`  → op id
//! - `ignis_poll(int $timeout_ms): array<int, int|array>` → id => payload;
//!   int payload = timer (late µs), array payload = HTTP request; -1 blocks
//! - `ignis_inflight(): int`
//! - `ignis_serve(string $addr): bool` → start listening (hyper on tokio)
//! - `ignis_respond(int $id, int $status, array $headers, string $body): bool`
//!
//! FFI contract: see `ignis_sys` crate docs. Every static here lives for the
//! process lifetime, which is what `zend_module_entry` requires.
use std::ffi::{CStr, c_char, c_int};
use std::ptr;
use std::sync::{Arc, OnceLock};
use std::time::Duration;

use ignis_sys as sys;

use super::zval;
use crate::reactor::{Completion, HttpResponse, Op, Outcome, Reactor};

/// Process-wide handles for the (single, in Cycle 1) PHP thread.
static REACTOR: OnceLock<Arc<Reactor>> = OnceLock::new();
static RUNTIME: OnceLock<tokio::runtime::Handle> = OnceLock::new();

pub fn install(r: Arc<Reactor>, rt: tokio::runtime::Handle) {
    REACTOR.set(r).ok().expect("reactor installed twice");
    RUNTIME.set(rt).ok().expect("runtime installed twice");
}

fn reactor() -> &'static Arc<Reactor> {
    REACTOR.get().expect("reactor not installed before PHP started")
}

/// Wrapper so a struct holding raw pointers can be a `static`. The pointees
/// are `'static` string literals and other statics; nothing is ever mutated
/// after process start, so sharing across threads is sound.
#[repr(transparent)]
struct SyncStatic<T>(T);
unsafe impl<T> Sync for SyncStatic<T> {}

const fn arg_info(name: &'static CStr) -> sys::zend_internal_arg_info {
    sys::zend_internal_arg_info {
        name: name.as_ptr(),
        type_: sys::zend_type { ptr: ptr::null_mut(), type_mask: 0 },
        default_value: ptr::null(),
    }
}

/// First entry of an arg-info array encodes `required_num_args` in `name`
/// (Zend's `ZEND_BEGIN_ARG_INFO_EX` does exactly this cast).
const fn arg_info_head(required: usize) -> sys::zend_internal_arg_info {
    sys::zend_internal_arg_info {
        name: required as *const c_char,
        type_: sys::zend_type { ptr: ptr::null_mut(), type_mask: 0 },
        default_value: ptr::null(),
    }
}

static ARGINFO_ONE: SyncStatic<[sys::zend_internal_arg_info; 2]> = SyncStatic([arg_info_head(1), arg_info(c"value")]);
static ARGINFO_NONE: SyncStatic<[sys::zend_internal_arg_info; 1]> = SyncStatic([arg_info_head(0)]);
static ARGINFO_RESPOND: SyncStatic<[sys::zend_internal_arg_info; 5]> = SyncStatic([
    arg_info_head(4),
    arg_info(c"id"),
    arg_info(c"status"),
    arg_info(c"headers"),
    arg_info(c"body"),
]);

unsafe extern "C" fn zif_ignis_submit_sleep(ex: *mut sys::zend_execute_data, rv: *mut sys::zval) {
    // SAFETY: called by the Zend VM on a PHP thread with a valid frame.
    unsafe {
        if zval::num_args(ex) != 1 {
            sys::zend_argument_count_error(c"ignis_submit_sleep() expects exactly 1 argument".as_ptr());
            return;
        }
        let Some(ms) = zval::arg_long(ex, 1) else {
            sys::zend_type_error(c"ignis_submit_sleep(): argument #1 ($ms) must be of type int".as_ptr());
            return;
        };
        let id = reactor().submit(Op::Sleep { ms: ms.max(0) as u64 });
        zval::set_long(rv, id as i64);
    }
}

/// Builds the PHP array for one HTTP request event:
/// `['method' => .., 'uri' => .., 'headers' => [name => value], 'body' => ..]`.
///
/// # Safety
/// Must run on the PHP thread inside a request; `out` receives ownership.
unsafe fn request_to_zval(out: *mut sys::zval, req: &crate::reactor::HttpRequest) {
    unsafe {
        zval::set_new_array(out);
        sys::add_assoc_stringl_ex(out, c"method".as_ptr(), 6, req.method.as_ptr() as *const c_char, req.method.len());
        sys::add_assoc_stringl_ex(out, c"uri".as_ptr(), 3, req.uri.as_ptr() as *const c_char, req.uri.len());
        let mut headers: sys::zval = std::mem::zeroed();
        zval::set_new_array(&mut headers);
        for (k, v) in &req.headers {
            sys::add_assoc_stringl_ex(&mut headers, k.as_ptr() as *const c_char, k.len(), v.as_ptr() as *const c_char, v.len());
        }
        // add_assoc_zval_ex moves ownership of `headers` into `out`.
        sys::add_assoc_zval_ex(out, c"headers".as_ptr(), 7, &mut headers);
        sys::add_assoc_stringl_ex(out, c"body".as_ptr(), 4, req.body.as_ptr() as *const c_char, req.body.len());
    }
}

unsafe extern "C" fn zif_ignis_poll(ex: *mut sys::zend_execute_data, rv: *mut sys::zval) {
    // SAFETY: as above. The array we return is emalloc'd and owned by the
    // returned zval; the VM frees it.
    unsafe {
        if zval::num_args(ex) != 1 {
            sys::zend_argument_count_error(c"ignis_poll() expects exactly 1 argument".as_ptr());
            return;
        }
        let Some(timeout_ms) = zval::arg_long(ex, 1) else {
            sys::zend_type_error(c"ignis_poll(): argument #1 ($timeout_ms) must be of type int".as_ptr());
            return;
        };
        let timeout = if timeout_ms < 0 { None } else { Some(Duration::from_millis(timeout_ms as u64)) };
        let done: Vec<Completion> = reactor().poll(timeout);
        zval::set_new_array(rv);
        for c in done {
            match c.outcome {
                Outcome::Slept { late_us } => sys::add_index_long(rv, c.id, late_us as i64),
                Outcome::Request(req) => {
                    let mut item: sys::zval = std::mem::zeroed();
                    request_to_zval(&mut item, &req);
                    // add_index_zval is a static inline in 8.5; its body is
                    // zend_hash_index_update(Z_ARRVAL_P(arg), index, value), which
                    // copies the zval bits and takes ownership of `item`.
                    sys::zend_hash_index_update((*rv).value.arr, c.id, &mut item);
                }
            }
        }
    }
}

unsafe extern "C" fn zif_ignis_inflight(_ex: *mut sys::zend_execute_data, rv: *mut sys::zval) {
    // SAFETY: rv is VM-owned writable storage.
    unsafe { zval::set_long(rv, reactor().inflight() as i64) }
}

unsafe extern "C" fn zif_ignis_serve(ex: *mut sys::zend_execute_data, rv: *mut sys::zval) {
    // SAFETY: zend_parse_parameters is the documented way to read args; the
    // char* it yields is VM-owned and valid for the duration of this call.
    unsafe {
        let mut s: *mut c_char = ptr::null_mut();
        let mut len: usize = 0;
        if sys::zend_parse_parameters(zval::num_args(ex), c"s".as_ptr(), &mut s, &mut len) != sys::SUCCESS {
            return; // zend_parse_parameters already threw
        }
        let addr = String::from_utf8_lossy(std::slice::from_raw_parts(s as *const u8, len)).into_owned();
        let rt = RUNTIME.get().expect("runtime not installed");
        match crate::http::start(rt, reactor().clone(), &addr) {
            Ok(local) => {
                tracing::info!(%local, "listening");
                zval::set_bool(rv, true);
            }
            Err(e) => {
                let msg = std::ffi::CString::new(format!("ignis_serve: {e:#}")).unwrap_or_default();
                sys::zend_throw_exception(ptr::null_mut(), msg.as_ptr(), 0);
            }
        }
    }
}

unsafe extern "C" fn zif_ignis_respond(ex: *mut sys::zend_execute_data, rv: *mut sys::zval) {
    // SAFETY: args are VM-owned for the call; we copy everything into owned
    // Rust data before returning. Hash iteration uses only ZEND_API functions.
    unsafe {
        let mut id: sys::zend_long = 0;
        let mut status: sys::zend_long = 0;
        let mut ht: *mut sys::HashTable = ptr::null_mut();
        let mut body: *mut c_char = ptr::null_mut();
        let mut body_len: usize = 0;
        if sys::zend_parse_parameters(zval::num_args(ex), c"llhs".as_ptr(), &mut id, &mut status, &mut ht, &mut body, &mut body_len)
            != sys::SUCCESS
        {
            return;
        }
        let mut headers = Vec::new();
        let mut pos: sys::HashPosition = 0;
        sys::zend_hash_internal_pointer_reset_ex(ht, &mut pos);
        loop {
            let v = sys::zend_hash_get_current_data_ex(ht, &pos);
            if v.is_null() {
                break;
            }
            let mut skey: *mut sys::zend_string = ptr::null_mut();
            let mut nkey: sys::zend_ulong = 0;
            let kt = sys::zend_hash_get_current_key_ex(ht, &mut skey, &mut nkey, &pos);
            if kt == sys::HASH_KEY_IS_STRING && zval::type_of(v) == sys::IS_STRING {
                let k = zval::zstr_to_string(skey);
                let val = zval::zstr_to_string((*v).value.str_);
                headers.push((k, val));
            } else {
                tracing::warn!("ignis_respond: header entries must be string => string; skipped one");
            }
            sys::zend_hash_move_forward_ex(ht, &mut pos);
        }
        let body = bytes::Bytes::copy_from_slice(std::slice::from_raw_parts(body as *const u8, body_len));
        let ok = reactor().respond(id as u64, HttpResponse { status: status.clamp(100, 599) as u16, headers, body });
        zval::set_bool(rv, ok);
    }
}

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
        frameless_function_infos: ptr::null(),
        doc_comment: ptr::null(),
    }
}

const fn fe_end() -> sys::zend_function_entry {
    sys::zend_function_entry {
        fname: ptr::null(),
        handler: None,
        arg_info: ptr::null(),
        num_args: 0,
        flags: 0,
        frameless_function_infos: ptr::null(),
        doc_comment: ptr::null(),
    }
}

static FUNCTIONS: SyncStatic<[sys::zend_function_entry; 6]> = SyncStatic([
    fe(c"ignis_submit_sleep", zif_ignis_submit_sleep, ARGINFO_ONE.0.as_ptr(), 1),
    fe(c"ignis_poll", zif_ignis_poll, ARGINFO_ONE.0.as_ptr(), 1),
    fe(c"ignis_inflight", zif_ignis_inflight, ARGINFO_NONE.0.as_ptr(), 0),
    fe(c"ignis_serve", zif_ignis_serve, ARGINFO_ONE.0.as_ptr(), 1),
    fe(c"ignis_respond", zif_ignis_respond, ARGINFO_RESPOND.0.as_ptr(), 4),
    fe_end(),
]);

/// `zend_module_entry` for the ignis module. Mutable because Zend writes
/// `module_started`, `module_number`, `handle` into it at registration.
pub static mut MODULE: sys::zend_module_entry = sys::zend_module_entry {
    size: sys::IGNIS_SIZEOF_ZEND_MODULE_ENTRY as u16,
    zend_api: sys::IGNIS_ZEND_MODULE_API_NO,
    zend_debug: sys::IGNIS_ZEND_DEBUG,
    zts: sys::IGNIS_USING_ZTS,
    ini_entry: ptr::null(),
    deps: ptr::null(),
    name: c"ignis".as_ptr(),
    functions: FUNCTIONS.0.as_ptr(),
    module_startup_func: None,
    module_shutdown_func: None,
    request_startup_func: None,
    request_shutdown_func: None,
    info_func: None,
    version: c"0.0.1".as_ptr(),
    globals_size: 0,
    globals_id_ptr: ptr::null_mut(),
    globals_ctor: None,
    globals_dtor: None,
    post_deactivate_func: None,
    module_started: 0,
    type_: 0,
    handle: ptr::null_mut(),
    module_number: 0,
    build_id: sys::IGNIS_ZEND_MODULE_BUILD_ID.as_ptr() as *const c_char,
};

/// Replacement for `php_embed_module.startup`: identical to the stock one
/// except it registers our module as `additional_module`.
pub unsafe extern "C" fn ignis_sapi_startup(sapi: *mut sys::sapi_module_struct) -> c_int {
    // SAFETY: called once by php_embed_init on the main PHP thread; MODULE is
    // a process-lifetime static and Zend keeps a pointer to it, which is the
    // documented contract for internal modules.
    unsafe { sys::php_module_startup(sapi, &raw mut MODULE) as c_int }
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn module_entry_matches_header_constants() {
        // SAFETY: read-only access to a static in a single test thread.
        let m = unsafe { &*(&raw const MODULE) };
        assert_eq!(m.size as u64, std::mem::size_of::<sys::zend_module_entry>() as u64);
        assert_eq!(m.zts, 1, "must be built against a ZTS PHP");
        let bid = unsafe { CStr::from_ptr(m.build_id) }.to_str().unwrap();
        assert!(bid.ends_with(",TS"), "build id {bid} is not a TS build");
        assert!(bid.starts_with(&format!("API{}", m.zend_api)));
    }

    #[test]
    fn function_table_is_terminated() {
        let last = &FUNCTIONS.0[FUNCTIONS.0.len() - 1];
        assert!(last.fname.is_null() && last.handler.is_none());
        assert_eq!(unsafe { CStr::from_ptr(FUNCTIONS.0[0].fname) }.to_str().unwrap(), "ignis_submit_sleep");
        assert_eq!(FUNCTIONS.0[4].num_args, 4);
    }
}
