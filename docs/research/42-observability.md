# 42 — Held-resource / wait-age observability audit (M4-6)

2026-09-19, main agent, at commit `fa383b0`. Read-only: no code changed, no `git` command run
beyond inspection. Method: every claim below is a citation into the tree as it stands today, not a
description of intent — where a doc (ADR-0022, BACKLOG.md) already claims something is built, that
claim was checked against the current source and corrected where it no longer holds.

## The subject moved since M4-6 was written — read this before the table

M4-6's own text starts "After M4-1" and lists **pg lease** as one of the waits to inventory. Both
premises are gone:

- **M4-1 is deleted.** It measured hold-time on the *runtime-owned* PostgreSQL pool
  (`crates/ignis/src/pg.rs`, ADR-0015). That pool, its 697 lines of Rust, its 27 registered
  functions and its `ignis_pg_stats()` (`oldest_lease_ms`, `leases_over_warn`) were removed
  2026-09-18 when ADR-0015 closed (V-87): parked `pdo_pgsql` measured faster than the native
  client (V-86), and no framework used the native driver. `crates/ignis/src/pg.rs` no longer
  exists; confirmed with `test -f` before writing a line of this table.
- **"pg lease" survives only as a userland concern.** `S-POOL-LEASE-AGE` (BACKLOG.md:315) is about
  `php/packages/doctrine/src/Pool/ConnectionPool.php` — a pool that lives entirely in PHP, on the
  PHP side of the reactor boundary. The runtime cannot see it at all unless PHP publishes it
  through the one channel that already exists for this (`reactor.rs:152`, below). This is the
  single biggest way the inventory's shape differs from what M4-6 describes: for the old pg pool,
  age tracking was a Rust-side change; for the pool that exists today, it can only be a PHP-side
  change that opts into publishing.
- **ADR-0022's own table is now stale in one row.** Its contract table says "Hold-time on every
  lease: gauge while held, warn line at release past a threshold — **built for pg (V-44)**". V-44
  was the deleted pool. The row should read "unbuilt" today, same as its offload/stream/gRPC
  neighbours on the same line — worth a follow-up edit to `docs/adr/0022-observability-contract.md`
  when this note lands, since nobody should read that ADR and believe lease age is visible
  anywhere right now. It is not, for any pool, of any kind (see the table below).

## Method

Enumerated from five places, in the order the acceptance criterion and the task named them:
`grep -n "Op::" crates/ignis/src/reactor.rs` (the authoritative, exhaustive list of what the
reactor itself can be asked to wait for), `crates/ignis/src/php/park.rs` (what universal park adds
on top without a new `Op` variant), `crates/ignis/src/php/wait.rs` (the C-side registry that
resumes a parked fiber), `php/packages/runtime/src/Loop.php` (the PHP-side registry, and the waits
that never reach the reactor at all), and `crates/ignis/src/metrics.rs` + `crates/ignis/src/http.rs`
(what `/_ignis/metrics` already renders — 19 metrics today, not the 22 V-55 counted; see the note
under the table).

## Two things worth understanding before the table, because the table is dense without them

**1. The reactor's wait vocabulary is two primitives, not a list of resources.**
`Op` has exactly four variants (`crates/ignis/src/reactor.rs:18-31`): `Sleep`, `Watch`,
`CancelWatch`, `Custom`. `CancelWatch` is not a wait — it is the message that ends one
(`reactor.rs:254-262`); a fiber is never suspended "on" a `CancelWatch`. `Custom` is a grab-bag:
any tokio future that produces a plain-data `Outcome` (`reactor.rs:27-30`), used today by
streamed-response backpressure, the gRPC client, and every Temporal core-sdk call. So at the
reactor's own level there are really three wait shapes (timer, fd-readiness, arbitrary future), and
universal park (`park.rs`) does not add a fourth: `park_on` (`park.rs:296`), `park_io`
(`park.rs:339`) and `park_pollfds` (`park.rs:501`) all submit `Op::Watch`; `park_sleep`
(`park.rs:352`) submits `Op::Sleep`. Sixteen-odd interposed libc symbols
(read/write/recv/send/recvfrom/sendto/connect/accept/accept4/poll/ppoll/select/flock/sleep/usleep/
nanosleep, `park.rs:26-28`) collapse into the same two primitives `Ignis\sleep()` and
`ignis_watch()` already use. ADR-0037's mechanism budget holds: universal park is a new *policy*
for who gets to park, not a new wait.

