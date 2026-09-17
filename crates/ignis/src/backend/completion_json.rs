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
