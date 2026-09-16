// Probe (E9 step 1, H20): link temporalio-sdk-core from git, start a worker against a local dev server,
// poll one workflow activation and complete it with a CompleteWorkflowExecution command.
use std::sync::Arc;
use temporalio_client::{Connection, ConnectionOptions};
use temporalio_protos::coresdk::workflow_commands::{CompleteWorkflowExecution, workflow_command};
use temporalio_protos::coresdk::workflow_completion::WorkflowActivationCompletion;
use temporalio_protos::temporal::api::common::v1::Payload;
use temporalio_sdk_core::{CoreRuntime, WorkerConfig, WorkerVersioningStrategy, init_worker};

#[tokio::main]
async fn main() {
    let runtime = CoreRuntime::new_assume_tokio(Default::default()).expect("core runtime");
    let opts = ConnectionOptions::new(url::Url::parse("http://127.0.0.1:7233").unwrap())
        .client_name("ignis-probe".to_string())
        .client_version("0.0.1".to_string())
        .identity("ignis-probe".to_string())
        .build();
    let connection = Connection::connect(opts).await.expect("connect to dev server");
    let cfg = WorkerConfig::builder()
        .namespace("default")
        .task_queue("ignis")
        .task_types(temporalio_common::worker::WorkerTaskTypes::workflow_only())
        .versioning_strategy(WorkerVersioningStrategy::None { build_id: "ignis-probe".to_string() })
        .build();
    let cfg = cfg.expect("worker config");
    let worker = Arc::new(init_worker(&runtime, cfg, connection).expect("init worker"));
    println!("worker up; waiting for an activation on task queue 'ignis'");
    let act = worker.poll_workflow_activation().await.expect("poll");
    println!("activation run_id={} jobs={}", act.run_id, act.jobs.len());
    let result = Payload { data: b"\"done by ignis probe\"".to_vec(), ..Default::default() };
    let cmd = workflow_command::Variant::CompleteWorkflowExecution(CompleteWorkflowExecution { result: Some(result) });
    worker
        .complete_workflow_activation(WorkflowActivationCompletion::from_cmds(act.run_id.clone(), vec![cmd]))
        .await
        .expect("complete");
    println!("completed run_id={}", act.run_id);
    worker.initiate_shutdown();
    worker.shutdown().await;
}
