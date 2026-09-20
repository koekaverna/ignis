//! The `ignis` internal PHP module: registers the C-level primitives the
//! userland scheduler (php/packages/runtime/src/ignis.php) is built on.
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

/// One reactor per PHP OS thread (ADR-0004); set by the worker before it runs
/// any PHP. The runtime handle is process-wide.
pub(crate) static RUNTIME: OnceLock<tokio::runtime::Handle> = OnceLock::new();
thread_local! {
    static REACTOR: std::cell::OnceCell<Arc<Reactor>> = const { std::cell::OnceCell::new() };
}

pub fn install_runtime(rt: tokio::runtime::Handle) {
    RUNTIME.set(rt).expect("runtime installed twice");
}

/// Binds `r` to the calling OS thread. Must precede any PHP execution on it.
pub fn install_thread_reactor(r: Arc<Reactor>) {
    REACTOR.with(|c| c.set(r).ok().expect("reactor installed twice on this thread"));
}

/// The calling PHP thread's reactor. Cloning the Arc is a refcount bump; the
/// handle is cheap and the thread-local keeps it alive for the thread's life.
pub fn reactor() -> Arc<Reactor> {
    REACTOR.with(|c| c.get().expect("reactor not installed on this PHP thread").clone())
}

/// Same, without the panic: `None` on a thread that never installed a reactor (function hooks, E15c).
pub fn try_reactor() -> Option<Arc<Reactor>> {
    REACTOR.with(|c| c.get().cloned())
}

/// The calling thread's reactor, or a thrown PHP `Error` and `None`.
///
/// Offload workers have no reactor on purpose (ADR-0016), and every runtime function is registered
/// on every thread, so an offloaded job that called one of these reached `reactor()`'s `expect`.
/// A panic in a `zif_` frame is not a failed job: `extern "C"` cannot unwind, so the process aborted
/// with SIGABRT. Measured 2026-09-18 with `Ignis\offload('ignis_inflight')` — exit 134, whole server
/// gone. Refusing in PHP makes it what it always should have been: this job throws, the pool and the
/// server carry on.
///
/// # Safety
/// PHP thread inside a `zif_` frame, where a thrown error is picked up on return.
unsafe fn reactor_or_throw() -> Option<Arc<Reactor>> {
    if let Some(reactor) = try_reactor() {
        return Some(reactor);
    }
    // SAFETY: the caller upholds `# Safety` above -- a VM frame on a PHP thread, which is where
    // zend_throw_error is defined to be called; the message is a 'static NUL-terminated literal.
    unsafe {
        sys::zend_throw_error(
            ptr::null_mut(),
            c"this Ignis runtime function needs a reactor; it is not available on an offload worker thread".as_ptr(),
        );
    }
    None
}

/// Wrapper so a struct holding raw pointers can be a `static`. The pointees
/// are `'static` string literals and other statics; nothing is ever mutated
/// after process start, so sharing across threads is sound.
#[repr(transparent)]
struct SyncStatic<T>(T);
// SAFETY: stated by the type's own doc above -- every T stored in one of these is built from
// 'static literals and other statics and is never mutated after process start, so no thread can
// observe a torn or freed value.
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

/// One arg-info per function, with that function's own parameter names.
///
/// They used to be shared by arity — one `ARGINFO_ONE` whose parameter was called `$value`, one
/// `ARGINFO_GRPC3` borrowed by `ignis_offload_submit`, `ARGINFO_RESPOND` borrowed by
/// `ignis_respond_chunk` and `ignis_stream_bind`. Three things were wrong with that. Named arguments
/// did not work, because the names were another function's. `ReflectionFunction` reported those
/// names as fact. And the borrowed heads carried the lender's `required_num_args`, so
/// `ignis_stream_bind` declared three parameters while telling the engine four were required, and
/// `ignis_offload_submit` marked an optional `$affinity` required.
///
/// The cost of sharing was never the bytes; it was that nothing tied a table to the function it
/// described. These are one per function and named after it.
macro_rules! arginfo {
    ($name:ident, $required:expr, $($arg:expr),+ $(,)?) => {
        static $name: SyncStatic<[sys::zend_internal_arg_info; 1 + [$($arg),+].len()]> =
            SyncStatic([arg_info_head($required), $(arg_info($arg)),+]);
    };
}

arginfo!(ARGINFO_SUBMIT_SLEEP, 1, c"ms");
arginfo!(ARGINFO_POLL, 1, c"timeout_ms");
arginfo!(ARGINFO_PUBLISH_STATS, 1, c"stats");
arginfo!(ARGINFO_SERVE, 1, c"addr");
arginfo!(ARGINFO_CANCEL_OP, 1, c"op");
arginfo!(ARGINFO_WATCH_FILES, 1, c"files");
arginfo!(ARGINFO_RESPOND_END, 1, c"id");
arginfo!(ARGINFO_GRPC_RECV, 1, c"stream");
arginfo!(ARGINFO_ROUTE_ENABLE, 1, c"on");
arginfo!(ARGINFO_STREAM_WRITE, 1, c"bytes");
arginfo!(ARGINFO_RESPOND_CHUNK, 2, c"id", c"bytes");
arginfo!(ARGINFO_STREAM_BIND, 3, c"id", c"status", c"headers");
arginfo!(ARGINFO_OFFLOAD_SUBMIT, 2, c"fn", c"serializedArgs", c"affinity");
arginfo!(ARGINFO_OFFLOAD_DONE, 2, c"job", c"serializedResult");
arginfo!(ARGINFO_OFFLOAD_CALLBACK, 3, c"job", c"cb", c"serializedArgs");
arginfo!(ARGINFO_OFFLOAD_CB_RESULT, 3, c"job", c"seq", c"serializedResult");
arginfo!(ARGINFO_GRPC_SEND, 2, c"id", c"message");
arginfo!(ARGINFO_GRPC_END, 3, c"id", c"code", c"message");
#[cfg(feature = "temporal")]
arginfo!(ARGINFO_TEMPORAL_WORKER, 1, c"worker");
#[cfg(feature = "temporal")]
arginfo!(ARGINFO_TEMPORAL_CONNECT, 3, c"url", c"namespace", c"taskQueue");
#[cfg(feature = "temporal")]
arginfo!(ARGINFO_TEMPORAL_REPLAY, 3, c"url", c"workflowId", c"taskQueue");
#[cfg(feature = "temporal")]
arginfo!(ARGINFO_TEMPORAL_COMPLETE, 2, c"worker", c"completionJson");
#[cfg(feature = "temporal")]
arginfo!(ARGINFO_TEMPORAL_HEARTBEAT, 2, c"worker", c"json");
#[cfg(php_async_abi)]
arginfo!(ARGINFO_OP_ID, 1, c"id");
arginfo!(ARGINFO_SCOPE_ALLOCATE, 1, c"class");
arginfo!(ARGINFO_SCOPE_SEAL, 1, c"instance");
static ARGINFO_NONE: SyncStatic<[sys::zend_internal_arg_info; 1]> = SyncStatic([arg_info_head(0)]);
static ARGINFO_SUPERGLOBALS: SyncStatic<[sys::zend_internal_arg_info; 5]> =
    SyncStatic([arg_info_head(4), arg_info(c"server"), arg_info(c"get"), arg_info(c"post"), arg_info(c"cookie")]);
static ARGINFO_CANCEL: SyncStatic<[sys::zend_internal_arg_info; 3]> =
    SyncStatic([arg_info_head(2), arg_info(c"fiber"), arg_info(c"exception")]);
static ARGINFO_GRPC4: SyncStatic<[sys::zend_internal_arg_info; 5]> =
    SyncStatic([arg_info_head(4), arg_info(c"url"), arg_info(c"path"), arg_info(c"message"), arg_info(c"streaming")]);
static ARGINFO_WATCH: SyncStatic<[sys::zend_internal_arg_info; 3]> = SyncStatic([arg_info_head(2), arg_info(c"stream"), arg_info(c"mode")]);
static ARGINFO_RESPOND: SyncStatic<[sys::zend_internal_arg_info; 5]> =
    SyncStatic([arg_info_head(4), arg_info(c"id"), arg_info(c"status"), arg_info(c"headers"), arg_info(c"body")]);

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
        let Some(reactor) = reactor_or_throw() else { return };
        let id = reactor.submit(Op::Sleep { us: (ms.max(0) as u64).saturating_mul(1000) });
        zval::set_long(rv, id as i64);
    }
}

