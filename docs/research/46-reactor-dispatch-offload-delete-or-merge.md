# 46 — Reactor, dispatch and offload: what deletes rather than what gets fixed

2026-09-19, agent, at commit `e39a5f6`. Read-only: no code changed, no writing `git` command run.
Scope: `crates/ignis/src/reactor.rs`, `http.rs`, `grpc.rs`, `offload.rs`, `main.rs`, `metrics.rs`,
`php/packages/runtime/src/Loop.php`. Question: which open defects in this territory disappear if
the path is removed or merged rather than guarded, and which genuinely need to stay separate.

Method: every claim below is read against the file and line it cites today, not against what the
backlog item says was true when filed — several items in this territory turned out to already be
fixed by an intervening commit and the backlog text is stale. That is reported explicitly per row,
because the honest answer to "does this disappear" is sometimes "it already did."

## Headline answer before the table

Of the items named in the brief, **four of R-REVIEW-CHORES's Rust items are already done** — not
just plausibly fixed elsewhere, but done *by name*: commit `d8d0b5c`
("refactor(rust): R-REVIEW-CHORES, fn main split into named methods, and 45 new tests," 2026-09-17)
says in its own message: "`http::handle`'s two `/_ignis/` ifs are one match above the gRPC check;
`CancelOnDrop` moved to module scope; the header-and-uri extraction duplicated between `http.rs`
and `grpc.rs` is one generic `request_parts<B>` ...; `grpc::is_grpc` is generic and its test-only
wrapper is gone." That same commit also says, explicitly, why the four allocation items were left
alone: "they are hot-path claims and this project's rule is numbers or it didn't happen, so they
need a before/after on a quiet box and their own V-n" — so those four are not stale, they are
*correctly* still open, deferred on purpose. `R-REVIEW-CHORES`'s BACKLOG.md text was never updated
to reflect any of this — it still lists all of it as "suspected unless marked." **One item pair is
the same defect filed twice** in addition to the one the brief already knew about (R-ANSWER-MAP /
S4-ANSWER-MAP): M4-3 and S1-CAP. The one genuine "delete a path" finding is `Loop::spawn`'s double
closure, which sits on `Ignis\all()`'s hot path (E2's own bench) and is a real line deletion, not a
refactor. Nothing here proposes a fourth mechanism; nothing here touches `Op`'s four variants.

## The ranked table

