# Research 35 — running temporal/sdk (the official PHP SDK) on Ignis

Date: 2026-09-17. Question from the owner: can we take the PHP SDK instead of growing our own
workflow runtime, and what does it cost? Everything below was run, not read — the probes are in this
session's scratchpad and are reproduced in `bench/e20-sdkphp.sh`.

## 1. The two seams, measured

`temporal/sdk` **v2.19** installed with composer (in docker: our PHP build has no `ext-phar`, so
composer cannot run under it — only `vendor/` is needed, and the autoloader is plain PHP).

**Seam A — the transport.** `WorkerFactory::run(?HostConnectionInterface $host = null)`;
`RoadRunner::create()` is only the default. The interface is three methods: `waitBatch(): ?CommandBatch`,
`send(string $frame)`, `error(\Throwable)`. Probe: a host that feeds one `GetWorkerInfo` frame and
ends. Run **inside `target/release/ignis`**:

```
run() exit=0
TaskQueue : ignis
Workflows : [{'name': 'GreetWorkflow', 'queries': [], 'signals': [], 'versioning_behavior': 0}]
Activities: [{'Name': 'greet'}]
```

sdk-php loads under the embed SAPI, drives its own loop on our transport, and reports the workflow
and activity it found by attribute. The workflow body was stock sdk-php — `yield $a->greet($name)`.

**Seam B — the codec.** `WorkerFactory::$codec` is `protected` (the `createCodec()` that chooses
Json/Proto from `$_SERVER['RR_CODEC']` is private, but the property is not), so a subclass can
install its own `CodecInterface`. Probe with a codec that speaks plain arrays:

```
[0] PHP -> host: [{"type":"SuccessClientResponse","payloads":[{"TaskQueue":"ignis","Options":{…}}]}]
[1] PHP -> host: [{"type":"FailedClientResponse","failure":"Method \"InvokeActivity\" is not registered"}]
```

The first frame is our format, our field names, **no protobuf anywhere**. The second is the probe's
own fault — the frame carried no task-queue info, so the factory-level router (which knows only
`GetWorkerInfo`) answered instead of the worker's. That failure is the useful result: **what binds us
is the command model and the shape of `options`, not the byte format.**

## 2. What the protobuf would have cost (and why we skip it)

The stock `JsonCodec` keeps frames as JSON but requires `payloads` to be wire bytes of
`temporal.api.common.v1.Payloads`, decoded with `google/protobuf`. Our build has no `ext-protobuf`,
so that is the pure-PHP implementation. Same value (54 bytes on the wire), 10k iterations, our
binary:

| boundary | encode | decode | round trip |
|---|---|---|---|
| `Payloads` in pure PHP | 35.91 µs | 17.53 µs | **53.45 µs** |
| our own JSON payload boundary (`Payloads::encode` in `php/packages/temporal-prototype/src/ignis-temporal.php`) | 0.35 µs | 0.55 µs | **0.90 µs** |

59× — and against a workflow task that costs a network round trip to the Temporal server (V-19:
1224 ms for 5 activations) it is noise either way. It is skipped because seam B makes it free to
skip, not because 53 µs would have hurt.

## 3. The command model we have to speak

sdk-php's worker is a request/response machine over batches. Two directions:

**Host → PHP (`ServerRequest`, dispatched by `Internal/Transport/Router/*`), 10 routes:**
`GetWorkerInfo`, `StartWorkflow`, `InvokeActivity`, `InvokeLocalActivity`, `InvokeSignal`,
`InvokeQuery`, `InvokeUpdate`, `CancelWorkflow`, `DestroyWorkflow`, `StackTrace`.

**PHP → host (`Internal/Transport/Request/*`), 17 requests:**
`ExecuteActivity`, `NewTimer`, `CompleteWorkflow`, `Panic`, `SideEffect`, `GetVersion`,
`ExecuteChildWorkflow`, `GetChildWorkflowExecution`, `ExecuteLocalActivity`, `ContinueAsNew`,
`Cancel`, `CancelExternalWorkflow`, `SignalExternalWorkflow`, `UpsertMemo`,
`UpsertSearchAttributes`, `UpsertTypedSearchAttributes`, `UndefinedResponse`.

**The correlation model is the part that is not a rename.** sdk-php gives every outgoing request a
single monotonic **id** and expects the host to answer that id later with a success or failure
response. sdk-core instead numbers commands by **`seq`, per command kind** (`ScheduleActivity.seq`,
`StartTimer.seq`), and reports resolutions as activation *jobs* (`resolveActivity`, `fireTimer`).
So the translator keeps, per run, `id → (kind, seq)` and turns:

| sdk-core activation job | what PHP must receive |
|---|---|
| `initializeWorkflow` | `ServerRequest('StartWorkflow', options.info = WorkflowInfo, payloads = args)` |
| `resolveActivity{seq}` | `SuccessResponse`/`FailureResponse` **with the id PHP used for `ExecuteActivity`** |
| `fireTimer{seq}` | `SuccessResponse` with the id PHP used for `NewTimer` |
| `removeFromCache` | `ServerRequest('DestroyWorkflow')` |
| `signalWorkflow` / `queryWorkflow` / `doUpdate` | `InvokeSignal` / `InvokeQuery` / `InvokeUpdate` |
| an activity task from `poll_activity_task` | `ServerRequest('InvokeActivity')`, answered with the task token |

and the reverse for the four MVP commands:

