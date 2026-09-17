//! Why the completion boundary has a hand-written schema instead of being a dumb pipe.
//!
//! The read direction already passes core's own JSON through untouched (`ignis_temporal_poll`
//! serialises the `WorkflowActivation` and PHP reads it). The obvious symmetry — let PHP emit
//! core's `WorkflowActivationCompletion` JSON and have Rust just deserialise it — would delete
//! `PhpCommand` entirely and make every future Temporal feature a PHP-only change.
//!
//! It does not work with `temporalio-protos`' serde derive, and this test pins exactly why, so the
//! next person does not rediscover it: **prost's serde derive is not protojson.** Well-known types
//! use the canonical form (a `Duration` is `"5s"`, not `{seconds, nanos}`), scalars and messages
//! default when absent — but **enum fields are required**. Partial documents therefore fail on the
//! first enum, one message at a time, at runtime.
//!
//! The principled fix is protojson proper — `prost-reflect` over the descriptor pool the build
//! script already emits (`cargo:descriptor_path`) — which defaults everything by specification.
//! Until then the small schema stays, and the cost is one arm per feature.

use temporalio_protos::coresdk::workflow_completion::WorkflowActivationCompletion;

fn parse(json: &str) -> Result<WorkflowActivationCompletion, serde_json::Error> {
    serde_json::from_str(json)
}

#[test]
fn prost_serde_is_not_protojson() {
    // 1. Unknown keys are ignored rather than rejected: a typo on the PHP side would produce an
    //    empty completion and a workflow that hangs to its task timeout. A dumb pipe would need
    //    `deny_unknown_fields`, which the derive does not give us either.
    let wrong_key = parse(r#"{"run_id":"r","successful":{"commands":[]}}"#).expect("unknown keys are ignored");
    assert!(wrong_key.status.is_none(), "the completion silently lost its commands");

    // 2. Durations are protojson strings, not {seconds, nanos}.
    let err = parse(
        r#"{"run_id":"r","status":{"Successful":{"commands":[{"variant":{"StartTimer":
           {"seq":1,"start_to_fire_timeout":{"seconds":5,"nanos":0}}}}]}}}"#,
    )
    .unwrap_err();
    assert!(err.to_string().contains("duration"), "unexpected error: {err}");

    // 3. With the canonical form the structure is accepted — including partially-filled submessages
    //    such as a retry policy — until an enum field is reached, which has no default.
    let err = parse(
        r#"{"run_id":"r","status":{"Successful":{"commands":[{"variant":{"ScheduleActivity":
           {"seq":1,"activity_id":"1","activity_type":"greet","task_queue":"ignis",
            "start_to_close_timeout":"5s","retry_policy":{"maximum_attempts":3,"initial_interval":"1s"},
            "arguments":[{"metadata":{"encoding":"anNvbi9wbGFpbg=="},"data":"IkFkYSI="}],"headers":{}}}}]}}}"#,
    )
    .unwrap_err();
    assert!(err.to_string().contains("cancellation_type"), "unexpected error: {err}");
}

/// The fix the test above points at: protojson against the real descriptor pool.
///
/// `prost-reflect` implements the specification — every absent field takes its default, enums
/// included — and, unlike the derive, it can be told to **reject** unknown fields, so a typo on the
/// PHP side is an error instead of a silently empty completion.
#[test]
fn protojson_accepts_partial_documents_and_rejects_typos() {
    use prost::Message;
    use prost_reflect::{DescriptorPool, DeserializeOptions, DynamicMessage};

    let bytes = std::fs::read(env!("IGNIS_TEMPORAL_DESCRIPTORS")).expect("descriptor set from temporalio-protos");
    let pool = DescriptorPool::decode(bytes.as_slice()).expect("descriptor pool");
    let md = pool
        .get_message_by_name("coresdk.workflow_completion.WorkflowActivationCompletion")
        .expect("WorkflowActivationCompletion in the pool");

    let decode = |json: &str| -> Result<WorkflowActivationCompletion, String> {
        let mut de = serde_json::Deserializer::from_str(json);
        let dm = DynamicMessage::deserialize_with_options(md.clone(), &mut de, &DeserializeOptions::new().deny_unknown_fields(true))
            .map_err(|e| e.to_string())?;
        WorkflowActivationCompletion::decode(dm.encode_to_vec().as_slice()).map_err(|e| e.to_string())
    };

    // The same document the derive rejected for `cancellation_type`, in protojson: oneofs are
    // flattened to their field name, durations stay canonical, bytes stay base64.
    let c = decode(
        r#"{"runId":"r","successful":{"commands":[{"scheduleActivity":
           {"seq":1,"activityId":"1","activityType":"greet","taskQueue":"ignis",
            "startToCloseTimeout":"5s","retryPolicy":{"maximumAttempts":3,"initialInterval":"1s"},
            "arguments":[{"metadata":{"encoding":"anNvbi9wbGFpbg=="},"data":"IkFkYSI="}]}}]}}"#,
    )
    .expect("a partial document is valid protojson");

    let commands = match c.status {
        Some(temporalio_protos::coresdk::workflow_completion::workflow_activation_completion::Status::Successful(s)) => s.commands,
        _ => panic!("no commands survived"),
    };
    assert_eq!(commands.len(), 1);
    assert_eq!(c.run_id, "r");

    // And a typo is refused rather than quietly dropping the batch.
    let err = decode(r#"{"runId":"r","succesful":{"commands":[]}}"#).unwrap_err();
    assert!(err.contains("succesful") || err.contains("unknown"), "unexpected error: {err}");
}