| # | item | verdict | backlog ids closed | measured line delta | kill criterion |
|---|---|---|---|---|---|
| 1 | `zif_ignis_poll`'s four repeated zeroed/array/fill/update blocks (`module.rs:245-278`) | **merge** | R-REVIEW-CHORES | not built; estimated -15 to -20 in `module.rs` (four ~8-line arms behind one `unsafe fn push_kv_array`) | `cargo nextest run -p ignis` (module.rs has a unit-testable shape once extracted) + E2 unmoved (this is the per-completion dispatch path `ignis_poll` runs every tick) |
| 2 | `zif_ignis_poll`'s `Slept`/`Ready` guard arms double-locking `wait::PARKED` (`is_parked` then `resume_parked`, `module.rs:233-240`) | **merge** | R-REVIEW-CHORES | ~neutral line count (4 arms -> 2), removes one `HashMap` lookup per parked completion | E2 (3.83 µs warm fiber switch, V-4) unmoved on a quiet box |
| 3 | `Loop::spawn`'s double closure for an attributed child fiber (`Loop.php:191-212`, `attributedToRequest` at `:243-249`) | **delete** | R-REVIEW-CHORES | measured against current source: -7 (`attributedToRequest` removed), -4 (`spawn`'s branch collapses to always passing `$arguments` through), +2 (`poolBody` sets `Scope::set('ignis.request', $requestId)` itself before calling `$function`) = **net -9 in `Loop.php`**, one fewer closure allocation per `Ignis\async()`/`Ignis\all()` call | `bench/php/e2_all.php` (E2: `per_fiber_us_warm`, V-4 baseline 4.38-4.56 µs) must not regress; this is exactly the path that closure runs on |
| 4 | `Registry::pick` nested locking (`http.rs:67-88`) | **keep, optimize — not a deletion** | R-ANSWER-MAP / S4-ANSWER-MAP (second half) | the map merge (row 5) already landed at **+51 net lines** (measured, see below); the remaining `AtomicUsize` swap is estimated ~neutral (+1 field, ~4 increment/decrement sites, `pending_requests()` drops from a `Mutex<HashMap>::len()` to an atomic load) | `bench/wrk-hello.sh` on a quiet box against V-82's ±6.7 % band, plus E4/E10/E11 before/after — explicitly deferred by the owner's own note in S4-ANSWER-MAP for exactly this reason |
| 5 | `Reactor`'s three answer maps (`responders`/`stream_out`/`streams`) | **already merged — DONE, not a pending candidate** | R-ANSWER-MAP = S4-ANSWER-MAP (confirmed one defect, filed twice) | **measured, historical:** commit `d960280` — 92 insertions, 41 deletions in `reactor.rs` alone, **net +51 lines**, not a reduction. Verified live: current `reactor.rs:123-156` has exactly one `answers: Mutex<HashMap<u64, Answer>>` with `enum Answer { Whole, Streaming, Grpc }`, `take_answer` checks the variant before removing, `cancel_request`/`fail_pending`/`pending_requests` are each one map operation. | already gated by the existing reactor test suite (`reactor.rs:539-779`, 13 tests including `a_streamed_answer_is_still_a_pending_request`, the V-75 regression test) |
| 6 | offload `JOBS` entry leak on a worker's mid-job death, `CALLBACKS` leak + unbounded `rx.recv()`, unbounded queues | **already fixed — DONE** | A-LEAKS-RUST (b), (c) | landed 2026-09-19 per the backlog's own note; verified live in `offload.rs`: `RunningJob{caller,op,worker}` (`:40-44`), `worker_gone()` (`:159-168`), `CALLBACK_TIMEOUT = 30s` (`:63`, used at `:172-186`), both queues `bounded(QUEUE_CAPACITY)` (`:77-79`) with `try_send`/explicit "queue is full" (`:100-108`) | already gated: `a_worker_that_dies_mid_job_fails_its_caller_and_releases_the_op`, `a_full_queue_is_refused_explicitly_and_releases_the_op`, `a_callback_the_caller_never_answers_times_out_and_is_cleaned_up` (`offload.rs:277-334`) |
| 7 | temporal `WORKERS` map, entries never removed (`backend/temporal.rs:42,82`) | **fix, trivial — not a deletion** | A-LEAKS-RUST (a), still open | +2/-0 estimated (`WORKERS.lock_unpoisoned().and_then(\|m\| m.get_mut()...remove(&id))` inside `zif_shutdown`, `:342-355`) | no bench exists for this arm today either — same gap the backlog names: "the bench arm the acceptance asks for does not exist." A minimal one: create N workers, shut each down, assert the map's `len()` returns to 0 via a unit test in `backend/temporal.rs` (a normal `#[test]`, no engine needed for the map logic alone) |
| 8 | offload `JOBS` + `CALLBACKS` merged into one map (the lead's suggestion, "ask whether the same consolidation applies") | **keep separate** | none — this closes nothing, it was a hypothesis to check | 0 (not proposed) | n/a — the reasoning is the finding |
| 9 | `M4-3` connection cap and `M3-7` `/stats` consolidation, mechanism vs. exposure | **keep + finish wiring** | M4-3, S1-CAP (confirmed same defect filed twice, see below), M3-7 | mechanism already built at 0 extra lines (see below); the metric is unbuilt: ~+6 lines (`AtomicUsize` counter in `accept_loop`, one `metric()` call); M3-7 itself unbuilt at all | M4-3's own bench, extended: `bench/b1-budget.sh` part (3) does not currently touch `IGNIS_MAX_CONNECTIONS` at all (checked: no reference in the script) — needs building, not just re-running |
| 10 | `http::handle`'s two `/_ignis/*` routes | **already one `match`, not two `if`s — DONE** | R-REVIEW-CHORES | 0 (already merged, `http.rs:377-381`) | none needed |
| 11 | `CancelOnDrop` declared inside the function body | **already module-scope — DONE** | R-REVIEW-CHORES | 0 (already at `http.rs:358-371`, outside `handle`) | none needed |
| 12 | header+URI extraction duplicated between `http.rs` and `grpc.rs` | **already shared — DONE** | R-REVIEW-CHORES | 0 (`grpc.rs:134` calls `crate::http::request_parts`, the same function `http.rs:376-391` and `grpc.rs:65-72`'s siblings share `request_parts` at `http.rs:47-52`) | none needed |
| 13 | `grpc::is_grpc` "a wrapper that exists for a test, should be generic" | **already generic — DONE** | R-REVIEW-CHORES | 0 (`pub fn is_grpc<B>(request: &http::Request<B>) -> bool`, `grpc.rs:65`, generic over the body type already) | none needed |
| 14 | `throwInto`'s linear scan of `$waiting` as a fallback (`Loop.php:1044-1051`) | **keep, documented tradeoff** | R-REVIEW-CHORES | 0 — not proposed | the backlog's own text already names the right test: a disconnect storm, not correctness |
| 15 | `Output.php`'s stale docblock ("neither path streams") | **fix, one line, out of this slice's files** | R-REVIEW-CHORES | -0/+0 (doc text only) | none — not measured, not in `reactor.rs`/`http.rs`/`grpc.rs`/`offload.rs`/`main.rs`/`metrics.rs`/`Loop.php` |
| 16 | `IgnisWorkerRunner::toSymfony` double `Request` construction, `Ignis\Http\Request`'s triple `?` scan | **not re-verified here** | R-REVIEW-CHORES | not measured | out of this slice — Symfony/HTTP-Request packages, not the reactor/dispatch/offload core named in the brief |

## What "already done" means for the total line delta

Summed only over what this note can measure against the current tree (rows 5, 6, 10-13 are
already-landed and contribute their historical deltas or zero; rows 1-4, 7, 9 are proposed and
unbuilt):

- **Already banked, historical:** reactor map merge, +51 net (`d960280`) — a size *increase* that
  bought correctness (V-75's bug class becomes structurally impossible) and the R-REVIEW-CHORES
  Rust items that turned out to already be fixed, 0 net (they were fixed as part of other commits,
  not additional work).
- **Proposed, unbuilt, estimated by reading the exact code (not guessed):** row 1 (-15 to -20), row
  2 (~0), row 3 (**-9**, the one clean deletion), row 4 (~0, a lock-contention fix not a size
  change), row 7 (+2), row 9's metric (+6).
- **Net across every proposed-but-unbuilt row: roughly -16 to -21 lines**, concentrated entirely in
  one real deletion (`Loop::spawn`'s wrapper closure) and one Rust consolidation
  (`zif_ignis_poll`'s repeated array-building block). Everything else in this territory that still
  needs code is a leak fix or a lock-contention fix, not a line-count win — consistent with the
  brief's own warning that moving code is not reducing it.

## Two "filed twice" pairs, not one

The brief already suspected R-ANSWER-MAP and S4-ANSWER-MAP. Confirmed: `BACKLOG.md:297`'s own text
says "Already filed as R-ANSWER-MAP," and both entries carry the identical `PARTLY DONE` status
line and the identical remaining work (the lock-free `Registry::pick`). They are one item.

A second pair, not asked about but found while reading M4-3: **M4-3 and S1-CAP are the same
item.** `S1-CAP`'s own title is `Cap concurrent connections at the listener (M4-3/B8)`
(`BACKLOG.md:240`) — it names M4-3 in its own header, the way S4-ANSWER-MAP names R-ANSWER-MAP. Both
describe `IGNIS_MAX_CONNECTIONS` / `[limits] connections`, both cite the same V-37 33 kB-per-connection
number, both are marked `open`. Worth closing one into the other the same way the owner will
presumably close S4-ANSWER-MAP into R-ANSWER-MAP.

**And the mechanism both describe is already built, twice over.** `http.rs:223-225`
(`max_connections()`, reading `IGNIS_MAX_CONNECTIONS`, default 8192) and `accept_loop`
(`http.rs:229-258`) already gate every accepted connection on `Arc<Semaphore>::try_acquire_owned()`,
answering a bare `503 Service Unavailable` and closing the socket when the cap is hit
(`refuse_over_capacity`, `http.rs:263-267`) rather than queueing it — landed in `f885b88` ("feat(ops):
`/_ignis/metrics`, a startup banner for `serve`..."). And `config.rs:64,169-170` wires
`[limits] max_connections` in `ignis.toml` through to that same `IGNIS_MAX_CONNECTIONS` variable
(`d8d0b5c`, the same commit that deleted the "`// Future ignis.toml key: limits.max_connections`"
TODO comment and filed it properly), with its own tests (`config.rs:297-313`) proving both the
default and the env-var-wins-over-toml precedence. Neither commit's message references M4-3 or
S1-CAP by id. What has **not** landed, and is the only real remaining work behind either id: the
cap is invisible in `/_ignis/metrics` (no `ignis_connections`/`ignis_connections_limit` gauge
anywhere in `metrics.rs`, confirmed by reading its full `render()`), and `bench/b1-budget.sh` does
not exercise `IGNIS_MAX_CONNECTIONS` at all (confirmed: no reference to the variable in the script)
— so M4-3's own acceptance criterion ("`bench/b1-budget.sh` part (3) extended... p99 of accepted
requests unchanged") has never been run. The mechanism is not a fourth wait or a new lock; it is
one `AtomicUsize` next to `connection_permits` and one `metric()` line — a small addition, not a
path to remove, and it is the one place in this table where the finding is "the code already
solves the problem the backlog describes, at two separate layers, but nobody told the backlog or
the bench."

## Where "delete/merge" was checked and rejected — say why

**Offload's `JOBS` and `CALLBACKS`, versus temporal's `WORKERS`.** The lead in the brief asks
whether the reactor's three-maps-one-id consolidation applies here too. It does not, for a reason
that mirrors R-ANSWER-MAP's own "what must stay separate" clause:

- `JOBS: HashMap<u64, RunningJob>` is keyed by job id and lives for the job's whole run (submit to
  `done()`/`worker_gone()`).
- `CALLBACKS: HashMap<(u64, u64), PendingCallback>` is keyed by `(job id, callback sequence)` and
  lives only for one mid-job round trip — a single job can have zero, one, or many callbacks over
  its life (`offload.rs:171-186`), each opening and closing its own `CALLBACKS` entry while `JOBS`'s
  entry for that job stays untouched throughout.

Folding `CALLBACKS` into `RunningJob` would need a nested collection per job — the same two-level
shape, not fewer maps, because the key domains genuinely differ (one id vs. a pair) and the
lifetimes genuinely differ (whole-job vs. one-round-trip). This is the same shape of answer
R-ANSWER-MAP already gives for gRPC's trailers: two things that are both "a pending op with
cleanup-on-death" look alike from a distance and are not the same state machine up close. Both maps
were independently hardened against the actual leak (A-LEAKS-RUST b, c, row 6 above) without
touching this structural question, which is the correct order: fix the leak, then ask whether the
shape was ever the problem. It wasn't.

**`temporal.rs`'s `WORKERS`** is a single flat map with no sibling to merge into — row 7 above is a
leak fix (one `remove()` call in `zif_shutdown`), not a consolidation candidate. It is also
`crates/ignis/src/backend/` and contains `unsafe`, so `.claude/hooks/guard-ffi.sh` blocks a
subagent from touching it; this note only reports the finding, per its own read-only charter.

**`Loop.php`'s other state maps** (`$requestFibers`, `$children`, `$deadlines`, `$deadlineOf`,
`$queued`, `$parkedOn`, `$waiting`, `$ready`, `$pending`, `$idle`, `$queueCancelled`, `$watched` —
twelve `private static array` fields, `Loop.php:13-677`) were checked against the same question and
rejected for the same reason: they are keyed on at least three different domains (request id,
op id, `spl_object_id($fiber)`) and several are queues, not lookup tables (`$ready`/`$pending` are
ordered job lists, not indexed by id at all). Nothing here suggested a "one id, several maps"
pattern the way `Reactor`'s three answer maps did; this was checked, not assumed, and the answer is
keep as they are.

## What must not move (the bench that proves each change safe)

- Row 3 (`Loop::spawn`) sits directly on `bench/php/e2_all.php`'s path — every `Ignis\all()` call is
  three `Loop::spawn`s, and with a request id in scope (the normal case: `Ignis\all()` called from
  inside a request handler) every one of them builds the wrapper closure being deleted here. V-4's
  baseline is `per_fiber_us_warm` 4.38-4.56 µs; this change must not move it outside that band, on a
  quiet box.
- Rows 1 and 2 sit on `ignis_poll()`'s own per-completion loop, which both E1 (`bench/php/e1_sleep_10k.php`,
  10k fibers, `wall_ms` < 1200, V-2) and E2 pass through on every resume; both benches gate both rows.
- Row 4 (the lock-free `Registry::pick`) is explicitly a hot-dispatch-path change and its bench is
  named by the item that already deferred it: `bench/wrk-hello.sh` against V-82's ±6.7 % run-to-run
  band on this box, plus an E4/E10/E11 before/after on a quiet box — not this box, not this session,
  because the effect claimed (2N locks down to N, now proposed down to ~0) is smaller than the
  instrument.
- Row 9's metric addition (a counter increment beside an existing `Semaphore::try_acquire_owned`)
  is off the hot path in the sense that it does not touch `Op`, the reactor, or `ignis_poll`; its
  own bench is the one M4-3 already named and never ran: `bench/b1-budget.sh` part (3) with
  `connections = 2000` and `wrk -c 4000`, RSS flat at the 2000-connection level.

## Contradicting the backlog, summarized

- `R-REVIEW-CHORES`'s Rust checklist claims four things that are no longer true against
  `crates/ignis/src/http.rs`/`grpc.rs` as they stand: the two-`if` routing, the function-local
  `CancelOnDrop`, the duplicated header/URI extraction, and the non-generic `is_grpc` are all
  already fixed. The item's own header says "every item suspected unless marked" — these four
  should be marked.
- `M4-3` and `S1-CAP` describe a mechanism (`IGNIS_MAX_CONNECTIONS` + a semaphore-backed accept
  gate) that already exists and already meets the connection-refusal half of both items'
  acceptance criteria; only the metrics-exposure and bench-extension halves remain, and both items
  are the same defect filed twice, the same way R-ANSWER-MAP/S4-ANSWER-MAP are.
- `A-LEAKS-RUST` is accurate as filed: (a) genuinely open, (b) and (c) genuinely landed — no
  correction needed there, only confirmation.

## Mechanism-budget compliance

Every row above is a merge, a deletion, or a fix inside the existing three mechanisms (park,
offload, context) and the one `Op` enum with its four variants (`Sleep`, `Watch`, `CancelWatch`,
`Custom`). None adds a second wait point to a PHP thread, a fourth `Op` variant, or a new table
outside `ignis.toml`'s policy row shape. Row 4's `AtomicUsize` and row 7's `remove()` call are
bookkeeping beside existing state, not new mechanisms.