/// Builds the PHP array for one HTTP request event:
/// `['method' => .., 'uri' => .., 'headers' => [name => value], 'body' => ..]`.
///
/// # Safety
/// Must run on the PHP thread inside a request; `out` receives ownership.
unsafe fn request_to_zval(out: *mut sys::zval, req: &crate::reactor::HttpRequest) {
    // SAFETY: the caller upholds `# Safety` above -- PHP thread, inside a request, `out` is VM-owned
    // storage this call may write once. Every string copied in is borrowed from `req`, which outlives
    // the call, and add_assoc_* copies rather than borrows.
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
        let Some(reactor) = reactor_or_throw() else { return };
        let done: Vec<Completion> = reactor.poll(timeout);
        zval::set_new_array(rv);
        for c in done {
            match c.outcome {
                // An error for a fiber parked C-side (universal park, ADR-0020) is consumed here:
                // the fiber is resumed and runs until its next suspension before we continue.
                Outcome::Error(ref msg) => {
                    let msg = msg.clone();
                    if !super::wait::resume_parked(c.id, c.outcome) {
                        tracing::debug!(id = c.id, %msg, "error completion with no parked fiber");
                    }
                }
                // A fiber parked inside the sleep()/usleep() hook (E15c) is resumed here, like stream ops.
                Outcome::Slept { late_us: _ } if super::wait::is_parked(c.id) => {
                    super::wait::resume_parked(c.id, c.outcome);
                }
                Outcome::Slept { late_us } => sys::add_index_long(rv, c.id, late_us as i64),
                // A fiber parked inside a C hook on a readiness/upgrade op (STARTTLS, ADR-0017) resumes here.
                Outcome::Ready if super::wait::is_parked(c.id) => {
                    super::wait::resume_parked(c.id, c.outcome);
                }
                Outcome::Ready => sys::add_index_long(rv, c.id, 1),
                Outcome::Json(json) => sys::add_index_stringl(rv, c.id, json.as_ptr() as *const c_char, json.len()),
                Outcome::Blob(Some(b)) => sys::add_index_stringl(rv, c.id, b.as_ptr() as *const c_char, b.len()),
                Outcome::Blob(None) => sys::add_index_null(rv, c.id),
                Outcome::OffloadCallback { job, seq, cb, args } => {
                    // ['kind' => 'offload_cb', 'job', 'seq', 'cb', 'args' => serialized]
                    let mut item: sys::zval = std::mem::zeroed();
                    zval::set_new_array(&mut item);
                    sys::add_assoc_stringl_ex(&mut item, c"kind".as_ptr(), 4, c"offload_cb".as_ptr(), 10);
                    sys::add_assoc_long_ex(&mut item, c"job".as_ptr(), 3, job as i64);
                    sys::add_assoc_long_ex(&mut item, c"seq".as_ptr(), 3, seq as i64);
                    sys::add_assoc_long_ex(&mut item, c"cb".as_ptr(), 2, cb as i64);
                    sys::add_assoc_stringl_ex(&mut item, c"args".as_ptr(), 4, args.as_ptr() as *const c_char, args.len());
                    sys::zend_hash_index_update((*rv).value.arr, c.id, &mut item);
                }
                Outcome::Failed(msg) => {
                    let mut item: sys::zval = std::mem::zeroed();
                    zval::set_new_array(&mut item);
                    sys::add_assoc_stringl_ex(&mut item, c"kind".as_ptr(), 4, c"error".as_ptr(), 5);
                    sys::add_assoc_stringl_ex(&mut item, c"message".as_ptr(), 7, msg.as_ptr() as *const c_char, msg.len());
                    sys::zend_hash_index_update((*rv).value.arr, c.id, &mut item);
                }
                Outcome::Cancelled { dropped_at } => {
                    // ['kind' => 'cancel', 'age_us' => µs since hyper dropped the request]
                    let mut item: sys::zval = std::mem::zeroed();
                    zval::set_new_array(&mut item);
                    sys::add_assoc_stringl_ex(&mut item, c"kind".as_ptr(), 4, c"cancel".as_ptr(), 6);
                    sys::add_assoc_long_ex(&mut item, c"age_us".as_ptr(), 6, dropped_at.elapsed().as_micros() as i64);
                    sys::zend_hash_index_update((*rv).value.arr, c.id, &mut item);
                }
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

/// `ignis_watch(resource $stream, int $mode): int` — one-shot readiness watch
/// on the stream's fd (mode 1 = readable, 2 = writable). Returns the op id;
/// `ignis_poll` reports it with payload 1 when ready (ADR-0008).
unsafe extern "C" fn zif_ignis_watch(ex: *mut sys::zend_execute_data, rv: *mut sys::zval) {
    // SAFETY: zend_parse_parameters validates the args; the stream resource is
    // VM-owned and only used to extract an fd number during this call.
    unsafe {
        let mut zres: *mut sys::zval = ptr::null_mut();
        let mut mode: sys::zend_long = 1;
        if sys::zend_parse_parameters(zval::num_args(ex), c"rl".as_ptr(), &mut zres, &mut mode) != sys::SUCCESS {
            return;
        }
        let stream = sys::zend_fetch_resource2_ex(zres, c"stream".as_ptr(), sys::php_file_le_stream(), sys::php_file_le_pstream())
            as *mut sys::php_stream;
        if stream.is_null() {
            return; // zend_fetch_resource2_ex threw
        }
        let mut fd: c_int = -1;
        if sys::_php_stream_cast(stream, sys::PHP_STREAM_AS_FD_FOR_SELECT as c_int, &mut fd as *mut c_int as *mut *mut std::ffi::c_void, 0)
            != sys::SUCCESS
            || fd < 0
        {
            sys::zend_throw_exception(ptr::null_mut(), c"ignis_watch(): stream has no selectable file descriptor".as_ptr(), 0);
            return;
        }
        // Already ready? Complete synchronously so a `poll(0)` right after arming sees it
        // (E15b: Revolt's DriverTest expects a writable fd to dispatch in the tick that armed it).
        let Some(reactor) = reactor_or_throw() else { return };
        let mut pfd = libc::pollfd { fd, events: if mode == 2 { libc::POLLOUT } else { libc::POLLIN }, revents: 0 };
        if libc::poll(&mut pfd, 1, 0) > 0 && pfd.revents != 0 {
            let id = reactor.reserve_op();
            reactor.complete(id, Outcome::Ready);
            zval::set_long(rv, id as i64);
            return;
        }
        let id = reactor.submit(Op::Watch { fd, write: mode == 2 });
        zval::set_long(rv, id as i64);
    }
}

/// `ignis_cancel(int $op): int` — cancel a pending `ignis_watch` op; closes the watched dup'd fd (E15b).
unsafe extern "C" fn zif_ignis_cancel(ex: *mut sys::zend_execute_data, rv: *mut sys::zval) {
    // SAFETY: plain integer argument.
    unsafe {
        let mut target: sys::zend_long = 0;
        if sys::zend_parse_parameters(zval::num_args(ex), c"l".as_ptr(), &mut target) != sys::SUCCESS {
            return;
        }
        let Some(reactor) = reactor_or_throw() else { return };
        let id = reactor.submit(Op::CancelWatch { target: target as u64 });
        zval::set_long(rv, id as i64);
    }
}

/// `ignis_stats(): array` — runtime counters: registered threads, threads stalled
/// in PHP for > 1 s, and worker restarts performed by the supervisor (ADR-0012).
unsafe extern "C" fn zif_ignis_stats(_ex: *mut sys::zend_execute_data, rv: *mut sys::zval) {
    // SAFETY: rv is VM-owned writable storage; values are plain integers.
    unsafe {
        let (stalled, threads) = crate::http::stalled_threads(Duration::from_secs(1));
        zval::set_new_array(rv);
        sys::add_assoc_long_ex(rv, c"threads".as_ptr(), 7, threads as i64);
        sys::add_assoc_long_ex(rv, c"stalled".as_ptr(), 7, stalled as i64);
        sys::add_assoc_long_ex(rv, c"restarts".as_ptr(), 8, crate::RESTARTS.load(std::sync::atomic::Ordering::Relaxed) as i64);
    }
}

/// `ignis_publish_stats(array $stats): void` — the PHP loop hands its own counters to the runtime
/// so `/_ignis/metrics` can answer them while PHP is busy (M4-4). Called once per loop turn, next
/// to the `ignis_poll()` that is about to block, so the cost is a handful of relaxed stores.
unsafe extern "C" fn zif_ignis_publish_stats(ex: *mut sys::zend_execute_data, _rv: *mut sys::zval) {
    // SAFETY: VM frame on the PHP thread; the array is borrowed for the duration of the call and
    // only integer values are read out of it.
    unsafe {
        let mut arr: *mut sys::zval = ptr::null_mut();
        if sys::zend_parse_parameters(zval::num_args(ex), c"a".as_ptr(), &mut arr) != sys::SUCCESS {
            return;
        }
        let ht = (*arr).value.arr;
        if ht.is_null() {
            return;
        }
        let Some(reactor) = reactor_or_throw() else { return };
        let published = &reactor.published;
        let mut key: *mut sys::zend_string = ptr::null_mut();
        let mut val: *mut sys::zval;
        let mut pos: sys::HashPosition = 0;
        sys::zend_hash_internal_pointer_reset_ex(ht, &mut pos);
        while {
            val = sys::zend_hash_get_current_data_ex(ht, &pos);
            !val.is_null()
        } {
            let mut idx: sys::zend_ulong = 0;
            if sys::zend_hash_get_current_key_ex(ht, &mut key, &mut idx, &pos) == sys::HASH_KEY_IS_STRING
                && !key.is_null()
                && zval::type_of(val) == sys::IS_LONG
            {
                let name = std::slice::from_raw_parts((*key).val.as_ptr() as *const u8, (*key).len);
                if let Ok(name) = std::str::from_utf8(name) {
                    published.set(name, (*val).value.lval.max(0) as u64);
                }
            }
            sys::zend_hash_move_forward_ex(ht, &mut pos);
        }
        published.stamp();
    }
}

unsafe extern "C" fn zif_ignis_inflight(_ex: *mut sys::zend_execute_data, rv: *mut sys::zval) {
    // SAFETY: rv is VM-owned writable storage, and reactor_or_throw() is called from this zif frame.
    unsafe {
        let Some(reactor) = reactor_or_throw() else { return };
        zval::set_long(rv, reactor.inflight() as i64)
    }
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
        let Some(reactor) = reactor_or_throw() else { return };
        match crate::http::start(rt, reactor, &addr) {
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
        let headers = header_pairs(ht, "ignis_respond");
        let body = bytes::Bytes::copy_from_slice(std::slice::from_raw_parts(body as *const u8, body_len));
        let Some(reactor) = reactor_or_throw() else { return };
        let ok = reactor.respond(
            id as u64,
            HttpResponse { status: status.clamp(100, 599) as u16, headers, body: crate::reactor::ResponseBody::Full(body) },
        );
        zval::set_bool(rv, ok);
    }
}

/// Reads a `string => string` PHP array into header pairs, skipping anything else loudly.
///
/// # Safety
/// `ht` must be a live hash table owned by the VM for the duration of the call.
/// Appends one `(name, value)` pair per element of a list-valued header.
///
/// # Safety
/// `values` must be a live `HashTable` on the PHP thread.
unsafe fn push_each_value(headers: &mut Vec<(String, String)>, name: &str, values: *mut sys::HashTable, who: &str) {
    // SAFETY: hash iteration through ZEND_API only; every value is copied before returning.
    unsafe {
        let mut pos: sys::HashPosition = 0;
        sys::zend_hash_internal_pointer_reset_ex(values, &mut pos);
        loop {
            let element = sys::zend_hash_get_current_data_ex(values, &pos);
            if element.is_null() {
                break;
            }
            if zval::type_of(element) == sys::IS_STRING {
                headers.push((name.to_string(), zval::zstr_to_string((*element).value.str_)));
            } else {
                tracing::warn!("{who}: every value of header {name} must be a string; skipped one");
            }
            sys::zend_hash_move_forward_ex(values, &mut pos);
        }
    }
}

pub(super) unsafe fn header_pairs(ht: *mut sys::HashTable, who: &str) -> Vec<(String, String)> {
    let mut headers = Vec::new();
    // SAFETY: hash iteration through ZEND_API only; every value is copied before returning.
    unsafe {
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
            if kt == sys::HASH_KEY_IS_STRING {
                let name = zval::zstr_to_string(skey);
                match zval::type_of(v) {
                    sys::IS_STRING => headers.push((name, zval::zstr_to_string((*v).value.str_))),
                    // A PHP array cannot hold the same string key twice, so a response with two
                    // Set-Cookie lines has to arrive as a list under one key. The wire is already a
                    // list of pairs, which is why only this side needed to learn about it.
                    sys::IGNIS_IS_ARRAY_EX | sys::IS_ARRAY => push_each_value(&mut headers, &name, (*v).value.arr, who),
                    _ => tracing::warn!("{who}: header {name} must be a string or a list of strings; skipped"),
                }
            } else {
                tracing::warn!("{who}: header entries must be keyed by name; skipped one");
            }
            sys::zend_hash_move_forward_ex(ht, &mut pos);
        }
    }

    headers
}

/// How many chunks may sit between PHP and the socket. Read once: it cannot change, and
/// `Reactor::respond_start` runs per streamed response.
pub(super) fn stream_chunks() -> usize {
    static V: OnceLock<usize> = OnceLock::new();
    *V.get_or_init(|| std::env::var("IGNIS_STREAM_CHUNKS").ok().and_then(|v| v.parse().ok()).unwrap_or(2usize))
}

/// `ignis_respond_chunk(int $id, string $bytes): int` — one frame; returns an op to await.
///
/// The op completes when the runtime has accepted the chunk, which it cannot do while the queue to
/// the socket is full. Awaiting it is therefore the client's back-pressure reaching the handler: the
/// fiber parks, the thread keeps serving, and memory stays at one chunk.
unsafe extern "C" fn zif_ignis_respond_chunk(ex: *mut sys::zend_execute_data, rv: *mut sys::zval) {
    // SAFETY: the string argument is copied before the future is submitted.
    unsafe {
        let mut id: sys::zend_long = 0;
        let mut buf: *mut c_char = ptr::null_mut();
        let mut len: usize = 0;
        if sys::zend_parse_parameters(zval::num_args(ex), c"ls".as_ptr(), &mut id, &mut buf, &mut len) != sys::SUCCESS {
            return;
        }
        let chunk = bytes::Bytes::copy_from_slice(std::slice::from_raw_parts(buf as *const u8, len));
        zval::set_long(rv, send_chunk(id as u64, chunk));
    }
}

/// Sends one frame of a streamed response. Returns `0` if the runtime took it outright, an op id to
/// await if the queue to the socket was full, `-1` if the stream is gone.
///
/// The fast path is the common one: while the client keeps up there is room in the channel, and
/// `try_send` puts the chunk there with no future, no task and no reactor round trip — the round trip
/// costs ~93 µs even unloaded (`reactor.rs`). Ordering is safe because a slow-path write is awaited
/// before the next one starts.
pub(super) fn send_chunk(id: u64, chunk: bytes::Bytes) -> i64 {
    let Some(reactor) = try_reactor() else { return -1 };
    let Some(tx) = reactor.stream_sender(id) else { return -1 };
    let chunk = match tx.try_send(chunk) {
        Ok(()) => return 0,
        Err(tokio::sync::mpsc::error::TrySendError::Full(c)) => c,
        Err(tokio::sync::mpsc::error::TrySendError::Closed(_)) => return -1, // the client hung up
    };
    // Full: now it is worth an op, because awaiting it is exactly the back-pressure.
    reactor.submit(Op::Custom(Box::pin(async move {
        match tx.send(chunk).await {
            Ok(()) => Outcome::Ready,
            // The receiver is gone: the client hung up. The handler sees it and can stop.
            Err(_) => Outcome::Failed("client gone".into()),
        }
    }))) as i64
}

/// `ignis_respond_end(int $id): bool` — no more chunks; the body is complete.
unsafe extern "C" fn zif_ignis_respond_end(ex: *mut sys::zend_execute_data, rv: *mut sys::zval) {
    // SAFETY: one integer argument.
    unsafe {
        let mut id: sys::zend_long = 0;
        if sys::zend_parse_parameters(zval::num_args(ex), c"l".as_ptr(), &mut id) != sys::SUCCESS {
            return;
        }
        let Some(reactor) = reactor_or_throw() else { return };
        zval::set_bool(rv, reactor.respond_end(id as u64));
    }
}

/// `ignis_grpc_send(int $id, string $message): bool` — one response message (E10).
unsafe extern "C" fn zif_ignis_grpc_send(ex: *mut sys::zend_execute_data, rv: *mut sys::zval) {
    // SAFETY: args are VM-owned for the call; the message is copied before returning.
    unsafe {
        let mut id: sys::zend_long = 0;
        let mut msg: *mut c_char = ptr::null_mut();
        let mut len: usize = 0;
        if sys::zend_parse_parameters(zval::num_args(ex), c"ls".as_ptr(), &mut id, &mut msg, &mut len) != sys::SUCCESS {
            return;
        }
        let b = bytes::Bytes::copy_from_slice(std::slice::from_raw_parts(msg as *const u8, len));
        let Some(reactor) = reactor_or_throw() else { return };
        zval::set_bool(rv, reactor.stream_send(id as u64, b));
    }
}

/// `ignis_grpc_end(int $id, int $code, string $message): bool` — finish a response stream (E10).
unsafe extern "C" fn zif_ignis_grpc_end(ex: *mut sys::zend_execute_data, rv: *mut sys::zval) {
    // SAFETY: as above.
    unsafe {
        let mut id: sys::zend_long = 0;
        let mut code: sys::zend_long = 0;
        let mut msg: *mut c_char = ptr::null_mut();
        let mut len: usize = 0;
        if sys::zend_parse_parameters(zval::num_args(ex), c"lls".as_ptr(), &mut id, &mut code, &mut msg, &mut len) != sys::SUCCESS {
            return;
        }
        let m = String::from_utf8_lossy(std::slice::from_raw_parts(msg as *const u8, len)).into_owned();
        let Some(reactor) = reactor_or_throw() else { return };
        zval::set_bool(rv, reactor.stream_end(id as u64, code as i32, m));
    }
}

/// `ignis_grpc_call(string $url, string $path, string $message, bool $streaming): int` — op id (E10 client).
unsafe extern "C" fn zif_ignis_grpc_call(ex: *mut sys::zend_execute_data, rv: *mut sys::zval) {
    // SAFETY: as above; everything is copied into owned Rust data before the future is built.
    unsafe {
        let (mut u, mut ul, mut p, mut pl, mut m, mut ml): (*mut c_char, usize, *mut c_char, usize, *mut c_char, usize) =
            (ptr::null_mut(), 0, ptr::null_mut(), 0, ptr::null_mut(), 0);
        let mut streaming: bool = false;
        if sys::zend_parse_parameters(
            zval::num_args(ex),
            c"sssb".as_ptr(),
            &mut u,
            &mut ul,
            &mut p,
            &mut pl,
            &mut m,
            &mut ml,
            &mut streaming,
        ) != sys::SUCCESS
        {
            return;
        }
        let url = String::from_utf8_lossy(std::slice::from_raw_parts(u as *const u8, ul)).into_owned();
        let path = String::from_utf8_lossy(std::slice::from_raw_parts(p as *const u8, pl)).into_owned();
        let msg = bytes::Bytes::copy_from_slice(std::slice::from_raw_parts(m as *const u8, ml));
        let Some(reactor) = reactor_or_throw() else { return };
        let id = reactor.submit(Op::Custom(crate::grpc::call(url, path, msg, streaming)));
        zval::set_long(rv, id as i64);
    }
}

/// `ignis_grpc_recv(int $stream): int` — op id; payload is the next message or null at the end (E10 client).
unsafe extern "C" fn zif_ignis_grpc_recv(ex: *mut sys::zend_execute_data, rv: *mut sys::zval) {
    // SAFETY: as above.
    unsafe {
        let mut h: sys::zend_long = 0;
        if sys::zend_parse_parameters(zval::num_args(ex), c"l".as_ptr(), &mut h) != sys::SUCCESS {
            return;
        }
        let Some(reactor) = reactor_or_throw() else { return };
        let id = reactor.submit(Op::Custom(crate::grpc::recv(h as u64)));
        zval::set_long(rv, id as i64);
    }
}

/// RINIT (every PHP thread): define `STDIN`/`STDOUT`/`STDERR` like php-cli does
/// (E15a finding: 13 of 15 main-mode phpt failures were these three constants).
unsafe extern "C" fn rinit(_type: c_int, module_number: c_int) -> sys::zend_result {
    // SAFETY: request startup on the calling thread; streams and constants are request-scoped
    // (non-persistent), exactly like sapi/cli's php_cli_register_file_handles().
    unsafe {
        for (name, path, mode) in
            [(c"STDIN", c"php://stdin", c"rb"), (c"STDOUT", c"php://stdout", c"wb"), (c"STDERR", c"php://stderr", c"wb")]
        {
            let stream = sys::_php_stream_open_wrapper_ex(path.as_ptr(), mode.as_ptr(), 0, ptr::null_mut(), ptr::null_mut());
            if stream.is_null() {
                continue;
            }
            // php_stream_to_zval: ZVAL_RES(zv, stream->res); the resource refcount is owned by the constant.
            let mut c: sys::zend_constant = std::mem::zeroed();
            c.value.value.res = (*stream).res;
            c.value.u1.type_info = sys::IS_RESOURCE;
            c.name = sys::zend_strpprintf(0, c"%s".as_ptr(), name.as_ptr());
            // ZEND_CONSTANT_SET_FLAGS(&c, CONST_CS, module_number): flags in u2.constant_flags (low 16 bits = flags, high = module).
            c.value.u2.constant_flags = (module_number as u32) << 16;
            if sys::zend_register_constant(&mut c).is_null() {
                // Already defined (a second RINIT on this thread): drop our stream.
                sys::_php_stream_free(stream, 1 /* PHP_STREAM_FREE_CLOSE */);
            }
        }
    }
    sys::SUCCESS
}

thread_local! {
    /// Index of this offload worker thread (E16), if it is one.
    pub static OFFLOAD_WORKER: std::cell::Cell<Option<usize>> = const { std::cell::Cell::new(None) };
}

/// # Safety
/// `p` must point to `l` readable bytes for the duration of the call.
unsafe fn str_arg(p: *mut c_char, l: usize) -> String {
    // SAFETY: the caller upholds the length contract above; the slice is read and copied before it
    // returns, so the String never borrows VM memory.
    unsafe { String::from_utf8_lossy(std::slice::from_raw_parts(p as *const u8, l)).into_owned() }
}

/// # Safety
/// `p` must point to `l` readable bytes for the duration of the call.
unsafe fn bytes_arg(p: *mut c_char, l: usize) -> bytes::Bytes {
    // SAFETY: as `str_arg` -- copy_from_slice owns the result, so nothing outlives the VM's buffer.
    unsafe { bytes::Bytes::copy_from_slice(std::slice::from_raw_parts(p as *const u8, l)) }
}

/// `ignis_offload_submit(string $fn, string $serializedArgs, int $affinity = -1): int|false` — op id (E16).
unsafe extern "C" fn zif_ignis_offload_submit(ex: *mut sys::zend_execute_data, rv: *mut sys::zval) {
    // SAFETY: VM-owned args copied before returning.
    unsafe {
        let (mut f, mut fl, mut a, mut al): (*mut c_char, usize, *mut c_char, usize) = (ptr::null_mut(), 0, ptr::null_mut(), 0);
        let mut aff: sys::zend_long = -1;
        if sys::zend_parse_parameters(zval::num_args(ex), c"ss|l".as_ptr(), &mut f, &mut fl, &mut a, &mut al, &mut aff) != sys::SUCCESS {
            return;
        }
        let affinity = if aff >= 0 { Some(aff as usize) } else { None };
        let Some(reactor) = reactor_or_throw() else { return };
        match crate::offload::submit(reactor, str_arg(f, fl), bytes_arg(a, al), affinity) {
            Ok(op) => zval::set_long(rv, op as i64),
            Err(e) => {
                tracing::warn!("ignis_offload_submit: {e}");
                zval::set_bool(rv, false);
            }
        }
    }
}

/// `ignis_offload_next(): ?array` — worker thread: blocks for the next job `[id, fn, args]` (E16).
unsafe extern "C" fn zif_ignis_offload_next(_ex: *mut sys::zend_execute_data, rv: *mut sys::zval) {
    // SAFETY: builds a fresh array on the worker thread.
    unsafe {
        let Some(w) = OFFLOAD_WORKER.with(|c| c.get()) else {
            zval::set_null(rv);
            return;
        };
        match crate::offload::next(w) {
            Some(job) => {
                zval::set_new_array(rv);
                sys::add_index_long(rv, 0, job.id as i64);
                sys::add_index_stringl(rv, 1, job.func.as_ptr() as *const c_char, job.func.len());
                sys::add_index_stringl(rv, 2, job.args.as_ptr() as *const c_char, job.args.len());
            }
            None => zval::set_null(rv),
        }
    }
}

/// `ignis_offload_done(int $job, string $serializedResult): bool` (E16, worker thread).
unsafe extern "C" fn zif_ignis_offload_done(ex: *mut sys::zend_execute_data, rv: *mut sys::zval) {
    // SAFETY: as above.
    unsafe {
        let mut id: sys::zend_long = 0;
        let (mut r, mut rl): (*mut c_char, usize) = (ptr::null_mut(), 0);
        if sys::zend_parse_parameters(zval::num_args(ex), c"ls".as_ptr(), &mut id, &mut r, &mut rl) != sys::SUCCESS {
            return;
        }
        zval::set_bool(rv, crate::offload::done(id as u64, bytes_arg(r, rl)));
    }
}

/// `ignis_offload_callback(int $job, int $cb, string $serializedArgs): string|false` (E16, worker thread; blocks).
unsafe extern "C" fn zif_ignis_offload_callback(ex: *mut sys::zend_execute_data, rv: *mut sys::zval) {
    // SAFETY: as above.
    unsafe {
        let (mut job, mut cb): (sys::zend_long, sys::zend_long) = (0, 0);
        let (mut a, mut al): (*mut c_char, usize) = (ptr::null_mut(), 0);
        if sys::zend_parse_parameters(zval::num_args(ex), c"lls".as_ptr(), &mut job, &mut cb, &mut a, &mut al) != sys::SUCCESS {
            return;
        }
        match crate::offload::callback(job as u64, cb as u64, bytes_arg(a, al)) {
            Ok(b) => {
                let mut tmp: sys::zval = std::mem::zeroed();
                zval::set_new_array(&mut tmp);
                sys::add_index_stringl(&mut tmp, 0, b.as_ptr() as *const c_char, b.len());
                // Move element 0 out as the return value (a string zval), then drop the array.
                let el = sys::zend_hash_index_find((tmp).value.arr, 0);
                (*rv).value = (*el).value;
                (*rv).u1 = (*el).u1;
                (*el).u1.type_info = sys::IS_NULL;
                sys::zval_ptr_dtor(&mut tmp);
            }
            Err(e) => {
                tracing::warn!("ignis_offload_callback: {e}");
                zval::set_bool(rv, false);
            }
        }
    }
}

/// `ignis_offload_cb_result(int $job, int $seq, string $serializedResult): bool` (E16, calling thread).
unsafe extern "C" fn zif_ignis_offload_cb_result(ex: *mut sys::zend_execute_data, rv: *mut sys::zval) {
    // SAFETY: as above.
    unsafe {
        let (mut job, mut seq): (sys::zend_long, sys::zend_long) = (0, 0);
        let (mut r, mut rl): (*mut c_char, usize) = (ptr::null_mut(), 0);
        if sys::zend_parse_parameters(zval::num_args(ex), c"lls".as_ptr(), &mut job, &mut seq, &mut r, &mut rl) != sys::SUCCESS {
            return;
        }
        zval::set_bool(rv, crate::offload::callback_result(job as u64, seq as u64, bytes_arg(r, rl)));
    }
}

/// `ignis_scope_allocate(string $class): object` — an instance whose declared slots are `IS_UNDEF`
/// and whose properties resolve per fiber (ADR-0042). The constructor is **not** run here;
/// `Ignis\\Scope::create()` runs it next, so its writes reach the scope's row instead of filling the
/// slots and arming the VM's fast path for every instance of the class.
unsafe extern "C" fn zif_ignis_scope_allocate(ex: *mut sys::zend_execute_data, rv: *mut sys::zval) {
    // SAFETY: zend_parse_parameters is the documented way to read args; the char* it yields is
    // VM-owned and valid for this call. The object `allocate` returns carries the one reference this
    // zval takes ownership of, which is what a zif returning an object owes its caller.
    unsafe {
        let mut class: *mut c_char = ptr::null_mut();
        let mut len: usize = 0;
        if sys::zend_parse_parameters(zval::num_args(ex), c"s".as_ptr(), &mut class, &mut len) != sys::SUCCESS {
            return; // zend_parse_parameters already threw
        }
        let name = String::from_utf8_lossy(std::slice::from_raw_parts(class as *const u8, len)).into_owned();
        match super::scoped::allocate(&name) {
            Ok(object) => {
                (*rv).value.obj = object;
                // IS_OBJECT_EX, not IS_OBJECT: the difference is the type *flags*, and dropping them
                // marks an object zval as neither refcounted nor collectable, so PHP never addrefs
                // it and the object is freed under whoever still holds it. It survived a short
                // script and segfaulted a real Symfony container, which is where it was found.
                (*rv).u1.type_info = sys::IS_OBJECT_EX;
            }
            Err(message) => {
                let message = std::ffi::CString::new(message).unwrap_or_else(|_| c"ignis: scoped allocation failed".into());
                sys::zend_throw_error(ptr::null_mut(), message.as_ptr());
            }
        }
    }
}

/// `ignis_scope_seal(object $instance): void` — the constructor has returned; what it wrote becomes
/// row zero, the values every other scope reads until it writes its own. Explicit, because a service
/// built lazily inside a request would otherwise trap its dependencies in that request's scope.
unsafe extern "C" fn zif_ignis_scope_seal(ex: *mut sys::zend_execute_data, _rv: *mut sys::zval) {
    // SAFETY: zend_parse_parameters is the documented way to read args; the object it yields is
    // borrowed for this call, which is all `seal` needs.
    unsafe {
        // "o" writes a **zval***, not a zend_object*. Passing the latter is a type confusion that
        // reads `handle` out of the zval's own memory: it sealed handle 8 while the object was 9,
        // so the constructor's values went to a row nothing would ever look up.
        let mut instance: *mut sys::zval = ptr::null_mut();
        if sys::zend_parse_parameters(zval::num_args(ex), c"o".as_ptr(), &mut instance) != sys::SUCCESS {
            return; // zend_parse_parameters already threw
        }
        super::scoped::seal((*instance).value.obj);
    }
}

/// `ignis_scope_rows_clear(): void` — drops this fiber's scoped property rows at request end, the
/// same boundary `Ignis\\Scope::clear()` drops its key-value bag at (V-67).
unsafe extern "C" fn zif_ignis_scope_rows_clear(_ex: *mut sys::zend_execute_data, _rv: *mut sys::zval) {
    // SAFETY: a zif frame on a PHP thread, which is what `rows_clear` asks for.
    unsafe { super::scoped::rows_clear() }
}

/// `ignis_offload_stats(): array` — `[workers, busy, done, queued]` (E16).
unsafe extern "C" fn zif_ignis_offload_stats(_ex: *mut sys::zend_execute_data, rv: *mut sys::zval) {
    // SAFETY: fresh array.
    unsafe {
        let (workers, busy, done, queued) = crate::offload::stats();
        zval::set_new_array(rv);
        sys::add_assoc_long_ex(rv, c"workers".as_ptr(), 7, workers as i64);
        sys::add_assoc_long_ex(rv, c"busy".as_ptr(), 4, busy as i64);
        sys::add_assoc_long_ex(rv, c"done".as_ptr(), 4, done as i64);
        sys::add_assoc_long_ex(rv, c"queued".as_ptr(), 6, queued as i64);
        // Index of this thread if it is an offload worker, else -1 (the worker loop needs it for handle refs).
        sys::add_assoc_long_ex(rv, c"this".as_ptr(), 4, OFFLOAD_WORKER.with(|c| c.get()).map(|w| w as i64).unwrap_or(-1));
    }
}

/// `ignis_route_enable(bool $on): void` — the PHP Router is loaded (E16 auto-routing).
unsafe extern "C" fn zif_ignis_route_enable(ex: *mut sys::zend_execute_data, rv: *mut sys::zval) {
    // SAFETY: bool argument.
    unsafe {
        let mut on: bool = true;
        if sys::zend_parse_parameters(zval::num_args(ex), c"b".as_ptr(), &mut on) != sys::SUCCESS {
            return;
        }
        super::route::set_enabled(on);
        zval::set_null(rv);
    }
}

/// `ignis_route_pass(): void` — the Router declines the current call; the original handler runs (E16).
unsafe extern "C" fn zif_ignis_route_pass(_ex: *mut sys::zend_execute_data, rv: *mut sys::zval) {
    super::route::pass();
    // SAFETY: `rv` is the VM's return slot for this internal call, valid and writable once.
    unsafe { zval::set_null(rv) }
}

/// `ignis_watch_files(array $files): int` — add loaded files to the development watcher (research 40);
/// returns how many directories became watched. A list of strings; anything else in it is skipped.
unsafe extern "C" fn zif_ignis_watch_files(ex: *mut sys::zend_execute_data, rv: *mut sys::zval) {
    // SAFETY: the array is VM-owned for the call and every string is copied before returning.
    unsafe {
        let mut ht: *mut sys::HashTable = ptr::null_mut();
        if sys::zend_parse_parameters(zval::num_args(ex), c"h".as_ptr(), &mut ht) != sys::SUCCESS {
            return;
        }
        let mut paths = Vec::new();
        let mut pos: sys::HashPosition = 0;
        sys::zend_hash_internal_pointer_reset_ex(ht, &mut pos);
        loop {
            let v = sys::zend_hash_get_current_data_ex(ht, &pos);
            if v.is_null() {
                break;
            }
            if zval::type_of(v) == sys::IS_STRING {
                paths.push(zval::zstr_to_string((*v).value.str_));
            }
            sys::zend_hash_move_forward_ex(ht, &mut pos);
        }
        zval::set_long(rv, crate::watch::watch(&paths) as i64);
    }
}

/// `ignis_watch_generation(): int` — settled changes seen so far. A worker records it at boot and
/// reloads when it grows; a boolean would be consumed by whichever thread read it first.
unsafe extern "C" fn zif_ignis_watch_generation(_ex: *mut sys::zend_execute_data, rv: *mut sys::zval) {
    // SAFETY: rv is VM-owned writable storage.
    unsafe { zval::set_long(rv, crate::watch::generation() as i64) }
}

/// `ignis_watch_claim_reset(): bool` — true for exactly one caller per settled change. The compiled
/// code cache is process-wide, so one reset serves every thread and N of them would only disturb the
/// siblings still finishing their requests.
unsafe extern "C" fn zif_ignis_watch_claim_reset(_ex: *mut sys::zend_execute_data, rv: *mut sys::zval) {
    // SAFETY: rv is VM-owned writable storage.
    unsafe { zval::set_bool(rv, crate::watch::claim_reset()) }
}

/// `ignis_watch_begin_reload(): bool` — claim the one reload slot, so workers go down one at a time.
unsafe extern "C" fn zif_ignis_watch_begin_reload(_ex: *mut sys::zend_execute_data, rv: *mut sys::zval) {
    // SAFETY: rv is VM-owned writable storage.
    unsafe { zval::set_bool(rv, crate::watch::begin_reload()) }
}

/// `ignis_watch_end_reload(): void` — this worker is up; whoever is waiting to reload may go.
unsafe extern "C" fn zif_ignis_watch_end_reload(_ex: *mut sys::zend_execute_data, rv: *mut sys::zval) {
    crate::watch::end_reload();
    // SAFETY: rv is VM-owned writable storage.
    unsafe { zval::set_null(rv) }
}

/// `ignis_stop_accepting(): void` — take this thread out of HTTP dispatch and keep what it holds.
/// The front door stops sending it requests; the ones already in flight are still its own to finish,
/// which is what separates a reload from a thread that died (`http::unregister`).
unsafe extern "C" fn zif_ignis_stop_accepting(_ex: *mut sys::zend_execute_data, rv: *mut sys::zval) {
    // SAFETY: rv is VM-owned writable storage, and reactor_or_throw() is called from this zif frame.
    unsafe {
        let Some(reactor) = reactor_or_throw() else { return };
        crate::http::leave_dispatch(&reactor);
        zval::set_null(rv)
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

#[cfg(all(not(php_async_abi), not(feature = "temporal")))]
static FUNCTIONS: SyncStatic<[sys::zend_function_entry; 41]> = SyncStatic([
    fe(c"ignis_scope_allocate", zif_ignis_scope_allocate, ARGINFO_SCOPE_ALLOCATE.0.as_ptr(), 1),
    fe(c"ignis_scope_rows_clear", zif_ignis_scope_rows_clear, ARGINFO_NONE.0.as_ptr(), 0),
    fe(c"ignis_scope_seal", zif_ignis_scope_seal, ARGINFO_SCOPE_SEAL.0.as_ptr(), 1),
    fe(c"ignis_stats", zif_ignis_stats, ARGINFO_NONE.0.as_ptr(), 0),
    fe(c"ignis_capture_start", super::output::zif_capture_start, ARGINFO_NONE.0.as_ptr(), 0),
    fe(c"ignis_capture_take", super::output::zif_capture_take, ARGINFO_NONE.0.as_ptr(), 0),
    fe(c"ignis_capture_reset", super::output::zif_capture_reset, ARGINFO_NONE.0.as_ptr(), 0),
    fe(c"ignis_stream_bind", super::output::zif_stream_bind, ARGINFO_STREAM_BIND.0.as_ptr(), 3),
    fe(c"ignis_stream_unbind", super::output::zif_stream_unbind, ARGINFO_NONE.0.as_ptr(), 0),
    fe(c"ignis_stream_write", super::output::zif_stream_write, ARGINFO_STREAM_WRITE.0.as_ptr(), 1),
    fe(c"ignis_cancel_parked_any", super::wait::zif_ignis_cancel_parked_any, ARGINFO_CANCEL.0.as_ptr(), 2),
    fe(c"ignis_watch", zif_ignis_watch, ARGINFO_WATCH.0.as_ptr(), 2),
    fe(c"ignis_cancel", zif_ignis_cancel, ARGINFO_CANCEL_OP.0.as_ptr(), 1),
    fe(c"ignis_set_superglobals", super::superglobals::zif_ignis_set_superglobals, ARGINFO_SUPERGLOBALS.0.as_ptr(), 4),
    fe(c"ignis_submit_sleep", zif_ignis_submit_sleep, ARGINFO_SUBMIT_SLEEP.0.as_ptr(), 1),
    fe(c"ignis_poll", zif_ignis_poll, ARGINFO_POLL.0.as_ptr(), 1),
    fe(c"ignis_inflight", zif_ignis_inflight, ARGINFO_NONE.0.as_ptr(), 0),
    fe(c"ignis_publish_stats", zif_ignis_publish_stats, ARGINFO_PUBLISH_STATS.0.as_ptr(), 1),
    fe(c"ignis_serve", zif_ignis_serve, ARGINFO_SERVE.0.as_ptr(), 1),
    fe(c"ignis_watch_files", zif_ignis_watch_files, ARGINFO_WATCH_FILES.0.as_ptr(), 1),
    fe(c"ignis_watch_generation", zif_ignis_watch_generation, ARGINFO_NONE.0.as_ptr(), 0),
    fe(c"ignis_watch_claim_reset", zif_ignis_watch_claim_reset, ARGINFO_NONE.0.as_ptr(), 0),
    fe(c"ignis_watch_begin_reload", zif_ignis_watch_begin_reload, ARGINFO_NONE.0.as_ptr(), 0),
    fe(c"ignis_watch_end_reload", zif_ignis_watch_end_reload, ARGINFO_NONE.0.as_ptr(), 0),
    fe(c"ignis_stop_accepting", zif_ignis_stop_accepting, ARGINFO_NONE.0.as_ptr(), 0),
    fe(c"ignis_respond", zif_ignis_respond, ARGINFO_RESPOND.0.as_ptr(), 4),
    fe(c"ignis_respond_chunk", zif_ignis_respond_chunk, ARGINFO_RESPOND_CHUNK.0.as_ptr(), 2),
    fe(c"ignis_respond_end", zif_ignis_respond_end, ARGINFO_RESPOND_END.0.as_ptr(), 1),
    fe(c"ignis_grpc_send", zif_ignis_grpc_send, ARGINFO_GRPC_SEND.0.as_ptr(), 2),
    fe(c"ignis_grpc_end", zif_ignis_grpc_end, ARGINFO_GRPC_END.0.as_ptr(), 3),
    fe(c"ignis_grpc_call", zif_ignis_grpc_call, ARGINFO_GRPC4.0.as_ptr(), 4),
    fe(c"ignis_grpc_recv", zif_ignis_grpc_recv, ARGINFO_GRPC_RECV.0.as_ptr(), 1),
    fe(c"ignis_offload_submit", zif_ignis_offload_submit, ARGINFO_OFFLOAD_SUBMIT.0.as_ptr(), 3),
    fe(c"ignis_offload_next", zif_ignis_offload_next, ARGINFO_NONE.0.as_ptr(), 0),
    fe(c"ignis_offload_done", zif_ignis_offload_done, ARGINFO_OFFLOAD_DONE.0.as_ptr(), 2),
    fe(c"ignis_offload_callback", zif_ignis_offload_callback, ARGINFO_OFFLOAD_CALLBACK.0.as_ptr(), 3),
    fe(c"ignis_offload_cb_result", zif_ignis_offload_cb_result, ARGINFO_OFFLOAD_CB_RESULT.0.as_ptr(), 3),
    fe(c"ignis_offload_stats", zif_ignis_offload_stats, ARGINFO_NONE.0.as_ptr(), 0),
    fe(c"ignis_route_enable", zif_ignis_route_enable, ARGINFO_ROUTE_ENABLE.0.as_ptr(), 1),
    fe(c"ignis_route_pass", zif_ignis_route_pass, ARGINFO_NONE.0.as_ptr(), 0),
    fe_end(),
]);
/// Backend (b) adds `ignis_park_on` / `ignis_op_result` (see backend/async_core.rs).
/// With the `temporal` feature (ADR-0013): sdk-core worker primitives.
#[cfg(all(not(php_async_abi), feature = "temporal"))]
static FUNCTIONS: SyncStatic<[sys::zend_function_entry; 49]> = SyncStatic([
    fe(c"ignis_scope_allocate", zif_ignis_scope_allocate, ARGINFO_SCOPE_ALLOCATE.0.as_ptr(), 1),
    fe(c"ignis_scope_rows_clear", zif_ignis_scope_rows_clear, ARGINFO_NONE.0.as_ptr(), 0),
    fe(c"ignis_scope_seal", zif_ignis_scope_seal, ARGINFO_SCOPE_SEAL.0.as_ptr(), 1),
    fe(c"ignis_temporal_connect", crate::backend::temporal::zif_connect, ARGINFO_TEMPORAL_CONNECT.0.as_ptr(), 3),
    fe(c"ignis_temporal_replay", crate::backend::temporal::zif_replay, ARGINFO_TEMPORAL_REPLAY.0.as_ptr(), 3),
    fe(c"ignis_temporal_poll", crate::backend::temporal::zif_poll_activation, ARGINFO_TEMPORAL_WORKER.0.as_ptr(), 1),
    fe(c"ignis_temporal_complete", crate::backend::temporal::zif_complete_activation, ARGINFO_TEMPORAL_COMPLETE.0.as_ptr(), 2),
    fe(c"ignis_temporal_poll_activity", crate::backend::temporal::zif_poll_activity, ARGINFO_TEMPORAL_WORKER.0.as_ptr(), 1),
    fe(c"ignis_temporal_complete_activity", crate::backend::temporal::zif_complete_activity, ARGINFO_TEMPORAL_COMPLETE.0.as_ptr(), 2),
    fe(c"ignis_temporal_heartbeat", crate::backend::temporal::zif_heartbeat, ARGINFO_TEMPORAL_HEARTBEAT.0.as_ptr(), 2),
    fe(c"ignis_temporal_shutdown", crate::backend::temporal::zif_shutdown, ARGINFO_TEMPORAL_WORKER.0.as_ptr(), 1),
    fe(c"ignis_stats", zif_ignis_stats, ARGINFO_NONE.0.as_ptr(), 0),
    fe(c"ignis_capture_start", super::output::zif_capture_start, ARGINFO_NONE.0.as_ptr(), 0),
    fe(c"ignis_capture_take", super::output::zif_capture_take, ARGINFO_NONE.0.as_ptr(), 0),
    fe(c"ignis_capture_reset", super::output::zif_capture_reset, ARGINFO_NONE.0.as_ptr(), 0),
    fe(c"ignis_stream_bind", super::output::zif_stream_bind, ARGINFO_STREAM_BIND.0.as_ptr(), 3),
    fe(c"ignis_stream_unbind", super::output::zif_stream_unbind, ARGINFO_NONE.0.as_ptr(), 0),
    fe(c"ignis_stream_write", super::output::zif_stream_write, ARGINFO_STREAM_WRITE.0.as_ptr(), 1),
    fe(c"ignis_cancel_parked_any", super::wait::zif_ignis_cancel_parked_any, ARGINFO_CANCEL.0.as_ptr(), 2),
    fe(c"ignis_watch", zif_ignis_watch, ARGINFO_WATCH.0.as_ptr(), 2),
    fe(c"ignis_cancel", zif_ignis_cancel, ARGINFO_CANCEL_OP.0.as_ptr(), 1),
    fe(c"ignis_set_superglobals", super::superglobals::zif_ignis_set_superglobals, ARGINFO_SUPERGLOBALS.0.as_ptr(), 4),
    fe(c"ignis_submit_sleep", zif_ignis_submit_sleep, ARGINFO_SUBMIT_SLEEP.0.as_ptr(), 1),
    fe(c"ignis_poll", zif_ignis_poll, ARGINFO_POLL.0.as_ptr(), 1),
    fe(c"ignis_inflight", zif_ignis_inflight, ARGINFO_NONE.0.as_ptr(), 0),
    fe(c"ignis_publish_stats", zif_ignis_publish_stats, ARGINFO_PUBLISH_STATS.0.as_ptr(), 1),
    fe(c"ignis_serve", zif_ignis_serve, ARGINFO_SERVE.0.as_ptr(), 1),
    fe(c"ignis_watch_files", zif_ignis_watch_files, ARGINFO_WATCH_FILES.0.as_ptr(), 1),
    fe(c"ignis_watch_generation", zif_ignis_watch_generation, ARGINFO_NONE.0.as_ptr(), 0),
    fe(c"ignis_watch_claim_reset", zif_ignis_watch_claim_reset, ARGINFO_NONE.0.as_ptr(), 0),
    fe(c"ignis_watch_begin_reload", zif_ignis_watch_begin_reload, ARGINFO_NONE.0.as_ptr(), 0),
    fe(c"ignis_watch_end_reload", zif_ignis_watch_end_reload, ARGINFO_NONE.0.as_ptr(), 0),
    fe(c"ignis_stop_accepting", zif_ignis_stop_accepting, ARGINFO_NONE.0.as_ptr(), 0),
    fe(c"ignis_respond", zif_ignis_respond, ARGINFO_RESPOND.0.as_ptr(), 4),
    fe(c"ignis_respond_chunk", zif_ignis_respond_chunk, ARGINFO_RESPOND_CHUNK.0.as_ptr(), 2),
    fe(c"ignis_respond_end", zif_ignis_respond_end, ARGINFO_RESPOND_END.0.as_ptr(), 1),
    fe(c"ignis_grpc_send", zif_ignis_grpc_send, ARGINFO_GRPC_SEND.0.as_ptr(), 2),
    fe(c"ignis_grpc_end", zif_ignis_grpc_end, ARGINFO_GRPC_END.0.as_ptr(), 3),
    fe(c"ignis_grpc_call", zif_ignis_grpc_call, ARGINFO_GRPC4.0.as_ptr(), 4),
    fe(c"ignis_grpc_recv", zif_ignis_grpc_recv, ARGINFO_GRPC_RECV.0.as_ptr(), 1),
    fe(c"ignis_offload_submit", zif_ignis_offload_submit, ARGINFO_OFFLOAD_SUBMIT.0.as_ptr(), 3),
    fe(c"ignis_offload_next", zif_ignis_offload_next, ARGINFO_NONE.0.as_ptr(), 0),
    fe(c"ignis_offload_done", zif_ignis_offload_done, ARGINFO_OFFLOAD_DONE.0.as_ptr(), 2),
    fe(c"ignis_offload_callback", zif_ignis_offload_callback, ARGINFO_OFFLOAD_CALLBACK.0.as_ptr(), 3),
    fe(c"ignis_offload_cb_result", zif_ignis_offload_cb_result, ARGINFO_OFFLOAD_CB_RESULT.0.as_ptr(), 3),
    fe(c"ignis_offload_stats", zif_ignis_offload_stats, ARGINFO_NONE.0.as_ptr(), 0),
    fe(c"ignis_route_enable", zif_ignis_route_enable, ARGINFO_ROUTE_ENABLE.0.as_ptr(), 1),
    fe(c"ignis_route_pass", zif_ignis_route_pass, ARGINFO_NONE.0.as_ptr(), 0),
    fe_end(),
]);
#[cfg(php_async_abi)]
static FUNCTIONS: SyncStatic<[sys::zend_function_entry; 43]> = SyncStatic([
    fe(c"ignis_scope_allocate", zif_ignis_scope_allocate, ARGINFO_SCOPE_ALLOCATE.0.as_ptr(), 1),
    fe(c"ignis_scope_rows_clear", zif_ignis_scope_rows_clear, ARGINFO_NONE.0.as_ptr(), 0),
    fe(c"ignis_scope_seal", zif_ignis_scope_seal, ARGINFO_SCOPE_SEAL.0.as_ptr(), 1),
    fe(c"ignis_stats", zif_ignis_stats, ARGINFO_NONE.0.as_ptr(), 0),
    fe(c"ignis_capture_start", super::output::zif_capture_start, ARGINFO_NONE.0.as_ptr(), 0),
    fe(c"ignis_capture_take", super::output::zif_capture_take, ARGINFO_NONE.0.as_ptr(), 0),
    fe(c"ignis_capture_reset", super::output::zif_capture_reset, ARGINFO_NONE.0.as_ptr(), 0),
    fe(c"ignis_stream_bind", super::output::zif_stream_bind, ARGINFO_STREAM_BIND.0.as_ptr(), 3),
    fe(c"ignis_stream_unbind", super::output::zif_stream_unbind, ARGINFO_NONE.0.as_ptr(), 0),
    fe(c"ignis_stream_write", super::output::zif_stream_write, ARGINFO_STREAM_WRITE.0.as_ptr(), 1),
    fe(c"ignis_cancel_parked_any", super::wait::zif_ignis_cancel_parked_any, ARGINFO_CANCEL.0.as_ptr(), 2),
    fe(c"ignis_watch", zif_ignis_watch, ARGINFO_WATCH.0.as_ptr(), 2),
    fe(c"ignis_cancel", zif_ignis_cancel, ARGINFO_CANCEL_OP.0.as_ptr(), 1),
    fe(c"ignis_set_superglobals", super::superglobals::zif_ignis_set_superglobals, ARGINFO_SUPERGLOBALS.0.as_ptr(), 4),
    fe(c"ignis_submit_sleep", zif_ignis_submit_sleep, ARGINFO_SUBMIT_SLEEP.0.as_ptr(), 1),
    fe(c"ignis_poll", zif_ignis_poll, ARGINFO_POLL.0.as_ptr(), 1),
    fe(c"ignis_inflight", zif_ignis_inflight, ARGINFO_NONE.0.as_ptr(), 0),
    fe(c"ignis_publish_stats", zif_ignis_publish_stats, ARGINFO_PUBLISH_STATS.0.as_ptr(), 1),
    fe(c"ignis_serve", zif_ignis_serve, ARGINFO_SERVE.0.as_ptr(), 1),
    fe(c"ignis_watch_files", zif_ignis_watch_files, ARGINFO_WATCH_FILES.0.as_ptr(), 1),
    fe(c"ignis_watch_generation", zif_ignis_watch_generation, ARGINFO_NONE.0.as_ptr(), 0),
    fe(c"ignis_watch_claim_reset", zif_ignis_watch_claim_reset, ARGINFO_NONE.0.as_ptr(), 0),
    fe(c"ignis_watch_begin_reload", zif_ignis_watch_begin_reload, ARGINFO_NONE.0.as_ptr(), 0),
    fe(c"ignis_watch_end_reload", zif_ignis_watch_end_reload, ARGINFO_NONE.0.as_ptr(), 0),
    fe(c"ignis_stop_accepting", zif_ignis_stop_accepting, ARGINFO_NONE.0.as_ptr(), 0),
    fe(c"ignis_respond", zif_ignis_respond, ARGINFO_RESPOND.0.as_ptr(), 4),
    fe(c"ignis_respond_chunk", zif_ignis_respond_chunk, ARGINFO_RESPOND_CHUNK.0.as_ptr(), 2),
    fe(c"ignis_respond_end", zif_ignis_respond_end, ARGINFO_RESPOND_END.0.as_ptr(), 1),
    fe(c"ignis_grpc_send", zif_ignis_grpc_send, ARGINFO_GRPC_SEND.0.as_ptr(), 2),
    fe(c"ignis_grpc_end", zif_ignis_grpc_end, ARGINFO_GRPC_END.0.as_ptr(), 3),
    fe(c"ignis_grpc_call", zif_ignis_grpc_call, ARGINFO_GRPC4.0.as_ptr(), 4),
    fe(c"ignis_grpc_recv", zif_ignis_grpc_recv, ARGINFO_GRPC_RECV.0.as_ptr(), 1),
    fe(c"ignis_offload_submit", zif_ignis_offload_submit, ARGINFO_OFFLOAD_SUBMIT.0.as_ptr(), 3),
    fe(c"ignis_offload_next", zif_ignis_offload_next, ARGINFO_NONE.0.as_ptr(), 0),
    fe(c"ignis_offload_done", zif_ignis_offload_done, ARGINFO_OFFLOAD_DONE.0.as_ptr(), 2),
    fe(c"ignis_offload_callback", zif_ignis_offload_callback, ARGINFO_OFFLOAD_CALLBACK.0.as_ptr(), 3),
    fe(c"ignis_offload_cb_result", zif_ignis_offload_cb_result, ARGINFO_OFFLOAD_CB_RESULT.0.as_ptr(), 3),
    fe(c"ignis_offload_stats", zif_ignis_offload_stats, ARGINFO_NONE.0.as_ptr(), 0),
    fe(c"ignis_route_enable", zif_ignis_route_enable, ARGINFO_ROUTE_ENABLE.0.as_ptr(), 1),
    fe(c"ignis_route_pass", zif_ignis_route_pass, ARGINFO_NONE.0.as_ptr(), 0),
    fe(c"ignis_park_on", crate::backend::async_core::zif_ignis_park_on, ARGINFO_OP_ID.0.as_ptr(), 1),
    fe(c"ignis_op_result", crate::backend::async_core::zif_ignis_op_result, ARGINFO_OP_ID.0.as_ptr(), 1),
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
    module_startup_func: Some(super::superglobals::minit),
    module_shutdown_func: None,
    request_startup_func: Some(rinit),
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
        let m = &raw const MODULE;
        // SAFETY: read-only access to a static in a single test thread. The raw pointer is never
        // turned into a reference, which is the whole point of `static_mut_refs`.
        unsafe {
            assert_eq!((*m).size as u64, size_of::<sys::zend_module_entry>() as u64);
            assert_eq!((*m).zts, 1, "must be built against a ZTS PHP");
            let bid = CStr::from_ptr((*m).build_id).to_str().unwrap();
            assert!(bid.ends_with(",TS"), "build id {bid} is not a TS build");
            assert!(bid.starts_with(&format!("API{}", (*m).zend_api)));
        }
    }

    #[test]
    fn function_table_is_terminated() {
        let last = &FUNCTIONS.0[FUNCTIONS.0.len() - 1];
        assert!(last.fname.is_null() && last.handler.is_none());
        let names: Vec<String> = FUNCTIONS
            .0
            .iter()
            .filter(|f| !f.fname.is_null())
            // SAFETY: fname is checked non-null just above and every entry's name is a 'static
            // CStr literal in the table.
            .map(|f| unsafe { CStr::from_ptr(f.fname) }.to_str().unwrap().to_string())
            .collect();
        for n in [
            "ignis_stats",
            "ignis_set_superglobals",
            "ignis_respond",
            "ignis_poll",
            "ignis_grpc_send",
            "ignis_grpc_end",
            "ignis_grpc_call",
            "ignis_grpc_recv",
            "ignis_offload_submit",
            "ignis_offload_next",
        ] {
            assert!(names.contains(&n.to_string()), "{n} missing");
        }
        let sg = FUNCTIONS
            .0
            .iter()
            // SAFETY: fname is checked non-null in the same expression, and the names are 'static
            // CStr literals in the table.
            .find(|f| !f.fname.is_null() && unsafe { CStr::from_ptr(f.fname) }.to_str().unwrap() == "ignis_set_superglobals")
            .unwrap();
        assert_eq!(sg.num_args, 4);
    }
}
