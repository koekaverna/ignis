# ADR-0040 — Temporal: run the official PHP SDK on sdk-core, and put the adapter where it belongs

Status: **accepted** (owner, 2026-09-17; V-61). Replaces the prototype userland of ADR-0013 — its
Rust half (sdk-core on the shared tokio runtime, `Op::TemporalPoll`/`TemporalComplete`) stands
unchanged and is what this builds on. Measurements in `docs/research/35-sdk-php-on-ignis.md`.

## Context

ADR-0013 shipped a workflow runtime of our own: `php/temporal/ignis-temporal.php`, ~250 lines,
workflows as fibers, activities as fibers, replay proven with a negative control (V-19). It was
accepted "for the prototype scope" and it has stayed there. What it does not have is everything an
application actually needs: signals, queries, updates, child workflows, cancellation scopes, local
activities, heartbeats, retry policies, data codecs, versioning, interceptors, `continue-as-new`.
Writing those is writing a Temporal SDK, and one already exists — `temporalio/sdk-php`, whose only
tie to RoadRunner turns out to be a default argument.

## What was measured before deciding

- **The transport is injectable.** `WorkerFactory::run(?HostConnectionInterface $host = null)`;
  `RoadRunner::create()` is the default, not a requirement. sdk-php **v2.19** loaded under our embed
  SAPI, ran its own loop on our host and reported its registered workflow and activity.
- **So is the wire format.** `WorkerFactory::$codec` is `protected`. With our own `CodecInterface`
  the frames are plain arrays: no protobuf on the wire, and no dependence on RoadRunner's frame
  layout. (For contrast: the stock `JsonCodec` needs `Payloads` wire bytes, which cost **53.45 µs**
  per payload in pure PHP — our own JSON boundary is 0.90 µs. Payload *objects* are still built, so
  the DataConverter keeps owning encode/decode; only the serialize/parse round trip is gone.)
- **The MVP needs no Rust change.** `ignis_temporal_poll()` already returns the whole
  `WorkflowActivation` as JSON and `ignis_temporal_complete()` already accepts the four commands a
  workflow with activities and timers emits.
- **It works end to end.** A stock sdk-php workflow — attributes, activity stub, `yield`,
  `Workflow::timer()` — drives start → activity → timer → completion → eviction against recorded
  sdk-core activations (V-61).

## Decision

1. **The official SDK is the userland.** Workflows and activities are written with
   `temporalio/sdk-php`; Ignis supplies the host. `php/temporal/ignis-temporal.php` stays only as
   the reference the replay test was built on and is not developed further.
2. **The adapter is a portable package, not Ignis glue.** `php/temporal/core/` holds
   `Temporal\Worker\Transport\Core\{ActivationSource, CoreCodec, CoreHost, CoreWorkerFactory}` and
   depends on nothing from this runtime. This is the owner's call and the important half of the
   decision: the code translates *sdk-php's own* command model, so sdk-php is its natural owner, and
   it is written so it can be offered upstream without being rewritten. PHP is the only Temporal SDK
   not sitting on sdk-core; this is the piece that is missing there.
3. **One port, two methods.** `ActivationSource::poll(kind)` / `complete(kind, json)` is the entire
   surface a host must implement; `php/temporal/sdk.php` does it in ~40 lines over the existing
   reactor ops. `bench/e20-sdkphp.sh` runs the conformance test under the ignis binary **and** under
   the stock PHP CLI, so "host-agnostic" is a gate, not a claim.
4. **Concurrency is a pool of factories, one per fiber.** `WorkerFactory` accumulates the responses
   of one dispatch in its own state, so two batches must never overlap inside one factory. One
   workflow factory (workflow code never waits on real I/O) and N activity factories, each in its
   own fiber. This is RoadRunner's worker pool with fibers instead of processes — an activity that
   waits on a database costs a parked fiber here and a whole OS process there.
5. **An untranslated command is an exception, never a silent drop.** `CoreCodec::encode()` throws
   by name for anything outside the translated set, because a dropped command is a workflow that
   hangs until its task timeout — a far worse bug to find.

## Options considered

- **Grow our own runtime** (status quo). Rejected: it is writing a second PHP SDK, and the features
  it lacks are exactly the ones real applications start with.
- **Reuse RoadRunner's frame format too.** Rejected once seam B was measured: it buys nothing and
  costs the protobuf round trip plus a dependency on a byte layout that is private to two other
  projects.
- **Adapter as Ignis-specific glue.** Rejected by the owner, correctly — see decision 2.
- **Rebuild sdk-php's engine to consume activations directly**, dropping its `ServerRequest`/
  `Request` layer. Rejected: that rewrite breaks RoadRunner for everyone else and no such PR is
  acceptable upstream.
- **Link the Go SDK through cgo.** Rejected: a second runtime with its own garbage collector and
  threads inside our process, against everything ADR-0012 and the fork/threading invariants assume.
- **RoadRunner as a sidecar.** Not rejected — it is the honest answer for anyone who needs Temporal
  in production before this is finished, and the documentation says so.

## Consequences

Better: signals, queries, updates, child workflows, saga, interceptors, data converters and
versioning arrive as sdk-php features rather than as our backlog; `php/temporal/ignis-temporal.php`
stops growing; the adapter is testable with recorded activations and no server.

Worse: we now depend on sdk-php's **internal** command model. `HostConnectionInterface` and
`CodecInterface` are public; the set of route names and the shape of `options` are not, and nothing
upstream promises they are stable. The mitigations are a pinned version, the conformance test as a
gate, and offering the package upstream so the coupling becomes theirs to maintain.

Also worse, and listed so nobody discovers it in production: queries, updates, cancellation, child
workflows, local activities, heartbeats and `SideEffect`/`GetVersion` are **not translated yet** —
each needs one arm in `CoreCodec` and, for those with no counterpart in our Rust translation layer,
one `workflow_command::Variant` in `backend/temporal.rs`. `ext-grpc` is absent, so sdk-php's
`WorkflowClient` cannot start workflows from PHP here; starting belongs on the Rust side, which
already links `temporalio-client`.

## Kill criterion

If a minor release of `temporalio/sdk-php` breaks the adapter twice in a row without a matching
upstream change we can follow — that is, if the private contract proves to move faster than we can
track it — this ADR is wrong about the risk, and the answer becomes RoadRunner as a sidecar (the
option kept above) rather than a third attempt at our own runtime.
