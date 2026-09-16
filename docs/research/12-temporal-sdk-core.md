# Research 12 — Temporal sdk-core in-process; how sdk-python bridges core ↔ asyncio

Date: 2026-09-16 (Cycle 12). Sources on disk: `/home/user/temporal-sdk-python` (clone of temporalio/sdk-python:
`temporalio/bridge/src/{runtime.rs,worker.rs}`, `temporalio/bridge/worker.py`, `temporalio/worker/_workflow.py`,
`temporalio/worker/_workflow_instance.py`), `temporal-sdk-core-api` (crates.io 0.1.0 and git `src/lib.rs`
`trait Worker`), the crates.io `temporal-sdk-core 0.1.0-alpha.1` source, temporalio/cli (git).

## How sdk-python bridges core ↔ asyncio (verified in source)
- **Core owns a tokio runtime** (`bridge/src/runtime.rs`: `CoreRuntime` built with `TokioRuntimeBuilder`), created
  once per process; the Python side never touches tokio.
- **Every core call is a future turned into an awaitable**: `bridge/src/worker.rs:556-633` wrap
  `worker.poll_workflow_activation()`, `complete_workflow_activation(bytes)`, `poll_activity_task()`,
  `complete_activity_task(bytes)` with `pyo3_async_runtimes::future_into_py`; the asyncio loop awaits them, the
  completion is posted back onto the loop through task locals. Only protobuf **bytes** cross the boundary
  (`comp.SerializeToString()` in `bridge/worker.py:242`).
- **The worker loop** (`_workflow.py:234-278`): `act = await poll_workflow_activation(); completion = handle(act);
  await complete_workflow_activation(completion)`. One activation = a batch of jobs for one workflow run
  (start, fire timer, resolve activity, signal, query, cancel, evict).
- **Determinism** (`_workflow_instance.py:269`): the workflow's coroutines run on a private
  `asyncio.AbstractEventLoop` implementation owned by the instance; `activate()` applies the jobs
  (`_apply_fire_timer` → `self._ready.append(handle)`, `_apply_resolve_activity` → resolves the future) and
  then runs the ready queue until nothing is runnable. Timers and activities are never real waits: they become
  **commands** in the completion; the run advances only on the next activation. Replay = feed history-driven
  activations to the same code; the instance's private loop makes ordering deterministic.

## Mapping to Ignis (the shape is ADR-0007's, with a different Op)
| sdk-python | Ignis |
|---|---|
| core on its own tokio runtime | core on the shared tokio runtime, driven by the dispatcher task |
| `await poll_workflow_activation()` | `Op::TemporalPoll{worker}` → `Outcome::Activation(bytes)`; the polling fiber parks (C-park or `Ignis\Loop::awaitOp`) |
| `await complete_workflow_activation(bytes)` | `Op::TemporalComplete{worker, bytes}` → `Outcome::Completed` |
| private asyncio loop per workflow instance | one `Fiber` per workflow run + a **workflow-local ready queue** in PHP; `await activity()` = record a command and `Fiber::suspend()`; a later activation's job resolves it and re-queues the fiber |
| pyo3 bytes | `ignis_poll` array payload (bytes) and `zend_parse_parameters("s")` |

Replay test = run the same PHP workflow twice: once against the dev server, once from the recorded history
(`temporal workflow show --output json`) fed through core's replay worker (`init_replay_worker` in sdk-core).

## Facts that constrain tonight
- **crates.io `temporal-sdk-core` is a 2021 alpha** (0.1.0-alpha.1, edition 2018) with a different API; the
  maintained crate is the git repo only (`temporal-sdk-core = { git = "https://github.com/temporalio/sdk-core" }`).
  Git dependencies resolve through this session's git proxy. Building it pulls prost/tonic and needs `protoc`
  (installed from apt).
- **A Temporal server is required** for anything beyond replay. `go install github.com/temporalio/cli@latest`
  is refused (replace directives); building the CLI in-tree from a clone works (in progress). The CLI's
  `temporal server start-dev` is the dev server.
- PHP already has what the workflow side needs: fibers (deterministic by construction here: no real I/O in a
  workflow fiber), per-fiber state (`Ignis\Scope`), and the parked-fiber wake path.

## Surprises
- The sdk-python bridge is smaller than expected (~700 lines of Rust): the whole design rests on "poll returns
  bytes, complete takes bytes" — the same two-op seam the Ignis reactor already uses for HTTP.
- Determinism is a *userland* concern in sdk-python too (the private event loop); core only validates
  command sequences against history. So an Ignis workflow runtime is PHP code over two reactor ops.
