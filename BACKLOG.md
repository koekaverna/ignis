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

---

## M3 — Real apps unchanged

### M3-1 Symfony recipe in README `agent` `done (README, validated by main)`
**What.** A "Symfony" section in README.md that a Symfony developer follows end to end: the five
composer commands from V-40, the `ignis.toml`, the docker invocation, and the two traps (`APP_RUNTIME`
in `.env` does nothing; `var/` must be writable by the image's `ignis` user).
**Why.** V-40 proved the recipe; it lives only in VALIDATION.md.
**Files.** README.md.
**Acceptance.** `grep -c "extra.runtime.class" README.md` ≥ 1; the section contains every command
from V-40 verbatim; no command in it references `php/symfony/worker.php`.
**Constraints.** Do not invent numbers; link V-40 and V-16 for the ones you cite.

### M3-2 Retire `php/symfony/worker.php` `agent` `done (validated by main: shim exits 2 with the migration note; references updated)`
**What.** The wrapper with the hardcoded `app/public/index.php` is superseded by the composer
package (V-40). Either delete it, or reduce it to a one-line shim that prints how to migrate and
exits 2. Update every reference (`grep -rn worker.php bench scripts docs README.md`).
**Why.** Two ways to do one thing, one of them wrong for any app not at that path.
**Acceptance.** `grep -rn "symfony/worker.php" --include=*.sh --include=*.md --include=*.php . | grep -v JOURNAL | grep -v VALIDATION` is empty, or every hit is the migration note; `bench/e8-symfony.sh` still runs (it may need the package route — see M3-3).
**Constraints.** JOURNAL/VALIDATION are history: never edit them to remove a mention.

### M3-3 `bench/e8-symfony.sh` on the package route `agent` `done (V-41: main re-run 4,680.64 / 13,629.61 req/s vs agent's 4,663 / 13,360; dev-mode 404, prod leg is M3-8)`
**What.** Make the E8 bench install the skeleton the way V-40 does (path repo, `platform.php`,
`extra.runtime.class`, `dump-autoload`) instead of through `worker.php`, and keep its numbers
comparable with V-16 (7.2k req/s at 1 thread / 25.2k at 4).
**Acceptance.** The script runs to completion on a box with composer (CI has it; this box does not —
use the builder image `ghcr.io/koekaverna/ignis-php:8.5.10-zts` with the repo bind-mounted, as V-40
did) and prints req/s for 1 and 4 threads. The orchestrator re-runs it before the numbers enter
VALIDATION.
**Constraints.** The port is `IGNIS_LISTEN`, never a literal (the 2026-09-16 smoke incident).

### M3-4 Laravel: research the route in `research` `done (docs/research/25-laravel.md, validated by main)`
**What.** Laravel does not use symfony/runtime. Read how Laravel Octane drives Swoole, RoadRunner
and FrankenPHP (`laravel/octane`: `Octane\Swoole\SwooleClient`, the worker loop in
`src/Worker.php`, request/response marshalling) and decide, with evidence, between (a) an Octane
"server" for Ignis, (b) a symfony/runtime-style runner that boots `bootstrap/app.php` and calls
`Illuminate\Contracts\Http\Kernel::handle` per fiber, (c) `php/classic.php` per request.
**Deliverable.** `docs/research/25-laravel.md`: sources with line references, what Octane requires
of a server (worker count, request lifecycle hooks, sandboxing of the container per request),
what the fiber-scoped superglobals (ADR-0006) already give, the recommended option and its kill
criterion. No code.
**Acceptance.** The note names the exact Octane interfaces a driver implements and the Laravel
version it verified against, from source, not memory.

### M3-5a Laravel in classic mode, serialised by the budget `agent` `open`
**What.** Research 25 (Octane v2.19.1, commit 68a2516): Octane never has two requests in one engine at
once, and `Illuminate\Container\Container::$instance` is process-global — so any route that
interleaves Laravel requests on one thread corrupts the container. `php/classic.php` already runs
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

## M4 — Operate

### M4-1 Hold-time on pool leases `main` `open`
**What.** `pg::Lease` gets an `Instant` at acquire; `pg::stats` reports the oldest live lease's age
and the count of leases older than a threshold; a `warn!` when a lease passes `IGNIS_PG_LEASE_WARN_MS`
(default 5000). Same for offload jobs in flight.
**Why.** The owner asked "will we see in the logs if someone is holding?" — the answer was no
(JOURNAL 2026-09-16T17:05Z): `Lease` has no timestamp, nothing logs a held resource.
**Acceptance.** A handler that holds a lease for 6 s produces one `warn!` line with the lease id
and age; `/_ignis/stats` shows `pg.oldest_lease_ms` ≥ 6000 while it is held and 0 after release.
**Constraints.** `main` (`pg.rs`, `module.rs`). An agent may write the PHP reproducer first.

### M4-2 Per-dependency bulkhead + circuit breaker (B2) `main` `open`
**What.** ROADMAP B2 as rewritten 2026-09-16: cap the number of fibers that may *wait* on one pool
(`pg::acquire` waits unboundedly today — `acquire_owned().await` behind a semaphore that bounds
connections, not waiters); past the cap fail fast with `Ignis\Pg\BusyError` instead of parking.
Breaker: after N consecutive failures/timeouts on a pool, refuse for a cooldown with the same
error. Explicitly **not** per route — routing lives in userland PHP (the owner's call).
**Acceptance.** The B2 criterion, unchanged: one dependency at 100 % failure leaves other endpoints
at ≥ 95 % of their throughput. Extend `bench/e11-cancel.sh` or write `bench/b2-bulkhead.sh`: a
route on a dead PG DSN under load beside `/` under `wrk`; `/` throughput with and without the dead
route within 5 %.
**Constraints.** `main` for `pg.rs`; an agent may write the bench and the PHP error class first
(`php/pg/ignis-pg.php` is agent-safe).

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

### M4-4 `/_ignis/metrics` (Prometheus) `main` `open` — after M3-7
**What.** The `/_ignis/stats` fields as Prometheus text format: gauges for threads/stalled/in-flight/
queued/idle fibers/oldest lease age, counters for requests/restarts/rejected/cancelled/offload jobs,
a histogram of request duration if cheap (per-thread, merged). Answered by Rust, exempt by
construction.
**Acceptance.** `promtool check metrics < <(curl -s /_ignis/metrics)` passes (an agent can run
promtool in a container); every counter in `/_ignis/stats` has a metric; the endpoint answers
under `wrk -c 200` load within 10 ms.

### M4-5 Graceful reload on `SIGHUP` `main` `open`
**What.** Drain: stop accepting on the old workers, let in-flight requests finish (bounded by a
`drain_timeout`), respawn each PHP thread one at a time (ADR-0012 has the mechanism), never reset
opcache. `SIGTERM`: drain then exit.
**Acceptance.** `wrk -c 64 -d 20s` against `/` while `kill -HUP` fires at t=5 s and t=10 s: **0**
non-2xx, **0** socket errors, `restarts` in `/_ignis/health` increments by `threads` each time.
**Constraints.** `main` (`main.rs`, `http.rs`). Agent writes `bench/m4-reload.sh` first.

### M4-6 Held-resource logging audit `agent` `open`
**What.** After M4-1: an inventory `docs/research/26-observability.md` of every wait a fiber can
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

### M4-8 Pool survival across a thread respawn `agent` `open`
**What.** Pain map FrankenPHP 7 / Swoole 7: "pools survive" is claimed (ADR-0015: the pool is
runtime-owned) but **not measured**. Bench: hold a PG lease on thread A, kill A with `/fatal`,
verify the pool's connection count is unchanged and the lease was reset (`DISCARD ALL` ran).
**Acceptance.** `bench/m4-pool-survives.sh` prints `created` before and after the respawn (equal),
and a query on the recycled connection sees no state from before (`SHOW search_path` default).
Needs PostgreSQL: the builder image has `postgresql-client`; run `postgres:17-alpine` over a
bind-mounted unix socket as research 24 did (TCP publishing is broken on the dev box).

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

### M5-1 Release workflow `agent` `done (validated by main: worktree dry run bumps Cargo.toml+Cargo.lock to 0.0.2-rc.1 and prints the tag commands; workflow parses; the tag push and the first real run are the owner's)`
**What.** `.github/workflows/release.yml` on a `v*` tag: build the image with `image.yml`'s
Dockerfile, tag `ghcr.io/koekaverna/ignis:vX.Y.Z`, attach the `ignis` binary + `libphp.so` tarball
to the GitHub release (with a note that it needs the six runtime libraries — M2's `ldd` list),
generate release notes from commits since the previous tag. `ignis --version` must equal the tag:
bump `crates/ignis/Cargo.toml` version in the same commit as the tag.
**Acceptance.** A dry run on a `v0.0.2-rc.1` tag produces the image and the release; `docker run
ghcr.io/koekaverna/ignis:v0.0.2-rc.1 --version` prints `ignis 0.0.2-rc.1`.
**Constraints.** Tag push is refused by the session git proxy (commit 8ffd758) — the owner pushes
the tag; the agent prepares everything else.

### M5-2 Migration guide from php-fpm / FrankenPHP / RoadRunner `agent` `done (validated by main: 25 V-n citations, no number without a V-n paragraph)`
**What.** `docs/migrate.md`, using `docs/pain-map.md` as its index: for each server, what changes
(config → `ignis.toml`, pool sizing → threads × budget, `ignore_user_abort` → cancellation,
`max_execution_time` → `Ignis\deadline`, opcache reset → thread respawn), what stays, what is not
supported yet (link the open BACKLOG items). Every claim links its V-n.
**Acceptance.** Someone who did not write it can follow the php-fpm section against
`examples/hello_server.php` in the image and reach a 200 on `/`. No number without a V-n.

### M5-3 Operator guide `agent` `done (validated by main: every config key and IGNIS_* var documented, defaults match config.rs)`
**What.** `docs/operate.md`: sizing (threads = cores; fibers = concurrency; memory = fibers × 15 kB +
connections × 33 kB, both from V-37); the budget/queue/503 behaviour (ADR-0019); health vs stats vs
metrics; the log floor and `RUST_LOG`; what a respawn looks like in the log; graceful reload once
M4-5 lands.
**Acceptance.** Every number cites a V-n; every env var and toml key in `config.rs` is documented.

### M5-4 Nightly perf job `agent` `done (validated by main: schedule+dispatch only, E1 1144.3 ms / E2 201.08 ms + 3.48 us on my run, dir=gt for throughput; the runner-side numbers wait for the first dispatch)`
**What.** `.github/workflows/nightly.yml`: E1, E2', E4 hello, E14 (if PG available), E16, B1's p99
pair, with thresholds from VALIDATION; a failure opens an issue with the numbers. Runs on a
schedule, never on push.
**Acceptance.** One manual `workflow_dispatch` run is green and its log shows every number beside
its threshold.

### M5-5 Static binary (B6) `research` `open`
**What.** Research first: a PHP rebuild with `--enable-embed=static`, curl built with fewer
backends (no ldap/rtmp/ssh2/gssapi), `-static-pie` if libphp allows, and what `ldd` shows after.
Write `docs/research/27-static-binary.md` with the exact configure lines tried and the resulting
`ldd`. Then, if feasible, `scripts/build-php-static.sh` and a second Dockerfile stage.
**Acceptance.** `ldd target/release/ignis` prints "not a dynamic executable" **or** the note says
exactly which library made it impossible and why.

---

## Product hygiene (small, `agent`)

### H-1 Remaining hardcoded `127.0.0.1:8080` in benches `agent` `done (validated by main: quoted grep clean, wrk-hello over IGNIS_LISTEN 24,884 req/s)`
`bench/{e8-symfony,rss-1m,soak-threads,e10-grpc,e10-compare,e12-inflight,e16-offload,ab-sleep,compare,wrk-hello}.sh`
and `examples/{classic_server,grpc_server}.php`, `php/amphp/examples/amp-socket-client.php`: the
same `ADDR="${IGNIS_LISTEN:-127.0.0.1:8080}"` + content-based readiness that the five smoke
benches got (commit 52e81a5). **Acceptance.** `grep -rln "127.0.0.1:8080" bench examples php --include=*.sh --include=*.php`
lists only files where it is the *default* inside `${IGNIS_LISTEN:-…}` or `getenv(...) ?: ...`.

### H-2 `bench/frankenphp/Caddyfile` and `bench/fpm/nginx.conf` paths `agent` `done (validated by main: templates render, stops with a clear message at the missing frankenphp binary)`
They hardcode `/home/user/ignis`. Generate them from templates at run time in `bench/compare.sh`
(`sed` the repo root in) so the comparison runs on any checkout. **Acceptance.** `bash -n` passes
and `bench/compare.sh` reaches the point where it needs `/opt/frankenphp-bin` (absent here) and
says so, instead of failing on the path.

### H-3 `examples/app.php` gains `/_ignis/health` mention and the budget `agent` `done (validated by main: 7 routes unchanged, /stats has budget, health ok)`
The API spec should show a handler reading `Ignis\Loop::budgetStats()` and the doc comment should
say the runtime answers `/_ignis/health` itself. **Constraints.** "Change it only with intent"
(CLAUDE.md): add, do not restructure; keep every existing route.

### H-4 CI concurrency: a push every few minutes cancels every run `agent` `done (main: YAML ok, comment trimmed; behavioural check on the next two pushes)`
Observed 2026-09-16: three CI runs in a row ended `cancelled` because the next push superseded
them; the gate never completed. Decide in `ci.yml`: keep cancel-in-progress but add a
`workflow_dispatch` "full gate" that is never cancelled, **or** drop cancel-in-progress for `main`.
Write the reason in the workflow file. **Acceptance.** Two pushes 2 minutes apart on a branch
produce one completed run of the full gate.

### H-5 `scripts/smoke.sh` runs against the image `agent` `done (validated by main: --image ignis:local GREEN, same 7 routes as binary mode, 0 containers left)`
A `--image ghcr.io/koekaverna/ignis:TAG` mode that runs the same route checks over `docker exec`
+ `/dev/tcp` (as `image.yml` does), so the image is smoke-tested with the *same* assertions as the
binary. **Acceptance.** `scripts/smoke.sh --image ignis:local` prints the app.php route table and
`smoke: GREEN`.

### H-7 IDE/static-analysis stubs for the runtime's functions `agent` `done (validated by main: stub set == module.rs set, php -l ok, guard yields to the real function)`
**What.** Every `ignis_*` function (`ignis_submit_sleep`, `ignis_poll`, `ignis_watch`, `ignis_cancel`,
`ignis_serve`, `ignis_respond`, `ignis_stats`, `ignis_inflight`, `ignis_pg_*`, `ignis_offload_*`,
`ignis_grpc_*`, `ignis_route_*`, `ignis_set_superglobals`, `ignis_cancel_parked_any`, and the
`temporal` ones behind the feature) is defined in Rust, so an IDE, PHPStan or Psalm sees an
"unknown function" at every call site (phpantom flagged `ignis_watch`, `ignis_stats` today). Ship
`php/stubs/ignis.php`: one stub per function with the exact signature and return type from
`crates/ignis/src/php/module.rs` (read-only for you) and a docblock naming the ADR/V-n, wrapped in
`if (!function_exists(...))` guards so it is harmless if loaded under the binary. Register it in
`php/composer.json` under `autoload-dev.files` and mention it in README's Configure section.
**Acceptance.** `grep -oE 'fe\(c"(ignis_[a-z_]+)"' crates/ignis/src/php/module.rs | sort -u` vs the
functions in the stub: identical sets; `php -l` passes; loading the stub under the binary then
calling `ignis_inflight()` still reaches the real function (the guard works).

### H-9 `bench/compare.sh` writes its results header before checking what it can run `agent` `in progress (batch 3)`
**What.** The header block is appended to `bench/results/compare.md` unconditionally, before the
`ONLY=` filter and the binary preflight, so a run that stops at "frankenphp binary not found"
still leaves an empty header row in a results file that is committed history. Move the header
write to after the preflight passes. **Acceptance.** `ONLY=franken bash bench/compare.sh` on this
box leaves `git status --short bench/results/compare.md` empty.

### H-10 `exit()` is logged as a fatal `main` `open`
**What.** Since the log floor moved to `warn` (2026-09-16), every script that ends with an explicit
`exit()` prints `WARN php_execute_script returned false (fatal error or exit)` — `bench/php/e1_sleep_10k.php`
and `e2_all.php` do it on every run. A clean exit is not a warning. In `crates/ignis/src/php/embed.rs`
distinguish `EG(exit_status)` from a real fatal (the `Engine::eval` sentinel already does this for
`-r`): warn only on a fatal, `debug` on exit. **Acceptance.** Running `e1_sleep_10k.php` prints no
WARN; a script with `trigger_error(..., E_USER_ERROR)` still prints one. `main` (guarded path).

### H-11 `examples/grpc_server.php` fails static analysis `agent` `open`
**What.** phpantom flags lines 24–25: an `int` parameter receives `int|float` (the request's number
fields are decoded from JSON). Pre-existing, not from today's edits. Cast or validate at the
boundary so the example passes PHPStan level 6 with `php/stubs/ignis.php` loaded (H-7).
**Acceptance.** `phpstan analyse -l 6 examples/grpc_server.php --autoload-file php/stubs/ignis.php`
reports 0 errors (phpstan via the builder image's composer, `composer global require phpstan/phpstan`).

### H-8 Retire the `IGNIS_ADDR` name `agent` `in progress (batch 3)`
**What.** `bench/e15-frankenphp.sh` sets `IGNIS_ADDR`; `examples/classic_server.php` and
`examples/grpc_server.php` fall back to it (H-1). One name: `IGNIS_LISTEN` everywhere, fallback
removed. **Acceptance.** `grep -rn IGNIS_ADDR --include=*.sh --include=*.php --include=*.md . | grep -v JOURNAL | grep -v VALIDATION` is empty.

### H-6 Delete `bench/php/pg_async_probe.php`'s hardcoded DSN default `agent` `done (validated by main: rc=2 on both probes)`
It defaults to `127.0.0.1` with user/password baked in. Make `PG_DSN` required with a one-line
message. **Acceptance.** Running without `PG_DSN` exits 2 with the message.

---

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
