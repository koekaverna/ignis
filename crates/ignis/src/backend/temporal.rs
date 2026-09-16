//! Temporal (E9, ADR-0013): sdk-core workers on the shared tokio runtime,
//! driven from PHP through `Op::Custom` futures. Only JSON crosses the
//! boundary (`serde_serialize` feature of `temporalio-protos`), mirroring
//! sdk-python's "poll returns bytes, complete takes bytes".
//!
//! FFI contract: the zif functions parse their arguments into owned Rust data
//! and submit a future; nothing Zend-owned is captured. Workers live in a
//! process-wide table for the process lifetime (or until shutdown).
use std::collections::HashMap;
use std::ffi::{c_char, c_int};
use std::sync::{Arc, Mutex, OnceLock};

use ignis_sys as sys;
use temporalio_client::{Connection, ConnectionOptions};
use temporalio_common::worker::WorkerTaskTypes;
use temporalio_protos::coresdk::workflow_completion::WorkflowActivationCompletion;
use temporalio_protos::coresdk::ActivityTaskCompletion;
use temporalio_protos::temporal::api::history::v1::History;
use temporalio_sdk_core::replay::{HistoryForReplay, ReplayWorkerInput};
use temporalio_sdk_core::{CoreRuntime, Worker, WorkerConfig, WorkerVersioningStrategy, init_replay_worker, init_worker};

use crate::php::module::reactor;
use crate::php::zval;
use crate::reactor::{Op, Outcome};

static RUNTIME: OnceLock<CoreRuntime> = OnceLock::new();
static WORKERS: Mutex<Option<HashMap<u64, Arc<Worker>>>> = Mutex::new(None);
static NEXT: Mutex<u64> = Mutex::new(1);

fn core() -> &'static CoreRuntime {
    RUNTIME.get_or_init(|| CoreRuntime::new_assume_tokio(Default::default()).expect("temporal core runtime"))
}

fn register(w: Worker) -> u64 {
    let mut n = NEXT.lock().unwrap();
    let id = *n;
    *n += 1;
    WORKERS.lock().unwrap().get_or_insert_with(HashMap::new).insert(id, Arc::new(w));
    id
}

fn worker(id: u64) -> Option<Arc<Worker>> {
    WORKERS.lock().unwrap().as_ref().and_then(|m| m.get(&id).cloned())
}

fn config(namespace: &str, task_queue: &str) -> anyhow::Result<WorkerConfig> {
    WorkerConfig::builder()
        .namespace(namespace)
        .task_queue(task_queue)
        .task_types(WorkerTaskTypes::all())
        .versioning_strategy(WorkerVersioningStrategy::None { build_id: "ignis".to_string() })
        .build()
        .map_err(|e| anyhow::anyhow!(e))
}

unsafe fn arg_str(zv: *mut c_char, len: usize) -> String {
    // SAFETY: zend_parse_parameters guarantees len valid bytes at zv for the call.
    unsafe { String::from_utf8_lossy(std::slice::from_raw_parts(zv as *const u8, len)).into_owned() }
}

unsafe fn submit(rv: *mut sys::zval, fut: impl Future<Output = Outcome> + Send + 'static) {
    let id = reactor().submit(Op::Custom(Box::pin(fut)));
    unsafe { zval::set_long(rv, id as i64) }
}

/// `ignis_temporal_connect(string $url, string $namespace, string $taskQueue): int` → op; result JSON `{"worker": id}`.
pub unsafe extern "C" fn zif_connect(ex: *mut sys::zend_execute_data, rv: *mut sys::zval) {
    unsafe {
        let (mut a, mut al, mut b, mut bl, mut c, mut cl): (*mut c_char, usize, *mut c_char, usize, *mut c_char, usize) =
            (std::ptr::null_mut(), 0, std::ptr::null_mut(), 0, std::ptr::null_mut(), 0);
        if sys::zend_parse_parameters(zval::num_args(ex), c"sss".as_ptr(), &mut a, &mut al, &mut b, &mut bl, &mut c, &mut cl) != sys::SUCCESS {
            return;
        }
        let (url, ns, tq) = (arg_str(a, al), arg_str(b, bl), arg_str(c, cl));
        submit(rv, async move {
            let run = || async {
                let opts = ConnectionOptions::new(url::Url::parse(&url)?)
                    .client_name("ignis".to_string())
                    .client_version(env!("CARGO_PKG_VERSION").to_string())
                    .identity("ignis".to_string())
                    .build();
                let conn = Connection::connect(opts).await?;
                let w = init_worker(core(), config(&ns, &tq)?, conn)?;
                anyhow::Ok(register(w))
            };
            match run().await {
                Ok(id) => Outcome::Json(format!("{{\"worker\":{id}}}")),
                Err(e) => Outcome::Failed(format!("temporal connect: {e:#}")),
            }
        });
    }
}

