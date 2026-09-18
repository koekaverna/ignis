//! Temporal (E9, ADR-0013; ADR-0040): sdk-core workers on the shared tokio runtime, driven from
//! PHP through `Op::Custom` futures, mirroring sdk-python's "poll returns bytes, complete takes
//! bytes".
//!
//! **The boundary carries core's own documents, in protojson, in both directions.** There is no
//! schema of ours in between: an activation is serialised straight out of the proto, a completion
//! is parsed straight into it. Every field core has is therefore expressible — retry policies,
//! cancellation types, headers, memo, search attributes — and a new Temporal feature costs nothing
//! here, only in the PHP package that speaks sdk-php's model (ADR-0040 §2).
//!
//! protojson and not prost's serde derive, for two measured reasons pinned in
//! `backend/completion_json.rs`: the derive has no default for enum fields, so partial documents
//! die one message at a time; and it ignores unknown keys, so a typo would silently produce an
//! empty completion. `prost-reflect` over the descriptor pool `temporalio-protos` publishes gets
//! both right, and rejects unknown fields on request.
//!
//! FFI contract: the zif functions parse their arguments into owned Rust data
//! and submit a future; nothing Zend-owned is captured. Workers live in a
//! process-wide table for the process lifetime (or until shutdown).
use std::collections::HashMap;
use std::ffi::{c_char, c_int};
use std::sync::{Arc, Mutex, OnceLock};

use ignis_sys as sys;
use prost::Message;
use prost_reflect::{DescriptorPool, DeserializeOptions, DynamicMessage, SerializeOptions};
use temporalio_client::{Connection, ConnectionOptions};
use temporalio_common::worker::WorkerTaskTypes;
use temporalio_protos::coresdk::workflow_completion::WorkflowActivationCompletion;
use temporalio_protos::coresdk::{ActivityHeartbeat, ActivityTaskCompletion};
use temporalio_protos::temporal::api::common::v1::WorkflowExecution;
use temporalio_protos::temporal::api::workflowservice::v1::GetWorkflowExecutionHistoryRequest;
use temporalio_sdk_core::replay::{HistoryForReplay, ReplayWorkerInput};
use temporalio_sdk_core::{CoreRuntime, Worker, WorkerConfig, WorkerVersioningStrategy, init_replay_worker, init_worker};

use crate::php::module::reactor;
use crate::php::zval;
use crate::reactor::{Op, Outcome};

static RUNTIME: OnceLock<CoreRuntime> = OnceLock::new();
static WORKERS: Mutex<Option<HashMap<u64, Arc<Worker>>>> = Mutex::new(None);
static NEXT: Mutex<u64> = Mutex::new(1);

/// The descriptor pool `temporalio-protos` publishes through its `links` key, loaded once.
fn pool() -> &'static DescriptorPool {
    static POOL: OnceLock<DescriptorPool> = OnceLock::new();
    POOL.get_or_init(|| {
        let bytes = std::fs::read(env!("IGNIS_TEMPORAL_DESCRIPTORS")).expect("temporal descriptor set");
        DescriptorPool::decode(bytes.as_slice()).expect("temporal descriptor pool")
    })
}

/// protojson -> proto. Unknown fields are refused: silently dropping half a completion is how a
/// workflow ends up hanging to its task timeout with nothing in the log.
pub(crate) fn from_protojson<T: Message + Default>(name: &str, json: &str) -> anyhow::Result<T> {
    let md = pool().get_message_by_name(name).ok_or_else(|| anyhow::anyhow!("{name} is not in the descriptor pool"))?;
    let mut de = serde_json::Deserializer::from_str(json);
    let dm = DynamicMessage::deserialize_with_options(md, &mut de, &DeserializeOptions::new().deny_unknown_fields(true))?;
    de.end()?;
    Ok(T::decode(dm.encode_to_vec().as_slice())?)
}

/// proto -> protojson. `stringify_64_bit_integers` stays off: PHP reads these as numbers.
fn to_protojson<T: Message>(name: &str, msg: &T) -> anyhow::Result<String> {
    let md = pool().get_message_by_name(name).ok_or_else(|| anyhow::anyhow!("{name} is not in the descriptor pool"))?;
    let dm = DynamicMessage::decode(md, msg.encode_to_vec().as_slice())?;
    let mut out = Vec::new();
    let mut ser = serde_json::Serializer::new(&mut out);
    dm.serialize_with_options(&mut ser, &SerializeOptions::new().stringify_64_bit_integers(false))?;
    Ok(String::from_utf8(out)?)
}

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
        // Sticky cache: the PHP fiber for a run stays suspended between activations instead of being
        // rebuilt by full replay on every workflow task (max_outstanding >= 2 is required by core).
        .max_cached_workflows(1000usize)
        .max_outstanding_workflow_tasks(16usize)
        .max_outstanding_activities(64usize)
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
    // SAFETY: `rv` is the caller's return slot, valid for the duration of the VM call; set_long only
    // writes that slot. The op id is plain data, so nothing Zend-owned reaches the reactor.
    unsafe { zval::set_long(rv, id as i64) }
}