/// The loop closed without a Temporal server: every completion the PHP transport actually produced
/// in `bench/e20-sdkphp.sh` is decoded here by the same function the runtime uses. Copied verbatim
/// from that run (`CORE_DUMP_RAW=1`), so if either side drifts, this fails.
#[test]
fn the_php_transport_produces_documents_core_accepts() {
    use temporalio_protos::coresdk::ActivityTaskCompletion;
    use temporalio_protos::coresdk::workflow_completion::workflow_activation_completion::Status;

    let workflow = [
        (r#"{"runId":"run-1","successful":{"commands":[{"scheduleActivity":{"seq":1,"activityId":"1","activityType":"greet","taskQueue":"ignis","arguments":[{"metadata":{"encoding":"anNvbi9wbGFpbg=="},"data":"IkFkYSI="}],"startToCloseTimeout":"5s"}}]}}"#, 1),
        (r#"{"runId":"run-1","successful":{"commands":[{"startTimer":{"seq":2,"startToFireTimeout":"1s"}}]}}"#, 1),
        (r#"{"runId":"run-1","successful":{"commands":[{"completeWorkflowExecution":{"result":{"metadata":{"encoding":"anNvbi9wbGFpbg=="},"data":"IkhFTExPLCBBREEhIg=="}}}]}}"#, 1),
        (r#"{"runId":"run-1","successful":{"commands":[]}}"#, 0),
        (r#"{"runId":"run-2","successful":{"commands":[{"updateResponse":{"protocolInstanceId":"pi-1","accepted":{}}},{"scheduleLocalActivity":{"seq":1,"activityId":"1","activityType":"projection.jobStarted","arguments":[{"metadata":{"encoding":"anNvbi9wbGFpbg=="},"data":"Ingi"}],"startToCloseTimeout":"5s"}}]}}"#, 2),
        (r#"{"runId":"run-2","successful":{"commands":[{"respondToQuery":{"queryId":"q-1","succeeded":{"response":{"metadata":{"encoding":"anNvbi9wbGFpbg=="},"data":"Im5ldyI="}}}}]}}"#, 1),
        (r#"{"runId":"run-2","successful":{"commands":[{"updateResponse":{"protocolInstanceId":"pi-1","completed":{"metadata":{"encoding":"anNvbi9wbGFpbg=="},"data":"Im9rOngi"}}},{"completeWorkflowExecution":{"result":{"metadata":{"encoding":"anNvbi9wbGFpbg=="},"data":"Im9rOngi"}}}]}}"#, 2),
    ];

    for (json, expected) in workflow {
        let c: WorkflowActivationCompletion = crate::backend::temporal::from_protojson(
            "coresdk.workflow_completion.WorkflowActivationCompletion",
            json,
        )
        .unwrap_or_else(|e| panic!("core refused a completion the transport produced: {e}\n{json}"));

        let commands = match c.status {
            Some(Status::Successful(s)) => s.commands,
            other => panic!("no successful status: {other:?}"),
        };
        assert_eq!(commands.len(), expected, "wrong command count for {json}");
        assert!(commands.iter().all(|c| c.variant.is_some()), "a command lost its variant: {json}");
    }

    for json in [
        r#"{"taskToken":"dG9rLTE=","result":{"completed":{"result":{"metadata":{"encoding":"anNvbi9wbGFpbg=="},"data":"IkhlbGxvLCBBZGEhIg=="}}}}"#,
        r#"{"taskToken":"dG9rLWJlYXQ=","result":{"completed":{"result":{"metadata":{"encoding":"anNvbi9wbGFpbg=="},"data":"IndvcmtlZDpiZWF0Ig=="}}}}"#,
    ] {
        let c: ActivityTaskCompletion =
            crate::backend::temporal::from_protojson("coresdk.ActivityTaskCompletion", json)
                .unwrap_or_else(|e| panic!("core refused an activity completion: {e}\n{json}"));
        assert!(!c.task_token.is_empty());
        assert!(c.result.and_then(|r| r.status).is_some());
    }
}
