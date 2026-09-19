# 47 — The gate and bench surface: what to delete instead of guard

Scope: `scripts/*.sh`, `bench/*.sh` (incl. `bench/e21/`, `bench/e22/`, `bench/e24/`), `.github/workflows/*.yml`,
`php/phpunit.xml`, `php/composer.json`'s `scripts`. Subject is the checking machinery, not the code it checks.
Everything below is measured on this checkout (`main`, 2026-09-19), not estimated.

## The surface, counted

```
$ find scripts bench -name '*.sh' | wc -l
45
$ find scripts bench -name '*.sh' -exec wc -l {} + | tail -1
3014 total
```

37 in `bench/` (2,514 lines), 8 in `scripts/` (500 lines). `.github/workflows/`: 6 files, 755 lines
(`ci.yml` 299, `nightly.yml` 178, `release.yml` 172, `image.yml` 46, `php-image.yml` 36, `docs.yml` 24) —
only `ci.yml` gates a push; `nightly.yml` is schedule-only (perf, opens an issue on regression, never
blocks); `image.yml`/`php-image.yml`/`docs.yml` are deploy workflows with their own narrow smoke checks.
`php/phpunit.xml` is 31 lines. `php/composer.json`'s `scripts` block has 5 entries (`lint`, `stan`, `cs`,
`fix`/`rector`, `test`, `check`).

**CI is 7 named jobs** (`lint`, `unit`, `smoke`, `php-lint`, `php-unit`, `e9-temporal`, `e15`), and `e15` is
a 4-leg matrix (`phpt`, `revolt`, `swoole`, `frankenphp`) — **10 runs per push**, confirming the brief's
count exactly (not 7 runs, not a flat list).

**Duplicated preamble**, measured, not estimated:
- `cd "$(dirname "$0")/.."` — 39 of 45 files (`grep -rlF`).
- A `LD_LIBRARY_PATH` export — 14 files, `PHP_CONFIG` export — 2 files (most benches don't need it directly,
  `target/release/ignis` links it in).
- The `up=0; for _ in $(seq 1 N); do curl ... && { up=1; break; }; sleep 0.1; done` readiness-poll idiom —
  20 files, 24 occurrences (`grep -rcF 'up=0'`).
- `for _ in $(seq 1 ...)` loops generally (readiness polls, retry loops) — 29 files, 48 occurrences.
- `kill ... 2>/dev/null` — 106 occurrences; `wait ... 2>/dev/null` — 47 occurrences (the start-server /
  probe / kill dance).

That is real, measurable duplication — roughly 39 + 24×4 (readiness block averages ~4 lines) + 106 + 47
≈ **480-550 lines** that a `bench/lib.sh` (`cd` to repo root, export the two env vars, `wait_up <url-or-check>
<pid>`, `stop <pid...>`) sourced by every script would delete, without changing what any single bench
measures. That is the concrete "what it would delete" the brief asked for. It is a `MERGE`, not a `DELETE`:
every script keeps its own measurement logic, only the preamble moves.

I did **not** write that helper — this note is read-only by mandate. The number above is the delta a
follow-up `agent` item would produce; it is bounded (not "estimated") by the four greps above, which are
reproducible.

## What most of the bench/ scripts are, and why most are `KEEP`

CLAUDE.md is explicit: "Benches are one script per expectation." 30 of the 37 `bench/*.sh` files map to a
named `E`/`A`/`B`/`H`/`M`-number from `BRIEF.md`, `HYPOTHESES.md` or a specific ADR, and 17 of the 45 total
scripts are referenced by **nothing** else in the tree (no CI job, no `gate.sh`, no `smoke.sh`, no other
bench). That is not orphaning — `docs/orchestration.md`'s "Gate" step names exactly `cargo nextest`,
`smoke.sh`, and `e15-phpt.sh` (conditionally) as the push gate; everything else is VALIDATION-only,
run by a `bencher` agent and re-run by the orchestrator, which is the documented split between
correctness-on-push (`ci.yml`) and performance-on-demand (`nightly.yml` + manual bench runs). Deleting a
bench because it isn't wired into the push gate would be deleting the instrument for a number `STATUS.md`
or `VALIDATION.md` still cites — checked below, script by script, before any `DELETE`.

## Table 1 — one row per script

