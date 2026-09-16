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
use temporalio_protos::coresdk::activity_result::{ActivityExecutionResult, Success, activity_execution_result};
use temporalio_protos::coresdk::workflow_commands::{CompleteWorkflowExecution, FailWorkflowExecution, ScheduleActivity, StartTimer, workflow_command};
use temporalio_protos::coresdk::workflow_completion::WorkflowActivationCompletion;
use temporalio_protos::coresdk::ActivityTaskCompletion;
use temporalio_protos::temporal::api::common::v1::{Payload, WorkflowExecution};
use temporalio_protos::temporal::api::failure::v1::Failure;
use temporalio_protos::temporal::api::workflowservice::v1::GetWorkflowExecutionHistoryRequest;
use temporalio_sdk_core::replay::{HistoryForReplay, ReplayWorkerInput};
use temporalio_sdk_core::{CoreRuntime, Worker, WorkerConfig, WorkerVersioningStrategy, init_replay_worker, init_worker};

use crate::php::module::reactor;
use crate::php::zval;
use crate::reactor::{Op, Outcome};

static RUNTIME: OnceLock<CoreRuntime> = OnceLock::new();
static WORKERS: Mutex<Option<HashMap<u64, Arc<Worker>>>> = Mutex::new(None);
static NEXT: Mutex<u64> = Mutex::new(1);

/// The command schema PHP emits (ADR-0013): small, defaulted, independent of prost's
/// serde field requirements. Payloads are `{"metadata": {..}, "data": base64}` like the wire.
#[derive(serde::Deserialize)]
struct PhpPayload {
    #[serde(default)]
    metadata: HashMap<String, String>,
    #[serde(default)]
    data: String,
}

impl PhpPayload {
    fn into_proto(self) -> anyhow::Result<Payload> {
        use base64::Engine;
        let e = base64::engine::general_purpose::STANDARD;
        let metadata = self.metadata.into_iter().map(|(k, v)| Ok((k, e.decode(v)?))).collect::<anyhow::Result<_>>()?;
        Ok(Payload { metadata, data: e.decode(self.data)?, ..Default::default() })
    }
}

#[derive(serde::Deserialize)]
#[serde(tag = "cmd")]
enum PhpCommand {
    StartTimer { seq: u32, ms: u64 },
    ScheduleActivity { seq: u32, activity_type: String, task_queue: String, #[serde(default)] args: Vec<PhpPayload>, #[serde(default = "thirty")] start_to_close_sec: u64 },
    CompleteWorkflow { result: Option<PhpPayload> },
    FailWorkflow { message: String },
}

fn thirty() -> u64 {
    30
}

#[derive(serde::Deserialize)]
struct PhpCompletion {
    run_id: String,
    #[serde(default)]
    commands: Vec<PhpCommand>,
}

#[derive(serde::Deserialize)]
struct PhpActivityCompletion {
    task_token: Vec<u8>,
    result: Option<PhpPayload>,
    #[serde(default)]
    failure: Option<String>,
}

fn dur(secs: u64, ms: u64) -> prost_wkt_types::Duration {
    prost_wkt_types::Duration { seconds: secs as i64 + (ms / 1000) as i64, nanos: ((ms % 1000) * 1_000_000) as i32 }
}

fn translate_completion(json: &str) -> anyhow::Result<WorkflowActivationCompletion> {
    let c: PhpCompletion = serde_json::from_str(json)?;
    let mut cmds = Vec::new();
    for cmd in c.commands {
        cmds.push(match cmd {
            PhpCommand::StartTimer { seq, ms } => workflow_command::Variant::StartTimer(StartTimer { seq, start_to_fire_timeout: Some(dur(0, ms)) }),
            PhpCommand::ScheduleActivity { seq, activity_type, task_queue, args, start_to_close_sec } => {
                workflow_command::Variant::ScheduleActivity(ScheduleActivity {
                    seq,
                    activity_id: seq.to_string(),
                    activity_type,
                    task_queue,
                    arguments: args.into_iter().map(PhpPayload::into_proto).collect::<anyhow::Result<_>>()?,
                    start_to_close_timeout: Some(dur(start_to_close_sec, 0)),
                    ..Default::default()
                })
            }
            PhpCommand::CompleteWorkflow { result } => {
                workflow_command::Variant::CompleteWorkflowExecution(CompleteWorkflowExecution { result: result.map(PhpPayload::into_proto).transpose()? })
            }
            PhpCommand::FailWorkflow { message } => {
                workflow_command::Variant::FailWorkflowExecution(FailWorkflowExecution { failure: Some(Failure { message, ..Default::default() }) })
            }
        });
    }
    Ok(WorkflowActivationCompletion::from_cmds(c.run_id, cmds))
}

fn translate_activity_completion(json: &str) -> anyhow::Result<ActivityTaskCompletion> {
    let c: PhpActivityCompletion = serde_json::from_str(json)?;
    let status = match (c.failure, c.result) {
        (Some(message), _) => activity_execution_result::Status::Failed(temporalio_protos::coresdk::activity_result::Failure {
            failure: Some(Failure { message, ..Default::default() }), ..Default::default()
        }),
        (None, result) => activity_execution_result::Status::Completed(Success { result: result.map(PhpPayload::into_proto).transpose()? }),
    };
    Ok(ActivityTaskCompletion { task_token: c.task_token, result: Some(ActivityExecutionResult { status: Some(status) }) })
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

/// `ignis_temporal_replay(string $url, string $workflowId, string $taskQueue): int` → `{"worker": id}`:
/// fetches the run's history from the server over gRPC (protobuf, no JSON round trip) and
/// builds a replay worker over it (`init_replay_worker`).
pub unsafe extern "C" fn zif_replay(ex: *mut sys::zend_execute_data, rv: *mut sys::zval) {
    unsafe {
        let (mut a, mut al, mut b, mut bl, mut c, mut cl): (*mut c_char, usize, *mut c_char, usize, *mut c_char, usize) =
            (std::ptr::null_mut(), 0, std::ptr::null_mut(), 0, std::ptr::null_mut(), 0);
        if sys::zend_parse_parameters(zval::num_args(ex), c"sss".as_ptr(), &mut a, &mut al, &mut b, &mut bl, &mut c, &mut cl) != sys::SUCCESS {
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
                let input = ReplayWorkerInput::new(config("default", &tq)?, futures::stream::iter(vec![HistoryForReplay::new(history, wid)]));
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
            let comp: WorkflowActivationCompletion = match translate_completion(&json) {
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
            let comp: ActivityTaskCompletion = match translate_activity_completion(&json) {
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
