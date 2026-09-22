# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Working agreement

- The full brief is in BRIEF.md. Re-read it, STATUS.md and the tail of JOURNAL.md after any context reset before doing anything else.
- Commit at every stage transition of the loop, not only per hypothesis; `wip(cycle-N/stage): …` mid-stage is fine.
- Push a batch only once `scripts/gate.sh` is green, never on top of a red run. On 2026-09-18 three pushes in a row left `main` red and the second and third were made without reading the first one's verdict; `cargo deny` would have caught the licence on the developer's box in one minute.
- Never leave more than 30 minutes of work uncommitted.
- `main` is the branch of record and the branch worked on (owner decision 2026-09-16, DECISIONS.md). The `night-N` branch model is gone (owner, 2026-09-18) and so is every night artefact. Do not rewrite history.
- Never ask for permission or confirmation. Decide, log in DECISIONS.md, continue.
- Numbers or it didn't happen. Every claim in STATUS.md links to a VALIDATION.md entry.

### Disk (owner rule, 2026-09-16)

- Before any build run `df -h /`; if less than 15% is free, stop and clean first.
- Comparison build trees (php-fpm, FrankenPHP, RoadRunner, ext-grpc) live under `/tmp/cmp/` and are deleted the moment their numbers are in VALIDATION.md; keep only the binaries `bench/compare.sh` and `bench/e10-compare.sh` need (`/opt/frankenphp-bin`, `/opt/php85-fpm/sbin/php-fpm`, `/tmp/cmp/rr` + `/tmp/cmp/rr-app`).

### Document roles