Legend: **detects** = what actually makes the script report failure (not what it was written to prove).
**verdict**: KEEP (real, distinct, no cheaper substitute) / MERGE (keep the logic, its preamble moves to a
shared helper) / FIX (keep, but it currently can't do its one job — see backlog id) / DELETE (superseded or
dead).

### `scripts/`

| script | lines | detects | verdict |
|---|---|---|---|
| `build-php.sh` | 42 | build failure; missing module after build (session/pdo_pgsql/Phar checked explicitly) | KEEP |
| `build-php-async.sh` | 27 | build failure for the true-async fork | KEEP, but **nothing calls it** — see `A-BACKEND-B-CI` below |
| `ci-coverage-gate.sh` | 22 | PHP line coverage % under the 31.6 floor, or no coverage line at all | KEEP — real floor, fails on regression |
| `ci-gate.sh` | 47 | E15 pass-count regression vs `bench/results/e15-baseline.txt`, plus a per-test PASS→not-PASS regression (`check_set`) | KEEP — but see `R-REVOLT-FLAKE`: it correctly gates on what its caller hands it, and the `revolt` caller hands it a non-representative number |
| `gate.sh` | 79 | fmt, clippy (default + off-build), `cargo deny`, nextest, php half (stan/cs/phpunit), optionally smoke + **phpt only** | KEEP, but its own usage comment ("skips smoke and the compat suites", plural) overclaims — see gate-step table |
| `release.sh` | 38 | dirty tree, version-bump-didn't-take | KEEP — small, real preconditions checked |
| `smoke.sh` | 203 | build, unit tests (Rust + PHP), 12+ distinct correctness properties (see gate-step table) | KEEP — the single highest-value script in the surface, ranked below |
| `test-php.sh` | 42 | phpunit failure (`exec`, exit code propagates); missing vendor/pcov triggers install, not a silent skip | KEEP |

### `bench/` — mapped to their expectation, with the finding where one exists

