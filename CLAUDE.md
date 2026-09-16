# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Working agreement

- The full brief is in BRIEF.md. Re-read it, STATUS.md and the tail of JOURNAL.md after any context reset before doing anything else.
- Commit at every stage transition of the loop, not only per hypothesis; `wip(cycle-N/stage): …` mid-stage is fine. Push after every commit.
- Never leave more than 30 minutes of work uncommitted.
- `main` is the branch of record (owner decision 2026-09-16, DECISIONS.md). Work on the night branch (`night-N`); it is merged into `main` with a merge commit after CI is green. Do not rewrite history.
- Never ask for permission or confirmation. Decide, log in DECISIONS.md, continue.
- Numbers or it didn't happen. Every claim in STATUS.md links to a VALIDATION.md entry.

### Disk (owner rule, 2026-09-16)

- Before any build run `df -h /`; if less than 15% is free, stop and clean first.
- Comparison build trees (php-fpm, FrankenPHP, RoadRunner, ext-grpc) live under `/tmp/cmp/` and are deleted the moment their numbers are in VALIDATION.md; keep only the binaries `bench/compare.sh` and `bench/e10-compare.sh` need (`/opt/frankenphp-bin`, `/opt/php85-fpm/sbin/php-fpm`, `/tmp/cmp/rr` + `/tmp/cmp/rr-app`).

### Document roles

BRIEF.md (owner's mission, immutable) → JOURNAL.md (timestamped line per stage transition) → HYPOTHESES.md (H-n, falsifiable, time-boxed) → docs/research/NN-*.md → docs/adr/NNNN-*.md (with a kill criterion) → VALIDATION.md (V-n: raw numbers, exact command, machine state) → STATUS.md (one screen, links to V-n) / GOALS.md / ROADMAP.md / DECISIONS.md. docs/pain-map.md is re-read and re-statused at every REASSESS.

## Build and test

Everything needs PHP 8.5.10 ZTS+embed at `/opt/php85-zts`; `PHP_CONFIG` (default `/opt/php85-zts/bin/php-config`) drives `crates/ignis-sys/build.rs` bindgen and linking, and `LD_LIBRARY_PATH=/opt/php85-zts/lib` is needed at runtime.

```bash
scripts/build-php.sh                  # idempotent, ~7 min → /opt/php85-zts
cargo build --release -p ignis
cargo nextest run --workspace
cargo nextest run -p ignis php::zval  # single test / filter
cargo +nightly miri test -p ignis -- php::zval php::module   # miri covers the unsafe modules only
scripts/smoke.sh                      # end-to-end gate: build, tests, app.php, E1/E2/E5/E6/E7/E11/E12/E13/E14
```

Run a script: `./target/release/ignis [--threads N] [--offload M] [--supervise] <script.php> [args...]`.

Benches are one script per expectation (`bench/eN-*.sh`), each writing into VALIDATION.md; `bench/compare.sh` produces `bench/results/compare.md` vs FrankenPHP and php-fpm. The comparison suites (E15) run in CI and are gated against `bench/results/e15-baseline.txt` by `scripts/ci-gate.sh <suite> <log>` — pass counts may never drop.

The second backend (true-async fork, ADR-0003) is a separate prefix and target dir:
`scripts/build-php-async.sh` then `PHP_CONFIG=/opt/php86-async-zts/bin/php-config CARGO_TARGET_DIR=target-async cargo build --release -p ignis` (enables `cfg(php_async_abi)` → `backend/async_core.rs`).

Useful env: `IGNIS_THREADS`, `IGNIS_PHP_INI` (the embed SAPI has no `-d`/`-c`/`-n`), `IGNIS_CHAOS`/`IGNIS_CHAOS_P`/`IGNIS_CHAOS_SEED` (random fiber switch at every await point), `IGNIS_NO_STREAM_HOOK` / `IGNIS_NO_SLEEP_HOOK` / `IGNIS_NO_SUPERGLOBALS` (hook-off controls — every hook claim needs one), `IGNIS_LOOP_GC`.

## Architecture

One process, two worlds that only ever exchange plain data over channels:

- **tokio side** (`http.rs`, `grpc.rs`, `pg.rs`, `reactor.rs`) — hyper 1.x auto h1/h2 front door, tonic on the same listener, rustls, tokio-postgres pool, all timers and sockets.
- **PHP side** — N OS threads, each with its own embedded ZTS engine context and *its own* `Reactor`; requests are dispatched to the least-inflight thread (ADR-0010).

`reactor.rs` is the only bridge. PHP calls `ignis_submit_*()` (an `Op`: Sleep/Connect/Read/Write/Upgrade/Custom…) and `ignis_poll(timeout)`; HTTP requests, gRPC calls, timer completions and offload answers all arrive on that one completion channel, so a PHP thread has **exactly one wait point**. Invariants: no Zend pointer ever crosses to tokio, PHP never awaits a tokio future, an `Op` is plain data.

`crates/ignis/src/php/` is the FFI/Zend boundary — `embed.rs` (engine lifecycle, `!Send` `Engine`, `WorkerThread::attach` per thread), `module.rs` (the `ignis` internal module: `ignis_submit_sleep`, `ignis_poll`, `ignis_serve`, `ignis_respond`, …), `zval.rs`, `stream.rs` (tcp/ssl/tls factories replaced at MINIT so unmodified `file_get_contents`/`fsockopen` park the fiber), `sleep.rs`, `accept.rs`, `superglobals.rs` (zend_observer fiber-switch hook swapping `$_SERVER`/`$_GET`/`$_POST`/`$_COOKIE` per fiber), `route.rs`. `crates/ignis-sys` is raw bindgen over the embed SAPI headers.

`php/ignis.php` is the userland scheduler: `Ignis\Loop` (fiber pool — parked Fibers are reused, which is the single biggest win of the project, V-4), `Future`, `async()`, `all()`, `sleep()`, `deadline()`, `Scope`, `serve()`. It is deliberately shaped like a Revolt driver (`php/amphp/src/IgnisDriver.php`). Integrations layer on top without new primitives: `php/pg/`, `php/offload/`, `php/grpc/`, `php/temporal/`, `php/symfony/`, `php/swoole/shim.php`, `php/classic.php`.

`examples/app.php` is the API spec — the file an application developer should be able to write. Unimplemented parts are feature-guarded and reported, never faked. Change it only with intent.

### Editing rules that come from the architecture

- Every `unsafe` block states why it is sound; the FFI boundary documents ownership, lifetime and who frees. `.claude/hooks/guard-ffi.sh` blocks subagents from editing `crates/ignis/src/php/**`, `crates/ignis-sys/**`, `crates/ignis/src/backend/**` or any file containing `unsafe` — only the main agent touches those.
- A new capability is normally a new `Op` variant + a tokio actor + a thin `module.rs` function, with the logic in `php/ignis.php`. Adding a second wait point to a PHP thread breaks the design.
- Subagents: `bencher` (measurements, comparison builds), `porter` (external test suites, failure classification: ours / not applicable / upstream), `scribe` (reconciles STATUS/GOALS/pain-map with VALIDATION/JOURNAL — never invents numbers).