**2. There are two disjoint parking registries, and neither one is timestamped.**
An op submitted from a PHP function call (`ignis_submit_sleep`, `ignis_watch`, `ignis_grpc_call`,
`ignis_temporal_*`, offload's `submit`) returns an id to PHP bytecode, which suspends the current
fiber itself: `Ignis\Loop::awaitOp()` stores `self::$waiting[$id] = $fiber` and calls
`Fiber::suspend()` (`php/packages/runtime/src/Loop.php:72-88`). An op triggered by universal park's
interposition of a raw libc call has no PHP frame to return to — the C shim is running inside an
*internal* function on the fiber's own C stack — so it suspends the fiber directly from Rust:
`wait.rs`'s `await_op`/`await_any` store `op id → *mut zend_fiber` in a thread-local `PARKED` map
and call `zend_fiber_suspend` (`crates/ignis/src/php/wait.rs:24-32, 40-63`). Same underlying Zend
fiber, two unrelated bookkeeping structures. **Neither stores a submission `Instant`.**
`Loop::$waiting` is `array<int, Fiber>` (Loop.php:31); `wait.rs::PARKED` is
`HashMap<u64, *mut zend_fiber>` (wait.rs:26). An id going in is timestamped nowhere. This is why
every row below that funnels through either registry lands in the "neither" column: the gap is not
missing plumbing to a metrics endpoint, it is a missing `Instant::now()` (or `hrtime(true)`) at the
one or two lines where each map gets its entry.

## The table

Columns: the wait, which `Op`/mechanism carries it, which registry parks the fiber (or "OS thread"
/ "PHP-only" for the two waits that never reach a fiber-park at all), and whether its **age** —
not its existence, not its count, its *age* — is visible. "Both" would need a metric and a log
line for the same number; nothing here reaches that bar.

| # | wait | `Op`/mechanism | parked in | visible in `/_ignis/metrics`? | visible in a log line? | category |
|---|---|---|---|---|---|---|
| 1 | timer sleep — `Ignis\sleep()`, `deadline()`, pool-wait timeout, chaos-yield, and native `sleep()/usleep()/nanosleep()` via park | `Op::Sleep` (`reactor.rs:19-21`, submitted at `reactor.rs:218-234`; park's path at `park.rs:352-360`) | PHP `$waiting` (explicit calls) or C `wait.rs::PARKED` (park) | no — `ignis_ops_inflight` (`metrics.rs:114`) is a total across every `Op` kind, not per-op, not age | no | **neither** |
| 2 | fd readiness — explicit `ignis_watch()` (stream_select shim, Revolt) and universal park's read/write/recv/send/connect/accept/poll/select/flock | `Op::Watch` (`reactor.rs:22-24`, dispatched at `reactor.rs:241-253`; `ignis_watch` at `module.rs:284-320`; park's three callers at `park.rs:296,339,501`) | PHP `$waiting` or C `wait.rs::PARKED` | no — same `ignis_ops_inflight` total only | no | **neither** |
| 3 | streamed HTTP response backpressure (server → client, channel full) | `Op::Custom` (`send_chunk`, `module.rs:545-563`, submitted at `module.rs:554`) | PHP `$waiting` (the id is returned to `ignis_respond_chunk`'s PHP caller) | no — `ignis_requests_inflight` (`metrics.rs:113`) counts the request as pending, not that its body write is specifically stalled, and carries no age | no | **neither** |
| 4 | gRPC client unary/streaming call | `Op::Custom` (`module.rs:634`, `grpc.rs:172-206`) | PHP `$waiting` | no | no | **neither** |
| 5 | gRPC client stream `recv` | `Op::Custom` (`module.rs:648`, `grpc.rs:209-228`) | PHP `$waiting` | no | no | **neither** |
| 6 | Temporal core-sdk RPC: connect, replay, poll/complete activation, poll/complete activity, shutdown | `Op::Custom` (`backend/temporal.rs:110-115` wraps every `zif_*` below it) | PHP `$waiting` | no | no (one `tracing::warn!` exists, `temporal.rs:327`, but only for a *failed* heartbeat, never for age) | **neither** |
| 7 | offload job run (worker executes a synchronous PHP function) | `reserve_op`/`complete`, same family as `Op::Custom` (`offload.rs:65-79, 116`) | PHP `$waiting` on the caller's side | no — `ignis_offload_stats()` (`module.rs:799`) returns `workers/busy/done/queued` but is not called by `metrics.rs::render()` at all, and carries no age even where an application calls it itself | no | **neither** |
| 8 | offload **callback** (worker asks the calling thread to run a closure, blocks for the answer) | `caller.inject(Outcome::OffloadCallback)` (`offload.rs:119-126`) | **neither fiber registry** — the offload *worker* thread blocks in a plain `crossbeam_channel::Receiver::recv()` (`offload.rs:126`); this is an OS thread block, not a parked fiber | no | no | **neither**, and architecturally distinct (see below) |
| 9 | Doctrine pool lease **acquisition** (fiber waiting for a free connection) | a `Future` plus an `Op::Sleep` timeout (`ConnectionPool.php:113-135`) | PHP `Future` internals, itself built on `Loop::awaitOp` | no | no | **neither** (self-bounded by `waitMilliseconds` — throws `PoolTimeoutException` rather than hanging, so less urgent than row 10) |
| 10 | Doctrine pool lease **hold** (a fiber has an acquired connection, including a fiber that never resumes) | none — `acquire()`/`release()` (`ConnectionPool.php:64-93`) record no `Instant` at all; a leased connection is just a PHP object the caller holds | not a park; the fiber may be running, parked elsewhere, or gone | no | no | **neither** — this is `S-POOL-LEASE-AGE` itself |
| 11 | fiber-budget admission queue (a request has arrived, no fiber exists for it yet) | none — `$requestQueue`/`$queued`/`$queuedPeak` (`Loop.php:764-777`) are counts, no per-entry timestamp | not a fiber wait at all — no `Fiber` object exists for a queued request | partially — `ignis_requests_queued`/`_peak` (`metrics.rs:130-131`) are published counts (`Loop.php:404-413`), but neither is an **age** | no | **neither** (counted, not aged) |
| 12 | client disconnect while a request is in flight | injected `Outcome::Cancelled { dropped_at }` (`reactor.rs:86-88, 384-390`) | not a wait the fiber chose — an interrupt delivered into whichever wait (1-10) the fiber was already in | no — `age_us` is computed at delivery (`module.rs:268`) and lands in `Loop::$cancelAgeUsMax`/`$cancelLatencyUsMax` (`Loop.php:1007-1009`), but `Loop::publishStats()` (`Loop.php:398-414`) does not include either field, so it never reaches `reactor.published` or `/_ignis/metrics` | no — only `examples/hello_server.php:100-102` exposes it, by hand, on its own `/stats` route | **neither**, but the cheapest possible fix in this whole table (see below) |
| 13 | `Op::CancelWatch` | control message, not a wait (`reactor.rs:26, 254-262`) | n/a | n/a | n/a | **not a wait** — listed only so the table is complete against the acceptance criterion's grep |

Grep-completeness: `grep -n "Op::" crates/ignis/src/reactor.rs` names four variants (`Sleep`,
`Watch`, `CancelWatch`, `Custom`) at lines 36-39 (the `Debug` impl) and 215-262 (the dispatch match)
— every one appears in the table above (rows 1, 2, 13, and 3-8 respectively for `Custom`'s several
call sites). Rows 9-12 are the waits that never produce an `Op` at all — required by the task's own
instruction to treat the reactor as a floor, not a ceiling, and confirmed against
`php/packages/runtime/src/Loop.php`'s `$waiting`/`$deadlines`/`$children`/`$requestQueue` state.

**Why "19 metrics today, not 22" (V-55) matters here.** `crates/ignis/src/metrics.rs::render()`
emits 19 named series (counted directly from the file: `ignis_build_info` through
`ignis_fiber_resumes_total`). V-55 recorded 22 at the time, explicitly including "pool leases" in
its list of what the Rust side already knew. Those were the deleted pg pool's `ignis_pg_stats()`
fields. The shrink from 22 to 19 is ADR-0015's removal working exactly as designed — nothing here
is a regression to fix — but it does mean the endpoint's own history is not a safe place to infer
that lease visibility still exists; it does not, for any pool.

## Proposed metrics — the minimum set, not one per row

The task asks for the minimum set that closes the "neither" rows, and to say where a gap cannot be
closed. Two facts shape the answer: rows 1-8 all reduce to "how long has this `Op` id been
outstanding," which the reactor can answer for all of them with one number; rows 10-12 never touch
the reactor and can only be closed by PHP publishing through the channel that already exists.

**Rust-observable (one gauge covers rows 1-7):**

- **`ignis_op_oldest_age_seconds`** (gauge). Add a monotonic `Instant` next to the id in whatever
  structure already tracks "outstanding" — `submit()`/`reserve_op()` (`reactor.rs:284-300`) are the
  two places an id is born; a small `BTreeMap<u64, Instant>` (id is already increasing, so the
  minimum key is the oldest) alongside `answers` (`reactor.rs:145`) gives "oldest outstanding op,
  any kind" for free at `poll()`/`complete()` time. This single number, summed as a max across
  `Totals::add` (`metrics.rs:169-187`) the way `oldest_publish_age_ms` already is
  (`metrics.rs:183-186`), closes rows 1 through 7 (sleep, fd-watch/park, streamed-response
  backpressure, gRPC call, gRPC recv, Temporal RPC, offload job) — seven rows, one metric, because
  they share one registry (the reactor's own id space) even though PHP script code never sees that
  sharing.
- **`ignis_offload_callback_oldest_age_seconds`** (gauge, Rust-observable). Row 8 needs its own
  metric because it is not in the reactor's id space at all — `offload::callback()`'s
  `PendingCallback` (`offload.rs:34-36`) has no `Instant` either; adding one there is the same
  five-line change, just in a different map, because the wait itself is architecturally different
  (an OS thread block, not a fiber park — see below).

**PHP-observable, published through the existing channel (`reactor.rs:152`'s `Published` struct,
fed by `ignis_publish_stats()` at `module.rs:354-386`, the same mechanism `Loop::publishStats()`
already uses for `budget`/`queue_depth`/etc., `Loop.php:398-414`):**

- **`ignis_requests_queue_oldest_wait_seconds`** (gauge). Row 11: record `microtime(true)` (or
  `hrtime(true)`) next to the id in `queueRequest()` (`Loop.php:764-777`) instead of only pushing
  `[$id, $raw]`, publish the oldest surviving entry's age in `publishStats()`.
- **`ignis_doctrine_pool_oldest_lease_seconds`** (gauge). Row 10, i.e. `S-POOL-LEASE-AGE`'s own
  proposal #1, unchanged in substance — an `Instant`/`microtime` recorded in `acquire()` and
  cleared in `release()` (`ConnectionPool.php:64-93`) — but routed through the *runtime's* publish
  channel instead of a bespoke field on a per-example `/stats` route, so it appears in
  `/_ignis/metrics` automatically rather than needing every application to wire it up by hand
  (which is the failure mode `M3-7` exists to close). This needs one small piece of plumbing this
  audit did not find already built: today only `Loop.php` calls `ignis_publish_stats()`; a
  userland package (`ignis/doctrine`) has no hook into that call. The cheapest shape is a static
  registry `Loop::registerStatsProvider(callable $provider): void` that `publishStats()` merges in
  each turn — a few lines, not a new mechanism, and the same shape `M3-7` needs anyway for
  `ignis_stats()`'s Rust-side counters plus every package's PHP-side ones to become one endpoint.
- **`ignis_requests_cancelled_age_us_max`** (or reuse the existing name, `cancel_age_us_max`, once
  it is a real metric rather than an example-only field). Row 12 needs no new instrumentation at
  all — `Loop::$cancelAgeUsMax`/`$cancelLatencyUsMax` already compute the number
  (`Loop.php:1007-1009`) — only a line added to `publishStats()`'s array (`Loop.php:404-413`) and a
  matching `metric()` call in `metrics.rs`. This is the cheapest fix in the entire table: the data
  already exists, only the wiring is missing.

That is five metrics closing eleven of the twelve "neither" rows (1-8, 10-12; row 13 needs none),
three of them Rust-side and free of new PHP-visible surface, two of them PHP-side and gated on one
small piece of shared plumbing (`Loop::registerStatsProvider`) that `M3-7` should build once, not
once per package. Row 9 (lease **acquisition** wait) is deliberately left out of the minimum set:
it is already self-bounded by `waitMilliseconds` and answers with a thrown `PoolTimeoutException`
rather than hanging, so it is the one "neither" row where the existing behaviour is closer to
correct than the others — a gauge for it is worth adding only if `PoolTimeoutException` frequency
in practice says otherwise, which is a question for measurement, not for this audit.

## Where the gap cannot be closed, at all, by either side

Two findings the task asked to state plainly rather than paper over:

1. **A stuck park cannot be named, only counted.** `ignis_op_oldest_age_seconds` tells an operator
   "some parked I/O has been waiting N seconds," never *which* URL, query, or peer. The C-side
   interposer (`park.rs`) resolves a call site to a *library* via `dladdr` (`park.rs:171-195`), not
   to a PHP-level identity — there is no PHP frame at the point of interposition to read a label
   from, and reaching for one would cross exactly the boundary ADR-0020 keeps closed (no Zend
   pointer crosses into the mechanism that decides park-vs-block). Turning "stuck" into "stuck on
   this request" needs the same request-identifying lookup `M4-7` is building
   (`Scope::get('ignis.request')`, read at the point a thread is judged stalled) — the two items
   should share that lookup rather than each inventing their own half.
2. **Row 8 (offload callback) has no watchdog at all today**, stuck or not. `main.rs`'s watchdog
   (`spawn_watchdog`, `main.rs:354-364`) walks `REGISTRY`'s reactors — the dispatch threads
   registered by `ignis_serve` — and offload worker threads never register there
   (`spawn_offload_workers`, `main.rs:295-318`, is a bare `std::thread::spawn` loop with no
   `Reactor` of its own). A metric on this row is an improvement over nothing, but unlike every
   other row in this table, nothing today would even notice the metric was climbing — that is a
   gap in the watchdog's coverage, not in this audit's proposed metric, and it is outside `M4-6`'s
   remit to fix.

## For the three items waiting on this

- **`S-POOL-LEASE-AGE`**: its own fix #1 is confirmed as the right shape (row 10) — nothing in this
  audit changes the diagnosis. The one addition: route it through `ignis_publish_stats()` /
  `Loop::registerStatsProvider` rather than a package-private field, so it lands in
  `/_ignis/metrics` directly. Its fix #2 (soft reaper) and fix #3 (runtime watchdog throwing
  `DeadlineExceeded`) are unaffected by anything found here.
- **`M4-7`**: the watchdog names the *thread* today (`main.rs:361`, `stalled`/`total` only) for
  every one of rows 1-10 uniformly — none of them carry a request id anywhere in the reactor or
  `wait.rs`. Whatever mechanism `M4-7` builds to attach a request id to a stalled thread is exactly
  the mechanism that would let "where cannot be closed" finding #1 above be closed later — build it
  once, expose it to both.
- **`M3-7`**: this audit found the shape `M3-7` already assumed — every example hand-builds a
  different `/stats` (`examples/app.php:146-150` merges `budgetStats()` plus three fields;
  `examples/hello_server.php:95-106` merges nine, including the two cancel-age fields no other
  example has) — and adds one concrete requirement: whatever `M3-7` builds needs a way for a
  **package** (`ignis/doctrine`, and any future one) to contribute fields, not only the runtime's
  own `ignis_stats()` and `Loop`'s own counters. `Loop::registerStatsProvider` above is the
  minimum shape; `M3-7` should build it rather than this audit inventing a parallel one.

## What could not be classified, and why

Nothing in the table above is unclassified — every row's answer came from reading the code that
implements it, not from inference. Two things this audit did **not** attempt to classify because
they are not waits a fiber can be in:

- `Loop::$phaseNs` (`Loop.php:66-69`) — cumulative nanoseconds per loop phase (`start`/`ready`/
  `poll`/`resume`). It is a throughput diagnostic, not a per-wait age, and nothing reads or
  publishes it today; noted here only so a future pass does not rediscover it as a stray field.
- The dev-reload worker rotation (`ignis_watch_begin_reload`/`end_reload`, `watch.rs:187-209`) —
  a worker that loses the race for the one reload slot goes back to `ignis_poll(-1)` and retries on
  the next wake, which is polling, not parking; there is no `Op`, no fiber suspension, and nothing
  in the `Op::Watch`/`ignis_watch()` family despite the shared name. Out of scope for the same
  reason `CancelWatch` is listed but not counted as a wait.