BRIEF.md (owner's mission, immutable) → JOURNAL.md (timestamped line per stage transition) → HYPOTHESES.md (H-n, falsifiable, time-boxed) → docs/research/NN-*.md → docs/adr/NNNN-*.md (with a kill criterion) → VALIDATION.md (V-n: raw numbers, exact command, machine state) → STATUS.md (one screen, links to V-n) / GOALS.md / ROADMAP.md / DECISIONS.md. BACKLOG.md is the work queue (open items first, closed ones as a one-line index). docs/pain-map.md is re-read and re-statused at every REASSESS.

## Code style (owner, 2026-09-17)

- SOLID, DRY, KISS, YAGNI — in that order of blame when a review finds bloat.
- No abbreviations in names. `request`, not `req`; `connection`, not `conn`. Established
  domain spellings that are not abbreviations of anything are fine (`zval`, `php`, `http`).
- No comments inside a function body. If a block needs explaining, extract it into a
  method whose name is the explanation. The method name, the argument names and the
  variable names carry the meaning; a comment is a hint that one of them is wrong.
- Doc blocks on methods, types and public functions are allowed: one or two lines, what
  and why, not how.
- Exceptions, because the architecture demands them: the `// SAFETY:` line on every
  `unsafe` block, the ownership/lifetime/who-frees notes at the FFI boundary, and
  `ponytail:` markers naming a deliberate ceiling. These are contracts, not commentary.

## Build and test

Everything needs PHP 8.5.10 ZTS+embed at `/opt/php85-zts`; `PHP_CONFIG` (default `/opt/php85-zts/bin/php-config`) drives `crates/ignis-sys/build.rs` bindgen and linking, and `LD_LIBRARY_PATH=/opt/php85-zts/lib` is needed at runtime.

```bash
scripts/build-php.sh                  # idempotent, ~7 min → /opt/php85-zts
cargo build --release -p ignis
cargo nextest run --workspace
cargo nextest run -p ignis php::zval  # single test / filter
cargo +nightly miri test -p ignis -- php::zval php::module   # miri covers the unsafe modules only
scripts/gate.sh [--fast]              # what CI runs, in CI's order; --fast skips smoke and the compat suites
scripts/smoke.sh                      # end-to-end gate: build, tests, app.php, output isolation, E22/E23, E1/E2, E13, E6, E7, E11, E12
```

Run a script: `./target/release/ignis [--threads N] [--supervise] <script.php> [args...]`.

Benches are one script per expectation (`bench/eN-*.sh`), each writing into VALIDATION.md; `bench/compare.sh` produces `bench/results/compare.md` vs FrankenPHP and php-fpm. The comparison suites (E15) run in CI and are gated against `bench/results/e15-baseline.txt` by `scripts/ci-gate.sh <suite> <log>` — pass counts may never drop.

The second **engine ABI** (non-thread-safe PHP, S-NTS-MODE, V-113) is a separate prefix and target
dir too: `scripts/build-php-nts.sh` then `PHP_CONFIG=/opt/php85-nts/bin/php-config
CARGO_TARGET_DIR=target-nts cargo build --release -p ignis` (enables `cfg(php_nts)`). It serves on
one PHP thread and refuses `--threads` above 1 and `--supervise`; `scripts/nts-checks.sh`
is its acceptance and `.github/workflows/nts.yml` runs it. **Run it with `LD_LIBRARY_PATH` unset** — both prefixes install a `libphp.so` with the same soname and the
variable beats RUNPATH, so the usual `LD_LIBRARY_PATH=/opt/php85-zts/lib` makes it die in the loader.

Useful env: `IGNIS_THREADS`, `IGNIS_PHP_INI` (the embed SAPI has no `-d`/`-c`/`-n`), `IGNIS_CHAOS`/`IGNIS_CHAOS_P`/`IGNIS_CHAOS_SEED` (random fiber switch at every await point), `IGNIS_NO_SUPERGLOBALS` / `IGNIS_NO_UNIVERSAL_PARK` (hook-off controls — every hook claim needs one), `IGNIS_PARK` (the policy table: `lib` or `lib:symbol` rows, ADR-0037), `IGNIS_LOOP_GC`.

## Architecture

One process, two worlds that only ever exchange plain data over channels:

- **tokio side** (`http.rs`, `grpc.rs`, `watch.rs`, `reactor.rs`) — hyper 1.x auto h1/h2 front door, tonic on the same listener, the development file watcher, all timers and socket readiness. There is no database client: the runtime-owned PostgreSQL pool was deleted on 2026-09-18 (ADR-0015 closed, V-87) because `pdo_pgsql` parks and is faster (V-86). The listener is plaintext (ADR-0032) and rustls is gone with the stream transport factory (ADR-0037 cycle 3): outbound TLS is PHP's own `ext/openssl`, parked like any other syscall.
- **PHP side** — N OS threads, each with its own embedded ZTS engine context and *its own* `Reactor`; requests are dispatched to the least-inflight thread (ADR-0010).

`reactor.rs` is the only bridge. PHP calls `ignis_submit_sleep()` / `ignis_watch()` and `ignis_poll(timeout)`; an `Op` is `Sleep`, `Watch`, `CancelWatch` or `Custom` — the `Connect`/`Read`/`Write`/`Upgrade` variants went with the transport factory; HTTP requests, gRPC calls and timer completions all arrive on that one completion channel, so a PHP thread has **exactly one wait point**. Invariants: no Zend pointer ever crosses to tokio, PHP never awaits a tokio future, an `Op` is plain data.

`crates/ignis/src/php/` is the FFI/Zend boundary — `embed.rs` (engine lifecycle, `!Send` `Engine`, `WorkerThread::attach` per thread), `module.rs` (the `ignis` internal module: `ignis_submit_sleep`, `ignis_poll`, `ignis_serve`, `ignis_respond`, …), `zval.rs`, `wait.rs` (the C-side park registry: op id → suspended fiber, resumed by `ignis_poll`), `park.rs` + `crates/ignis/csrc/park.c` (universal park, ADR-0020/0037: the interposed libc calls, on by default, policy from `IGNIS_PARK` — this is how unmodified `file_get_contents`/`fsockopen`/`ext/sockets`/`sleep()` park the fiber), `superglobals.rs` (zend_observer fiber-switch hook swapping `$_SERVER`/`$_GET`/`$_POST`/`$_COOKIE` per fiber), `scoped.rs` (ADR-0042 per-fiber properties). `crates/ignis-sys` is raw bindgen over the embed SAPI headers.

`php/packages/runtime/src/ignis.php` is the userland scheduler: `Ignis\Loop` (fiber pool — parked Fibers are reused, which is the single biggest win of the project, V-4), `Future`, `async()`, `all()`, `sleep()`, `deadline()`, `Scope`, `serve()`. It is deliberately shaped like a Revolt driver (`php/packages/revolt/src/IgnisDriver.php`). Integrations layer on top without new primitives: `php/packages/grpc/`, `php/packages/temporal/`, `php/packages/symfony-runtime/`, `php/packages/runtime/src/classic.php` (one package per integration since V-62).

`examples/app.php` is the API spec — the file an application developer should be able to write. Unimplemented parts are feature-guarded and reported, never faked. Change it only with intent.

### Mechanism budget (owner, 2026-09-17; cut to two on 2026-09-22)

Two mechanisms and one table, no more: **park** (syscall interposition with a per-symbol
policy, ADR-0020), **context** (fiber-switch observer slots, ADR-0006/0042), and one policy table
in `ignis.toml` — `symbol | PHP function → park | block`. The third mechanism, **offload**
(synchronous worker threads, ADR-0016), was deleted on 2026-09-22 with the MVP cut (DECISIONS): a
call that cannot park — a regular file, `SQLite3` — blocks its thread, and that is documented, not
worked around. Adapters (Revolt, symfony/runtime, gRPC, Temporal) carry no mechanism of their
own. The transition from seven wait mechanisms to this budget, with its measurements and gates,
is ADR-0037; the inventory is research 29. A new blocking library or PHP function is a table
row, not a hook — anything that needs more is an "outside the budget" entry in ADR-0037 with its
reason.

### MVP scope (owner interview, 2026-09-22 — DECISIONS)

An async platform for Symfony: HTTP, gRPC, server-sent events and websockets as the protocols,
Symfony Messenger asynchronously, Temporal on the official SDK. Kept: park, context, gRPC,
Temporal (`ignis/temporal` + `ignis/temporal-core-transport`), the Revolt driver, symfony-runtime,
development reload, `--supervise`, metrics, classic mode (the FrankenPHP compat gate runs through
it), the NTS mode. Deleted: the offload pool, the Swoole shim, the Doctrine package, backend (b),
`temporal-prototype`, the nightly perf job. Laravel is out of scope.

### Editing rules that come from the architecture

- Every `unsafe` block states why it is sound; the FFI boundary documents ownership, lifetime and who frees. `.claude/hooks/guard-ffi.sh` blocks subagents from editing `crates/ignis/src/php/**`, `crates/ignis-sys/**`, `crates/ignis/src/backend/**` or any file containing `unsafe` — only the main agent touches those.
- A new capability is normally a new `Op` variant + a tokio actor + a thin `module.rs` function, with the logic in `php/packages/runtime/src/ignis.php`. Adding a second wait point to a PHP thread breaks the design.
- Subagents: `bencher` (measurements, comparison builds), `porter` (external test suites, failure classification: ours / not applicable / upstream), `scribe` (reconciles STATUS/GOALS/pain-map with VALIDATION/JOURNAL — never invents numbers).
- **Product mode (owner, 2026-09-16):** the work queue is `BACKLOG.md`, the procedure is `docs/orchestration.md` — the main agent orchestrates, Sonnet agents execute `agent`/`research` items, every agent number is re-run by main before it enters VALIDATION.md.
