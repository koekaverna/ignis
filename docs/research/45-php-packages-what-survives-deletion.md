# Research 45 — the PHP packages, and which defects disappear if the path is removed

Date: 2026-09-19. Owner's question: not "how do we fix these defects" — every one below is already
filed — but **which of them disappear if we remove the path instead of guarding it**. This is a
read-only inventory: nothing in `php/packages/` or `crates/` changed while writing it. Every number
below is measured (`wc -l`, `grep -c`, `git log`, `grep -rl`), not estimated.

## 0. Method

For each of the eleven directories under `php/packages/`: source lines (`find src -name '*.php' |
xargs wc -l`), test lines and test-method count (`grep -Ec 'function test[A-Z]|#\[Test\]'`),
`composer.json`'s own one-line description, and **exercised-by** — split into two honest tiers
because the repository conflates them everywhere else:

- **unit-gated**: `php/phpunit.xml` runs it on every push (`.github/workflows/ci.yml` job
  `php-unit`), or it is the one package excluded from that file (`ignis/revolt`) and has its own
  reason.
- **integration-gated**: a `bench/e*.sh` script exercises the package end-to-end (a real Ignis
  binary, real dependencies) **and that script is invoked by `.github/workflows/ci.yml` or
  `nightly.yml`** — not merely present in `bench/`. This distinction turned out to be the single
  biggest finding: most integration benches exist and pass when run by hand, and are wired into
  nothing.

Backlog ids are quoted from `BACKLOG.md` as of this commit; every quote was re-read in full, not
matched by grep alone.

## 1. The table