/// `ignis_temporal_connect(string $url, string $namespace, string $taskQueue): int` → op; result JSON `{"worker": id}`.
pub unsafe extern "C" fn zif_connect(ex: *mut sys::zend_execute_data, rv: *mut sys::zval) {
    // SAFETY: called by the VM through this module's function table, so `ex` is the live frame for
    // this call and `rv` is the return slot the VM owns for it. The body only reads arguments through
    // zend_parse_parameters and writes `rv` through zval::set_*, on the calling thread with the engine
    // active — no Zend pointer escapes into the future handed to the reactor.
    unsafe {
        let (mut a, mut al, mut b, mut bl, mut c, mut cl): (*mut c_char, usize, *mut c_char, usize, *mut c_char, usize) =
            (std::ptr::null_mut(), 0, std::ptr::null_mut(), 0, std::ptr::null_mut(), 0);
        if sys::zend_parse_parameters(zval::num_args(ex), c"sss".as_ptr(), &mut a, &mut al, &mut b, &mut bl, &mut c, &mut cl)
            != sys::SUCCESS
        {
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

/// `ignis_temporal_replay(string $url, string $workflowId, string $taskQueue): int` → `{"worker": id}`:
/// fetches the run's history from the server over gRPC (protobuf, no JSON round trip) and
/// builds a replay worker over it (`init_replay_worker`).
pub unsafe extern "C" fn zif_replay(ex: *mut sys::zend_execute_data, rv: *mut sys::zval) {
    // SAFETY: called by the VM through this module's function table, so `ex` is the live frame for
    // this call and `rv` is the return slot the VM owns for it. The body only reads arguments through
    // zend_parse_parameters and writes `rv` through zval::set_*, on the calling thread with the engine
    // active — no Zend pointer escapes into the future handed to the reactor.
    unsafe {
        let (mut a, mut al, mut b, mut bl, mut c, mut cl): (*mut c_char, usize, *mut c_char, usize, *mut c_char, usize) =
            (std::ptr::null_mut(), 0, std::ptr::null_mut(), 0, std::ptr::null_mut(), 0);
        if sys::zend_parse_parameters(zval::num_args(ex), c"sss".as_ptr(), &mut a, &mut al, &mut b, &mut bl, &mut c, &mut cl)
            != sys::SUCCESS
        {
            return;
        }
        let (url, wid, tq) = (arg_str(a, al), arg_str(b, bl), arg_str(c, cl));
        submit(rv, async move {
            let run = || async {
                let opts = ConnectionOptions::new(url::Url::parse(&url)?)
                    .client_name("ignis".to_string())
                    .client_version(env!("CARGO_PKG_VERSION").to_string())
                    .identity("ignis".to_string())
                    .build();
                let conn = Connection::connect(opts).await?;
                let mut svc = conn.workflow_service();
                let resp = svc
                    .get_workflow_execution_history(tonic::Request::new(GetWorkflowExecutionHistoryRequest {
                        namespace: "default".into(),
                        execution: Some(WorkflowExecution { workflow_id: wid.clone(), run_id: String::new() }),
                        ..Default::default()
                    }))
                    .await?
                    .into_inner();
                let history = resp.history.ok_or_else(|| anyhow::anyhow!("no history returned"))?;
                let _ = core();
                let input =
                    ReplayWorkerInput::new(config("default", &tq)?, futures::stream::iter(vec![HistoryForReplay::new(history, wid)]));
                let w = init_replay_worker(input)?;
                anyhow::Ok(register(w))
            };
            match run().await {
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
    // SAFETY: called by the VM through this module's function table, so `ex` is the live frame for
    // this call and `rv` is the return slot the VM owns for it. The body only reads arguments through
    // zend_parse_parameters and writes `rv` through zval::set_*, on the calling thread with the engine
    // active — no Zend pointer escapes into the future handed to the reactor.
    unsafe {
        let Some((id, _)) = worker_arg(ex) else { return };
        submit(rv, async move {
            let Some(w) = worker(id) else { return Outcome::Failed("unknown worker".into()) };
            match w.poll_workflow_activation().await {
                Ok(act) => match to_protojson("coresdk.workflow_activation.WorkflowActivation", &act) {
                    Ok(json) => Outcome::Json(json),
                    Err(e) => Outcome::Failed(format!("activation json: {e}")),
                },
                Err(e) => Outcome::Failed(format!("poll: {e}")),
            }
        });
    }
}

/// `ignis_temporal_complete(int $worker, string $completionJson): int` → `"ok"`.
pub unsafe extern "C" fn zif_complete_activation(ex: *mut sys::zend_execute_data, rv: *mut sys::zval) {
    // SAFETY: called by the VM through this module's function table, so `ex` is the live frame for
    // this call and `rv` is the return slot the VM owns for it. The body only reads arguments through
    // zend_parse_parameters and writes `rv` through zval::set_*, on the calling thread with the engine
    // active — no Zend pointer escapes into the future handed to the reactor.
    unsafe {
        let Some((id, Some(json))) = worker_arg(ex) else { return };
        submit(rv, async move {
            let Some(w) = worker(id) else { return Outcome::Failed("unknown worker".into()) };
            let comp: WorkflowActivationCompletion = match from_protojson("coresdk.workflow_completion.WorkflowActivationCompletion", &json)
            {
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
    // SAFETY: called by the VM through this module's function table, so `ex` is the live frame for
    // this call and `rv` is the return slot the VM owns for it. The body only reads arguments through
    // zend_parse_parameters and writes `rv` through zval::set_*, on the calling thread with the engine
    // active — no Zend pointer escapes into the future handed to the reactor.
    unsafe {
        let Some((id, _)) = worker_arg(ex) else { return };
        submit(rv, async move {
            let Some(w) = worker(id) else { return Outcome::Failed("unknown worker".into()) };
            match w.poll_activity_task().await {
                Ok(t) => match to_protojson("coresdk.activity_task.ActivityTask", &t) {
                    Ok(json) => Outcome::Json(json),
                    Err(e) => Outcome::Failed(format!("activity task json: {e}")),
                },
                Err(e) => Outcome::Failed(format!("poll activity: {e}")),
            }
        });
    }
}

/// `ignis_temporal_complete_activity(int $worker, string $completionJson): int`.
pub unsafe extern "C" fn zif_complete_activity(ex: *mut sys::zend_execute_data, rv: *mut sys::zval) {
    // SAFETY: called by the VM through this module's function table, so `ex` is the live frame for
    // this call and `rv` is the return slot the VM owns for it. The body only reads arguments through
    // zend_parse_parameters and writes `rv` through zval::set_*, on the calling thread with the engine
    // active — no Zend pointer escapes into the future handed to the reactor.
    unsafe {
        let Some((id, Some(json))) = worker_arg(ex) else { return };
        submit(rv, async move {
            let Some(w) = worker(id) else { return Outcome::Failed("unknown worker".into()) };
            let comp: ActivityTaskCompletion = match from_protojson("coresdk.ActivityTaskCompletion", &json) {
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

/// `ignis_temporal_heartbeat(int $worker, string $json): bool`
///
/// Unlike every other call here this one is **synchronous and returns no op**: core's
/// `record_activity_heartbeat` only enqueues, so there is nothing to await, and sdk-php calls it
/// from inside an activity through its RPC seam where a parked fiber would be surprising.
pub unsafe extern "C" fn zif_heartbeat(ex: *mut sys::zend_execute_data, rv: *mut sys::zval) {
    // SAFETY: called by the VM through this module's function table, so `ex` is the live frame for
    // this call and `rv` is the return slot the VM owns for it. The body only reads arguments through
    // zend_parse_parameters and writes `rv` through zval::set_*, on the calling thread with the engine
    // active — no Zend pointer escapes into the future handed to the reactor.
    unsafe {
        let Some((id, Some(json))) = worker_arg(ex) else { return };
        let Some(w) = worker(id) else {
            zval::set_bool(rv, false);
            return;
        };
        let ok = (|| -> anyhow::Result<()> {
            w.record_activity_heartbeat(from_protojson::<ActivityHeartbeat>("coresdk.ActivityHeartbeat", &json)?);
            Ok(())
        })();
        if let Err(e) = &ok {
            tracing::warn!(error = %e, "temporal heartbeat");
        }
        zval::set_bool(rv, ok.is_ok());
    }
}

/// `ignis_temporal_shutdown(int $worker): int` — initiates shutdown; pollers return errors afterwards.
pub unsafe extern "C" fn zif_shutdown(ex: *mut sys::zend_execute_data, rv: *mut sys::zval) {
    // SAFETY: called by the VM through this module's function table, so `ex` is the live frame for
    // this call and `rv` is the return slot the VM owns for it. The body only reads arguments through
    // zend_parse_parameters and writes `rv` through zval::set_*, on the calling thread with the engine
    // active — no Zend pointer escapes into the future handed to the reactor.
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