| sdk-php request | our existing `PhpCommand` (translate_completion, `backend/temporal.rs`) |
|---|---|
| `ExecuteActivity{name, options}` | `ScheduleActivity{seq, activity_type, task_queue, args, start_to_close_sec}` |
| `NewTimer{ms}` | `StartTimer{seq, ms}` |
| `CompleteWorkflow{values}` | `CompleteWorkflow{result}` |
| `Panic{failure}` | `FailWorkflow{message}` |

`StartWorkflow`'s `options['info']` is unmarshalled into `Temporal\Workflow\WorkflowInfo`, so its
keys are fixed by that class's `#[Marshal]` names: `WorkflowExecution{ID,RunID}`,
`WorkflowType{Name}`, `TaskQueueName`, `Namespace`, `Attempt`, `WorkflowExecutionTimeout`,
`WorkflowRunTimeout`, `WorkflowTaskTimeout`, `HistoryLength`, `HistorySize`, `CronSchedule`, …

## 4. Why the MVP needs no Rust change

`ignis_temporal_poll()` already returns the **whole `WorkflowActivation` as JSON** (serde over the
proto, `backend/temporal.rs:255`), and `ignis_temporal_complete()` already accepts exactly the four
commands above. So the translator is PHP, on top of functions that exist, and the Rust side is
touched only when a fifth command type is needed (`SideEffect`, `GetVersion`, child workflows …) —
one `workflow_command::Variant` arm each.

That also keeps the work outside `crates/ignis/src/php/**` entirely, which is where the FFI guard
and the `unsafe` review cost live.

## 5. Risks, stated before building

- **The command model is sdk-php's internals.** `HostConnectionInterface` and `CodecInterface` are
  public; the set of route names and the shape of `options` are not. They change on minor versions
  without notice, and nothing in sdk-php promises otherwise. Mitigation is a pinned version plus a
  conformance test that fails loudly (`bench/e20-sdkphp.sh`), not hope.
- **Re-entrancy.** `WorkerFactory` accumulates `$this->responses` inside one `dispatch()`. If an
  activity body parks mid-dispatch and another batch is pulled on the same factory, that state
  interleaves. The MVP therefore runs one batch at a time; concurrency comes from a *pool of
  factories* (one per fiber), which is RoadRunner's worker pool with fibers instead of processes.
- **`ext-grpc` is absent**, so sdk-php's `WorkflowClient` (starting workflows from PHP) does not
  work here. The runtime already links `temporalio-client` on the Rust side, so starting a workflow
  belongs there as an op — not in scope for the MVP.
- **`ext-protobuf` is absent**; with seam B we never need it, but anything that reaches for the
  stock `JsonCodec`/`ProtoCodec` (a future sdk-php internal that bypasses ours) would pay 53 µs per
  payload or fail.

## 6. Where the translation lives — the owner's correction

The first draft of this note put the translator in our repository, as Ignis glue. The owner's
objection is the right one: **the thing being translated is sdk-php's own internal model**, so
sdk-php is its natural owner. If a route is renamed there, the adapter should break there, in their
CI, not silently rot here. PHP is also the only Temporal SDK that does not sit on sdk-core — .NET,
Ruby and Python all do, which is what `temporalio-sdk-core-c-bridge` exists for — so a core
transport is a missing piece of their architecture rather than a favour to us.

Upstream acceptance cannot be waited on, so the code is written to be *movable* instead: it is a
composer package, `ignis/temporal-core-transport` in `php/packages/temporal-core-transport/` — its own `composer.json`,
PSR-4 `Temporal\Worker\Transport\Core\` over `src/`, `temporal/sdk` as its only dependency, its own
README and conformance test. It depends on nothing from Ignis and reaches its host through one
interface with two methods:

```php
public function poll(string $kind): ?string;             // JSON WorkflowActivation / ActivityTask
public function complete(string $kind, string $json): void;
```

Ignis implements that port in `php/packages/temporal/src/CoreSource.php` (~40 lines over `ignis_temporal_*`). Anything
else that can produce activations — a PECL extension, sdk-core's C bridge, a file of recorded
activations — works unchanged. That is what `bench/e20-sdkphp.sh` proves by running the conformance
test twice, once under the ignis binary and once under the stock PHP CLI.

## 7. Result

`php/packages/temporal-core-transport/tests/conformance.php` feeds a recorded script of sdk-core activations to a **stock**
sdk-php worker — attributes, `Workflow::newActivityStub()`, `yield`, `Workflow::timer()` — and
asserts the completions. Green under both hosts (V-61):

| | |
|---|---|
| `InitializeWorkflow` → | `ScheduleActivity{seq:1, activity_type:"greet", start_to_close_sec:5}` |
| activity task → | result `"Hello, Ada!"`, task token echoed |
| `ResolveActivity{seq:1}` → | `StartTimer{seq:2, ms:1000}` |
| `FireTimer{seq:2}` → | `CompleteWorkflow{result:"HELLO, ADA!"}` |
| `RemoveFromCache` → | no commands |

Two defects found and fixed while getting there, both worth recording because they are the seams
nobody documents: `StartWorkflow`'s `options['info']` must carry the `#[Marshal]` names of
`Temporal\Workflow\WorkflowInfo` with nanosecond timeouts; and sdk-php builds its own responses with
`EncodedValues::fromValues()` and **no** data converter, so `toPayloads()` throws unless the
transport calls `setDataConverter()` first.
