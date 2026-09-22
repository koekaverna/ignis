# ADR-0013 — Temporal: sdk-core on the shared tokio runtime; workflows are fibers with a PHP-side deterministic queue

Status: accepted for the prototype scope (Cycle 13, 2026-09-16; V-18, V-19); **the prototype runtime (`ignis/temporal-prototype`) was deleted on 2026-09-22** (owner, MVP cut, DECISIONS) — the sdk-core primitives this ADR added stay and host the official SDK through ADR-0040, where the replay gate now lives. Sticky cache on (`max_cached_workflows = 1000`) so a workflow fiber survives between activations; the JSON boundary uses Ignis's own command schema translated in Rust, not raw proto JSON (see V-19).

Decision: link `temporal-sdk-core` (git) into the ignis binary behind a `temporal` cargo feature; expose
`Op::TemporalPoll`/`Op::TemporalComplete` (bytes in, bytes out) exactly like sdk-python's bridge; a PHP
`Ignis\Temporal\Worker` loop polls activations and dispatches each to the workflow run's fiber; workflow
code awaits activities/timers by recording commands and suspending; the run's fiber is resumed only from
activation jobs (never by the HTTP loop, timers or streams), which is what makes it deterministic and
replayable. Activities run as ordinary Ignis fibers (real I/O allowed).
Rejected: a Rust-side workflow interpreter (would duplicate sdk-python's `_workflow_instance` in Rust and
keep PHP out of the loop); a PHP-only client via gRPC (loses core's state machines and replay).
Pain-map: none directly; RoadRunner 4 (Table/KV over RPC) is adjacent — Temporal state stays in core.
Kill criteria: sdk-core does not build in this environment; or the dev server cannot be built/run here
(then only the replay half is testable: `init_replay_worker` with a recorded history); or an activation
cannot be completed from a parked PHP fiber within the workflow task timeout (10 s default).
