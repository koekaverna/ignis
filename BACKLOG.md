# BACKLOG — the product, as work an agent can pick up

Written 2026-09-16 at the owner's request ("харнес для автоматического выполнения: оркестратор +
Sonnet-агенты, большой подробный бэклог"). The orchestrator (the main agent) dispatches items to
Sonnet agents, validates, re-runs every number, commits. The procedure is `docs/orchestration.md`.

**Lane** decides who may do an item:
- `agent` — a Sonnet agent may implement it end to end. It never touches `crates/ignis/src/php/**`,
  `crates/ignis-sys/**`, `crates/ignis/src/backend/**` or any file containing `unsafe`
  (`.claude/hooks/guard-ffi.sh` blocks it anyway).
- `main` — the item lands in those paths or needs an `unsafe` block; only the orchestrator does it.
  An agent may still do the research, the bench and the PHP side, and hand the FFI part back.
- `research` — no code: read source, measure, write `docs/research/NN-*.md`, recommend.

**Acceptance is a command and its expected output.** A number produced by an agent enters
VALIDATION.md only after the orchestrator re-runs it once (DECISIONS, C15). "Numbers or it didn't
happen" applies to agents too: an item is not done because its diff exists.

Status: `open` / `in progress (agent name)` / `validating` / `done (V-n)` / `dropped (reason)`.

**This file is the open queue.** Until 2026-09-18 it held all 108 items, open and closed interleaved,
and answering "what is left" meant reading 985 lines. Closed items keep their full text in
[`BACKLOG-CLOSED.md`](BACKLOG-CLOSED.md) and appear here only as the index at
the end. Nothing was summarised away in the move.

---

## M3 — Real apps unchanged

### M3-5a Laravel in classic mode, serialised by the budget `agent` `open`
**What.** Research 25 (Octane v2.19.1, commit 68a2516): Octane never has two requests in one engine at
once, and `Illuminate\Container\Container::$instance` is process-global — so any route that
interleaves Laravel requests on one thread corrupts the container. `php/packages/runtime/src/classic.php` already runs
one `include` per request but only *assumes* no suspension ("a classic script must not suspend").
Ship Laravel through classic mode with **`budget.fibers = 1` per thread**: ADR-0019 then makes the
serialisation a guarantee (the second request waits as data), hooks can stay on, and the model is
exactly Octane's own — one request per worker at a time, `threads` workers. `php/laravel/README`
recipe + `bench/e8-laravel.sh` in M3-3's shape + a README section like M3-1.
**Acceptance.** A `laravel/laravel` skeleton served from the runtime image with `budget.fibers = 1`
answers `/` with its welcome page; 20 concurrent requests are all answered (queued, not
interleaved — `/_ignis/stats` `queued_peak` ≥ 1 proves the queue engaged); health ok before and
after; 0 restarts. And the safety proof: a route that does a hooked `file_get_contents('http://…')`
mid-request, hit by two clients at once, records the same `spl_object_id(app())` before and after
the fetch in both requests. Run the same test with `budget.fibers = 2` as the control and show it
**fails** — that is what justifies the `1`.
**Constraints.** Composer only in the builder image (V-40). Do not claim throughput; M3-3's shape.

### M3-5b Fiber-scoped `Container::$instance` and Facade caches `main` `open` — needs an ADR
**What.** The route to real Laravel concurrency: swap `Container::$instance` (and the Facade
resolved-instance cache) on the fiber-switch observer, the ADR-0006 model that already swaps
`$_SERVER`/`$_GET`/`$_POST`/`$_COOKIE` (`crates/ignis/src/php/superglobals.rs`). Research 25 §"decisive
finding" and its option (a) kill criterion are the spec; then an Octane `Contracts\Client` for Ignis.
**Acceptance.** The V-16 RequestStack test, for Laravel: two interleaved requests never observe
each other's `app()`; then M3-5a's control run passes at `budget.fibers = 1024`.
**Constraints.** `main` (`superglobals.rs`, an `unsafe` observer hook). ADR first, with the kill
criterion from research 25.

### M3-8 Prod-mode Symfony leg for the E8 bench and the recipe `agent` `open`
**What.** The V-40 recipe and `bench/e8-symfony.sh` (M3-3) serve the skeleton in dev mode: `/` is
the welcome **404** with the profiler on, so its req/s is not V-16's quantity (prod-mode 200 on a
real route, 7.2k/25.2k). Add to the bench a second leg: `.env.local` with `APP_ENV=prod
APP_DEBUG=0`, a minimal `src/Controller/HelloController.php` answering `/` with 200, cache warmed
by the first request, then the same `wrk` shape; print both legs. README's Symfony section already
says dev mode is the default — link the bench.
**Acceptance.** The bench prints `dev-404` and `prod-200` lines for 1 and 4 threads; the prod-200
numbers are within the run-to-run spread of V-16's on this box when the orchestrator re-runs them
(if they are not, that is a finding, not a failure of this item).

### M3-6 Publish `ignis/runtime` to Packagist `main` `open`
**What.** Packagist submission of `php/composer.json` (`ignis/runtime`), a tag that composer can
resolve, and the README recipe switched from the path repository to `composer require ignis/runtime`.
**Why.** A path repository is not an install path for a user.
**Acceptance.** On a clean box: `composer require ignis/runtime` resolves without a `repositories`
entry. Owner action: the Packagist account. `main` because it is a publication, not code.

### M3-7 `/stats` is per-example; make it part of the runtime `main` `open`
**What.** Every example builds its own `/stats` array by hand (hello_server, a3-soak, app.php).
Move the counters behind `/_ignis/stats`, answered like `/_ignis/health` — the runtime's own
counters from Rust (threads, stalled, restarts, in-flight per thread, reactor in-flight ops,
offload queue) merged with the scheduler's (`Ignis\Loop::budgetStats()`, resumes, fibers, idle,
cancelled) through one `ignis_stats()` call. Then delete the hand-built arrays.
**Why.** M4 metrics need one source of truth; today `/stats` queues behind the load it reports
unless exempted by hand.
**Acceptance.** `curl /_ignis/stats` on `examples/hello_server.php` returns every field the old
`/stats` had; `bench/e11-cancel.sh`, `bench/e12-isolation.sh`, `bench/a3-soak.sh`, `bench/b1-budget.sh`
read from the new path and still pass.
**Constraints.** `main`: touches `module.rs` and `http.rs`.

---

## E18 — Universal park (owner, 2026-09-16; ADR first)

Export the blocking libc symbols from the ignis binary (`read`/`write`/`recv`/`send`/`poll`/
`select`/`connect`/`accept`/`nanosleep`/`getaddrinfo`/…) so calls made *inside* libcurl, libpq,
libssl and friends resolve to us; a thread-local "fiber active" gate decides in a few ns whether
to park (submit `Op::Watch` on the fd, suspend the fiber, then make the real call once ready —
the A4 "park then delegate" rule at the syscall layer) or to fall straight through to libc; a
per-caller-library policy (`park` / `block`, resolved from the return address and cached) keeps
libraries that hold a lock across a blocking call on the `block` path. The php_stream factory
hook (ADR-0007) stays as it is. Default policy for an unknown caller is `block` — delegating is
always semantically correct; parking wrongly is a hang.

### E18-I Implementation `main` `default build since ADR-0037 cycle 1 (V-46: sleep.rs deleted, lib:symbol policy, seed table); stage 2 symbols built (V-47: accept/accept4, select, ppoll, __poll_chk, recvmsg/sendmsg, readv/writev; left: getaddrinfo, ECANCELED, boot self-check, blocked-in-fiber detector); stage 1 built (V-45): read/write/recv/send/recvfrom/sendto/poll/connect/nanosleep/usleep/sleep; H32 curl 279–337 ms, H33 pgsql 296–333 ms at N=100 (controls 20 s); feature off by default; stage 2 (getaddrinfo/select/accept/vectored/__poll_chk, ECANCELED, per-symbol policy) open`
C shim per exported symbol (captures `__builtin_return_address(0)`, calls into Rust) built by
`cc` in `crates/ignis/build.rs`; Rust side in `crates/ignis/src/park/`; feature-gated
(`universal-park`) so the overhead bench has its control build; `IGNIS_PARK_POLICY=libcurl=park,libpq=park`
env/toml; `IGNIS_NO_UNIVERSAL_PARK` as the hook-off control (every hook claim needs one).

## M4 — Operate

### M4-3 Cap concurrent connections at the listener (B8) `main` `open`
**What.** `IGNIS_MAX_CONNECTIONS` / `[limits] connections` in `ignis.toml`: past it, `accept` is
delayed (do not accept what cannot be served) rather than accepted-then-dropped. Count in
`http.rs`; expose in `/_ignis/stats`.
**Why.** V-37: a held connection costs ~33 kB whatever the fiber budget does; RSS is bounded by
connections, not fibers. This is the half of B1's acceptance a fiber budget could not meet.
**Acceptance.** `bench/b1-budget.sh` part (3) extended: with `connections = 2000`, `wrk -c 4000`
holds RSS at the 2000-connection level (±5 %) and the extra 2000 connections wait in the kernel
backlog or are refused, never served-then-dropped; p99 of accepted requests unchanged.
**Constraints.** `main` (`http.rs`, `config.rs`).

### M4-5 Graceful reload on `SIGHUP` `main` `SIGTERM/SIGINT drain DONE (V-56): two-phase — health says draining while still accepting for IGNIS_DRAIN_DELAY_MS, then the listener closes and in-flight requests get IGNIS_DRAIN_TIMEOUT_MS. SIGHUP reload-without-restart still open.` `main` `open` — note (E18-B, 2026-09-16): under 100 keep-alive connections `hello_server` outlived `kill` + `wait`; hyper's graceful shutdown waits on idle keep-alive connections, so `SIGTERM` needs a bounded drain, not just a signal handler
**What.** Drain: stop accepting on the old workers, let in-flight requests finish (bounded by a
`drain_timeout`), respawn each PHP thread one at a time (ADR-0012 has the mechanism), never reset
opcache. `SIGTERM`: drain then exit.
**Acceptance.** `wrk -c 64 -d 20s` against `/` while `kill -HUP` fires at t=5 s and t=10 s: **0**
non-2xx, **0** socket errors, `restarts` in `/_ignis/health` increments by `threads` each time.
**Constraints.** `main` (`main.rs`, `http.rs`). Agent writes `bench/m4-reload.sh` first.

### M4-6 Held-resource logging audit `agent` `open`
**What.** After M4-1: an inventory `docs/research/42-observability.md` of every wait a fiber can
be in (stream read/write/connect, sleep, pg lease, offload job, watch, gRPC call, Temporal
activation) and, for each, whether its age is visible in `/_ignis/stats`, in a log line, in both,
or in neither. Propose the minimal set to close the "neither" rows.
**Acceptance.** Table complete against `grep -n "Op::" crates/ignis/src/reactor.rs`; every "neither"
row has a proposed metric name.