| script | lines | maps to | detects | verdict |
|---|---|---|---|---|
| `a3-soak.sh` | 43 | A3 (RSS soak, mixed routes + offload, no monotonic trend past 5–10M requests) | RSS trend, server death, non-zero errors | KEEP — distinct from E3/`rss-1m.sh` (single route, 1M, no offload); both are still cited in `STATUS.md`/`VALIDATION.md` |
| `ab-sleep.sh` | 7 | generic load shape, used by other scripts | n/a (a helper invoked with args) | KEEP (it already *is* the kind of shared helper the brief asks for, just for one idiom) |
| `app-check.sh` | 128 | deploy-shaped MVP acceptance for any Symfony app on Ignis | route table, `/_ignis/health`, error log greps | KEEP |
| `b1-budget.sh` | 66 | B1 fiber-budget admission control (ADR-0019) | RSS under deep queue, p99 of admitted requests | KEEP |
| `classic-finish.sh` | 41 | classic-mode `finish()` semantics | request ends, worker doesn't | KEEP |
| `compare.sh` | 76 | E4 (Ignis vs FrankenPHP vs php-fpm) | req/s comparison table | KEEP |
| `e10-compare.sh` | 38 | E10/H21c (Ignis vs pure tonic vs RoadRunner grpc) | comparison table | KEEP — distinct from `e10-grpc.sh` (functional + own-load test vs three-way comparison) |
| `e10-grpc.sh` | 29 | E10/H21/H21b (unary+streaming correctness, then load) | grpcurl correctness (`Unimplemented` on unknown method, streaming body), then ghz load | KEEP |
| `e11-cancel.sh` | 25 | E11 (cancel propagation, deadline) | cancellation flag, 504 on deadline | KEEP |
| `e12-inflight.sh` | 23 | E12′ (in-flight request on a dying thread) | 500 at once, not a hang | KEEP |
| `e12-isolation.sh` | 27 | E12 (supervisor: fatal + spin isolation) | other workers keep serving | KEEP |
| `e13-http.sh` | 22 | E13a (200 concurrent HTTP, per-request state) | each response echoes its own request | KEEP |
| `e15-chaos.sh` | 264 | E15e/H22e (Symfony+Doctrine under chaos scheduling) | zero new failures vs stock PHP | KEEP — largest bench script, and the chaos property (`IGNIS_CHAOS`) has no cheaper substitute |
| `e15-frankenphp.sh` | 248 | E15d/H22d (FrankenPHP testdata) | pass count vs baseline (29) | KEEP — see `S0-FRANK` below, the script itself is fine, the *finding* it produced was never closed |
| `e15-phpt.sh` | 111 | E15a/H22a (php-src phpt suites, 3 modes) | per-suite pass counts, per-test regression | KEEP — the only E15 leg `gate.sh` runs locally |
| `e15-revolt.sh` | 148 | E15b/H22b (Revolt DriverTest on IgnisDriver) | `IGNIS_PASSED=<n>` | **FIX** — see `R-REVOLT-FLAKE` below, the number it emits is not the number its own comment says it computes |
| `e15-swoole.sh` | 126 | E15c/H22c (Swoole shim vs swoole_runtime tests) | pass count vs baseline (54) | KEEP |
| `e16-offload.sh` | 19 | E16 (offload pool wall time) | wall-clock vs pool size | KEEP |
| `e18-deadlock.sh` | 18 | H36/ADR-0020#5 (lock hazard is real, policy contains it) | must-deadlock-under-`park`, must-pass-under-`block` | KEEP — a negative control that actually asserts a bad outcome under one arm, rare and valuable |
| `e18-overhead.sh` | 34 | E18-B/H35 (per-syscall overhead, interposed vs not) | ns/call diff | KEEP |
| `e18.sh` | 60 | E18 driver (curl/pgsql/dns through the park policy) | wall time with offload routing off | KEEP |
| `e20-sdkphp.sh` | 34 | E20/ADR-0040 (official temporalio/sdk-php on Ignis) | package runs unmodified | KEEP |
| `e21/e21-fiber-scope.sh` | 88 | fiber-scope isolation under Symfony/Doctrine | cross-fiber state leak | KEEP |
| `e22/e22-multipart.sh` | 32 | E22 (multipart parser parity with PHP's own) | case-for-case agreement | KEEP — run by `smoke.sh` |
| `e23-stream.sh` | 83 | E23/R-STREAM (streamed response reaches client mid-production) | 3 named properties | KEEP — run by `smoke.sh` |
| `e24/e24-pdo-sharing.sh` | 250 | E24 (PDO handle sharing/reuse across fibers, incl. loop GC arm) | connection reuse count; **also the only place `IGNIS_LOOP_GC=0` is exercised today** | KEEP — closest existing thing to `M4-10`'s ask, but it is a *side comparison inside a different bench*, not the p99/RSS-vs-`IGNIS_LOOP_GC` measurement the item asks for — see `M4-10` below |
| `e25-reload.sh` | 128 | E25 (dev reload keeps concurrency) | every arm names the defect it guards (2026-09-19) | KEEP |
| `e6-fetch.sh` | 24 | E6 (unmodified `file_get_contents` parks) | 3×200ms on 1 thread ≈ 200ms not 600ms | KEEP — run by `smoke.sh` |
| `e6-ssl.sh` | 19 | E6′/H25 (`ssl://` + STARTTLS through the hook) | 3 concurrent https fetches timing + 5 verify arms + STARTTLS | **FIX** — see `R-E6SSL-VERIFY` below, the 5 verify arms have no way to report correct vs wrong |
| `e7-revolt.sh` | 77 | E7 (Revolt/AMPHP examples, IgnisDriver vs stock) | output diff vs stock event loop | KEEP — run by `smoke.sh` when revolt vendor is present |
| `e8-symfony.sh` | 87 | E8/M3-3 (symfony/skeleton on Ignis) | health, throughput at 1/4 threads | **FIX** — see `M3-8` below, only measures the dev-mode 404 |
| `e9-probe.sh` | 14 | E9 step 1/H20 (sdk-core in a bare Rust binary, no PHP) | one activation completed | KEEP, but its `PROBE` fallback path is a dead absolute path into a different, now-gone agent session's scratchpad (`/tmp/claude-0/-home-user-ignis/…/tprobe`) — cosmetic rot, one-line cleanup, not a `DELETE` |
| `e9-temporal.sh` | 21 | E9 step 2/H20b (PHP workflow + replay) | live COMPLETED + replay pass + mutated replay must fail | KEEP — run by CI's `e9-temporal` job |
| `limits.sh` | 55 | B8/M4-3 (listener limits actually bite) | 3 limits enforced | KEEP |
| `rss-1m.sh` | 26 | E3 (RSS flat over ≥1M requests, single route) | RSS samples across bursts | KEEP — distinct from A3, both still cited |
| `soak-threads.sh` | 18 | 4-thread mixed soak | 0 socket errors, server alive | KEEP |
| `wrk-hello.sh` | 5 | hello-world throughput, invoked by other scripts | n/a (helper) | KEEP — already the shared-helper pattern the brief asks for, for exactly one idiom |

**Deletions found in this pass that are not on the backlog:** none as clean `DELETE`s. The closest
candidate — `rss-1m.sh` vs `a3-soak.sh` both being "the E3-shaped RSS bench" — does not hold up: `a3-soak.sh`'s
own header says it replaces an *earlier version of A3's own acceptance criterion* ("compared 1M with 10M
and was unsatisfiable"), not `rss-1m.sh`/E3, and both scripts are independently cited in `STATUS.md`'s
per-expectation list and in separate `VALIDATION.md` entries as late as 2026-09-18. Recorded here so the
next person doesn't re-open the same false lead.

## Table 2 — one row per gate step (`scripts/gate.sh`, `scripts/ci-gate.sh`, `scripts/smoke.sh`, `.github/workflows/ci.yml`)

"What makes it fail" is the literal condition; "nothing" means the step's only failure mode is
infrastructure (command not found, image pull failure) — CLAUDE.md's definition of a gate that cannot fail.

| step | where | what makes it fail | verdict |
|---|---|---|---|
| `fmt` | gate.sh, ci `lint` | any formatting diff | real, cheap, real |
| `clippy` (default features) | gate.sh, ci `lint` | any `-D warnings` lint | real |
| off build compiles | gate.sh, ci `lint` | `cargo check --no-default-features` fails | real — this is the exact shape of check that would have caught the `--no-default-features` build breaking, had the equivalent existed for `--features temporal` before 2026-09-19 |
| `cargo deny check` | gate.sh, ci `lint` | licence/advisory/source violation | real — this is the gate whose *absence* burned `main` on 2026-09-18 per CLAUDE.md's own history |
| `nextest --workspace` | gate.sh, ci `unit` | any test failure/panic | real |
| `--all-features` clippy+nextest ("the temporal job") | gate.sh (conditional on `protoc`), ci `e9-temporal` | on this box: **nothing — prints "NOT COVERED HERE" and exits the step successfully** if `protoc` is absent; in CI: real (protoc always present there) | **found the exact incident CLAUDE.md names**: a syntax error in `temporal.rs` passed local `gate.sh` in full for anyone without `protoc`, because the step degrades to a print statement rather than failing closed. It is now *honest* (prints the gap loudly) but still not a local gate — CI is the only place this compiles. Acceptable per docs/orchestration.md's "local is for numbers, CI is for correctness" split, but it means a contributor cannot reproduce a `temporal` feature break before pushing |
| miri (`php::zval`, `php::module` only) | ci `unit` | UB in those two modules' own tests | real for what it covers; **structurally cannot catch anything in the 11 test-less modules** (`park.rs`, `wait.rs`, `embed.rs`, `route.rs`, `watch.rs`, `locklib.rs`, `superglobals.rs`, `output.rs`, `temporal.rs`, `async_core.rs`, `tsrm.rs`) because there is nothing there for it to run — this is `A-RUST-TESTS` in gate form, not a new finding, but worth stating as a gate row: miri's coverage is bounded by what has a test module, and park.rs (1,056 lines, the file with the most `unsafe`) has none |
| Rust coverage (`cargo llvm-cov`) | ci `unit` | nothing — "Reported, not gated" by the workflow's own comment, citing ADR-0041 §6 | **by design, documented, not a silent gap** — the one place in this audit where "cannot fail" is the intended behavior and says so in the file |
| php half: `php -l`, `composer stan`, `php-cs-fixer check`, `phpunit` | gate.sh, ci `php-lint`+`php-unit` | syntax error / phpstan error / cs diff / any test failure | real, four independent real checks |
| PHP coverage floor | ci `php-unit`, `ci-coverage-gate.sh` | line coverage % < 31.6 (fixed floor, not a ratchet, by design) | real |
| `smoke.sh` (12+ properties) | gate.sh (non-`--fast`), ci `smoke` | build failure; php unit failure; output-isolation leak (with its own **negative control that must leak**, `output_isolation.php`); abandoned-fiber binding reuse; arg-info name mismatch; classic `finish()`; E22 multipart; E23 streaming; hello; cli.php overlap (<3s, a real regression trip-wire, separate from the *unnegated* E1 perf number below); app.php route table; E1 fiber-count correctness (gated) vs wall-clock (printed, **not** gated, explicitly to avoid false failures from box noise — the comment explains this is deliberate); E5 thread count; E13 isolation; E15 fixes; flock-park (ticks ≥30, not just "didn't crash"); stream-cancel (client-hangup must reach the producer); multi-valued headers (3 Set-Cookie, 2 Vary — a real RFC 7230 case that was broken by a flat-map bug); E13-http; E6-fetch; E7 (conditional on vendor present) | real, and the single most valuable script measured here — 203 lines buys more distinct, previously-broken properties than any other file in the surface. This is the "gate that is slow but real" the brief says to rank above three fast ones |
| `ci-gate.sh phpt` | gate.sh, ci `e15` matrix | pass count < baseline (per-suite), or a specific test that PASSED in `HEAD`'s committed `.tsv` and doesn't now | real, and the per-test check (`check_set`) is itself a fix for an earlier silent-gate incident (`can_block`/`socket_read_params`, 2026-09-17) |
| `ci-gate.sh revolt` | ci `e15` matrix only (not local `gate.sh`) | `IGNIS_PASSED < 80` | **the number it's fed is not representative — see `R-REVOLT-FLAKE`.** `ci-gate.sh` itself is correct; its input is wrong roughly half the time by the backlog's own measurement (79 vs 80 on identical code) |
| `ci-gate.sh swoole` | ci `e15` matrix only | pass count < 54 | real, though the baseline comment admits `swoole.pass` "flapped the same day" once already — noted, not re-litigated here |
| `ci-gate.sh frankenphp` | ci `e15` matrix only | pass count < 29 | real; see `S0-FRANK` — the gate did its job (caught the regression), the *investigation* of which test regressed was the part that was dropped |
| `e9-temporal` job's grep | ci `e9-temporal` | missing `REPLAY_OK`, missing `REPLAY_FAILED`, or missing `Status.*COMPLETED` in the log | real — and it is one of the few steps in this whole surface with a **negative** assertion (a mutated replay must fail) |

## The backlog items, verified and traded off

**`R-E6SSL-VERIFY`** — confirmed by reading `bench/php/e6_ssl.php`: all five `verify [...]` cases run only
through `./target/release/ignis`; there is no `/opt/php85-zts/bin/php` control anywhere in `e6-ssl.sh` or
the PHP file. `FAIL` on `allow_self_signed` and `cafile + verify_peer_name=false` (which should succeed
against the bench's own self-signed cert) cannot be told apart from stock PHP failing on the same
certificate for an unrelated reason, because nothing runs the stock arm. **Verdict: FIX, small** — add a
stock-PHP leg to `bench/php/e6_ssl.php` (or a twin driven the same way `e15-revolt.sh` drives a stock
baseline) and print both columns, exactly as the backlog's acceptance says. This closes `R-E6SSL-VERIFY` and
turns a "prints FAIL, nobody knows if that's right" step into a real gate — it is the clearest "gate that
cannot fail" in this slice because it has been reporting a five-line FAIL block into VALIDATION.md since
V-49 with no gate anywhere reading it.

**`R-REVOLT-FLAKE`** — confirmed exactly. `e15-revolt.sh` runs `REPS=3` ignis passes (loop `for i in $(seq 1
"$REPS")`) plus 3 env variants (`no-park-only`, `both-hooks-on`, `no-ini`) — six ignis runs total, matching
the backlog's "six arcs." But `IGN_LINE` is set with `[ -z "$IGN_LINE" ] && IGN_LINE=$line` **inside the
REPS loop only**, so it captures the summary of **run 1** and is never touched again — the other two REPS
runs and all three env variants are printed to the log but never reach `IGNIS_PASSED`. `check_set` doesn't
apply here either (that path is `phpt`-only). Whichever arm run 1 happens to be, its flaky
`testExecutionOrderGuarantees` failure or pass becomes the whole gate's verdict. **Verdict: FIX, one line**
— change the capture to the minimum pass count across the 6 recorded lines (`sed`/`awk` over
`$TMP/out-ignis-*.txt` and `$TMP/out-var-*.txt`'s `summary` lines), matching how the 80-baseline was chosen
in the first place per the backlog's own acceptance text. This is a harness bug, and fixing it does not
delete any code — it makes ~40 lines of the script's own output (the 5 extra runs it already computes)
finally count for something, which is a net line *increase* of maybe 3-5 lines, not a deletion. Flagging
this because the brief's framing ("is that a harness bug that deletes code when fixed?") expects a
delete-shaped answer here and the honest one is: no, it's a one-line arithmetic fix, and the fix makes
existing measurement useful rather than removing it.

**`A-BACKEND-B-CI`** — confirmed nothing anywhere (`ci.yml`, `nightly.yml`, `release.yml`, `image.yml`,
`php-image.yml`, `gate.sh`, `smoke.sh`) invokes `build-php-async.sh` or sets `cfg(php_async_abi)`/builds
`crates/ignis/src/backend/async_core.rs` (141 lines). The brief's trade, laid out:
  - **Option A — CI job.** A scheduled (not per-push) job running `build-php-async.sh` then `cargo check
    -p ignis` with `PHP_CONFIG`/`CARGO_TARGET_DIR` pointed at the async prefix. Cost: a second PHP build (the
    backlog notes the true-async engine build is slow, hence "on a schedule"), plus upkeep of a second
    toolchain image or build step. Buys: backend (b) stops silently rotting between the rare times someone
    touches it (it sat with a two-arm match against a nine-variant enum until V-91, per the backlog).
  - **Option B — delete backend (b), or mark it explicitly a recorded experiment.** `async_core.rs` is 141
    lines, entirely behind `cfg(php_async_abi)`, with no build in this repo capable of turning that cfg on
    outside a from-scratch fork build (`scripts/build-php-async.sh` clones `true-async/php-src`). ADR-0003
    already frames it as the "second backend" of a two-backend architecture; the honest move if it isn't
    getting CI is to say so at the top of the file and in ADR-0003, not imply parity with backend (a).
  - **This note's read is Option A is cheap enough to be worth it**: the async fork build is already
    idempotent (`build-php-async.sh`'s own skip-if-present check) and a nightly-cadence job costs nothing on
    every push. But this is exactly a "the owner decides" trade per this task's rules, not a call this note
    makes — both options close `A-BACKEND-B-CI`, and only one of them keeps backend (b) as a maintained
    target rather than a documented experiment. Either way, `scripts/build-php-async.sh` (27 lines) is fine
    as written; it is not itself a defect.

**`S0-FRANK`** — confirmed "half done": `bench/results/e15-baseline.txt` records `frankenphp.pass 29`, and
`ci-gate.sh frankenphp` (line 43: `check frankenphp.pass "$(sed -nE 's/^passed=([0-9]+).*/\1/p' "$log" |
tail -1)"`) is a real, working gate against that number — it did its job. What's missing is exactly what the
backlog says: nothing in `bench/e15-frankenphp.sh` (248 lines) or `ci-gate.sh` **names** which of the five
candidate tests (`server-variable.php`, `cookies.php`, `autoloader.php`, `env/putenv.php`, `file-upload.php`)
regressed and recovered. **Verdict: this is not a gate defect** — the gate correctly caught a drop from 29 to
28 and would catch it again. It's a missing bisection, which is `main`-lane investigative work (`git bisect`
over the 2026-09-17 16:50–18:26 window the backlog names), not a script change. No line count to report here
because the fix is not a diff to this surface.

**`M4-10`** — confirmed `bench/m4-loop-gc.sh` **does not exist** (`ls` fails). `IGNIS_LOOP_GC` is read at
`Loop.php:626`/`:628`; the only place it's exercised today is one comparison arm inside
`bench/e24/e24-pdo-sharing.sh:164` (`reuse_probe "loop GC off, PHP collects cycles itself" ... IGNIS_LOOP_GC=0`),
which measures PDO-handle reuse under a cycle-creating workload, not p99/RSS on a cycle-creating handler at
`wrk -c 64` as the item's acceptance literally asks for. Also confirmed the backlog's follow-on point:
`collectGarbage()` (`Loop.php:386-391`) calls `gc_collect_cycles()` and discards its return (`self::$gcRuns`
only counts *invocations*, never *collected nodes*), so even a purpose-built bench would have no signal for
"did the collection collect anything" without that one-line change first. **Verdict: FIX by building it, not
deleting it** — this is a real, currently-absent bench, not a broken one; nothing in this surface currently
measures the claim in `ADR-0034`/pain-map "RoadRunner 2 / Engine 1", so `IGNIS_LOOP_GC` is shipped and
undocumented-by-measurement. Writing `bench/m4-loop-gc.sh` (a `wrk -c 64` pair, `IGNIS_LOOP_GC=1` vs `0`,
3 reps, p99 + RSS) is new lines, not a deletion — recorded here so the ranked list doesn't imply otherwise.

**`M3-8`** — confirmed. `bench/e8-symfony.sh` never sets `APP_ENV=prod`/`APP_DEBUG=0` anywhere in its
`install.sh` heredoc, and its two `curl`/`wrk` targets are `/_ignis/health` (ignis's own endpoint, not
Symfony's router) and `/` (Symfony's dev-mode welcome page, a 404). **Verdict: FIX, additive** — a second
leg per the backlog's acceptance (`.env.local` + a minimal controller + cache warm + the same `wrk` shape),
printing `dev-404` and `prod-200` lines. This does not delete the dev-mode leg — README already documents
dev mode as the default recipe, so both legs stay meaningful; it's roughly the same +30-40 lines the
backlog's acceptance describes, not a rewrite.

**`A-REVOLT-UNTESTED`** — confirmed at `php/phpunit.xml:22`, `<exclude>packages/revolt/tests</exclude>`,
with the file's own top comment explaining why (`IgnisDriverTest` needs the real reactor). The backlog's
own preferred fix (a fake-reactor unit suite for `$pendingWatch`/`$watchOf` bookkeeping) is code under
`php/packages/revolt/`, not this slice's scripts — but note for the trade: **this item and `R-REVOLT-FLAKE`
compound.** A fake-reactor unit suite that runs in `php-unit`'s ordinary phpunit pass (which already
enforces the 31.6% floor and runs on every push) would catch bookkeeping regressions the *binary-only*
`e15-revolt.sh` currently can't isolate from `testExecutionOrderGuarantees`'s known timing race — exactly
the situation the backlog names (`IgnisDriver`/`Ignis\Loop` incompatibility, V-93, that a unit test had
nowhere to live to catch). Fixing `R-REVOLT-FLAKE` first (one line) is still worth doing regardless, since
even a fake-reactor suite won't exercise the real `ignis_poll()` timing path DriverTest depends on.

## Composer scripts — a small finding not on the backlog

`php/composer.json`'s `scripts.rector` (`vendor/bin/rector process --dry-run`) and `scripts.check` (the
`@lint`+`@stan`+`@cs`+`@test` aggregate) are **never invoked** by `gate.sh`, `scripts/test-php.sh`, or any
`ci.yml` job — grepped directly, confirmed (an earlier grep hit was a false positive: `pcov.directory`
contains the substring "rector"). `gate.sh` and `ci.yml`'s `php-lint`/`php-unit` jobs instead run the
constituent commands piecemeal (raw `php -l`, `composer run-script stan`, `php-cs-fixer check`, `phpunit`
directly) rather than through composer's own `lint`/`check` scripts, so those two composer entries describe
a workflow that doesn't exist anywhere else in the repo. `rector.php`'s config exists (`php/rector.php`) but
nothing ever runs it, dry-run or otherwise — it is pure unexercised tooling. Not urgent (it costs 4 lines
in `composer.json` and a config file, not runtime), but it's the kind of thing this task is explicitly
about: either wire `rector`/`check` into `gate.sh`'s php half, or delete them and the config. Not filed as
a backlog id because it predates this note; flagging it for the next `REASSESS`.

## `gate.sh`'s own overclaim

`scripts/gate.sh`'s header comment says `--fast` "skips smoke and the compat suites" (plural). Read
literally, non-`--fast` gate.sh should therefore run "the compat suites." It runs exactly one:
`bench/e15-phpt.sh`. `e15-revolt.sh`, `e15-swoole.sh` and `e15-frankenphp.sh` are never invoked by
`gate.sh` under any flag — they exist only inside `ci.yml`'s `e15` matrix. That means `R-REVOLT-FLAKE`,
the `A-SWOOLE-TICKERS` shim behavior, and anything like `S0-FRANK` are **only ever discoverable by pushing
and waiting for CI** — the documented local gate cannot reproduce them. This matches
`docs/orchestration.md`'s stated split ("local runs are for VALIDATION numbers and perf... correctness and
compat suites run [in CI]" — the same line appears verbatim atop `ci.yml`), so it is not a bug so much as
an inaccurate comment: `gate.sh`'s usage line should say "the phpt compat suite" (singular), not imply
parity with all four E15 legs. One-word-scale fix, flagged because "what a step can actually detect" was
the brief's own frame and the comment currently overpromises it.

## Ranked list

1. **`R-E6SSL-VERIFY`** — highest-value fix in this slice: a step that has printed an unverifiable FAIL
   block for every run since V-49 with zero gate reading it. Small diff (add a stock-PHP arm).
2. **`R-REVOLT-FLAKE`** — one-line arithmetic bug turning a real six-arm measurement into a coin flip that
   has already produced a false regression in CI (job 105769856567). Smallest diff on this list.
3. **`gate.sh`'s "compat suites" comment** — one word, but it's the gap between "I ran the gate" and "I
   validated E15," and CLAUDE.md's recurring defect is exactly this shape.
4. **`M3-8`** — closes a real blind spot (the only Symfony number on record is a 404 page's throughput).
5. **`A-BACKEND-B-CI`** — real trade, owner's call; this note recommends the scheduled CI job over deletion
   because the build is already idempotent and cheap to run off the push path, but says so as a
   recommendation, not a fact.
6. **`M4-10`** — new bench to write, plus the one-line `collectGarbage()` return-value fix that makes it
   meaningful; no existing code to delete.
7. **`A-REVOLT-UNTESTED`** — real, but its fix lives in `php/packages/revolt/`, outside this slice; sequence
   after `R-REVOLT-FLAKE` per the backlog's own note.
8. **Shared `bench/lib.sh` for the preamble** — ~480-550 measured duplicate lines (`cd`, env exports,
   readiness polls, kill/wait), zero measurement logic lost. Not urgent, not blocking anything, but the
   single largest line-count reduction available in this slice and the literal answer to "what would a
   shared helper delete."
9. **`S0-FRANK`** — the gate is fine; what's missing is a `git bisect`, not a script change.
10. **`composer.json`'s `rector`/`check` scripts** — not on the backlog, smallest stakes, either wire or
    delete.

## What this note found that the backlog didn't have

- `gate.sh`'s "compat suites" (plural) overclaim (above).
- `php/composer.json`'s `rector`/`check` scripts are dead — never invoked anywhere.
- `bench/e9-probe.sh`'s `PROBE` fallback is a stale absolute path into a different, no-longer-existing
  agent session's scratchpad directory — harmless (it's a fallback after the real path), but rot worth a
  one-line cleanup next time the file is touched.
- The `--all-features`/temporal step's local behavior ("NOT COVERED HERE" when `protoc` is missing) is the
  literal incident CLAUDE.md's working agreement names from 2026-09-19 — confirmed it is now a loud,
  documented gap rather than a silent one, but it is still a gap: CI is the only place that feature compiles
  before merge.
- Rust `llvm-cov` in CI is genuinely "reported, not gated" by design (ADR-0041 §6) — the one intentional,
  self-documenting non-gate in the whole surface. Listed so it isn't mistaken for a new finding by the next
  reader of this table.

## What contradicts the backlog

Nothing found contradicts a backlog item's factual claim. The one place this note diverges from the
backlog's *framing* rather than its facts: `R-REVOLT-FLAKE` asks "is that a harness bug that deletes code
when fixed?" — verified the answer is no (a one-line capture fix, net +3-5 lines to actually use the 5 runs
already being computed), not a deletion. Recorded so the next pass doesn't go looking for lines to cut that
aren't there.