| package | src | test (methods) | composer description (verbatim, trimmed) | unit-gated | integration-gated | backlog ids | verdict |
|---|--:|--:|---|---|---|---|---|
| `runtime` | 2427 | 1698 (93) | "The Ignis userland: the fiber scheduler … Requires the ignis binary; does nothing under php-fpm or php-cli." | yes | yes — `examples/app.php`, `examples/hello.php`, `scripts/smoke.sh` (E1/E2/E4/E6/E7/E11/E12/E13), `bench/e15-phpt.sh`, `bench/e15-frankenphp.sh` (all in `ci.yml`) | R-GLOBALS, A-CLASSIC-FINISH, S0-FRANK, M3-5a, M3-5b | **KEEP**, mandatory — everything else requires it. Classic mode inside it is a **NARROW** target, see §3. |
| `offload` | 708 | 1098 (49) | "`Ignis\offload()`: synchronous worker threads … for the calls universal park cannot reach." | yes | yes — `nightly.yml` runs `bench/php/e16_offload.php` directly through the real pool; `examples/app.php` calls `Ignis\offload()` | A-ADAPTER-MECHANISMS (the `eval()`) | **KEEP.** Test lines exceed source lines (1098 vs 708) — the opposite of bloat. |
| `grpc` | 301 | 307 (24) | "gRPC handlers and clients … tonic serves h2 next to HTTP, the PHP side sees plain data." | yes | **no** — `bench/e10-grpc.sh` exists, has a real demo (`examples/grpc_server.php` + `examples/grpc/greeter.proto`), E10 is CONFIRMED (V-20) — but the script is not called from `ci.yml` or `nightly.yml` | A-ADAPTER-MECHANISMS (the `preg_match`) | **KEEP**, real feature, real defect. Fix the defect (§2); wire `e10-grpc.sh` into `nightly.yml` — nothing but a human running it by hand currently catches a wire-format regression between `reactor.rs`'s error completion and this file. |
| `doctrine` | 1063 | 639 (33) | "Doctrine ORM under Ignis fibers: one EntityManager per request instead of one per process …" | yes | **no** — `bench/e21/e21-fiber-scope.sh` exists and is what found and confirmed a request answering with *another request's row, HTTP 200* (V-85); it is not in `ci.yml` or `nightly.yml` | S-DBAL-DIRECT, S-POOL-LEASE-AGE, S-EXCLUSIVE | **KEEP** — this is the package the pain map now credits for PHP-FPM row 8 and RoadRunner row 1, since the runtime-owned pool it used to be was deleted (ADR-0015, V-87). Given what its own bench caught once, leaving it un-gated is the highest-regret gap in this inventory; wire `bench/e21` in. |
| `symfony-runtime` | 457 | 363 (22) | "symfony/runtime adapter … boots the kernel once and serves each request in its own fiber, with a per-fiber RequestStack." | yes | **no** — `bench/e8-symfony.sh` exists (E8 CONFIRMED, V-16: 7.2k/25.2k req/s), not wired | none open | **KEEP** — this is the flagship "plain Symfony, unmodified" story (pain map, FrankenPHP-1/RoadRunner-8). Wire `e8-symfony.sh` (and M3-8's prod-mode leg) into `nightly.yml`. |
| `revolt` | 154 | 115 (4, and excluded from `phpunit.xml`) | "Revolt event-loop driver backed by the Ignis reactor (E7): AMPHP libraries run unchanged …" | **excluded on purpose** | yes — `bench/e15-revolt.sh` in `ci.yml`, runs AMPHP's own `DriverTest` (≈80 tests) against `IgnisDriver` | A-REVOLT-UNTESTED, R-REVOLT-FLAKE | **KEEP.** Smallest file in the tree (154 lines) unlocking an entire ecosystem (E7, V-13: 7/8 AMPHP examples byte-identical). Two cheap, real defects, not a deletion case — see §4. |
| `swoole` | 503 | 171 (9) | "A shim for a subset of the Swoole coroutine API … A compatibility layer, not a Swoole implementation." | yes | yes — `bench/e15-swoole.sh --all` in `ci.yml` (`timeout 1800`) | A-SWOOLE-TICKERS | **DELETE**, recommended — see §5. The strongest case in this inventory. |
| `phpstan` | 170 | 150 (1) | "A PHPStan rule for the one defect fiber scope cannot fix by itself … (V-96)." | yes | yes, continuously — `php/phpstan.neon` `includes:` it, and `scripts/gate.sh`'s `stan` step runs it on every gate | none open | **KEEP** — smallest and cheapest package here, self-hosted (the repo is its own user, every push), catches a real class of bug. Thin test count (1) is proportionate to one rule class, not a gap. |
| `temporal` | 197 | 83 (5) | "Ignis host for the official temporalio/sdk-php (ADR-0040) … runs the worker as one workflow fiber plus a pool of activity fibers." | yes | **no** — `bench/e20-sdkphp.sh` exists (E20 CONFIRMED, V-61/64/65/66), needs a real `temporal/sdk` composer install + core, not wired | none open | **KEEP** — the current, minimal, correct architecture; supersedes `temporal-prototype` below. Wire `e20-sdkphp.sh` into `nightly.yml` (it is heavier than a unit test, same shape as `e9-temporal.sh`'s existing CI job, so the infra precedent already exists). |
| `temporal-core-transport` | 1381 | 689 (9, plus a standalone `tests/conformance.php` needing no Ignis binary at all) | "… Host-agnostic — it needs an activation source, not a particular runtime. Written to be offered upstream." | yes | yes — `tests/conformance.php` runs against both the ignis binary and stock PHP inside `bench/e20-sdkphp.sh` (same wiring gap as `temporal` above) | none open | **KEEP** — the actual engineering asset behind E20; the biggest of the three Temporal packages because it is a complete translation layer, deliberately written to know nothing about Ignis. |
| `temporal-prototype` | 460 | 409 (20) | "The pre-ADR-0040 workflow runtime of our own … **Frozen** — kept because the replay test was built on it. New work uses `ignis/temporal` with the official SDK." | yes | yes — `bench/e9-temporal.sh` in `ci.yml` | (E9's caveat in GOALS.md; see §6) | **DELETE**, recommended — see §6. Second-strongest case in this inventory, and the project's own composer.json already calls it frozen. |

Totals: 7821 source lines / 5722 test lines across all eleven packages (`find php/packages/*/src
-name '*.php' \| xargs wc -l`, same for `tests`).

`examples/app.php`, which `CLAUDE.md` calls the API spec, requires exactly two packages:
`runtime` and `offload` (`grep -n "^require" examples/app.php`). That is expected, not a finding
against the other nine — `app.php` is the core scheduler API demo; `examples/classic_server.php`
and `examples/classic_worker.php` cover classic mode (`runtime` only), `examples/grpc_server.php`
covers `grpc`. **`doctrine`, `symfony-runtime`, `temporal`, `temporal-core-transport`,
`temporal-prototype`, `revolt` and `swoole` have no file under `examples/` at all** — their only
demonstration is a `bench/e*.sh` script (for the first three, one that CI never runs) or, for
`revolt`/`swoole`, a ported upstream test suite. That absence is itself worth recording: for six of
eleven packages, the only place a reader can see the package actually used end-to-end is a script
this inventory found is not gated.

## 2. `A-ADAPTER-MECHANISMS` — mechanism, shape, or defect, verified against the actual code

CLAUDE.md's mechanism budget says adapters carry none of their own. Three do, confirmed by reading
each site named in the backlog item, not by trusting its description:

**`offload/src/ignis-offload.php:260`, `proxyClass()` — `eval()`.** Confirmed: it builds a
`namespace Ignis\Offload\Proxy; class X extends \X { … }` string via reflection (parameter lists,
return types, one forwarding call to `Router::method()` per public method) and `eval()`s it once
per class, memoized by `class_exists('Ignis\Offload\Proxy\\' . $class, false)`. **Verdict: shape,
not a new wait mechanism** — it produces an LSP-compatible subclass so an offloaded object still
passes `instanceof` and type hints, which `__call()` alone cannot do when the parent class already
declares the methods being overridden. `ADR-0016` describes the proxy generally but never mentions
`eval()` by name or defends it as a decision. **Action: no deletion — name it in ADR-0016** (one
paragraph: why codegen over `__call`, why `eval` over a build step) so the next reader does not
have to re-derive this. Zero lines removed; a documentation gap closed.

**`temporal/src/CoreSource.php:66`, `poll()` — catches `\RuntimeException` and returns `null`.**
Confirmed. sdk-php's `ActivationSource::poll(): ?string` contract has exactly one non-string
outcome, "clean shutdown," so a transport failure and an intentional stop are indistinguishable to
the caller. **Verdict: shape forced by an upstream interface, but currently over-broad** — it
catches *every* `RuntimeException`, including a malformed completion, not only the reactor's actual
shutdown signal. **Action: narrow the catch**, not delete the package: if `ignis_temporal_poll`
already distinguishes "worker is stopping" from "the completion could not be parsed" (a question
for the Rust side, out of this slice's scope), that distinction should reach here as a typed
exception rather than being flattened before it arrives. Filed as a new, small defect; does not
change the package's KEEP verdict.

**`grpc/src/ignis-grpc.php:185`, `result()` — `preg_match('/code=(\d+)/', $m, $mm)`.** Confirmed,
and confirmed to be exactly what the item suspected: **a plain defect, not a mechanism or a
justified shape.** `php/phpstan.neon`'s own `IgnisCompletion` type alias —
`array{kind: 'error', message: string}|…` — shows the reactor's error completion carries free text
and nothing else; this file recovers a gRPC status code by pattern-matching that text. Any change
to how `reactor.rs` phrases an error message silently turns every status into `UNKNOWN`, with no
compile-time or type-level signal. **Action: the completion needs an integer `code` field
alongside `message`** so this becomes a struct read instead of a regex — Rust-side, `main` lane,
outside this slice's read-only scope, but the fix is now specified precisely enough to file as its
own backlog item rather than live inside `A-ADAPTER-MECHANISMS`.

**Net effect on `A-ADAPTER-MECHANISMS`:** closes as "answered" — one item is a shape needing one
ADR paragraph, one is a shape needing a narrower catch, one is a confirmed defect needing a
Rust-side field. None of the three justifies deleting or narrowing `offload`, `temporal`, or `grpc`
as packages; all three keep their KEEP verdict from §1.

## 3. Classic mode — the minimum that keeps its one real guarantee

`runtime/src/classic.php` (27) plus `Classic/{Finished.php (8), polyfills.php (36), functions.php
(83), InputStream.php (96), Runner.php (348)}` — 598 lines total, inside the `runtime` package, not
a separate one. It carries four open items: `R-GLOBALS`, `A-CLASSIC-FINISH`, `S0-FRANK`, and the
Laravel plan split across `M3-5a` (shippable today) and `M3-5b` (needs an ADR and an `unsafe` FFI
hook in `superglobals.rs`).

The one guarantee classic mode exists for, per its own docblock and V-53/V-54, is real: **`listen()`
is the only shape in which the entry script's top-level variables are actual PHP globals** — the
thing a legacy docroot needs and nothing else in Ignis gives it. `serve()` was retrofitted
concurrent on 2026-09-18 (DECISIONS.md) by moving per-request state into `Ignis\Scope`, which fixed
the superglobal-leak class of bug but left two things unresolved:

- **`R-GLOBALS`'s harder half is still open**: an unguarded top-level `function foo() {}` in a
  script served twice is an uncatchable "Cannot redeclare" fatal that kills the worker. The only
  real fix is per-request RINIT/RSHUTDOWN, which the worker model exists to avoid — so this is
  correctly filed as "document the constraint" (`require_once`/`function_exists`), not "build the
  fix." Nothing to delete; the open half of this item should be closed as *documented, not built*,
  which the backlog entry already effectively says.
- **`A-CLASSIC-FINISH` is a real, narrow bug**: `finish()` throws `Finished`; `Runner::handle()`
  (used by `serve()`) catches it, but the documented `listen()` shape in
  `examples/classic_worker.php:116` — `while ($file = accept()) { include $file; respond(); }` — has
  no catch, so the throw unwinds the whole accept loop and the thread stops serving. This is a
  handful of lines to fix (catch `Finished` around the `include` in the documented `listen()` loop
  or inside `Runner::accept()`/`end()`), not an argument for removing `listen()`.

**`M3-5b` is where "remove the path instead of guarding it" bites.** `M3-5a` already ships Laravel
in classic mode today by setting `budget.fibers = 1` — the runtime's existing admission control,
zero new code — which serialises requests per thread exactly as Octane itself does. `M3-5b` proposes
a *second*, independent way to get there: swap `Container::$instance` and the Facade cache on the
fiber-switch observer, an ADR-scale decision plus a new `unsafe` hook in `crates/ignis/src/php/
superglobals.rs`, to make Laravel safe under *concurrent* classic-mode requests. Nothing under
`M3-5b` is built yet (`git log` on `superglobals.rs` shows no Laravel/Container work), so this is a
zero-line, pre-emptive deletion: **decline `M3-5b`**. `M3-5a`'s control run (budget=1 passes,
budget=2 fails, per its own acceptance test) is already the proof that serialisation is sufficient
for the case anyone has asked for; concurrent Laravel throughput is a new product ask with no
evidence behind it yet, not a gap in what's shipped. If that evidence shows up later, `M3-5b` is a
fresh decision with fresh numbers, not a backlog item that was always going to be built.

**Recommendation: NARROW.** Keep `listen()` and `serve()` (598 lines, no deletion — both entry
points serve different real needs and neither is large). Fix `A-CLASSIC-FINISH` (small, in place).
Close `R-GLOBALS`'s open half as *documented constraint, not built*. Decline `M3-5b` before it costs
a single line of `unsafe` FFI code — this is the one recommendation in this note that prevents
future growth rather than removing existing code. `S0-FRANK` is orthogonal (a test-bisect debt, not
an architecture question) and is not resolved by any of the above; it stays open on its own.

## 4. `ignis/revolt` — 154 lines unlocking an ecosystem, with two cheap real defects

`IgnisDriver.php` is the entire package: 154 lines mapping Revolt's `Driver` interface onto
`Ignis\Loop`. What it buys is disproportionate to its size: E7 is CONFIRMED (V-13, 7/8 AMPHP
examples byte-identical) and `bench/e15-revolt.sh` runs AMPHP's own upstream `DriverTest` — not a
ported subset, the real suite — against it in CI. This is the opposite of the question the owner is
asking: nothing here is superfluous.

Both open items are real but neither argues for touching the package's shape:

- **`A-REVOLT-UNTESTED`**: `php/phpunit.xml` excludes `packages/revolt/tests` by design, with a
  comment explaining why (`IgnisDriverTest` needs the real reactor, so it only means something run
  through `bench/e15-revolt.sh` inside the actual binary). The cost named in the backlog is real:
  the incompatibility between `IgnisDriver` and `Ignis\Loop` that produced V-93 could not have been
  caught by a plain unit test because nowhere in the unit suite could host one. The callback
  bookkeeping (`$pendingWatch`, `$watchOf`) is pure PHP state machinery with no reactor calls in the
  read path — testable against a fake reactor without the binary. **Recommend: add that suite**
  (a handful of methods against a fake), not touch the 154-line driver itself.
- **`R-REVOLT-FLAKE`**: `bench/e15-revolt.sh` runs six arms and reports one number; measured
  2026-09-18 as 79 on one local run, 80 on the next, 80 in CI with the failure in a *different* arm
  each time — `testExecutionOrderGuarantees`, a timing race AMPHP's own `StreamSelectDriverTest`
  also fails. The gate currently reads whichever arm happened to run, which cannot distinguish a
  real regression from the coin flip. The item's own acceptance criterion is precise and cheap:
  report the **minimum across the arms** (matching how the baseline of 80 was set in the first
  place) or quarantine the one flaky test by name. Either is a same-size fix to
  `bench/e15-revolt.sh`, not a code change to the package.

**Recommendation: KEEP**, unchanged in shape. Both fixes are cheap and belong to the test harness
around the package, not the package.

## 5. `ignis/swoole` — the strongest delete case

`shim.php` is 503 lines, single file; `ShimTest.php` is 171 lines, 9 methods. `bench/e15-swoole.sh
--all` runs in `ci.yml` with a **30-minute timeout**, the longest of any package-specific CI step in
this inventory (`grep timeout .github/workflows/ci.yml` — `e15-swoole` is the only one at 1800s;
`e15-revolt` is 900s, `e15-frankenphp` is 900s).

Three independent findings, all confirmed by reading the code and the docs rather than the backlog
description alone:

1. **The package's own documentation disclaims real usage.** `docs/packages/swoole.md`: "it exists
   so code written against Swoole can be run — and its suite ported — **not so anyone writes new
   code against it**." No file under `examples/` uses it. `grep -rl "ignis/swoole"` outside the
   package's own directory finds only docs — no bench, no example, no other package depends on it.
   **I could not find a real application user for this package in this repository or its docs** —
   its only exercised role is the E15c compatibility measurement, which is already DONE and its
   number (44/153) already recorded in `GOALS.md` and `docs/pain-map.md` (Swoole row 5). Continuing
   to re-run the same already-answered question on every push is what costs the 30 minutes.
2. **`A-SWOOLE-TICKERS` is confirmed exactly as filed.** `shim.php:225` and `:235`:
   `\Ignis\Loop::spawn(static function () use ($stop): void { while (!$stop()) { \Ignis\sleep(5); }
   });` (the outer driver) and `while (!$stop()) { \Ignis\sleep(1); }` (the nested-fiber path). Both
   are polling loops standing in for a real wait point — up to 5 ms of latency per `Co\run`
   completion — and a fourth wait shape the mechanism budget (CLAUDE.md, ADR-0037) does not list:
   park, offload, and fiber-scoped context are the three; a `sleep`-loop ticker is none of them.
3. **Coverage bought is modest even on its own terms.** 44/153 upstream Swoole hook tests pass
   (V-22/23) — about 29%. The package's own docs are explicit that this will not change: "the shim
   changes nothing about what is hooked… if a suite needs more than the subset, the answer is the
   runtime API, not a bigger shim."

**What deleting would cost.** The E15c number (44/153, already banked) would no longer be
re-verified on every push — but it is a one-time inventory question ("how much of Swoole's surface
would a shim need"), already answered and written into `GOALS.md` and the pain map, not a
regression-prone product guarantee like E4's throughput or E11's cancellation latency. No other
package, example, or bench depends on `swoole`. If a real user shows up later wanting a specific
Swoole-shaped library to run unmodified, that is new evidence for a fresh, smaller shim built to
that library's actual surface — not a reason to keep a 503-line general shim with a budget-violating
polling loop alive against a hypothetical.

**What narrowing would cost instead**, if the owner wants to keep the E15 signal: replace the two
`sleep()`-loop tickers with a `Future` the last coroutine resolves and the driver awaits once (the
same primitive every other package in this tree already uses) — a same-order-of-magnitude rewrite
of `Coroutine::drive()`/`Event::wait()`, not measured here because it is unwritten; and move
`bench/e15-swoole.sh` from every push to `nightly.yml` to cut the recurring 30-minute cost while
keeping the signal.

**Recommendation: DELETE**, with narrow as the fallback if the owner judges the E15c number worth
continuously re-verifying. Either way, `A-SWOOLE-TICKERS` closes: by deletion, the mechanism is gone
with the file; by the narrow fix, the mechanism is replaced with one already in budget.
**Kill criterion for the delete recommendation**: a real, named consumer of `ignis/swoole` — an
application or library, not a test suite — surfaces. None exists today.

## 6. `ignis/temporal-prototype` — frozen by the project's own admission

`composer.json`: *"The pre-ADR-0040 workflow runtime of our own (ADR-0013, V-19)… **Frozen** — kept
because the replay test was built on it. New work uses `ignis/temporal` with the official SDK."*
460 source lines, 409 test lines, 20 test methods. Superseded, by the project's own words, by
`ignis/temporal` (197 lines) + `ignis/temporal-core-transport` (1381 lines) — E20, CONFIRMED,
V-61/64/65/66, the shipped path.

What keeps it in the tree today is `bench/e9-temporal.sh`, wired into `ci.yml` (`e9-temporal` job),
which starts a real `temporal server start-dev`, runs `temporal-prototype/bin/worker.php`, and
asserts a replay negative control: a mutated workflow history must fail replay.

That assertion is the part worth reading closely before recommending deletion, and it does not hold
up. `GOALS.md`'s E9 row still carries, as of this inventory (2026-09-19), the caveat dated
2026-09-17: *"the CI negative control prints `REPLAY_OK` while sdk-core evicts the mutated history
for nondeterminism, so V-19's 'mutated workflow FAILS' is not being re-verified by CI."*
`BACKLOG-CLOSED.md`'s `R-MAIN-RED` (closed 2026-09-18, "green on all ten jobs") documents the same
gap in detail: the harness greps for `REPLAY_FAILED`, the mutated run prints `REPLAY_OK
activations=3 eviction_errors=0` in the same log line that carries sdk-core's own `evicted:
reason=NONDETERMINISM`, and the counter that should have caught it does not. `R-MAIN-RED` closed
because the surrounding *gate* went green (an unrelated `unzip`/composer fix and the frankenphp
count), not because this specific assertion was corrected — its own "Acceptance" text ("assert on
the eviction reason, not [REPLAY_FAILED]") describes a fix that is not evident in the current
`replay.php`. So the CI job keeping `temporal-prototype` alive is not currently proving the one
thing it exists to prove.

**What deleting would cost.** The E9 result itself — "workflows as fibers, deterministic replay is
architecturally possible" — was already established (V-18, V-19) before ADR-0040 concluded that
hosting the *official* SDK on sdk-core is the better path; VALIDATION.md's numbers stand regardless
of whether this code still exists in the tree (CLAUDE.md: don't rewrite history — the note, not the
prototype, is what the rule protects). Re-proving E9's specific claim forever, on an architecture
the project has already moved past, buys diminishing value — especially while the proof itself is
compromised. If the *product* still wants a continuously-verified replay-determinism guarantee, the
680-line, host-agnostic `tests/conformance.php` in `temporal-core-transport` (needs no Temporal
server, already used by `bench/e20-sdkphp.sh`) is the right place to add a mutated-history arm
against the shipped path — new, small, and worth doing regardless of what happens to the prototype.

**Recommendation: DELETE.** `temporal-prototype` (869 lines total) and the `e9-temporal` CI job.
**Kill criterion**: if `temporal-core-transport`'s conformance harness cannot be made to exercise an
equivalent mutated-history negative control, that is new evidence the shipped path lacks a
capability the prototype had, and the deletion should be reversed or the gap rebuilt there instead —
not patched by keeping the frozen code and its already-broken assertion alive.

## 7. `A-PHP-FLOOR` — verified, unrelated to deletion

Confirmed as filed: root `composer.json` requires `php: >=8.4`; every package matches except
`ignis/revolt` (`>=8.1`, deliberate — it promises AMPHP users 8.1) and
`ignis/temporal-core-transport` (`>=8.2`, deliberate — written to be offered upstream).
`php/phpstan.neon` sets `phpVersion.min: 80200`, matching the temporal-core-transport floor rather
than the root's 8.4, with `packages/revolt/phpstan.neon` re-running the same config at `80100` for
that one package. The two package-level exceptions are intentional and documented; the analyser
config's drift is what the item flags. This is a one-line fix (derive the phpstan floor from the
manifests, or a test asserting they match) with no bearing on any delete/narrow/keep verdict above —
noted here because the task asked every open item on the list to be resolved to a decision, not
silently dropped.

## 8. Answering the owner's question directly

**Measured line delta if every recommended deletion is taken:**

| deletion | src | test | total |
|---|--:|--:|--:|
| `temporal-prototype` | 460 | 409 | 869 |
| `swoole` (if delete over narrow) | 503 | 171 | 674 |
| **total** | 963 | 580 | **1543** |

1543 of 13543 total package lines (7821 src + 5722 test), 11%, deleted rather than guarded — from
two packages the project already, in its own words or its own CI evidence, does not stand behind:
one explicitly "frozen," the other explicitly "not so anyone writes new code against it," with its
CI job's central assertion independently shown not to hold.

**`M3-5b` declined pre-emptively** adds no measured deletion (nothing was ever built), but removes a
planned `unsafe` FFI hook and an ADR's worth of future work from the queue before it costs a line —
the "remove the path instead of guarding it" move applied to a plan rather than to existing code.

**Backlog items that close by removal, not by fixing:**
- `A-SWOOLE-TICKERS` — closes with the `swoole` package (mechanism deleted with the file), or is
  answered in place if the owner takes the narrow fallback instead.
- `M3-5b` — closes by decision not to build it; `M3-5a` already covers the shipped requirement.

**Backlog items that stay open and need an actual code fix, not a deletion** (found or sharpened
while answering the owner's leads, not resolved by them): the `grpc` `preg_match` defect (needs an
integer `code` field on the reactor's error completion — Rust side, out of this slice), the
`temporal` `RuntimeException` catch (needs narrowing to the actual shutdown signal), `A-CLASSIC-
FINISH` (catch `Finished` in `listen()`'s documented loop), `A-REVOLT-UNTESTED` (a fake-reactor unit
suite for the driver's own bookkeeping), `R-REVOLT-FLAKE` (report the minimum across arms), `S0-
FRANK` (name the regressed FrankenPHP test — unrelated to any package deletion), `A-PHP-FLOOR`
(derive the phpstan floor from the manifests).

**Packages with a real user, said plainly, not implied by size:** `doctrine`'s user is any
Symfony+Doctrine application under concurrency — and its bench is the thing that caught a request
answering with another request's row at HTTP 200 (V-85), which is as real as a defect gets.
`symfony-runtime`'s user is any Symfony kernel (E8, 7.2k–25.2k req/s). `grpc`'s user is anything
calling or serving gRPC on the shared listener (E10, 16.7k req/s vs RoadRunner's 5.1k–11.4k).
`revolt`'s user is the entire AMPHP ecosystem, unmodified (E7, byte-identical examples), in 154
lines. `offload`'s user is `examples/app.php` itself, directly. `phpstan`'s user is this repository,
on every gate run. `temporal` + `temporal-core-transport`'s user is anyone running Temporal
workflows through the official SDK (E20) — and the latter's stated future user is
`temporalio/sdk-php` itself, upstream.

**Package I could not find a user for:** `ignis/swoole`. Its own documentation says it is not meant
to be used the way the other ten are; the only role I could find exercised anywhere in the tree is a
compatibility measurement that already has its answer recorded elsewhere.