### M4-7 Watchdog reports the fiber, not only the thread `main` `open`
**What.** Pain map Swoole 4: when a thread stalls > 1 s, log which request (id, uri, age) it was
running. The reactor knows the pending request ids; the PHP side knows the current fiber's request
(`Scope::get('ignis.request')`). Cheapest: `ignis_poll` records the id it is about to dispatch in
a per-thread atomic the watchdog reads.
**Acceptance.** `/spin?s=5` in `examples/hello_server.php` produces one `warn!` with `uri=/spin?s=5`
and `age_ms` ≥ 1000 within 1.5 s of the stall.

### M4-9 Cancellation of offload jobs and PG queries on disconnect (E11') `main` `open`
**What.** ROADMAP E11': today a client disconnect cancels the fiber (V-14) but a query already sent
to tokio-postgres or a job already on an offload worker runs to completion. PG: `cancel_token()`
on the client. Offload: mark the job cancelled so its result is dropped and the worker is not
blocked on the reply channel.
**Acceptance.** Extend `bench/e11-cancel.sh`: 20 clients disconnect 50 ms into a 2 s `pg_sleep`;
`pg_stat_activity` shows 0 of those queries still running at t=200 ms. Same shape for offload with
a 2 s `sleep` in the job: worker pool is idle again within 100 ms of the disconnect.

### M4-10 `IGNIS_LOOP_GC` measured `agent` `open`
**What.** Pain map RoadRunner 2 / Engine 1: loop-scheduled GC is implemented and **not measured**.
Bench: a handler that creates cycles; compare p99 and RSS with `IGNIS_LOOP_GC=1` vs `0` under
`wrk -c 64`, 3 reps each.
**Acceptance.** `bench/m4-loop-gc.sh` prints both arms; the orchestrator re-runs; a V-n either way
(a null result is a result).

---

## M5 — Ship

### M5-5 Static binary (B6) `research` `open`
**What.** Research first: a PHP rebuild with `--enable-embed=static`, curl built with fewer
backends (no ldap/rtmp/ssh2/gssapi), `-static-pie` if libphp allows, and what `ldd` shows after.
Write `docs/research/43-static-binary.md` with the exact configure lines tried and the resulting
`ldd`. Then, if feasible, `scripts/build-php-static.sh` and a second Dockerfile stage.
**Acceptance.** `ldd target/release/ignis` prints "not a dynamic executable" **or** the note says
exactly which library made it impossible and why.

---

## Product hygiene (small, `agent`)

### R-HELP The binary has no `--help` `agent` `open`
**What.** Found 2026-09-18 while refreshing the site documentation against the code.
`LD_LIBRARY_PATH=/opt/php85-zts/lib ./target/release/ignis --help` does not print usage: the flag is
taken for an entry script and the engine fatals with `Failed opening required '--help'` (exit 255).
`ignis serve --help` answers `ignis serve: entry script --help does not exist` (exit 2). Only
`--version` works, printing `ignis 0.1.0-rc.1`.
**Why.** The CLI surface cannot be discovered from the program, so `docs/reference/cli.md` has to be
transcribed by hand from `main.rs` and re-verified by a human at every change — the documentation
cannot be checked against the binary, which is how it drifted in the first place. A user's first
reflex on an unknown command fails with a PHP fatal error about a file they never named.
**Files.** `crates/ignis/src/main.rs`, `crates/ignis/src/config.rs` (`serve_to_legacy_args`).
**Acceptance.** `ignis --help`, `ignis -h` and `ignis serve --help` print usage listing every
subcommand and flag, and exit 0; an unknown leading flag prints usage to stderr and exits 2.
**Constraints.** Argument parsing is hand-rolled here and a script's own `--` arguments must keep
passing through untouched (A5/V-32 php-cli parity) — a flag after the script path belongs to the
script, not to `ignis`. Exit statuses are *not* part of this item: they were measured and are
already correct (`exit(7)` → 7, a fatal → 255, `serve` with a missing entry → 2).

## Cycle 2026-09-18 — bugs, stabilisation, production readiness (owner)

Framing the owner set: "production readiness" is the question *what stops someone running this
today*. The answer comes from our own measurements, and it orders the cycle. Stage 0 first because
a gate that does not gate makes every later claim unverifiable.

### S0-DOCS-UNVERIFIED Two pages were promoted into the navigation without being checked `agent` `open — 2026-09-18`
**What.** `docs/deploy.md` and `docs/migrate.md` were added to `mkdocs.yml`'s nav during the site
refresh, on a recommendation, but nobody updated or verified them in that pass — the agents only read
them for contradictions and neither had an owner. Until then they were reachable only by direct URL.
**Why it matters more than an un-refreshed page.** Visibility was raised on text whose accuracy was
not, which is the one change that makes stale documentation worse rather than merely old. Everything
else in the refresh was checked against the code; these two were not, and they now sit in the menu
looking as if they were.
**Acceptance.** Both pages read line by line against the code, like the rest of the refresh, or taken
back out of the nav until they are.

### S0-FRANK One frankenphp test regressed and nobody knows which `main` `HALF DONE 2026-09-18 — gate green, test still unnamed`
**What.** `passed=28` against baseline 29. The arithmetic pins it at exactly one test: 28+5+33 and
29+4+33 are both 66. Five fail now — `server-variable.php`, `cookies.php`, `autoloader.php`,
`env/putenv.php` (`got 'test=8'`, a value surviving into the next request), `file-upload.php`.
**Acceptance.** `scripts/ci-gate.sh frankenphp` passes, with the regressing test named and either
fixed or re-baselined against evidence. Bisect over the 2026-09-17 16:50–18:26 window (V-76/V-77, E22).
**Half done, and the half that is missing is the one that matters.** The gate passes again on `main`,
so the count recovered somewhere in the cycle — but **nobody named the test**, so nothing was learned
and nothing stops it recurring. Recovering by accident is not the same as fixing. Still open: name it.

### S1-CAP Cap concurrent connections at the listener (M4-3/B8) `main` `open`
**What.** ADR-0025. Nothing bounds accepted connections, and V-37 measured ~33 kB per held one, so
RSS is unbounded under load — the half of B1's acceptance a fiber budget cannot reach.
**Acceptance.** `limits.max_connections` enforced at accept; over the cap the listener stops
accepting rather than queueing unboundedly; RSS at 2× the cap is flat. Lands with S3-LIMITS.

### S4-MIXED Validate what crosses the boundary (PHPStan level 9) `main` `open`
**What.** Level 9 costs +310 over level 8, and the composition says what it is:
`offsetAccess.nonOffsetAccessible` 122, `argument.type` 90, `cast.string` 35, `cast.int` 33. That is
"you indexed or cast something typed `mixed` without checking it" — and our boundary with Rust and
with the network *is* `mixed`: `ignis_poll()` returns `array<int, mixed>`, protobuf maps and JSON
payloads likewise. **This is the same defect family the tests caught by hand**: `Proto::decode`
inventing a value for a truncated message was an unchecked offset access on `mixed`.
**Why it is not just a config bump.** Honest fixes are validation at the boundary, with new throws on
malformed input — real behaviour change, roughly the size of the whole 2026-09-17 PHP effort.
**The trap.** PHPStan prints it itself: do not silence these with casts, `assert()` or inline
`@var`. 349 findings closed by casts would make the types a lie and leave us worse off than an
honest level 6.
**Owner's question, 2026-09-18: can the `mixed` be typed at the language level instead?** Yes, and it
is the better route. Two steps, and the first must be tried before the second:

1. **The `mixed` is ours.** `ignis_poll(): array<int, mixed>` is a declaration we wrote in
   `stubs/ignis.php`, not a fact about PHP. A completion is a tagged union with a known set of
   shapes (Request, Slept, Ready, Error, Blob, Json). Declared as a union of array shapes with a
   literal-string `kind` discriminator, PHPStan narrows on `match ($payload['kind'])` by itself.
   Zero runtime cost, and most of those 122 accesses were never unchecked -- the type just could not
   say so. **Measure how many of the 349 survive this before writing a single runtime check.**