/// `ignis_temporal_replay(string $historyJson, string $workflowId, string $taskQueue): int` → `{"worker": id}` for a replay worker.
pub unsafe extern "C" fn zif_replay(ex: *mut sys::zend_execute_data, rv: *mut sys::zval) {
    unsafe {
        let (mut a, mut al, mut b, mut bl, mut c, mut cl): (*mut c_char, usize, *mut c_char, usize, *mut c_char, usize) =
            (std::ptr::null_mut(), 0, std::ptr::null_mut(), 0, std::ptr::null_mut(), 0);
        if sys::zend_parse_parameters(zval::num_args(ex), c"sss".as_ptr(), &mut a, &mut al, &mut b, &mut bl, &mut c, &mut cl) != sys::SUCCESS {
            return;
        }
        let (hist, wid, tq) = (arg_str(a, al), arg_str(b, bl), arg_str(c, cl));
        submit(rv, async move {
            let run = || {
                let history: History = serde_json::from_str(&hist)?;
                let _ = core();
                let input = ReplayWorkerInput::new(config("default", &tq)?, futures::stream::iter(vec![HistoryForReplay::new(history, wid)]));
                let w = init_replay_worker(input)?;
                anyhow::Ok(register(w))
            };
            match run() {
                Ok(id) => Outcome::Json(format!("{{\"worker\":{id}}}")),
                Err(e) => Outcome::Failed(format!("temporal replay: {e:#}")),
            }
        });
    }
}

fn worker_arg(ex: *mut sys::zend_execute_data) -> Option<(u64, Option<String>)> {
    // SAFETY: VM frame; parses "l" or "ls".
    unsafe {
        let mut id: sys::zend_long = 0;
        let (mut s, mut sl): (*mut c_char, usize) = (std::ptr::null_mut(), 0);
        if zval::num_args(ex) >= 2 {
            if sys::zend_parse_parameters(zval::num_args(ex), c"ls".as_ptr(), &mut id, &mut s, &mut sl) != sys::SUCCESS {
                return None;
            }
            Some((id as u64, Some(arg_str(s, sl))))
        } else {
            if sys::zend_parse_parameters(zval::num_args(ex), c"l".as_ptr(), &mut id) != sys::SUCCESS {
                return None;
            }
            Some((id as u64, None))
        }
    }
}

/// `ignis_temporal_poll(int $worker): int` → JSON `WorkflowActivation`, or error (shutdown).
pub unsafe extern "C" fn zif_poll_activation(ex: *mut sys::zend_execute_data, rv: *mut sys::zval) {
    unsafe {
        let Some((id, _)) = worker_arg(ex) else { return };
        submit(rv, async move {
            let Some(w) = worker(id) else { return Outcome::Failed("unknown worker".into()) };
            match w.poll_workflow_activation().await {
                Ok(act) => Outcome::Json(serde_json::to_string(&act).unwrap_or_default()),
                Err(e) => Outcome::Failed(format!("poll: {e}")),
            }
        });
    }
}

/// `ignis_temporal_complete(int $worker, string $completionJson): int` → `"ok"`.
pub unsafe extern "C" fn zif_complete_activation(ex: *mut sys::zend_execute_data, rv: *mut sys::zval) {
    unsafe {
        let Some((id, Some(json))) = worker_arg(ex) else { return };
        submit(rv, async move {
            let Some(w) = worker(id) else { return Outcome::Failed("unknown worker".into()) };
            let comp: WorkflowActivationCompletion = match serde_json::from_str(&json) {
                Ok(c) => c,
                Err(e) => return Outcome::Failed(format!("completion json: {e}")),
            };
            match w.complete_workflow_activation(comp).await {
                Ok(()) => Outcome::Json("\"ok\"".into()),
                Err(e) => Outcome::Failed(format!("complete: {e}")),
            }
        });
    }
}

/// `ignis_temporal_poll_activity(int $worker): int` → JSON `ActivityTask`.
pub unsafe extern "C" fn zif_poll_activity(ex: *mut sys::zend_execute_data, rv: *mut sys::zval) {
    unsafe {
        let Some((id, _)) = worker_arg(ex) else { return };
        submit(rv, async move {
            let Some(w) = worker(id) else { return Outcome::Failed("unknown worker".into()) };
            match w.poll_activity_task().await {
                Ok(t) => Outcome::Json(serde_json::to_string(&t).unwrap_or_default()),
                Err(e) => Outcome::Failed(format!("poll activity: {e}")),
            }
        });
    }
}

/// `ignis_temporal_complete_activity(int $worker, string $completionJson): int`.
pub unsafe extern "C" fn zif_complete_activity(ex: *mut sys::zend_execute_data, rv: *mut sys::zval) {
    unsafe {
        let Some((id, Some(json))) = worker_arg(ex) else { return };
        submit(rv, async move {
            let Some(w) = worker(id) else { return Outcome::Failed("unknown worker".into()) };
            let comp: ActivityTaskCompletion = match serde_json::from_str(&json) {
                Ok(c) => c,
                Err(e) => return Outcome::Failed(format!("activity completion json: {e}")),
            };
            match w.complete_activity_task(comp).await {
                Ok(()) => Outcome::Json("\"ok\"".into()),
                Err(e) => Outcome::Failed(format!("complete activity: {e}")),
            }
        });
    }
}

/// `ignis_temporal_shutdown(int $worker): int` — initiates shutdown; pollers return errors afterwards.
pub unsafe extern "C" fn zif_shutdown(ex: *mut sys::zend_execute_data, rv: *mut sys::zval) {
    unsafe {
        let Some((id, _)) = worker_arg(ex) else { return };
        submit(rv, async move {
            let Some(w) = worker(id) else { return Outcome::Failed("unknown worker".into()) };
            w.initiate_shutdown();
            Outcome::Json("\"ok\"".into())
        });
    }
}

#[allow(dead_code)]
fn _unused(_: c_int) {}