2. **Objects across the boundary**, if step 1 leaves a meaningful residue: `ignis_poll()` returning
   typed objects rather than assoc arrays makes PHP's own type system do the work -- `instanceof`,
   `match(true)`, no phpdoc at all. But building zend objects costs more than an array on the
   hottest path in the system (one poll per loop tick, against E2's 4.5 µs per-fiber budget), so it
   is a measured trade, not an obvious win. Same fork on the Rust side: `serde_json::Value` versus
   typed structs.

**Step 1 is done and measured (2026-09-18).** The completion union is declared in
`stubs/ignis.php` as a `@phpstan-type` with a literal `kind` discriminator, and `ignis_poll()`'s
`@return` names it. Level 9 went **349 -> 307**, level 10 **399 -> 356**: 42 findings, a tenth, at
zero runtime cost.

**And it answered the question about step 2: don't.** The remaining 307 are concentrated, not
diffuse --

| file | findings |
|---|---|
| `temporal-core-transport/src/CoreCodec.php` | 108 |
| `temporal-prototype/src/ignis-temporal.php` | 59 |
| `temporal-core-transport/tests/conformance.php` | 27 |
| `offload/src/ignis-offload.php` + `worker.php` | 40 |
| `runtime/src/Loop.php` | 13 |

-- and the top two are the protojson decoders, i.e. documents off the network indexed without
being checked. That is validation work in two files, not a reason to pay allocation per completion
on the hottest path in the system. Step 2 stays unbuilt unless something else argues for it.

**Acceptance.** Level 9 clean with zero added casts whose only purpose is silence; every new throw
covered by a test that feeds the malformed input.

### S4-ANSWER-MAP One `HashMap<u64, Answer>` instead of three `main` `PARTLY DONE 2026-09-18`
Already filed as R-ANSWER-MAP. Owner included it in this cycle. Lands with S2-STREAM-CANCEL and the
lock-free `Registry::pick`, and needs E4/E10/E11 re-measured on a quiet box before it is called done.
**Done:** the map. `enum Answer { Whole | Streaming | Grpc }` behind one `answers: Mutex<HashMap<u64,
Answer>>`, with `take_answer(id, expected)` checking the variant *before* removing — the first cut of
this change reintroduced V-75's bug class by removing without checking, and the new test caught it.
`cancel_request`, `fail_pending` and `pending_requests` are one map each now instead of three, which
is what made `pending_requests` correct by construction.
**Not done, deliberately:** the lock-free `Registry::pick`. `pending_requests()` is still
`answers.lock().len()`, so `pick` holds `reactors.lock()` and takes one more lock per candidate —
down from three, not to zero. The remaining change is an `AtomicUsize` bumped on deliver and dropped
on answer. It is a hot-path performance claim, and this box measures +/-6.7 % run to run on hello
throughput (V-82), so its effect is under the instrument. Landing it here would be exactly the
unmeasured optimisation the cycle's own rule forbids. **Needs a quiet box and E4/E10 before/after.**

### R-GLOBALS A legacy entry script loses its globals and cannot redeclare its functions `main` `half done (V-54): globals fixed by the top-level worker loop (Ignis\Classic\listen/accept/respond, examples/classic_worker.php) — $GLOBALS and `global $x` now behave as under stock php -S, 10.5k req/s on one thread, FrankenPHP testdata still 29. Still open: an unguarded top-level `function foo() {}` survives into the next request and PHP's uncatchable "Cannot redeclare" fatal ends the worker (the supervisor respawns, the request is lost). Only a per-request PHP request cycle (RINIT/RSHUTDOWN) fixes that — the bootstrap cost the worker model exists to avoid, so it is an ADR, not a patch; documented as the worker-runtime rule (require_once / function_exists) for now. NOTE: the option once written here — "run the entry on the thread's main context, not in a fiber" — is WRONG and V-54 measured why: a method's scope is no more global than a fiber's; only the top level of the main script is.`

## Open defects and deferred decisions

Items raised by reviews, benches and the owner that belong to no milestone.

Measured before deciding, and the numbers are kept because they are the point: the `mprotect`+`SIGSEGV` barrier costs **7.1 µs per fault**; a real Symfony request dirties **11–12 boot pages** (not the assumed 50) against a **6 MiB** boot heap; so the proposal costs **71 µs against its own 100 µs budget** and is the only one of three mechanisms that does not grow with the heap (whole-arena memcpy 120 µs, soft-dirty 141 µs and process-wide). Rejected not for cost but for the constraint: the undo log is per thread, so a restore is only safe with one request in flight, i.e. `budget.fibers = 1` — php-fpm's concurrency with a faster bootstrap, which switches off the parking this runtime is built on, for exactly the applications that need it most. The variant that keeps fibers (restore when the thread goes idle) bounds RSS but fails "identical response bytes". Research: `docs/research/34-e19-boot-heap-snapshot-feasibility.md`. Instruments kept: `bench/e19/barrier.c`, `bench/e19/compare.c`, `bench/e19/dirty-probe.php`.

### R-PDO-SQLITE `new PDO('sqlite:…')` blocks the thread and cannot be routed per driver `main` `open — owner decision 2026-09-17: document only, do not build yet`. The runtime picks the mechanism in `create_object`, which the VM calls when it executes `new` — before the constructor's arguments exist, so `pgsql:` and `sqlite:` are indistinguishable at the only moment it can choose. Routing the `PDO` class therefore sends `pgsql` to a worker too (2,753 ms against 303 ms parked for 100 × 200 ms queries, V-59 addendum), and not routing it leaves `pdo_sqlite` blocking the OS thread for the file access. Today the choice is `IGNIS_OFFLOAD_CLASSES`, per class and not per driver, and an application using both under concurrency cannot have both right. **The designed fix, ~60 lines, unbuilt:** give the proxy class the *original* `create_object` instead of plain object creation, so the proxy carries a real PDO payload; then decide inside `Proxy\PDO::__construct`, where the DSN is visible — `sqlite:` keeps today's worker handle, everything else calls `parent::__construct()` on the calling thread and every forwarder goes to `parent::` instead of the worker, i.e. it parks. `instanceof PDO`, type hints and constants are unaffected (the proxy already extends PDO); the only visible difference stays `$pdo::class`. Needs a suppression flag in `route.rs` so the nested `new` inside the constructor is not re-proxied (the `PASS` thread-local already exists for functions). **Gate before it lands:** `pdo_pgsql` still 303 ms (the extra forwarding layer must not cost), `pdo_sqlite` reaches the pool, and `PDOStatement`/`fetch()` behave as V-24 measured.

### R-FOREIGN-FIBER Park suspends a fiber that no Ignis loop is driving `main` `open — found and measured 2026-09-17 (V-63) while fixing E7`. The gate in `park.rs` is "we are in a fiber": `on_switch` sets it to 1 for any context that is not `EG(main_fiber_context)`. That is right for fibers `Ignis\Loop` started and wrong for anyone else's. A fiber created by a **foreign scheduler** — Revolt's own `StreamSelectDriver`, a library's `new Fiber`, a test harness — that makes a parkable syscall is suspended into our reactor, and in that program nothing calls `ignis_poll()`, so it is never resumed. Measured: `vendor/revolt/event-loop/examples/fiber-local-automatic.php` under `REVOLT_DRIVER=StreamSelectDriver` dies with Revolt's own "Event loop terminated without resuming the current suspension"; with `IGNIS_NO_UNIVERSAL_PARK=1` the same program prints the correct four lines. **Blast radius is narrower than it looks:** every fiber the runtime itself uses comes from `Ignis\async()`/the pool (`ignis.php:237`), including `scripts/phpt-harness.php` and `php/packages/runtime/src/classic.php`, and `Ignis\Revolt\IgnisDriver` awaits through `Loop::awaitOp` rather than the C hook — so the failure needs a *foreign* scheduler, which today means running AMPHP on a non-Ignis driver under our binary. **The fix, unbuilt:** park only in fibers the runtime owns — a thread-local set of owned `zend_fiber_context` pointers, filled by a one-line internal call at the top of the pool body and of `Loop::spawn`'s one-off fiber, with `on_switch` consulting it instead of `to != main`. Two things to settle before it lands: the set's lifetime (contexts are freed and their addresses reused — E1 creates 10k fibers, so a stale entry could make an unowned fiber park), and the cost on the switch path, which E2 measures at 3.83 µs warm and must not move. An opt-in for deliberate cases (a harness that does drive the loop from a hand-made fiber) is a table row, not a second mechanism. **Until then** the honest answer is the documented one: under Ignis, AMPHP runs on `Ignis\Revolt\IgnisDriver` — which is what E7 exists to prove — and `bench/e7-revolt.sh` runs its stock-driver control with park off for exactly this reason.

### R-ANSWER-MAP One request id, three maps — and the split is what caused V-75 `main` `PARTLY DONE 2026-09-18 — the map landed, the lock-free pick did not; see S4-ANSWER-MAP`. `Reactor` keys the same thing three ways: `responders` (`reactor.rs:120`) for a whole-body answer, `stream_out` (`:123`) for a streamed one, `streams` (`:125`) for gRPC — all from one `next_id`. The cost is paid everywhere: `cancel_request` takes three locks and three removes (`:320-328`), `fail_pending` drains three maps (`:368-375`), and `pending_requests` counted **two of the three** until V-75 — which is how a graceful shutdown came to truncate a live download. One `HashMap<u64, Answer>` with `enum Answer { Whole(oneshot::Sender<HttpResponse>), Streaming(mpsc::Sender<Bytes>), Grpc(..) }` makes the invariant explicit (an id is in exactly one state) and turns `respond_start` into a **transition** rather than a delete-plus-insert, so the next person cannot forget the third map. **What must stay separate:** the payloads — gRPC's terminal value carries trailers HTTP has no analogue for, and its channel is unbounded while the HTTP stream's bound *is* the back-pressure. **Land it with** the dispatch fix: `Registry::pick` (`http.rs:52-73`) holds `reactors.lock()` while calling `pending_requests()` on each reactor, which takes two more locks each — nested locks held across work on the hottest path, 2N per request. An `AtomicUsize` bumped on deliver and dropped on answer makes `pick` lock-free per candidate.

### R-REVIEW-CHORES Small, safe deletions and allocations, from the same review `agent` `open — architecture review 2026-09-17; every item suspected unless marked`. Each is independent; none changes behaviour. **Rust:** `http::handle` (`http.rs:294-366`) routes two `/_ignis/*` paths with two `if`s that want one `match` above the gRPC check; `CancelOnDrop` is declared inside the function body and wants module scope; header+uri extraction is the same five lines in `http.rs:325-330` and `grpc.rs:133-138`; `zif_ignis_poll`'s match (`module.rs:154-215`) has three guard arms doing two registry lookups where `resume_parked` already returns a bool, and four arms repeating the same zeroed/set_new_array/fill/update block that an `unsafe fn push_event` would collapse; `grpc::is_grpc` (`grpc.rs:64-66`) is a wrapper that exists for a test and should be generic instead. **Allocations, all suspected:** ~12 per request for request headers (`http.rs:325-329`) where `HeaderName` is a cheap copy and `from_utf8_lossy` copies an already-borrowed `Cow`, then `request_to_zval` copies each again; response headers cross `String` and are re-parsed into `HeaderName`/`HeaderValue`; `Arc<Mutex<Instant>>` per connection (`http.rs:223`) locked twice per request where an `AtomicU64` of millis would do; `msg.clone()` per `Error` completion (`module.rs:160-161`) for a `debug!` line that is almost never printed; `output.rs` does a `SINKS` hash lookup per `echo` (`:56-65`) where the fiber-switch observer could keep a `Cell<*mut Vec<u8>>`, and drops the capture `Vec` per request instead of clearing it. **Must stay a copy** and deserves a comment saying so: the response body and each chunk — a ZTS `zend_string` refcount is thread-local, so `Bytes::from_owner` cannot borrow it. **PHP:** `IgnisWorkerRunner::toSymfony` builds a Symfony `Request` with `createFromGlobals()` and then **throws it away and builds a second** for any non-urlencoded body (`:71-76`) — decide first, construct once; `Ignis\Http\Request` scans the URI for `?` three times (`:24`, `:31`, `:52`) and runs `parse_str` twice when a handler uses both `query()` and the superglobals; `Loop::spawn` wraps the callable in a second closure when a request id exists, so a request handler runs two closures deep; `throwInto` scans `$waiting` linearly per cancellation, which is fine normally and suspect in a disconnect storm; `Output.php`'s class docblock still says "neither path streams (BACKLOG R-STREAM)" while `captureChunked()` eighty lines below streams.

### R-SESS A file lock held across a yield DEADLOCKS the thread `main` `open — severity corrected 2026-09-17 by measurement (V-58); the rule and the options are ADR-0038`. Not a stall: a hang. `ext/session`'s files handler takes a blocking `flock(LOCK_EX)` (`mod_files.c:210`) and holds it from `session_start()` to `session_write_close()`. A regular file cannot be parked (epoll refuses it), so a second fiber's `flock` blocks the **OS thread**, so the loop can never resume the holder, so the lock is never released — measured: two fibers, killed at 12 s with no progress. Park makes it *more* likely, not less: `usleep`, `sleep` and stream I/O all yield now, so the holder is suspended far more often than a php-fpm developer would expect. **Symfony's cache lock is NOT affected** (V-58): `LockRegistry` uses non-blocking `flock` plus `usleep(100 ms)` polling, and `usleep` parks — measured 60/60 ticks during a 600 ms contended wait. **The rule:** a lock that can be held across a yield must live on a socket (Redis, PostgreSQL) or in the runtime, never on a file. Fixes to choose from, none built: (1) ship a PostgreSQL session handler on `Ignis\Pg` using `pg_advisory_xact_lock` — the wait is server-side and the client waits on a socket, which parks; (2) document Redis/PDO handlers and refuse to start with `session.save_handler=files` (cheapest, turns a mysterious hang into a message); (3) a runtime-owned session store with the lock as a reactor op — fastest and dependency-free, but new Rust and no durability across restarts (ADR-0024). Also unmeasured: whether an actual Symfony session reaches that `flock` under the embed SAPI at all — `session_start()` hit `headers already sent` in the probe.

### S-DBAL-DIRECT A service that injects `Doctrine\DBAL\Connection` directly keeps it for the life of the thread `main` `open — found while building E24 (V-85), the manager path is fixed, this one is not`. `DoctrineFiberScopePass` now marks every id in `doctrine.connections` non-shared, so a fiber's own EntityManager builds its own connection and two overlapping requests never meet inside one PostgreSQL socket (V-85: 6 of 6 requests correct with the pass, 5 of 6 wrong without it — including one that returned **another request's row with a 200**). But a definition is resolved when its consumer is constructed, and application services are built once per container, which is once per thread: a repository or a service that takes the connection in its constructor and keeps it in a property therefore still shares one handle across every fiber on that thread. **What it needs:** the same shape the manager got — a shared `FiberConnection` published under the connection id, resolving the fiber's instance from a locator on every call. DBAL 4 has no `ConnectionInterface` and `Doctrine\DBAL\Connection` is a concrete class with a constructor that opens nothing, so the decorator has to extend it and override the query surface rather than implement an interface (research 38 §3 describes the inventory step: every `Reference` to a connection id or one of its four aliases, recorded as a container parameter). **Gate before it lands:** an E24 arm whose controller takes `Connection` in its constructor instead of going through the manager, failing today and passing after. **Cheaper interim:** the compiler pass can *name* those services in a container parameter and the bundle can log them at boot, which turns a silent data leak into a startup warning.

### S-POOL-LEASE-AGE A hung fiber keeps its database connection forever `main` `open — measured 2026-09-18 (V-85 addendum 5): the crash path returns the lease, the hang path does not`. A connection comes back when `Scope::clear()` drops the request's `FiberManager`, which happens in `Loop::admitRequest`'s `finally` — so an exception, a cancelled request and a deadline all release, and six deliberately dying handlers through a pool of 2 leave the pool healthy. A fiber that never resumes never reaches that `finally`: its connection is in no idle list, the pool shrinks by one permanently, and at `size` hangs every request is refused with `PoolTimeoutException` until the thread restarts. **Three fixes, in order of price.** (1) Record an `Instant` at acquire and report `oldest_lease_ms` per pool in `/_ignis/stats` — makes the leak visible minutes before the pool empties, ~20 lines, no behaviour change. (2) A soft reaper: a lease older than a configured age is written off, the pool opens a replacement and counts it; the hung fiber keeps its socket until the thread dies, which is correct, because nothing here can safely take a connection away from a fiber that might still wake up. (3) The real fix, and not this package's: a per-request watchdog in the runtime that throws `DeadlineExceeded` into a fiber that has not progressed, so the ordinary `finally` runs and **every** resource is released, not just connections — related to `R-STREAM-CANCEL` and to the watchdog naming the fiber (M4-7). **Gate before any of them lands:** an E24 arm that parks a handler forever (a `Fiber::suspend()` with nothing to resume it), asserts the pool degrades by exactly one, and — for (2) — that it recovers after the configured age.

### S-EXCLUSIVE Two fibers can still reach one resource, and nothing stops them `main` `open — research 39, 2026-09-18`. Ownership under fibers is discipline today, not enforcement: `ConnectionPool::acquire()` pops the connection out of its idle list and `Scope` is keyed by the fiber, but one `$this->connection = $connection` in a shared service defeats both silently — which is how V-85 produced another request's rows with a 200. Research 39 lays out eight options across three enforcement surfaces (the object boundary, the fiber-switch observer, PHPStan) and rejects one (new syntax in php-src). **The order it recommends:** (1) a fiber-affinity check in `PooledConnection` plus the one-lease-per-fiber rule — five lines, turns the leak into an exception naming the bug; (2) extract `Ignis\Mutex`/`Ignis\Semaphore` into `ignis/runtime`, since the Doctrine pool already contains a hand-rolled one and applications have shared state we currently offer nothing for; (3) a PHPStan rule for the `S-DBAL-DIRECT` shape — a shared service storing a resource that must not be shared, caught before the code runs; (4) a "held across a suspension" detector on the fiber-switch observer, which is the only option that covers resources nobody wrapped and makes ADR-0038's rule enforceable — **gated on E2's 3.83 µs warm switch, which it may not move**; (5) an opt-in guarded proxy through `create_object` for classes we do not wrap. None of it is a new mechanism (ADR-0037): the primitives are `Future` plus the loop, the detector is the context slot, the proxy is the existing route hook. **Not covered by any of it:** cross-thread sharing (a different problem — offload's worker-pinned handles or the runtime owning the resource), and a fiber that hangs while holding something (`S-POOL-LEASE-AGE`).

### S-REQUEST-FORMATS `Request::$formats` is one map per thread, and resetting it hits requests in flight `agent` `open — raised by the owner reading FiberRequestStack, 2026-09-18 (V-88)`. `RequestStack` is tagged `kernel.reset` with `resetRequestFormats`, and that method nulls `Request::$formats` — `protected static ?array` on `Request` (`http-foundation/Request.php:235`), so it is per PHP thread and shared by every fiber on it. `FiberRequestStack` leaves it inherited deliberately: unlike `getSession()` it does not read the parent's array, and it cannot be moved into `Scope` without rewriting `Request` itself — registering a format (`Request::setFormat()`, static) is just as thread-wide as clearing one. **The trade-off nobody has decided with evidence.** Symfony resets it so a format registered by one request does not leak into the next; here the leak is thread-wide anyway, and the reset lands on concurrent requests — an application that registers a custom format at boot loses it for everyone the first time the resetter runs, silently and permanently. **Mitigating, which is why this is filed and not fixed:** under Ignis the resetter effectively never runs (research 36 — `Kernel::boot()` resets only when `requestStackSize == 0`, which under load is never, and `IgnisWorkerRunner` does not call the services resetter itself), and the reset restores the defaults every application has unless it registered its own. **What would settle it:** an E21 arm where one request registers a custom format, a second triggers the reset, and the first reads its format back — then either neutralise the override (a no-op with a comment) or keep the inherited behaviour with the measurement behind it.

### S-SERVE-SECOND-ADDRESS A second `ignis_serve()` with a different address is silently ignored `main` `open — found answering the owner, 2026-09-18`. `http::start` (`http.rs:180-197`) keeps one `BOUND: Option<SocketAddr>` for the process: if something is already bound it registers the calling thread's reactor and **returns the address that is already bound**, without ever parsing the one it was given. That is exactly right for the design — N PHP threads each call `ignis_serve()` with the same address and share one listener — and wrong for the script that tries `ignis_serve('0.0.0.0:9000')` after `'127.0.0.1:8080'` and is told `true`. **Fix, two lines:** compare the parsed address with the bound one and throw when they differ, naming both. **Gate:** a script that calls serve twice with different addresses fails with that message; one that calls it twice with the same address still succeeds, because that is the multi-thread path.

### S-SERVE-STOP There is no way to stop serving without ending the process `main` `PARTLY DONE 2026-09-19 (V-90): Ignis\stop() and SIGHUP exist, with unregister -> drain -> return. Still open: more than one listener, which is a single BOUND slot.` `Ignis\serve()` binds and then runs the loop forever: the listener closes on `SIGTERM`/`SIGINT` (drain: stop accepting, answer `/_ignis/health` 503 for `IGNIS_DRAIN_DELAY_MS`, finish in-flight within `IGNIS_DRAIN_TIMEOUT_MS`, V-75), and `php::http_unregister_current()` takes a thread's reactor out of dispatch when its script ends — neither is reachable from PHP, and neither leaves the process alive and idle. **What is missing, in two pieces that can land separately.** (1) `Ignis\stop()`: stop accepting, drain what is in flight, return from `serve()` so the script can do something else — a test that serves and then asserts, a maintenance pass, the reload half of M4-5 (`SIGHUP` without restart) which today cannot exist because nothing can un-bind. (2) More than one listener: `BOUND` is a single slot, so HTTP on 8080 and gRPC on 50051, or a separate metrics port, are impossible — both protocols share one port today, which is a feature (tonic and hyper on the same socket, detected per request) right up to the moment an operator needs them apart. **Constraints:** `main` (`http.rs`, `module.rs`); the accept loop already has a shutdown branch to reuse, and the fiber budget and drain timeouts must behave the same for a userland stop as for a signal. **Gate:** `serve()` returns, in-flight requests finish, the port is free (a second bind on the same address succeeds in the same process), and the loop can serve again afterwards.

### S-RELOAD-SIGHUP-ONLY `SIGHUP` does nothing unless file watching is on `main` `open — found writing the CLI reference, 2026-09-19`. The signal bumps the watcher's generation (`watch::request()`), but `Loop` only compares generations when `self::$watching` is true, which needs `IGNIS_WATCH`/`[watch] enabled`. So the operator who wants "reload on signal" without an inotify watch on every loaded file — a deploy that swapped the code and then signals, the case M4-5 names — gets a no-op. **Fix:** separate the two flags in `Loop::boot()` — report loaded files only when watching, check the generation whenever the runtime is supervised — and let `[watch]` default the file half off. **Gate:** `SIGHUP` with `supervise = true` and `enabled = false` serves the new code afterwards, with zero non-2xx across the signal.

## R&D backlog kept alive (each returns to the product the day a user needs it)

### R-1 In-process Table (B3) `main` — ROADMAP B3, acceptance unchanged.
### R-2 MySQL and Redis native drivers (B4) `main` — the offload router covers them today (V-24).
### R-3 Allocator-level leak detector (B5) `main` — `Ignis\Scope` is the only detector (V-11).
### R-4 TLS read-ahead in `stream_select` (B7) `main` — research 23, design 2 only.
### R-5 `curl_multi` `research` — pain map Swoole 5: not hooked, not routed; measure whether offload
routing of `curl_multi_exec` is enough or a native driver is needed.
### R-6 Xdebug against this libphp `agent` — pain map Swoole 2: "DESIGNED, not validated". Build
Xdebug in the builder image, step through a fiber. A V-n either way.
### R-7 GC ordering guarantee (Engine 1) `main` — "NOT VALIDATED": a test where a `__destruct`
inside `gc_collect_cycles()` calls `Fiber::suspend()` and the loop survives.
### R-8 `PG(http_globals)` swap (E13') `main` — the remaining superglobal not fiber-scoped.
### R-9 Multi-value `Set-Cookie` (E8') `agent` — `IgnisWorkerRunner` collapses cookies to one
header ("single cookie per response in this cycle"); fix with `ignis_respond` accepting repeated
headers (`main` for the Rust half if needed).
### R-10 Temporal signals/queries/cancellation (E9') `main` — extends V-19.
### R-11 gRPC client-streaming and bidi, TLS on the listener (E10') `main` — extends V-20.
### R-12 AMPHP HTTP client/server on the hooked transports (E7') `agent` — extends V-13.
### R-13 Scaling past 4 threads `agent` — ROADMAP known unknown 3: E5 at 8/16/24 threads on this
24-thread box, `bench/soak-threads.sh`; TSRM and the allocator have never been measured there.
### R-14 `IGNIS_POLL_SPIN_US` under a real I/O-heavy app `agent` — V-33 showed it helps sub-100 µs
completions only; measure it on the Symfony skeleton with a local PG (research 24 path) before
recommending a default.

### R-REVOLT-FLAKE `revolt.pass` reads 79 or 80 on identical code `agent` `open — measured 2026-09-18`
**What.** `bench/e15-revolt.sh` runs six arms and reports one `IGNIS_PASSED`. `testExecutionOrderGuarantees`
fails in exactly one arm per run — on this box **and** in CI, on the same commit — and which arm that is
varies, so the number the gate reads is 79 on one run and 80 on the next with nothing changed. Measured
2026-09-18: local run 1 = 79 (`both-hooks-on` arm carried the failure), local run 2 = 80, CI job
105769856567 = 80 with the failure in a different arm. The baseline sits at 80, so half the runs are a
false regression — and a true one of a single test would be invisible underneath that.
**Why.** A gate that reads a different arm each time cannot distinguish a regression from a coin flip,
and `bench/results/e15-baseline.txt` already says as much about `swoole.pass` ("it flapped the same day").
**Acceptance.** Either the reported number is the **minimum across the arms** (matching how the baseline
was chosen in the first place), or `testExecutionOrderGuarantees` is quarantined by name with its reason —
not both silently. `scripts/ci-gate.sh revolt` then means what it says. Three consecutive local runs and
one CI run must agree on the number.
**Constraints.** Do not raise or lower the baseline to make a run pass; the timing race is upstream's
(AMPHP's own `StreamSelectDriverTest` fails it too — the summary line records 4 errors / 1 failure there).

### R-E6SSL-VERIFY All five certificate-verification arms of `bench/e6-ssl.sh` report FAIL, and nothing says whether that is correct `main` `open — found 2026-09-18`
**What.** Run today: the parking claim is intact — 3 concurrent 200 ms https fetches in **211 ms**
against **616 ms** with the policy table emptied, which is V-25's band (210–231 vs 613–623) — and
STARTTLS still works. But every one of the five `verify [...]` arms prints
`FAIL — file_get_contents(https://127.0.0.1:8441/): Failed to open stream`, including
`allow_self_signed` and `cafile + verify_peer_name=false`, which are the two that should succeed
against the bench's own self-signed certificate.
**Why it is a question and not a bug report.** V-25 recorded "5/5 verification cases behave like
ext/openssl" when TLS was rustls in the reactor. V-49 deleted that and TLS is now `ext/openssl`'s own,
so "behaves like ext/openssl" is true by construction and tells us nothing — the bench has **no stock
control**, so a FAIL here cannot be distinguished from stock PHP failing the same way on the same
certificate. Nobody has run this bench since V-49: it is in no gate and no CI job.
**Acceptance.** `bench/e6-ssl.sh` grows a stock-PHP arm that fetches the same URLs with the same
contexts through `/opt/php85-zts/bin/php` and no ignis binary, and prints both columns. Then either
the five arms agree with stock — and the bench says PASS/FAIL against *that*, not against a wish — or
they do not, and the difference is a defect with a name.
**Constraints.** Do not change the certificate or the context options to make an arm pass; the
comparison is the point.

## Found by the 2026-09-18 audit, not fixed in it

Each was read and confirmed; none was changed, because the change is larger than the finding or the
right answer needs a decision. Filed so the reading is not lost.

### A-CLASSIC-FINISH `Ignis\Classic\finish()` stops the `listen()` worker loop `agent` `open — 2026-09-18`
**What.** `finish()` throws `Finished`. `Runner::handle()` catches it, so `Classic\serve()` is fine —
but the documented `listen()` shape, `while ($file = accept()) { include $file; respond(); }`
(`examples/classic_worker.php:116`), has no catch, so the throw unwinds the whole loop and the thread
stops serving. In the mode legacy code most needs it, the documented replacement for `exit()` is an
exit.
**Acceptance.** `finish()` inside a script served by `listen()` ends that request and the loop takes
the next one; a test in `runtime/tests/Classic/` pins it, and `docs/classic-mode.md` shows whichever
shape is correct.

### A-DESTRUCTOR-IO `PooledConnection::__destruct` makes a database round trip `main` `open — 2026-09-18`
**What.** `doctrine/src/Pool/PooledConnection.php:200` → `ConnectionPool::release()` → `reset()` →
`$connection->exec('ROLLBACK; CLOSE ALL; …')`. A destructor firing during cycle collection — which
`Loop::collectGarbage()` schedules at the loop's idle point — therefore executes a query, and under
universal park that suspends whichever fiber happened to trigger the collection.
**Why it matters.** ADR-0034 and pain-map "Engine 1" are about exactly this: the scheduler must never
resume a fiber from inside a destructor. This is the reverse — a destructor that parks — and it is
ours, not an application's.
**Acceptance.** Release is explicit (the middleware returns the connection at request end, as it
already does) and the destructor is a safety net that closes without talking to the server, or the
reset is deferred to the next acquire. A test that destroys a leased connection inside
`gc_collect_cycles()` must not park.

### A-SWOOLE-TICKERS The Swoole shim polls instead of waiting `agent` `open — 2026-09-18`
**What.** `swoole/src/shim.php:225` and `:235` are `while (!$stop()) { \Ignis\sleep(5); }` and
`… sleep(1)`. A ticker fiber standing in for a wait point: up to 5 ms of latency on every `Co\run`
completion, and a mechanism the mechanism budget (CLAUDE.md) does not list. `Coroutine::$parked`
(`:97`) also keeps an entry for a coroutine that yields and is never resumed.
**Acceptance.** The last coroutine resolves a `Future` the caller awaits once; `Co\run` returns
without a poll loop, `bench/e15-swoole.sh` does not drop below its baseline, and `$parked` is empty
after a run that abandons a coroutine.

### A-ADAPTER-MECHANISMS Three adapters carry a mechanism of their own `research` `open — 2026-09-18`
**What.** CLAUDE.md's budget says adapters carry none. Three do: `offload/src/ignis-offload.php:260`
generates proxy classes with `eval()` from `var_export`ed reflection output; `Temporal\CoreSource::poll()`
(`:66`) turns every `RuntimeException` — a malformed completion and a transport failure alike — into
`null`, which sdk-php reads as a clean shutdown; and `grpc/src/ignis-grpc.php:185` recovers the gRPC
status code with `preg_match('/code=(\d+)/')` against a free-text error message, so a reactor that
stops spelling `code=N` silently becomes `Status::UNKNOWN`.
**Deliverable.** A note saying, for each, whether it is a mechanism (and must go in the budget or the
table) or a shape (and must be justified where it is). The gRPC one is probably just a defect: the
status belongs in the completion, not in its message.

### A-DUPES Two copies of the same thing, in three places `agent` `open — 2026-09-18`
**What.** (a) `CallbackRef` and `RemoteException` are declared twice, `class_exists`-guarded, in
`offload/src/ignis-offload.php:14` and `offload/src/worker.php:19` — and the two sides disagree on the
error envelope, so `RemoteException::$remoteTrace` is always empty for a callback failure while the
job path carries it. (b) The raw-request validator is written twice, `Loop::asIgnisRequest()`
(`Loop.php:473`) and `Runner::requestFrom()` (`Classic/Runner.php:139`), the same eight checks with a
different exception prefix. (c) `Loop::publishStats()` and `Loop::budgetStats()` build overlapping
arrays with different key sets for the same seven counters.
**Acceptance.** One declaration each, the callback envelope carrying the trace, and a test that a
failed callback reaches the caller with a stack. (c) may be left with a note saying why two shapes
exist, if they do.

### A-PHP-FLOOR The minimum PHP version is declared in three places and they disagree `agent` `open — 2026-09-18`
**What.** Root `>=8.4`, every package `>=8.4` except `ignis/revolt` `>=8.1` and
`ignis/temporal-core-transport` `>=8.2`; `php/phpstan.neon` encodes `min: 80200` and re-runs revolt at
`80100`. The two exceptions are deliberate (revolt promises AMPHP users 8.1) — the third copy, in the
analyser config, is what drifts silently.
**Acceptance.** The phpstan floors are derived from the manifests, or a test asserts they match.

### A-REVOLT-UNTESTED `ignis/revolt` is excluded from the PHPUnit suite `agent` `open — 2026-09-18`
**What.** `php/phpunit.xml:191` leaves it out deliberately — its suite only runs inside the ignis
binary, through `bench/e15-revolt.sh`. The cost is concrete: the incompatibility between `IgnisDriver`
and `Ignis\Loop` (V-93) could not have been caught by a unit test because there is nowhere to put one.
**Acceptance.** Either the parts that need no binary (the callback bookkeeping, `$pendingWatch`/
`$watchOf`) get a suite that runs with the fake reactor, or the exclusion is documented in
`phpunit.xml` with what it costs — and `R-REVOLT-FLAKE` is the reason to prefer the first.

### A-BACKEND-B-CI Nothing anywhere builds backend (b) `main` `open — 2026-09-18`
**What.** `crates/ignis/src/backend/async_core.rs` is behind `cfg(php_async_abi)`, which needs the
true-async engine (`scripts/build-php-async.sh`). No CI job builds it and this box has no such engine,
which is why it sat with a two-arm `match` against a nine-variant enum until V-91. The tripwire now in
`reactor.rs` catches the *enum* growing; it cannot catch anything else in that file.
**Acceptance.** A CI job that runs `scripts/build-php-async.sh` and
`PHP_CONFIG=/opt/php86-async-zts/bin/php-config CARGO_TARGET_DIR=target-async cargo check -p ignis`,
on a schedule rather than per push if the engine build is too slow for the main gate. Or ADR-0003 is
amended to say backend (b) is a recorded experiment that is not kept compiling, and the file says so
at the top.

### A-RUST-STYLE The Rust half does not follow the project's own naming and comment rules `agent` `open — 2026-09-18`
**What.** CLAUDE.md forbids abbreviations and comments inside function bodies. Measured across the
crate: `module.rs` has ~20 abbreviated names (`ex`, `rv`, `ht`, `zv`, and a six-way tuple of
one-letter names at `:552`), `route.rs` ~11, `embed.rs` ~10, `park.rs` ~9, `temporal.rs` ~8; non-SAFETY
comments inside function bodies number 26 in `park.rs`, 21 in `module.rs`, 20 in `embed.rs`, 11 in
`main.rs`, 10 in `output.rs`, 8 in `superglobals.rs`. Clean by both rules: `main.rs` naming,
`offload.rs`, `metrics.rs`, `watch.rs`, `php/wait.rs`.
**Why it is a separate item.** It is a mechanical diff across the files most likely to be edited for
substance, and it buys no behaviour. Doing it *with* a correctness change hides the correctness change.
**Acceptance.** One commit per file, no behaviour change, `cargo nextest` and the gate green at each
step. Signature-level names (`zif_*(ex, rv)`) come last and may be argued for as the Zend vocabulary,
the way Swoole's `cid` is — but then that argument goes in DECISIONS.md.

### A-RUST-DEAD Three pieces of machinery kept alive by an empty default `main` `open — 2026-09-18`
**What.** (a) `route.rs:45` `const DEFAULT_FUNCTIONS: &str = ""` means the loop at `:82-92` never
runs, so `trampoline`, `call_original`, `frame_name` and `ORIG_FN` — about 80 lines — are unreachable
unless `IGNIS_OFFLOAD_FUNCTIONS` is set by hand. (b) `locklib.rs` is 202 lines of the H36 test harness
compiled into the production binary, reachable only via `IGNIS_LOCKLIB`; its own doc says "a normal
build has no trace of them", which is true of the function table and not of the binary. (c)
`temporal.rs:348` is `#[allow(dead_code)] fn _unused(_: c_int) {}`, a placeholder keeping an import
alive.
**Acceptance.** For each: a caller, a feature gate, or deletion. (b) behind a `cfg(feature)` would
also make the claim in its doc block true.

### A-PARK-ARITHMETIC Overflow before the clamp, and two syscall shims that answer wrongly `main` `open — 2026-09-18`
**What.** (a) `module.rs:110` does `(ms.max(0) as u64) * 1000` with no clamp, so
`ignis_submit_sleep(PHP_INT_MAX)` wraps into a short sleep. (b) `park.rs:531` computes
`ts.tv_sec * 1000 + …` and clamps *after* the multiply; same shape at `:320` (`SO_RCVTIMEO`) and
`:725` (a caller-supplied `timespec`). (c) `ignis_park_sleep` (`park.rs:769`) always returns 0, while
POSIX `sleep()` returns the unslept remainder when interrupted. (d) `ignis_park_flock`
(`park.rs:863-879`) retries in a 20 ms loop with no overall deadline: a lock held by a crashed holder
parks the fiber for the life of the process, and that ceiling carries no `ponytail:` marker.
**Acceptance.** Saturating arithmetic before every clamp with a unit test per site (these are pure
functions and `park.rs` has no tests at all today — see A-RUST-TESTS); `sleep` returns the remainder;
`flock` either takes a deadline or says in a `ponytail:` line that it does not and why.

### A-UNSAFE-CONTRACTS Four `// SAFETY:` notes that do not justify their code `main` `open — 2026-09-18`
**What.** The four-line paragraph "Nothing here dereferences the caller's buffer — it is handed
straight back to the kernel" appears verbatim 14× in `park.rs` and is **false** at two of them:
`ignis_park_select` (`:604`) does `std::ptr::read`, `FD_ISSET` and `ptr::write` on the caller's fd
sets, and `ignis_park_ppoll` (`:578`) dereferences `ts`. `locklib::install` (`:190`) says "the
entries live for the process lifetime" while the code copies the array to the **stack** — it happens
to be sound because `zend_register_functions` copies each entry, but that is not the stated reason.
`worker_arg` (`temporal.rs:199`) is a **safe** fn whose body dereferences a raw pointer behind a
`// SAFETY:` comment asserting a caller contract its signature does not require.
**Why it matters.** ADR-0041 gates on every `unsafe` block having a note, and the gate counts notes,
not whether they are true. A duplicated contract is one nobody re-reads, which is exactly how two of
them came to sit over code they do not describe.
**Acceptance.** The two `park.rs` sites get notes about what they actually touch; `locklib`'s states
the real reason; `worker_arg` becomes an `unsafe fn`. The 14 copies are not a style problem to sweep —
whatever is genuinely common goes in the module doc once, and each site keeps what is its own.

### A-LEAKS-RUST Three thread-local and process-wide maps that only grow `main` `open — 2026-09-18`
**What.** (a) `temporal.rs:41,81` — `WORKERS` never removes an entry; `zif_shutdown` (`:333`) calls
`initiate_shutdown()` and leaves it, while the module doc says "for the process lifetime (or until
shutdown)". (b) `offload.rs:44` — `JOBS` entries are removed only in `done()`, so a worker that dies
mid-job (a PHP fatal) leaks the entry *and* leaves `Reactor::inflight` permanently raised on the
caller — which is the same failure `submit`'s own doc says was already fixed once for the rejection
path. `CALLBACKS` (`:39`) leaks the same way when the calling thread dies, and `callback()` (`:126`)
then blocks a worker on `rx.recv()` with no timeout. (c) The offload job queues are `unbounded()`
(`:55-56`).
**Acceptance.** A dead worker's job fails its caller and releases the op; `WORKERS` drops what it
shuts down; the reply wait has a timeout. Bench: kill an offload worker mid-job under
`bench/e16-offload.sh` and show `ignis_inflight()` returning to zero.

### A-SWALLOWED-RUST Errors dropped where the drop changes behaviour `agent` `open — 2026-09-18`
**What.** `offload.rs:57` `let _ = POOL.set(…)` — a second `initialize(n)` is silently ignored, so
`--offload N` after a pool exists keeps the old width and says nothing. `main.rs:319`
`let _ = h.join()` — an offload thread that panicked is indistinguishable from one that exited
cleanly. `http.rs:248` `let _ = stream.set_nodelay(true)`. And on the PHP side, `Loop::dispatchUnawaited()`
(`Loop.php:425`) drops an unawaited array payload matching none of its three tags with no log at all —
`Router::release()` relies on exactly that, which makes the silence load-bearing and undocumented.
**Acceptance.** Each either logs at `warn` with what was lost, or carries one line saying why losing
it is correct. The `dispatchUnawaited` case needs the second, and then a test pinning it.

### A-RUST-TESTS The two files with the most `unsafe` have no tests at all `agent` `open — 2026-09-18`
**What.** Eleven of twenty modules have no test module — 3,092 lines, 47 % of the crate — and they
are the wrong eleven: `park.rs` (1,051 lines, the largest file in the crate), `wait.rs` (where V-91's
`RESULTS` leak lived), `output.rs`, `superglobals.rs`, `route.rs`, `watch.rs`, `locklib.rs`,
`embed.rs`, `temporal.rs`, `async_core.rs`, `tsrm.rs`. The two best-covered files, `reactor.rs` and
`config.rs`, are the two with the fewest `unsafe` blocks.
**Why it is tractable.** Much of it needs no engine: `would_block`'s socket-state matrix
(`park.rs:201-244`), `ms_ceil` (`:530`), `park_pollfds`' cancel bookkeeping (`:496-527`), the
`select` fd-set copy and refill (`:608-667`), `watch.rs`'s settle/CAS logic (`:142-183`),
`output.rs`'s framing threshold, `embed.rs`'s `exit_status`/`SENTINEL` (`:111-124`).
**Acceptance.** A test module in each of those, testing the pure logic named above. Not a coverage
number: ADR-0041 already says the percentage cannot mean much here (V-78).


### S-RESET-AUTOSCOPE A `kernel.reset` service should become fiber-scoped, not merely un-reset `main` `open — raised by the owner 2026-09-19, deferred with a reason`
**What.** The owner's specification for `S-RESET-FIBER` had a second half: a `ResetInterface` tag
auto-registers the service as fiber-scoped and takes it out of the resetter. V-95 built the first half
— the resetter is a no-op in fiber mode — and left this on purpose.
**Why it was not built with the rest.** `FiberScopePass` states its own rule in its doc block: *only
rows that are measured are listed; adding one is a line here plus a decorator, and it needs a test
that fails without it.* Blanket auto-scoping of every tagged service is the opposite — it would put a
proxy in front of fifteen services on the E21 fixture alone, none of them measured, and a wrong proxy
is a harder defect than the state it was meant to isolate.
**The inventory to work from**, read out of that fixture's compiled container:
`App\Service\ResetWitness`, `cache.app`, `cache.property_info`, `cache.security_expression_language`,
`cache.security_is_csrf_token_valid_attribute_expression_language`,
`cache.security_is_granted_attribute_expression_language`, `cache.serializer`, `cache.system`,
`cache.validator`, `container.env_var_processor`, `controller.cache_attribute_listener`, `doctrine`,
`doctrine.debug_data_holder`, `security.logout_url_generator`, `security.untracked_token_storage`.
Most are caches, shared on purpose, which must **not** be per fiber — and that is the point: the tag
is not a reliable signal of "per-request state", which is exactly what auto-scoping would assume.
**Acceptance.** Per service, in the order of that list: a probe that shows two overlapping requests
disturbing each other through it, then a fiber-scoped replacement, then the probe green with the
control still failing. A service whose probe cannot be made to fail is not scoped, and the reason is
written down. Ends with one line in `FiberScopePass` per scoped service, which is what its rule asks.
**Constraints.** `main`. `FiberServicesResetter` already names the services that lose their reset, in
debug, so nothing here is silent while it waits.

---

## Closed — index

Every closed item, one line each. The full text of each — several are the only written account of
an investigation — is in [`BACKLOG-CLOSED.md`](BACKLOG-CLOSED.md).

| item | outcome |
|---|---|
| **S-RESET-FIBER** | Symfony's service reset is process-wide, and a fiber can be inside the services it resets `main` `DONE 2026-09-19 (V-95)` — `services_resetter` is a no-op in fiber mode; E21's `/reset` arm, control 3/3 and fixed 0/3 |
| **A-ARGINFO** | Four PHP functions reflect somebody else's parameter names `main` `DONE 2026-09-19 (V-94 addendum)` — one table per function, declared by an `arginfo!` macro; `bench/php/arginfo_names.php` reflects all 37 against the stubs and is in smoke |
| **A-REACTOR-POISON** | A panic in the dispatcher poisons a mutex and every later op panics with it `main` `DONE 2026-09-19 (V-94)` — one `lock_unpoisoned()` in `crates/ignis/src/lock.rs`, 42 sites across eight files, with a test that poisons a real mutex and takes the data anyway |
| **A-OUTPUT-FIBERKEY** | `output.rs` keys per-fiber state by address with no destroy hook `main` `DONE 2026-09-19 (V-94)` — keyed by `zend_fiber_context` now and dropped by a destroy observer registered at MINIT; probe `bench/php/output_abandoned_fiber.php` in smoke, 2000/2000 leaked before the fix and 0/2000 after |
| **S0-RESPOND-START** | `ignis_respond_start` is registered twice and called by nobody `main` `DONE 2026-09-18 — removed as an intentional API change (V-92 addendum); php-api.md records it` |
| **M3-1** | Symfony recipe in README `agent` `done (README, validated by main)` |
| **M3-2** | Retire the Symfony worker wrapper `agent` `done (shim first, then deleted outright on 2026-09-17 — owner: no back-compat before the first stable release)` |
| **M3-3** | `bench/e8-symfony.sh` on the package route `agent` `done (V-41: main re-run 4,680.64 / 13,629.61 req/s vs agent's 4,663 / 13,360; dev-mode 404, prod leg is M3-8)` |
| **M3-4** | Laravel: research the route in `research` `done (docs/research/25-laravel.md, validated by main)` |
| **E18-R1** | Which blocking symbols the installed libraries actually import `research` `done (research 26: 31-symbol export list incl. __poll_chk; curl = threaded resolver, libpq = synchronous getaddrinfo)` |
| **E18-R2** | Who holds a lock across a blocking call `research` `done (research 27: OpenSSL park, libcurl park, libpq park-except-GSSAPI, libphp block; locklib spec)` |
| **E18-R3** | Interposition from the executable binds inside a shared library `main` `done (research 28: poll from inside libcurl hit 8×, gate ≈ 8.3 ns, this libcurl never calls getaddrinfo)` |
| **E18-B** | The five benches, control arms measured today `agent` `done (validated by main: control curl 20,337 / pgsql 20,558 ms re-run within 1 %; V-45)` |
| **E18-I1** | `bench/php/e18_pgsql.php` exits 0 and prints nothing under the park build `main` `done (2026-09-17, V-46): the loop's idle checks ignored $ready/$pending — ignis_poll() resumes C-parked fibers itself, the fiber they settle waits in $ready with nothing in flight, and a nested all() inside an outer fiber broke out of the loop; pre-existing (the old sleep hook showed it too), not an offload interaction; fixed in php/packages/runtime/src/ignis.php; H33 through the bench script: 308/301/303 ms` |
| **E18-C** | Retire the PHP-level wrappers universal park makes redundant `main` `done (V-46, V-48, V-49): sleep.rs, sockets.rs, accept.rs, stream.rs + the rustls path in reactor.rs all deleted; three mechanisms left (park, offload, context). Remaining wrappers are adapters, which carry no mechanism by design` — owner question 2026-09-17 |
| **M4-1** | Hold-time on pool leases `main` `done (V-44); removed 2026-09-18 with the pool it measured (ADR-0015 closed) — the idea is alive as S-POOL-LEASE-AGE for the userland pool` |
| **M4-2** | Per-dependency bulkhead + circuit breaker (B2) `main` `DONE 2026-09-18 — see S1-BULKHEAD; removed the same day with ADR-0015, and the bounded wait now lives in ignis/doctrine's pool (PoolTimeoutException)` |
| **M4-4** | `/_ignis/metrics` (Prometheus) `main` `done (V-55): 22 metrics, promtool clean, 1.9-5.5 ms under wrk -c200; per-reactor publication from each PHP loop` |
| **M4-8** | Pool survival across a thread respawn `agent` `done (V-42/V-43: re-run by main — TWO defects found, fixes are M4-11 and M4-12)` |
| **M4-11** | A PHP thread dying with a lease leaks the pool permit for ever `main` `done (V-42 addendum: available back to max)` |
| **M4-12** | Every thread opens its own pool — ADR-0015's "process-wide" is false as deployed `main` `done (V-43 addendum: one pool id across threads)` |
| **M5-1** | Release workflow `agent` `done (validated by main: worktree dry run bumps Cargo.toml+Cargo.lock to 0.0.2-rc.1 and prints the tag commands; workflow parses; the tag push and the first real run are the owner's)` |
| **M5-2** | Migration guide from php-fpm / FrankenPHP / RoadRunner `agent` `done (validated by main: 25 V-n citations, no number without a V-n paragraph)` |
| **M5-3** | Operator guide `agent` `done (validated by main: every config key and IGNIS_* var documented, defaults match config.rs)` |
| **M5-4** | Nightly perf job `agent` `done (validated by main: schedule+dispatch only, E1 1144.3 ms / E2 201.08 ms + 3.48 us on my run, dir=gt for throughput; the runner-side numbers wait for the first dispatch)` |
| **R-LIMITS-CONFIG** | The four listener limits are env-only and were promised in comments `agent` `DONE 2026-09-18` |
| **S0-E9** | The negative control does not detect nondeterminism `main` `DONE 2026-09-18` |
| **S0-FIBER** | `gh9916-009.phpt` fails in fiber mode on two unrelated machines `CLOSED 2026-09-18 (V-89): the test asserts the script shutdown sequence, which fiber mode does not have — deterministic, refuted the collector hypothesis, passes in main mode; baseline 78 -> 77 with the reason attached, and the stale PASSED row in the committed .tsv is what kept it "unexplained"` |
| **S1-SESS** | Refuse to start on `session.save_handler=files` `main` `NOT BUILT — premise refuted by V-80` |
| **S1-FLOCK** | A blocking `flock` inside a fiber kills the thread, silently `main` `DONE 2026-09-18 — V-81` |
| **S1-COOKIES** | `ignis_respond` cannot carry two headers with the same name `main` `DONE 2026-09-18` |
| **S1-ANSWER** | `Loop::answer()` loses the request and kills the loop `main` `DONE 2026-09-18` |
| **S1-BULKHEAD** | Per-dependency bulkhead and breaker (M4-2/B2) `main` `DONE 2026-09-18, then removed with ADR-0015 — the userland pool has the bounded wait, not the breaker (S-POOL-LEASE-AGE)` |
| **S2-LEAKS** | The four smaller defects the tests pinned `agent` `DONE 2026-09-18` |
| **S2-STREAM-CANCEL** | A streaming handler never learns the client left `main` `DONE 2026-09-18` |
| **S3-RSS-DRIFT** | 13 MB of RSS growth that is not the extensions `main` `DONE 2026-09-18 — V-82` |
| **S3-STATS-SCOPE** | `/stats` reports one thread's PHP heap, not the process's `agent` `DONE 2026-09-18 — renamed` |
| **S3-NUMBERS** | The numbers this work made stale `main` `DONE 2026-09-18 — V-10 addendum, V-79 addendum 2, V-82` |
| **S3-LIMITS** | `[limits]` in `ignis.toml` `agent` `DONE 2026-09-18` |
| **S3-STAN8** | PHPStan level 8 `agent` `done 2026-09-18 — level: 8 in php/phpstan.neon, both configs clean` |
| **R-MAIN-RED** | `main` has been red since before the quality work, on two gates `main` `DONE 2026-09-18 — green on all ten jobs` |
| **R-LINT-GATE** | Blocking fmt/clippy/deny + real coverage, Rust side `main` `done (V-78)` |
| **R-PHP-GATE** | Blocking php -l/phpstan/cs-fixer + phpunit with coverage `agent` `done (V-79)` |
| **H-1** | Remaining hardcoded `127.0.0.1:8080` in benches `agent` `done (validated by main: quoted grep clean, wrk-hello over IGNIS_LISTEN 24,884 req/s)` |
| **H-2** | `bench/frankenphp/Caddyfile` and `bench/fpm/nginx.conf` paths `agent` `done (validated by main: templates render, stops with a clear message at the missing frankenphp binary)` |
| **H-3** | `examples/app.php` gains `/_ignis/health` mention and the budget `agent` `done (validated by main: 7 routes unchanged, /stats has budget, health ok)` |
| **H-4** | CI concurrency: a push every few minutes cancels every run `agent` `done — behavioural check 2026-09-17: the in-progress run survives; queued runs still collapse to the newest (GitHub keeps one pending per group), so a burst is verified by the tip's run — recorded in docs/orchestration.md` |
| **H-5** | `scripts/smoke.sh` runs against the image `agent` `done (validated by main: --image ignis:local GREEN, same 7 routes as binary mode, 0 containers left)` |
| **H-7** | IDE/static-analysis stubs for the runtime's functions `agent` `done (validated by main: stub set == module.rs set, php -l ok, guard yields to the real function)` |
| **H-9** | `bench/compare.sh` writes its results header before checking what it can run `agent` `done (validated by main: preflight stop leaves compare.md untouched)` |
| **H-10** | `exit()` is logged as a fatal `main` `done (exit() -> debug, fatal -> warn with status=255; exit(7) still 7; nextest, smoke GREEN; phpt below)` |
| **H-11** | `examples/grpc_server.php` fails static analysis `agent` `done (validated by main: phpstan L6 0 errors on the example + grpc lib)` |
| **R-DNS** | Name resolution blocks the whole PHP thread `main` `open — owner decision 2026-09-17: record the risk, do not build yet`. `getaddrinfo()` has no file descriptor, so universal park cannot park it: park's mechanism is "wait for fd readiness, then make the real call", and there is nothing to wait on. Every name lookup therefore blocks its OS thread for the whole resolve — microseconds against a warm cache, seconds against a sick resolver, and with N threads that is N requests stalled, not one fiber. **What is affected:** libpq (`pg_getaddrinfo_all` calls it synchronously on the caller's thread, research 27), and PHP's own `fsockopen`/`stream_socket_client`/`gethostbyname` to a hostname. **What is not:** libcurl in this build uses its *threaded* resolver — it resolves on its own pthread and the fiber waits in `poll` on a socketpair, which park already handles (research 26, 28). **The plan when it is built** (research 31, and ADR-0020 corrected to match): interpose the symbol and run the **real** `getaddrinfo` on `tokio::task::spawn_blocking` behind an `Op::Custom`, suspending the fiber on that completion exactly as `pg.rs` does; hand the caller glibc's own pointer untouched, so nothing fabricates an `addrinfo` chain and `freeaddrinfo` needs no interposition at all. Known costs: a hung resolver holds a blocking-pool thread (the pool is bounded — needs a cap and a metric, or the stall just moves one floor down), and a lookup already started cannot be cancelled. **Rejected for now: writing our own resolver in Rust** (hickory-dns / `lookup_host`). It would be genuinely async and cancellable, but it has to reproduce `nsswitch.conf`, `/etc/hosts`, `resolv.conf`'s `search`/`ndots`/`options`/`timeout`/`attempts`, `AI_ADDRCONFIG`/`AI_V4MAPPED`/`AI_CANONNAME`, service names from `/etc/services`, IDN and RFC 6724 address sorting — and being 99 % right there fails as "connected to the wrong address" or "a Kubernetes service name does not resolve" (ndots:5 and search domains), not as "slow". musl and systemd-resolved both carry famous divergences here. **Operational mitigation available today, no code:** run a local caching resolver (nscd, systemd-resolved, dnsmasq in the pod) so the call returns from cache in microseconds. Documented in docs/operate.md. A middle option if measurement ever demands it: keep the real call and put a TTL-respecting cache in front of it — most of the benefit, none of the semantics risk. |
| **R-STREAM** | There is no chunked HTTP response, so a streamed body is collected in memory `main` `DONE 2026-09-17 (V-74): built as designed — ignis_respond_start/chunk/end over a hyper channel body, the chunk op awaited for back-pressure, Ignis\Http\Stream in userland, Symfony's StreamedResponse wired through it. TTFB 906 ms -> 1.7 ms, transfer-encoding chunked, the thread keeps serving (3/3 ticks). Gated by bench/e23-stream.sh. What remains is the ob_start() lock on the Symfony path, below.` The only response primitive is `ignis_respond(id, status, headers, body)`: one call, whole body. gRPC has a streaming pair (`ignis_grpc_send`/`ignis_grpc_end`, E10) but that is tonic, not hyper. So `Symfony\Component\HttpFoundation\StreamedResponse` — the class whose entire purpose is not holding the body — is collected into a string by `Ignis\Output::capture()` and handed over in one piece. A 1 GB export is 1 GB of PHP memory and then 1 GB of Rust memory, silently. Same for `BinaryFileResponse`. **What it needs:** `ignis_respond_start(id, status, headers)` / `ignis_respond_chunk(id, bytes)` / `ignis_respond_end(id)` over a hyper streaming body, with `ignis_respond_chunk` returning an op the fiber **awaits** — a bounded channel plus an awaited op is real back-pressure, and it keeps the thread free while the client drains. **The PHP side is already possible:** measured (V-72) that a fiber CAN suspend inside an `ob_start()` handler, so the handler can forward each chunk and await the op; memory stays at one chunk. **What does not change:** the output-buffer stack is per thread whatever the transport is, so `Output::capture()`'s exclusivity is still required — streaming does not remove it, it only bounds the memory. **Gate before it lands:** a 1 GB streamed response with RSS flat, a slow client applying back-pressure without stalling the thread (a ticker fiber must keep running), and `Content-Length` absent with `Transfer-Encoding: chunked` present. |
| **R-STREAM-CANCEL** | A streaming handler never learns the client left `main` `DONE 2026-09-18 — see S2-STREAM-CANCEL`. `http::handle` sets `guard.answered = true` the moment the oneshot resolves (`http.rs:348`), and for a streamed response that is when the **headers** go out, not the body. After that a client hang-up is never delivered as `Outcome::Cancelled`. A fiber parked in `Stream::write()` still finds out — the channel closes and the send errors (`module.rs`) — but one parked on a slow query between chunks does not, and keeps producing for a client that is gone. Fix is to keep the cancel path armed for the lifetime of the stream, not just until the first byte. Gate: start a stream, kill the client mid-body, assert the handler's fiber is cancelled within the same bound E11 uses for whole-body responses. |
| **R-HEADERS-MULTI** | The response header map cannot hold two headers with the same name `main` `DONE 2026-09-18 — see S1-COOKIES`. `ignis_respond` takes `array<string,string>` and hyper is handed one value per name, so a response with two `Set-Cookie` headers loses all but the last: `IgnisWorkerRunner::headers()` assigns `$headers['set-cookie']` inside a `foreach` over the cookie bag (`IgnisWorkerRunner.php:88-90`), which is a correctness bug, not the "E8 caveat" the comment calls it. Every framework that sets more than one cookie per response is affected, and so is any `Link`/`Vary`/`Warning` pair. Fix is a list-valued header shape across the boundary (`array<string, string\|list<string>>`) and `header_pairs` emitting one pair per value. Gate: a response with two cookies arrives with two `Set-Cookie` lines. |
| **R-LOOP-SPLIT** | `Loop::runUntil` is 124 lines doing seven things — and one flag silently skips two of them `main` `done 2026-09-17 (8613a4a): runUntil 125 -> 30 lines, one boot(); the metrics half of the claim did NOT hold ($publishStats defaults true) and the confirmed damage was gc_disable() never running on the await-before-serve path — architecture review 2026-09-17; the flag defect confirmed by reading`. **The defect first:** `$chaosInit` (`Loop.php:306`) gates three unrelated initialisations at `runUntil:172-176` — `chaosInit()`, `gcInit()` **and** the `ignis_publish_stats` probe — but `awaitOp:78-79` calls `chaosInit()` alone, which sets the flag (`:368`). So on any path where `awaitOp` runs before `runUntil` — a script that awaits before serving — `IGNIS_LOOP_GC` never initialises and `$publishStats` stays false, which means that loop **never publishes its metrics** and `/_ignis/metrics` reports it as one whose numbers are ageing without bound. A flag standing in for a state machine. **The rest:** `runUntil` wants to be the same `while` over four named calls — `startPending()` (180-189), `resumeReady()` (191-203), `idle()` and `dispatchEvents($events)` (252-278); the six-term idle condition is written twice (`:209`, `:249`) and must be one predicate; the event demux decodes a Rust tagged union with an `is_array`/`isset`/string-ladder instead of one `match ($payload['kind'] ?? null)`; `$budgetInit` (`:50`) is checked per request (`:410-412`) for an environment variable that cannot change; `$requestHandler`/`$rawRequestHandler` (`:30`, `:402`) are two nullable callables tested together in three places where one typed handler would say which mode it is. All of it belongs in one `boot()` called from `serve()`. |
| **R-NOT-DOING** | Decided against, so nobody re-litigates it `main` `closed 2026-09-17 — both architecture reviews agreed independently`. **The three `FUNCTIONS` tables** (`module.rs:829-940`, ~100 duplicated lines): the explicit array length makes a mistake a compile error, and the macro that removes the repetition is harder to read at 3am than the repetition. **A shared base class or interface for the three fiber-scoped services** (`FiberRequestStack`, `FiberTokenStorage`, `FiberEntityManager`): the shared idea is already extracted — it is `Scope` plus `Scope::clear()` at the request boundary — and what remains in each is the *shape* of the state (a stack, a slot, a lazily-built resource with a rollback). A base class would save three lines in two of three and buy an abstraction. **Shrinking `FiberEntityManager`'s 35 generated forwarders**: its docblock argues the case and is right. **The `hrtime` phase timers in `Loop`** and `Published::set`'s string match: measurement is this project's currency and they are cheap. **`Output::capture()`'s `ob_start` fallback** despite having no production caller: it is what lets the unit suite run under plain php-cli. |
| **H36** | The lock hazard has a number `main` `done (V-51, 2026-09-17)`: shim + harness written and run — `park` breaks (fiber 1 sees the mutex held by a parked fiber), `block` serializes (400 ms). The four internal functions live in `crates/ignis/src/php/locklib.rs` and are registered only when `IGNIS_LOCKLIB` names the .so. |
| **H-13** | `php/packages/runtime/src/ignis.php` at phpstan level 6 (filed as a second `H-12` until 2026-09-18) `agent` `done 2026-09-17 (V-79 addendum): level 6 is 0 across every package, not just this file; the entry's path was stale after the package split` |
| **H-8** | Retire the `IGNIS_ADDR` name `agent` `done (validated by main: no code hits, classic_server answers on IGNIS_LISTEN)` |
| **H-6** | Delete `bench/php/pg_async_probe.php`'s hardcoded DSN default `agent` `done (validated by main: rc=2 on both probes)` |
| **S-DBAL-POOL** | A connection per request is parity with php-fpm, not the answer `main` `built 2026-09-18 (V-85 addendum 2, rewired to container configuration in addendum 3): `ignis_doctrine.pool.size: N` gives each thread a pool of N, warmed at boot, leased per fiber, returned with the V-21 reset; per-fiber stays the default. What is left is the number below.` A pool of 4 serves 30 sequential requests on one backend where the per-fiber mode opens 30, and a pool of 2 serves six overlapping requests correctly and in turn. **Still unmeasured, and it is the number that should decide the default:** `pdo_pgsql` per query against `Ignis\Pg`'s 112 µs on the same box — V-21 recorded "Not measured yet" and it still is. Also unbuilt, in rough order of appetite: a pool shared across threads (today it is per thread, so the process opens `threads × N`), `stats()` on `/_ignis/stats` next to the `Ignis\Pg` numbers, and a reset for drivers other than PostgreSQL — MySQL and SQLite get none today and carry their session settings into the next request, which the documentation states rather than hides. |
| **S-RELOAD** | Code reloading without restarting the process `main` `DONE 2026-09-19 (V-90): IGNIS_WATCH over get_included_files(), workers one at a time, SIGHUP, gate bench/e25-reload.sh. What is left is the watcher's blind spot — a file never loaded is never watched — for which Node's answer is --watch-path and ours is not built.` In worker mode a file change does **nothing, ever**: the kernel is booted once per thread and its classes live in that engine until the thread dies. Classic mode has no such problem — the script is included per request and opcache's defaults pick a change up within ~2 s — so this is specifically the Symfony path, and today the only answer is restarting the process. **Most of the machinery already exists:** `spawn_worker` gives a respawned thread a fresh TSRM context (new class table, new statics), the listener is process-wide so a thread can leave and return without dropping a connection (V-17: recovery inside the 50 ms tick, 95.7 % of baseline), and `http_unregister_current()` takes one thread out of dispatch while the others serve. **What is missing is the trigger** — a worker's script ends only on a fatal or by returning, and `serve()` never returns (`S-SERVE-STOP`). **The order to build it:** (1) `Ignis\stop()`, with unregister → drain → return rather than today's return → unregister; (2) `SIGHUP` as a rolling restart, one worker at a time, which is the half of M4-5 that has been waiting for it — gate: `wrk` across a reload with zero non-2xx and the new code answering afterwards; (3) **a watcher over `get_included_files()`** — Node's model (`--watch` follows "the entry point and any required or imported module"), and for us the *primary* rather than the fallback, because the owner named the deciding property: restarting the worker after each request — RoadRunner's `pool.debug`, where they landed after deleting their own watcher — **destroys concurrency in development**, and every bug of the last week (V-68, V-69, V-85) needs two requests overlapping to appear. Measured on our own fixture: one request includes **283 files** (258 vendor, 23 `var/cache`, 2 own) against **4,621 PHP files under `vendor/`** — 16× fewer inotify watches, the compiled container watched for free because the application loaded it, and no ignore list to maintain. The gap is Node's gap: a file never loaded is never watched, answered by an explicit `--watch-path` that adds to the set rather than replacing it. PHP reports the set through a zif after boot and after each request (first call 283 strings, later ones an almost-always-empty delta); Rust owns the watches, the debounce and the grace period, and the workers stop one at a time so requests keep overlapping through the reload; (3a) per-request restart stays available as an opt-in for people who want RoadRunner's behaviour, documented with its cost — it serialises development, so a fiber-scope bug will not show up there; (4) `opcache_reset()` on an **explicit** reload while a respawn-after-fatal keeps leaving SHM untouched — the two events mean different things, and a production ini with `validate_timestamps=0` would otherwise make reload silently do nothing. **Rejected:** rebuilding the kernel per request in debug mode — it costs a boot per request, which is what worker mode exists to avoid, and it only reloads what the kernel rebuilds, so the illusion holds until it does not. |
| **E18-A** | ADR-0020 `main` `done (accepted; policy table from research 27). H32/H33 CONFIRMED by V-45, H35 by research 28, H36 by V-51; H34 answered "not as written" — this box's libcurl uses a threaded resolver (R-DNS)` |
| **H-12** | E4 hello throughput is 58k req/s on this box today, V-6 measured 128k `main` `CLOSED 2026-09-18 — V-82 (the trailing "open" this status also carried is struck: the residual 4-5% is recorded, not open work): the bisect is refused with a number, the effect is under the instrument's noise`. `open — measured (V-46 addendum 3): V-6's own commit gives 61.5–63.1k on this box, so 128k → 62k is the box; the code-side drop 2026-09-15 → HEAD is ≈ 4–5 % (58.3–60.0k) and still worth one bisect in a quiet slot` — V-46 addendum 2: park on and off both ~58k, p99 1.8 ms, quiet box, same wrk shape as V-6 (`-t2 -c64 -d10s`, 1 PHP thread). Either the box changed (WSL2 kernel 6.18 now; V-6's kernel not recorded) or something landed between 2026-09-15 and cycle 1 (budget admission, health route, superglobals lazy swap, log floor). Bisect with `git bisect run` over `bench/wrk-hello.sh` before any perf claim cites V-6 again. |
