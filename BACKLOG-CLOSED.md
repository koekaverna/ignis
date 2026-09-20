# BACKLOG — closed items, in full

Split out of `BACKLOG.md` on 2026-09-18 (it lives beside it in the repository root, not under `docs/`, because it is a working record and not a page of the site). The queue had grown to 985 lines in which `open`, `done`,
`DONE`, `CLOSED`, `dropped`, `PARTLY DONE`, `HALF DONE` and `NOT BUILT` were interleaved, so the
question "what is left" could only be answered by reading all of it.

Nothing is summarised away here: every closed item keeps the text it had, because several of them
are the only written account of an investigation (`S0-FIBER`, `H-12`, `S3-RSS-DRIFT`). The open
queue lives in `BACKLOG.md`; the numbers live in `VALIDATION.md`; the narrative lives in
`JOURNAL.md`.

## M3 — Real apps unchanged

### M3-1 Symfony recipe in README `agent` `done (README, validated by main)`
**What.** A "Symfony" section in README.md that a Symfony developer follows end to end: the five
composer commands from V-40, the `ignis.toml`, the docker invocation, and the two traps (`APP_RUNTIME`
in `.env` does nothing; `var/` must be writable by the image's `ignis` user).
**Why.** V-40 proved the recipe; it lives only in VALIDATION.md.
**Files.** README.md.
**Acceptance.** `grep -c "extra.runtime.class" README.md` ≥ 1; the section contains every command
from V-40 verbatim; no command in it references the deleted Symfony worker wrapper.
**Constraints.** Do not invent numbers; link V-40 and V-16 for the ones you cite.

### M3-2 Retire the Symfony worker wrapper `agent` `done (shim first, then deleted outright on 2026-09-17 — owner: no back-compat before the first stable release)`
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
`Illuminate\Contracts\Http\Kernel::handle` per fiber, (c) `php/packages/runtime/src/classic.php` per request.
**Deliverable.** `docs/research/25-laravel.md`: sources with line references, what Octane requires
of a server (worker count, request lifecycle hooks, sandboxing of the container per request),
what the fiber-scoped superglobals (ADR-0006) already give, the recommended option and its kill
criterion. No code.
**Acceptance.** The note names the exact Octane interfaces a driver implements and the Laravel
version it verified against, from source, not memory.

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

### E18-R1 Which blocking symbols the installed libraries actually import `research` `done (research 26: 31-symbol export list incl. __poll_chk; curl = threaded resolver, libpq = synchronous getaddrinfo)`
`nm -D --undefined-only` / `objdump -T` on the `.so` files this binary links (see `ldd target/release/ignis`):
libcurl, libpq, libssl, libcrypto, libphp, libsqlite3, libonig, libz, libnghttp2. For each: the
blocking libc symbols it imports, and from reading the library's I/O layer which ones sit on the
request path (curl: `Curl_poll`/`Curl_socket_check`, `Curl_recv`/`Curl_send`, resolver; libpq:
`pqSocketCheck`, `pqsecure_read/write`, `getaddrinfo`; OpenSSL: `BIO_read`/`BIO_write` → `read`/`write`
or `recv`/`send`). Deliverable `docs/research/26-e18-blocking-symbols.md` with the exact list and
versions. **Acceptance.** Every symbol in the ADR's export list appears in this note with the
library that imports it and the call site, from `nm` and source, not memory.

### E18-R2 Who holds a lock across a blocking call `research` `done (research 27: OpenSSL park, libcurl park, libpq park-except-GSSAPI, libphp block; locklib spec)`
From the installed versions' source (`curl --version`, `openssl version`, `pg_config --version`;
clone the matching tags under /tmp/cmp): does OpenSSL 3 hold any `CRYPTO_THREAD_*` lock across
`BIO_read`/`BIO_write` or `RAND_bytes`; does libcurl hold `Curl_share_lock`/the multi handle's
locks across `Curl_poll`; does libpq's `pg_g_threadlock` wrap anything that blocks. Deliverable
`docs/research/27-e18-locks.md`: per library, the lock, the call it wraps, the line, and a verdict
`park` / `block` / `park-except-init`. **Acceptance.** Each verdict cites a line; acceptance (5)'s
deliberate mutex-holding test library is specified here (a 20-line C shim that locks a pthread
mutex, calls `read`, unlocks — under `park` two fibers on one thread must deadlock).

### E18-R3 Interposition from the executable binds inside a shared library `main` `done (research 28: poll from inside libcurl hit 8×, gate ≈ 8.3 ns, this libcurl never calls getaddrinfo)`
A scratch crate defining `#[no_mangle] extern "C" fn getaddrinfo` (forwarding via
`dlsym(RTLD_NEXT)`), linked with `-Wl,--export-dynamic-symbol=getaddrinfo`, dlopening libcurl and
running `curl_easy_perform` on a local URL: the interposer must be hit from inside libcurl. Also:
does Rust std keep working with `read`/`write` interposed; does `--export-dynamic-symbol` work
with the linker cargo uses here. Deliverable `docs/research/28-e18-interposition.md`.

### E18-B The five benches, control arms measured today `agent` `done (validated by main: control curl 20,337 / pgsql 20,558 ms re-run within 1 %; V-45)`
**What.** H32–H35's falsifiers can exist before the feature: `bench/php/e18_curl.php`,
`bench/php/e18_pgsql.php`, `bench/php/e18_dns.php`, `bench/e18-overhead.sh` (two-build shape,
`--feature universal-park` on/off; until the feature exists both arms are the same binary and the
delta must read ≈ 0), and their driver `bench/e18.sh` that prints one line per hypothesis in the
`nightly:` style. The control arms (everything blocks today) are the baselines: run them and report
the numbers — the orchestrator re-runs before V-n. `bench/e18-deadlock.sh` waits for R2's shim.
**Acceptance.** All scripts pass `bash -n` / `php -l`; `bench/e18.sh` runs end to end on this box
and prints `e18: curl_100x200ms wall_ms=<≈20000> …`, `e18: pgsql_100x200ms wall_ms=<≈20000> …`,
`e18: overhead_ns delta=<≈0>`; WRITEFUNCTION fiber identity is recorded per transfer (today: all
in the main fiber or each in its own — report which, that is a fact about offload-off blocking).

### E18-I1 `bench/php/e18_pgsql.php` exits 0 and prints nothing under the park build `main` `done (2026-09-17, V-46): the loop's idle checks ignored $ready/$pending — ignis_poll() resumes C-parked fibers itself, the fiber they settle waits in $ready with nothing in flight, and a nested all() inside an outer fiber broke out of the loop; pre-existing (the old sleep hook showed it too), not an offload interaction; fixed in php/packages/runtime/src/ignis.php; H33 through the bench script: 308/301/303 ms`
**What.** Under `target-park` with `IGNIS_PARK=libpq` the agent's bench runs its 100 connections
(the trace shows 100 forwarded `connect`s and 398 `poll` wake-ups), then exits 0 with **zero
bytes on stdout and stderr**; the default build prints `e18: pgsql_100x200ms …`. Not a `write`
problem: a fiber writing to stdout/stderr under the same build prints in every configuration
(`fiber_echo.php`). Difference from `scratchpad/park_pg.php`, which prints 296–333 ms: the agent's
script wraps the whole run in an outer `Ignis\async` and prints from inside it. **Acceptance.**
The cause named, the script printing under park, and the number recorded in V-45.

### E18-C Retire the PHP-level wrappers universal park makes redundant `main` `done (V-46, V-48, V-49): sleep.rs, sockets.rs, accept.rs, stream.rs + the rustls path in reactor.rs all deleted; three mechanisms left (park, offload, context). Remaining wrappers are adapters, which carry no mechanism by design` — owner question 2026-09-17
**What.** Each wrapper below exists to make one C call park. Once the syscall layer parks, the
wrapper is a second mechanism for the same call. Ordered by what each deletion requires:
1. **Now (when `universal-park` is the default):** the offload auto-routing entries for `curl_*`
   and `PDO pgsql` (ADR-0016's router list, `IGNIS_OFFLOAD_FUNCTIONS/CLASSES` defaults). V-45 shows
   both park with no hook and no worker thread. The pool itself stays for what cannot park —
   `SQLite3`/`PDO sqlite` (disk `pread`/`fsync`), any library on `block`, and `Ignis\offload()`.
2. **After a per-symbol policy** (`IGNIS_PARK=libphp:nanosleep,libphp:recv`, not just per library):
   `sleep.rs` (the PHP `sleep()`/`usleep()` hook, E15c) and `sockets.rs`'s nine `ext/sockets` hooks
   (A4) plus `accept.rs` — libphp's own `nanosleep`/`recv`/`send`/`accept`/`connect` call sites
   park instead. Precondition: A4's `can_block()` rule moves into `would_block()` — a `recv` on a
   listening or unconnected blocking socket must forward, never park (the six-test hang of A4).
3. **After stage 2 interposes `select` (done, V-47):** the `stream_select` hook — it lives in `accept.rs` with the accept hook, not in `stream.rs`; `libphp:select` parks it (201 vs 601 ms).
4. **Kept, as the owner said:** the php_stream transport factory (ADR-0007/0017: `tcp://`,
   `ssl://`, `unix://`, rustls in the reactor). It is also the largest candidate: with universal
   park, `ext/openssl` would do TLS in-process with OpenSSL's `read`/`write` parked (policy `park`
   per research 27), ~1,000 lines of rustls plumbing would go, and the A6/B7 bug class (TLS
   read-ahead invisible to `stream_select`) disappears by construction because PHP's own
   `has_buffered_data` handling for its native TLS streams would apply. Not a decision: a
   measurement first — E6/E6'/E6'' through the hook vs through universal park + ext/openssl,
   side by side (H-n), then the owner decides.
5. **Not wrappers, stay:** `superglobals.rs`, `route.rs`, `embed.rs`, the Revolt driver's `ignis_watch`.
**Acceptance.** For each deletion: the suites and benches that validated the wrapper (V-12/V-22/V-25/V-26/V-29 for the hook family, V-24 for routing) give the same numbers through universal park, with the hook-off control now being `IGNIS_NO_UNIVERSAL_PARK=1`.

## M4 — Operate

### M4-1 Hold-time on pool leases `main` `done (V-44); removed 2026-09-18 with the pool it measured (ADR-0015 closed) — the idea is alive as S-POOL-LEASE-AGE for the userland pool`
**What.** `pg::Lease` gets an `Instant` at acquire; `pg::stats` reports the oldest live lease's age
and the count of leases older than a threshold; a `warn!` when a lease passes `IGNIS_PG_LEASE_WARN_MS`
(default 5000). Same for offload jobs in flight.
**Why.** The owner asked "will we see in the logs if someone is holding?" — the answer was no
(JOURNAL 2026-09-16T17:05Z): `Lease` has no timestamp, nothing logs a held resource.
**Acceptance.** A handler that holds a lease for 6 s produces one `warn!` line with the lease id
and age; `/_ignis/stats` shows `pg.oldest_lease_ms` ≥ 6000 while it is held and 0 after release.
**Constraints.** `main` (`pg.rs`, `module.rs`). An agent may write the PHP reproducer first.

### M4-2 Per-dependency bulkhead + circuit breaker (B2) `main` `DONE 2026-09-18 — see S1-BULKHEAD; removed the same day with ADR-0015, and the bounded wait now lives in ignis/doctrine's pool (PoolTimeoutException)`
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
(`php/packages/pg/src/ignis-pg.php` is agent-safe).

### M4-4 `/_ignis/metrics` (Prometheus) `main` `done (V-55): 22 metrics, promtool clean, 1.9-5.5 ms under wrk -c200; per-reactor publication from each PHP loop`
**What.** The `/_ignis/stats` fields as Prometheus text format: gauges for threads/stalled/in-flight/
queued/idle fibers/oldest lease age, counters for requests/restarts/rejected/cancelled/offload jobs,
a histogram of request duration if cheap (per-thread, merged). Answered by Rust, exempt by
construction.
**Acceptance.** `promtool check metrics < <(curl -s /_ignis/metrics)` passes (an agent can run
promtool in a container); every counter in `/_ignis/stats` has a metric; the endpoint answers
under `wrk -c 200` load within 10 ms.

### M4-8 Pool survival across a thread respawn `agent` `done (V-42/V-43: re-run by main — TWO defects found, fixes are M4-11 and M4-12)`
**What.** Pain map FrankenPHP 7 / Swoole 7: "pools survive" is claimed (ADR-0015: the pool is
runtime-owned) but **not measured**. Bench: hold a PG lease on thread A, kill A with `/fatal`,
verify the pool's connection count is unchanged and the lease was reset (`DISCARD ALL` ran).
**Acceptance.** `bench/m4-pool-survives.sh` prints `created` before and after the respawn (equal),
and a query on the recycled connection sees no state from before (`SHOW search_path` default).
Needs PostgreSQL: the builder image has `postgresql-client`; run `postgres:17-alpine` over a
bind-mounted unix socket as research 24 did (TCP publishing is broken on the dev box).

### M4-11 A PHP thread dying with a lease leaks the pool permit for ever `main` `done (V-42 addendum: available back to max)`
**What.** V-42: `--threads 2 --supervise`, pool max 2, both threads holding a lease, `/fatal` on
one → after the respawn `available` is 1 of 2 and stays there; `created` unchanged, so the
connection object is orphaned inside `LEASES` with its permit. Nothing associates a lease with the
thread that took it and `http::unregister` only fails HTTP responders. Fix: record the owning
reactor in `Lease`; on unregister, reset-and-return every lease it owns (the reset runs on the
tokio side, so a dying thread cannot block it). **Acceptance.** `bench/m4-pool-survives.sh`'s
"available back to max" line flips to PASS, 3 of 3 runs.

### M4-12 Every thread opens its own pool — ADR-0015's "process-wide" is false as deployed `main` `done (V-43 addendum: one pool id across threads)`
**What.** V-43: `ignis_pg_open(dsn, max)` mints a new pool per call and every worker thread runs the
script, so `--threads 3` gives `pool_id` 1, 2, 3 — three pools of `max` each. At `threads = cores`
(24 here) with `max = 20` that is 480 connections against PostgreSQL's default `max_connections =
100`. Fix: dedupe by DSN — a second `ignis_pg_open` with an identical DSN returns the existing id
(first opener's `max` wins; a differing `max` is logged at warn). **Acceptance.** The V-43 probe
prints one `pool_id` for every thread; `bench/php/e14_pg.php` unchanged.

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

## Product hygiene (small, `agent`)

### R-LIMITS-CONFIG The four listener limits are env-only and were promised in comments `agent` `DONE 2026-09-18`
**What.** `http.rs` carried four `// Future ignis.toml key: …` comments. A promise in a comment is
tracked by nobody, so they are here instead and deleted from the source. Each is an environment
variable today with no `ignis.toml` key and no default in `config.rs`:
`limits.max_body_bytes` (`IGNIS_MAX_BODY_BYTES`, 8 MiB), `limits.max_connections`
(`IGNIS_MAX_CONNECTIONS`, 8192, ~34 kB per held connection per V-5),
`limits.header_timeout_ms` (`IGNIS_HEADER_TIMEOUT_MS`, 10 000),
`limits.idle_timeout_ms` (`IGNIS_IDLE_TIMEOUT_MS`, 60 000).
**Why.** M1 made `ignis.toml` the one configuration file, and these four are the last listener knobs
that are not in it. `max_connections` is also ADR-0025's connection cap, which M4-3 needs.
**Acceptance.** A `[limits]` table in `Config` with `deny_unknown_fields`, bridged by
`serve_to_legacy_args` the way `budget` already is, env still winning over the file; a test in
`config.rs` covering the precedence for at least one of them; `ignis.toml.example` updated.

## Cycle 2026-09-18 — bugs, stabilisation, production readiness (owner)

Framing the owner set: "production readiness" is the question *what stops someone running this
today*. The answer comes from our own measurements, and it orders the cycle. Stage 0 first because
a gate that does not gate makes every later claim unverifiable.

### S0-E9 The negative control does not detect nondeterminism `main` `DONE 2026-09-18`
**What.** `bench/e9-temporal.sh` greps for `REPLAY_FAILED`; the mutated replay prints
`REPLAY_OK activations=3 eviction_errors=0` while the same log carries
`evicted: reason=NONDETERMINISM ... [TMPRL1100] Activity machine does not handle this event`.
sdk-core rejects the mutated history and the harness counts zero eviction errors.
**Why.** V-19's "a mutated workflow FAILS" is not being re-verified by anything, whatever the run
says. A green negative control that cannot go red is worse than no control.
**Acceptance.** With the timer removed, the script prints `REPLAY_FAILED` and names the eviction
reason; with the history intact it still prints `REPLAY_OK`. Assert on the eviction, not on a counter.
**Done.** `Temporal\isEvictionAnError()` now asserts on the eviction itself, spelling-insensitive
(`strtoupper` plus separator strip, because protojson renders the enum SCREAMING_SNAKE while the
PHP constant reads `Nondeterminism`) and accepting the bare prost tag `3`. `E9 temporal` is green.

### S0-FIBER `gh9916-009.phpt` fails in fiber mode on two unrelated machines `CLOSED 2026-09-18 (V-89): the test asserts the script shutdown sequence, which fiber mode does not have — deterministic, refuted the collector hypothesis, passes in main mode; baseline 78 -> 77 with the reason attached, and the stale PASSED row in the committed .tsv is what kept it "unexplained"`
**What.** `bench/e15-phpt.sh` reports `phpt.fiber.Zend_tests_fibers=77` against a baseline of 78, and
`scripts/ci-gate.sh` names the test: `Zend/tests/fibers/gh9916-009.phpt`. It covers entering the
shutdown sequence with a fiber suspended inside a Generator; the run prints `Not executed` where the
engine should raise `Cannot use "yield from" in a force-closed generator`. So the generator's
`finally` runs past a `yield from` that ought to have been refused.
**What it is not, and this was measured rather than assumed.** Not tonight's work: the full fibers
suite gives **77 with the HEAD binary and 77 with the binary rebuilt at night-3's branch point** —
identical. Restoring `php/packages/runtime` to its pre-night state does not change it either. Not a
flake: 77 across four independent suite runs and 5/5 in isolation.
**Not this box either — CI agrees (added 2026-09-18, after the docs session's handover).** CI run
35326670050 reports `phpt.fiber.Zend_tests_fibers=77` on a GitHub runner, the same 77 this box gives.
So the earlier framing of "the box is a suspect" is wrong and is struck: two unrelated machines agree,
and only the baseline `.tsv` committed on **2026-09-16** says 78.

**Where that leaves it.** The test passes in `stock` mode and in `main` mode on both machines (0
failures in each) and fails only in `fiber` mode, so it is specific to running the test body inside an
Ignis fiber. The baseline predates the engine rebuild with the toolchain extensions (2026-09-17), and
the engine is built by the same `scripts/build-php.sh` in CI and here — which is exactly why an
engine-level change would show identically in both, as it does. That is now the leading hypothesis
with two machines behind it, but still no direct measurement.

**The one thing still unexplained** is the single reading of **78** I took on this box earlier on
2026-09-18 with php-src on the same commit and the engine untouched. Recorded as unexplained rather
than given an invented cause.
**Deliberately left red.** The new `.tsv` was **not** committed. `check_set` compares against the one
in HEAD, so committing a run where this test FAILED would make the gate stop reporting it forever —
which is the exact defect this cycle has now found six times.
**Acceptance.** Either a cause named with a measurement, or a conscious re-baseline that records why
the test fails. The decisive experiment is now narrow, because stock and main mode are clean on both
machines: build an engine **without** the toolchain extensions and run `Zend/tests/fibers` in fiber
mode. If it returns to 78, the cause is the engine rebuild and the baseline is simply stale; if it
stays at 77, the cause is in our fiber harness (`scripts/phpt-harness.php`) and is ours to fix.

### S1-SESS Refuse to start on `session.save_handler=files` `main` `NOT BUILT — premise refuted by V-80`
**What.** The files handler takes a blocking `flock(LOCK_EX)` and holds it across a yield, so a
second fiber blocks the OS thread and the loop can never resume the holder. Measured: two fibers,
killed at 12 s, no progress (V-58). Owner's decision: turn the hang into a message at boot rather
than build a session store tonight.
**Outcome (V-80).** The measurement said no, so nothing was built. `ext/session` is a per-thread
singleton: a second fiber's `session_start()` joins the first's session rather than taking a second
lock, so there is no second `flock` to deadlock on. Across threads two requests on one session id
serialise and both finish — 818 ms against a 401 ms baseline, no thread lost — which is php-fpm's
behaviour for a shared session. A boot refusal would have forbidden a configuration that works.
V-58's rule is unaffected: it was measured with a raw `flock`, not through `ext/session`.
**Replaced by S1-FLOCK**, which is where the real exposure turned out to be.

### S1-FLOCK A blocking `flock` inside a fiber kills the thread, silently `main` `DONE 2026-09-18 — V-81`
**What.** V-58's rule is real: a blocking `flock(LOCK_EX)` held across a yield takes the OS thread
down for good, because a regular file is not epoll-able (research 30 group (d)) so the call cannot
park. V-80 then showed the path everyone assumed — `ext/session`'s files handler — is not how you
reach it. Application code taking its own lock is, and **`flock` is not in the interposed set**:
`crates/ignis/build.rs` lists read, write, recv, send, recvfrom, sendto, poll, connect, nanosleep,
usleep, sleep, accept and more, and no `flock`. So today that call blocks the thread with no
warning, no `ignis_park_failed_total` increment and nothing in the log — the operator sees a worker
stop and a supervisor respawn, with no cause.
**Why this shape rather than a boot refusal.** A refusal keyed on a configuration value cannot see
user code, and V-80 measured that the configuration it would have refused works fine.
**The design, and it is a policy row rather than a mechanism** (CLAUDE.md's budget is untouched):
interpose `flock`; outside a fiber pass through; inside one, turn a blocking `LOCK_EX` into
`LOCK_NB` plus a parked retry on a timer. That is exactly what Symfony's cache does by hand, and
V-58 already measured that shape working — 607 ms for three losers with park on, never finishing
with park off. The fallback, if the retry is judged too clever, is to count and warn: increment
`PARK_FAILED` and log the symbol once, so the cause is visible even when the thread dies.
**Acceptance.** A probe that holds `flock(LOCK_EX)` across a yield in one fiber while another asks
for the same file: both complete, the thread keeps serving, and the timing shows the second waited
rather than spun. `IGNIS_PARK` gets a row so the behaviour can be turned off. The E15 phpt suites do
not drop.
**Done (V-81).** Interposed with a `LOCK_NB` + parked-retry loop, 200 us doubling to a 20 ms
ceiling; `libphp:flock` is a `SEED` row. With the hook `{"holder_released_ms":401,
"waiter_acquired_ms":405,"ticks":45}`; without the row, killed at the 20 s timeout with no
output. `smoke.sh` gates on it and was verified to go red under the negative-control policy.

### S1-COOKIES `ignis_respond` cannot carry two headers with the same name `main` `DONE 2026-09-18`
**What.** R-HEADERS-MULTI. The header map is `array<string, string>`, so a response with two
`Set-Cookie` lines keeps the last. `IgnisWorkerRunner::headers()` assigns `$headers['set-cookie']`
inside a foreach over the cookie bag. A session cookie plus a CSRF cookie is the most ordinary pair
there is.
**Acceptance.** `array<string, string|list<string>>` across the boundary, `header_pairs` emitting one
pair per value, and a response with two cookies arrives with two `Set-Cookie` lines. Every adapter
(symfony-runtime, classic, gRPC) updated; the PHP test that currently pins the wrong behaviour flips
to pin the right one.
**Done.** `header_pairs` takes a list under one key (`push_each_value` in `module.rs`),
`IgnisWorkerRunner::headers()` returns `array<string, list<string>>` and its extra `getCookies()`
foreach — which was double-adding, masked by the overwrite — is gone. `smoke.sh` gates it:
`set-cookie=3 vary=2`.

### S1-ANSWER `Loop::answer()` loses the request and kills the loop `main` `DONE 2026-09-18`
**What.** `runHandler()` now guards entering the request, but `answer()`/`produce()` runs after it
and is guarded only by `poolBody()`, which rejects a Future `admitRequest()` discarded. A
`StreamedResponse` whose `ignis_stream_bind` fails produces no response and no log line; the
throwable surfaces later as an unobserved rejection and `runUntil()` rethrows it, killing the loop
instead of the request. Test exists: `LoopTest::testAThrowOutsideTheHandlersTryLosesTheRequestEntirelyBug`.
**Acceptance.** That test is renamed without "Bug" and asserts a 500 plus a log line; the loop
survives.
**Done.** `Loop::answerFailed()`, and the test is now
`LoopTest::testAFailureWhileAnsweringBecomesA500AndTheLoopKeepsServing`.

### S1-BULKHEAD Per-dependency bulkhead and breaker (M4-2/B2) `main` `DONE 2026-09-18, then removed with ADR-0015 — the userland pool has the bounded wait, not the breaker (S-POOL-LEASE-AGE)`
**What.** `pg::acquire` waits unboundedly, so one slow dependency stalls every fiber that wants it.
pain-map PHP-FPM 2, still NOT STARTED.
**Acceptance.** A bounded wait with a configurable ceiling; past it the caller gets an error rather
than a hang; a breaker opens after N consecutive failures and half-opens on a timer. Gate: with
PostgreSQL stopped, `/users` answers an error inside the ceiling and `/` keeps serving.
**Done.** `IGNIS_PG_ACQUIRE_TIMEOUT_MS` (5000), `IGNIS_PG_BREAKER_FAILURES` (5),
`IGNIS_PG_BREAKER_COOLDOWN_MS` (5000) in `pg.rs`, with two nextest cases covering the ceiling and
the breaker's open/half-open transition.

### S2-LEAKS The four smaller defects the tests pinned `agent` `DONE 2026-09-18`
**What.** Each already has a failing-or-pinning test from the 2026-09-17 suite.
`Offload\Router::release()` does not mark the Handle released, so every proxied object is freed
twice. `Client::$pending` grows one closure per offload call for ever, while `Client::$callbacks`
beside it is written, unset and never read. `Request::query()` returns the literal string `'Array'`
for `?x[]=1&x[]=2` while `$_GET` has the real list. `cancelRequest()` throws into the parent before
its children (`array_reverse` over `[...children, parent]`), so the parent answers 499 and returns
its fiber to the pool while the children are still unwinding.
**Acceptance.** Each test renamed off "Bug" and asserting the correct behaviour; `Client::$pending`
empty after a completed offload call with a closure argument.
**Done.** All four. No test named `*Bug*` remains anywhere under `php/packages`; the dead
`Client::$callbacks` static is gone and its local replacement is unset from `self::$pending` on
completion; `Request::query()` returns `string|array|null`; cancellation walks children first.

### S2-STREAM-CANCEL A streaming handler never learns the client left `main` `DONE 2026-09-18`
**What.** R-STREAM-CANCEL. `guard.answered` is set when the oneshot resolves, which for a streamed
response is when the *headers* go out, so a later hang-up is never delivered as `Outcome::Cancelled`.
A fiber parked in `Stream::write()` still finds out; one parked on a slow query between chunks does
not, and keeps producing for a client that is gone.
**Acceptance.** Start a stream, kill the client mid-body, the handler's fiber is cancelled inside the
bound E11 uses for whole-body responses. Lands with S4-ANSWER-MAP.
**Done.** `guard.answered = !streamed`, so the guard stays armed for a streamed response, and the
Loop keeps the request's fiber mapping until the body ends. `smoke.sh` gates it:
`{"cancelled":1,"finally_ran":1,"chunks_written":3,"loop_cancelled":1}`.

### S3-RSS-DRIFT 13 MB of RSS growth that is not the extensions `main` `DONE 2026-09-18 — V-82`
**What.** The E3 re-measurement (S3-NUMBERS) came back with worker-mode RSS at **42.7 MB** against
V-10's 26.2 MB, +16.5 MB. The framing in the brief -- "that is the cost of building the extensions
in" -- did not survive the measurement, and the bencher said so rather than confirming it:

- the owner's own 2026-09-17 before/after puts the extensions at **~3.0 MB**;
- that measurement's *pre-extension* baseline was already **34.6 MB, 7.7 MB above V-10**;
- `mimalloc` (8.1 MB resident) landed 2026-09-15, before V-10, so it is not new;
- `libphp.so` grew 50.3 -> 67.5 MB on disk but is demand-paged, only 8,956 kB resident -- 17 MB of
  disk cannot become 16.5 MB of RSS.

So it decomposes as roughly **~8 MB before the extensions, ~3 MB extensions, ~5 MB since**, across
the 281 commits between V-10 and HEAD. Nobody has ever asked where that went.
**Why.** E3 is one of the project's headline claims and the absolute is now quoted wrong either way
-- as a regression it is not, or as an extension cost it mostly is not.
**Acceptance.** A `git bisect run` over `bench/rss-1m.sh` at a fixed request count naming the commits
that moved it, or a recorded decomposition (allocator, tokio, added statics) that accounts for the
~13 MB. Then V-10 is amended with the true figure and its reason.
**Constraints.** Needs a quiet box: the same instrument measured ~52k req/s under contention against
V-10's ~167k, so throughput from a loaded run is junk even though RSS at a given request count is
not -- the two runs agreed on RSS to 0.3% across a 5x load difference, which is what makes the
flatness claim safe to keep and the throughput claim unsafe to quote.
**Done (V-82), and the decomposition above was wrong in its largest term.** Bisected on startup
RSS, not soak RSS: its noise floor is **232 kB (0.6 %)** over six repeats, against +/-6.7 % for
throughput, which is what makes a bisect possible at all. Building `7a5c43f` -- V-10's own commit --
in a worktree against **today's** engine gives **36 888 kB** where V-10 recorded 26 920 kB. So
**~10 MB is outside this repository**, not ~3 MB: the engine rebuild with the toolchain extensions
is the large term, and no bisect could ever have found it because no commit here contains it. The
repo's own share is **4.7 MB over 281 commits**, and one commit carries 2.08 MB of it -- `17a2ceb`
("sockets.rs + accept.rs deleted"), parent 38 496-38 800 over three runs against 40 616-40 980 over
five, non-overlapping. Two candidate causes inside that commit are **killed by their own
off-switches**: the 19-row park policy measures the same as the old 7-row one on HEAD (and the whole
park mechanism is worth 1.1 MB), and the stream transport hook measures the same as the stock
transport on the parent. The remaining cause is recorded open rather than guessed. It is not
chased further because it is a **fixed footprint, not a leak** -- the same session's E3 run holds
RSS flat across 1.15 M requests with the heap flat to the byte -- and it bought the deletion of 641
lines of Rust that the mechanism budget wanted gone.

### S3-STATS-SCOPE `/stats` reports one thread's PHP heap, not the process's `agent` `DONE 2026-09-18 — renamed`
**What.** `examples/hello_server.php`'s `/stats` reports `mem` from `memory_get_usage()`, and the
Zend MM heap is thread-local under ZTS. At `--threads 1` -- V-10 and every E3 run so far -- that is
the whole PHP side, so "flat to the byte" is honest. At `--threads N` it is one worker's heap and
the number means much less than a reader would assume.
**Acceptance.** Either a per-thread sum across the registry, or the field renamed and documented so
it cannot be read as process-wide. The summing version touches `module.rs`, so it is a `main` item
if that route is taken.
**Done.** Renamed, not summed: `mem` -> `mem_this_thread` and `mem_real` -> `mem_real_this_thread`
in `examples/hello_server.php` and `bench/php/a3-soak.php`. Nothing parses those two fields, so the
rename is free; summing would have meant reading other threads' Zend heaps, which is a new
cross-thread mechanism on the hottest boundary for a diagnostic field. `rss_kb` is process-wide
already and `ignis_stats()` comes from the Rust side. `resumes`/`fibers`/`idle` are equally
thread-local and keep their names deliberately: `bench/a3-soak.sh` parses them by name.

### S3-NUMBERS The numbers this work made stale `main` `DONE 2026-09-18 — V-10 addendum, V-79 addendum 2, V-82`
**What.** V-10's 26.2 MB predates the toolchain extensions (+2.9 MB, DECISIONS 2026-09-17); the PHP
coverage floor V-79's addendum calls for is not in CI; H-12's 58k-vs-128k has never been bisected.
**Acceptance.** V-10 re-run and its entry amended; `scripts/ci-coverage-gate.sh` enforcing
`achieved − 5` in the `php-unit` job; `git bisect run` over `bench/wrk-hello.sh` naming the commit,
or a recorded conclusion that the drop is the box.
**Done, all three.** V-10 re-run by me and amended (flatness CONFIRMED again at -1.2 % over 1.15 M
requests, heap flat to the byte; the absolutes are stale and now decomposed in V-82).
`scripts/ci-coverage-gate.sh` is in the `php-unit` job at `FLOOR=31.6`, set from my own 36.60 %, and
was proved to fail three ways -- under the floor, over it, and on a log with no coverage summary at
all. The `wrk-hello` bisect is **refused with a number**: five consecutive runs on one HEAD server
spread 50.8k-54.2k req/s (+/-6.7 %), and the residual code-side drop V-46 addendum 3 left open is
4-5 %, i.e. under the noise. A bisect gated on it would name an innocent commit. V-82.

### S3-LIMITS `[limits]` in `ignis.toml` `agent` `DONE 2026-09-18`
Already filed as R-LIMITS-CONFIG; it is the config half of S1-CAP and lands with it.

### S3-STAN8 PHPStan level 8 `agent` `done 2026-09-18 — level: 8 in php/phpstan.neon, both configs clean`
**What.** Measured 2026-09-18 with level 6 at zero: level 7 = 36, **level 8 = 39**, level 9 = 349,
level 10 = 399. The 39 are worth taking and four are bug-shaped — `$pdo->query(...)->fetchAll()` on
a `PDOStatement|false` in `examples/app.php`, a string invoked without a callable check in the
offload worker (a method name arriving from another thread), and two protobuf methods called on a
bare `object` in `CoreServiceClient`.
**Acceptance.** `level: 8` in `php/phpstan.neon`, 0 errors, no new `ignoreErrors` entry.

### R-MAIN-RED `main` has been red since before the quality work, on two gates `main` `DONE 2026-09-18 — green on all ten jobs`
**What.** Every one of the last six `ci.yml` runs on `main` failed, including runs that predate this
body of work (35249027373 at 16:50, 35249914989, 35258827928, 35259213051). Three jobs were failing;
`E15 revolt` recovered by itself once `unzip` reached the image and composer could install, leaving two.

**E9 temporal — the negative control does not detect what it exists to detect.** The gate greps for
`REPLAY_FAILED`, and the mutated replay prints `REPLAY_OK activations=3 eviction_errors=0` even
though the same log carries `evicted: reason=NONDETERMINISM ... [TMPRL1100] Activity machine does not
handle this event: HistoryEvent(id: 11, TimerStarted)`. So sdk-core *did* reject the mutated history
and the harness counted zero eviction errors. Either the eviction is no longer surfaced the way the
harness counts it, or the counter never covered this path. Until it is fixed, V-19's "mutated
workflow FAILS" claim is not being re-verified by CI, whatever the run says.

**E15 frankenphp — `passed=28` against a baseline of 29.** Five failures in the run:
`server-variable.php` (REMOTE_HOST/ADDR/PORT/IDENT), `cookies.php` (four cookies absent),
`autoloader.php`, `env/putenv.php` (`got 'test=8'` — a value leaking across requests), and
`file-upload.php` (no `Upload OK`). The counts say exactly one of the five is newer than the
baseline: 28 + 5 + 33 skipped = 66, and 29 + 4 + 33 is the same 66. The window is the
stream/multipart work of 2026-09-17 16:50–18:26 (V-76/V-77 and E22).

**Correction, 2026-09-18 (owner):** an earlier version of this entry, and a JOURNAL line before it,
attributed those commits to "a parallel session working in this repository". There is no parallel
session and there never was; `git log --format=%an` over that window is one author. Nothing here is
anyone else's territory, and the frankenphp half is ours to diagnose like any other regression.

**Why it matters.** `main` is the branch of record, and a permanently red gate is a gate nobody
reads. It also means the E15 per-test `check_set` regression detector is warning-only in CI
(`scripts/ci-gate.sh`: `[ -n "${CI:-}" ] || fail=1`), so a swap of one passing test for another is
invisible there.
**Acceptance.** `scripts/ci-gate.sh frankenphp` and the E9 grep both pass on `main`, and the E9
negative control is proven to fail when the history is mutated — assert on the eviction reason, not
only on a counter.
**Constraints.** Not this work's scope (it is the quality gate), and the frankenphp half overlaps a
parallel session's files. Diagnose from `bench/results/e15-phpt/*.tsv` and the frankenphp runner
rather than by re-running blind.

### R-LINT-GATE Blocking fmt/clippy/deny + real coverage, Rust side `main` `done (V-78)`
**What.** ADR-0041. `[workspace.lints]`, `rustfmt.toml` (140 cols, measured), `deny.toml`,
`.config/nextest.toml`, `ignis-sys` narrowed to its bindgen module, the debt driven to zero, tests
on the tokio-side modules, `cargo llvm-cov` reported as a number, and a blocking `lint` job in CI.
**Why.** There was no linter of any kind in CI, on either side, and its absence had already cost two
things nobody noticed: `--no-default-features` had stopped compiling although the manifest documents
that configuration, and 92 `unsafe` blocks had no SAFETY comment while CLAUDE.md claimed every one
of them did.
**Acceptance.** `cargo fmt --all --check`, `cargo clippy --workspace --all-targets -- -D warnings`,
`cargo clippy --workspace --all-targets --all-features -- -D warnings` (in the CI image, it has
protoc), `cargo check --workspace --no-default-features`, `cargo deny check` all clean;
`cargo nextest run --workspace` green with the new tests; `scripts/smoke.sh` GREEN;
`scripts/ci-gate.sh phpt` no drop; coverage recorded as a V-n.
**Constraints.** The four allocation items in R-REVIEW-CHORES stay out: they are hot-path claims and
need a before/after on a quiet box, which is `bencher` work and its own V-n.

### R-PHP-GATE Blocking php -l/phpstan/cs-fixer + phpunit with coverage `agent` `done (V-79)`
**What.** The root `php/composer.json` gains dev tooling and scripts; `phpstan.neon` at level 6
(plus a six-line override for `revolt`, whose 8.1 floor is a promise to AMPHP users);
`.php-cs-fixer.dist.php` at @PER-CS; `rector.php` as a one-shot local tool, never a gate;
`phpunit.xml`; a fake reactor so `Loop.php` is unit-testable; tests for the nine packages that have
none; pcov-based line coverage; and `php-lint` + `php-unit` jobs in CI.
**Why.** **The PHP unit tests had never executed in CI.** The image had no zip/unzip/7z, so
`composer install` could not extract a dist archive; both composer steps ended in `|| echo`;
`smoke.sh` then found neither vendor nor docker and printed `skipped`; the job went green. Fixed by
building the extensions in (owner, DECISIONS 2026-09-17) and putting `unzip` in the image.
**Acceptance.** `cd php && composer check` exits 0 with phpstan reporting 0 errors at its committed
level, cs-fixer clean, the suite green, coverage above the committed floor,
`git status --porcelain` empty after a test run (the bootstrap is no longer rewritten), and
`scripts/smoke.sh` GREEN.
**Constraints.** No `phpstan-baseline.neon` — it is the file that makes "debt to zero" optional. If
the real error count exceeds 250, gate at level 4 and file level 6 separately (ADR-0041 §7).


### H-1 Remaining hardcoded `127.0.0.1:8080` in benches `agent` `done (validated by main: quoted grep clean, wrk-hello over IGNIS_LISTEN 24,884 req/s)`
`bench/{e8-symfony,rss-1m,soak-threads,e10-grpc,e10-compare,e12-inflight,e16-offload,ab-sleep,compare,wrk-hello}.sh`
and `examples/{classic_server,grpc_server}.php`, `php/packages/revolt/examples/amp-socket-client.php`: the
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

### H-4 CI concurrency: a push every few minutes cancels every run `agent` `done — behavioural check 2026-09-17: the in-progress run survives; queued runs still collapse to the newest (GitHub keeps one pending per group), so a burst is verified by the tip's run — recorded in docs/orchestration.md`
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
`php/packages/runtime/stubs/ignis.php`: one stub per function with the exact signature and return type from
`crates/ignis/src/php/module.rs` (read-only for you) and a docblock naming the ADR/V-n, wrapped in
`if (!function_exists(...))` guards so it is harmless if loaded under the binary. Register it in
`php/composer.json` under `autoload-dev.files` and mention it in README's Configure section.
**Acceptance.** `grep -oE 'fe\(c"(ignis_[a-z_]+)"' crates/ignis/src/php/module.rs | sort -u` vs the
functions in the stub: identical sets; `php -l` passes; loading the stub under the binary then
calling `ignis_inflight()` still reaches the real function (the guard works).

### H-9 `bench/compare.sh` writes its results header before checking what it can run `agent` `done (validated by main: preflight stop leaves compare.md untouched)`
**What.** The header block is appended to `bench/results/compare.md` unconditionally, before the
`ONLY=` filter and the binary preflight, so a run that stops at "frankenphp binary not found"
still leaves an empty header row in a results file that is committed history. Move the header
write to after the preflight passes. **Acceptance.** `ONLY=franken bash bench/compare.sh` on this
box leaves `git status --short bench/results/compare.md` empty.

### H-10 `exit()` is logged as a fatal `main` `done (exit() -> debug, fatal -> warn with status=255; exit(7) still 7; nextest, smoke GREEN; phpt below)`
**What.** Since the log floor moved to `warn` (2026-09-16), every script that ends with an explicit
`exit()` prints `WARN php_execute_script returned false (fatal error or exit)` — `bench/php/e1_sleep_10k.php`
and `e2_all.php` do it on every run. A clean exit is not a warning. In `crates/ignis/src/php/embed.rs`
distinguish `EG(exit_status)` from a real fatal (the `Engine::eval` sentinel already does this for
`-r`): warn only on a fatal, `debug` on exit. **Acceptance.** Running `e1_sleep_10k.php` prints no
WARN; a script with `trigger_error(..., E_USER_ERROR)` still prints one. `main` (guarded path).

### H-11 `examples/grpc_server.php` fails static analysis `agent` `done (validated by main: phpstan L6 0 errors on the example + grpc lib)`
**What.** phpantom flags lines 24–25: `intdiv(hrtime(true), …)` — `hrtime(true)` is typed `int|float`.
(The first version of this entry blamed JSON-decoded fields; the agent read the line, I had not.)
Fixed with a cast. Note for any phpstan run: `php/packages/runtime/stubs/ignis.php` covers only the `ignis_*` C
functions — pass `php/packages/runtime/src/ignis.php` (and pg/offload files) too, or every `Ignis\*` symbol is "not found". Cast or validate at the
boundary so the example passes PHPStan level 6 with `php/packages/runtime/stubs/ignis.php` loaded (H-7).
**Acceptance.** `phpstan analyse -l 6 examples/grpc_server.php --autoload-file php/packages/runtime/stubs/ignis.php`
reports 0 errors (phpstan via the builder image's composer, `composer global require phpstan/phpstan`).

## E19 — Boot-heap snapshot, classic mode `REJECTED 2026-09-17 (owner), ADR-0039`

Measured before deciding, and the numbers are kept because they are the point: the `mprotect`+`SIGSEGV` barrier costs **7.1 µs per fault**; a real Symfony request dirties **11–12 boot pages** (not the assumed 50) against a **6 MiB** boot heap; so the proposal costs **71 µs against its own 100 µs budget** and is the only one of three mechanisms that does not grow with the heap (whole-arena memcpy 120 µs, soft-dirty 141 µs and process-wide). Rejected not for cost but for the constraint: the undo log is per thread, so a restore is only safe with one request in flight, i.e. `budget.fibers = 1` — php-fpm's concurrency with a faster bootstrap, which switches off the parking this runtime is built on, for exactly the applications that need it most. The variant that keeps fibers (restore when the thread goes idle) bounds RSS but fails "identical response bytes". Research: `docs/research/34-e19-boot-heap-snapshot-feasibility.md`. Instruments kept: `bench/e19/barrier.c`, `bench/e19/compare.c`, `bench/e19/dirty-probe.php`.

### R-DNS Name resolution blocks the whole PHP thread `main` `open — owner decision 2026-09-17: record the risk, do not build yet`. `getaddrinfo()` has no file descriptor, so universal park cannot park it: park's mechanism is "wait for fd readiness, then make the real call", and there is nothing to wait on. Every name lookup therefore blocks its OS thread for the whole resolve — microseconds against a warm cache, seconds against a sick resolver, and with N threads that is N requests stalled, not one fiber. **What is affected:** libpq (`pg_getaddrinfo_all` calls it synchronously on the caller's thread, research 27), and PHP's own `fsockopen`/`stream_socket_client`/`gethostbyname` to a hostname. **What is not:** libcurl in this build uses its *threaded* resolver — it resolves on its own pthread and the fiber waits in `poll` on a socketpair, which park already handles (research 26, 28). **The plan when it is built** (research 31, and ADR-0020 corrected to match): interpose the symbol and run the **real** `getaddrinfo` on `tokio::task::spawn_blocking` behind an `Op::Custom`, suspending the fiber on that completion exactly as `pg.rs` does; hand the caller glibc's own pointer untouched, so nothing fabricates an `addrinfo` chain and `freeaddrinfo` needs no interposition at all. Known costs: a hung resolver holds a blocking-pool thread (the pool is bounded — needs a cap and a metric, or the stall just moves one floor down), and a lookup already started cannot be cancelled. **Rejected for now: writing our own resolver in Rust** (hickory-dns / `lookup_host`). It would be genuinely async and cancellable, but it has to reproduce `nsswitch.conf`, `/etc/hosts`, `resolv.conf`'s `search`/`ndots`/`options`/`timeout`/`attempts`, `AI_ADDRCONFIG`/`AI_V4MAPPED`/`AI_CANONNAME`, service names from `/etc/services`, IDN and RFC 6724 address sorting — and being 99 % right there fails as "connected to the wrong address" or "a Kubernetes service name does not resolve" (ndots:5 and search domains), not as "slow". musl and systemd-resolved both carry famous divergences here. **Operational mitigation available today, no code:** run a local caching resolver (nscd, systemd-resolved, dnsmasq in the pod) so the call returns from cache in microseconds. Documented in docs/operate.md. A middle option if measurement ever demands it: keep the real call and put a TTL-respecting cache in front of it — most of the benefit, none of the semantics risk.

### R-STREAM There is no chunked HTTP response, so a streamed body is collected in memory `main` `DONE 2026-09-17 (V-74): built as designed — ignis_respond_start/chunk/end over a hyper channel body, the chunk op awaited for back-pressure, Ignis\Http\Stream in userland, Symfony's StreamedResponse wired through it. TTFB 906 ms -> 1.7 ms, transfer-encoding chunked, the thread keeps serving (3/3 ticks). Gated by bench/e23-stream.sh. What remains is the ob_start() lock on the Symfony path, below.` The only response primitive is `ignis_respond(id, status, headers, body)`: one call, whole body. gRPC has a streaming pair (`ignis_grpc_send`/`ignis_grpc_end`, E10) but that is tonic, not hyper. So `Symfony\Component\HttpFoundation\StreamedResponse` — the class whose entire purpose is not holding the body — is collected into a string by `Ignis\Output::capture()` and handed over in one piece. A 1 GB export is 1 GB of PHP memory and then 1 GB of Rust memory, silently. Same for `BinaryFileResponse`. **What it needs:** `ignis_respond_start(id, status, headers)` / `ignis_respond_chunk(id, bytes)` / `ignis_respond_end(id)` over a hyper streaming body, with `ignis_respond_chunk` returning an op the fiber **awaits** — a bounded channel plus an awaited op is real back-pressure, and it keeps the thread free while the client drains. **The PHP side is already possible:** measured (V-72) that a fiber CAN suspend inside an `ob_start()` handler, so the handler can forward each chunk and await the op; memory stays at one chunk. **What does not change:** the output-buffer stack is per thread whatever the transport is, so `Output::capture()`'s exclusivity is still required — streaming does not remove it, it only bounds the memory. **Gate before it lands:** a 1 GB streamed response with RSS flat, a slow client applying back-pressure without stalling the thread (a ticker fiber must keep running), and `Content-Length` absent with `Transfer-Encoding: chunked` present.

### R-STREAM-CANCEL A streaming handler never learns the client left `main` `DONE 2026-09-18 — see S2-STREAM-CANCEL`. `http::handle` sets `guard.answered = true` the moment the oneshot resolves (`http.rs:348`), and for a streamed response that is when the **headers** go out, not the body. After that a client hang-up is never delivered as `Outcome::Cancelled`. A fiber parked in `Stream::write()` still finds out — the channel closes and the send errors (`module.rs`) — but one parked on a slow query between chunks does not, and keeps producing for a client that is gone. Fix is to keep the cancel path armed for the lifetime of the stream, not just until the first byte. Gate: start a stream, kill the client mid-body, assert the handler's fiber is cancelled within the same bound E11 uses for whole-body responses.

### R-HEADERS-MULTI The response header map cannot hold two headers with the same name `main` `DONE 2026-09-18 — see S1-COOKIES`. `ignis_respond` takes `array<string,string>` and hyper is handed one value per name, so a response with two `Set-Cookie` headers loses all but the last: `IgnisWorkerRunner::headers()` assigns `$headers['set-cookie']` inside a `foreach` over the cookie bag (`IgnisWorkerRunner.php:88-90`), which is a correctness bug, not the "E8 caveat" the comment calls it. Every framework that sets more than one cookie per response is affected, and so is any `Link`/`Vary`/`Warning` pair. Fix is a list-valued header shape across the boundary (`array<string, string|list<string>>`) and `header_pairs` emitting one pair per value. Gate: a response with two cookies arrives with two `Set-Cookie` lines.

### R-LOOP-SPLIT `Loop::runUntil` is 124 lines doing seven things — and one flag silently skips two of them `main` `done 2026-09-17 (8613a4a): runUntil 125 -> 30 lines, one boot(); the metrics half of the claim did NOT hold ($publishStats defaults true) and the confirmed damage was gc_disable() never running on the await-before-serve path — architecture review 2026-09-17; the flag defect confirmed by reading`. **The defect first:** `$chaosInit` (`Loop.php:306`) gates three unrelated initialisations at `runUntil:172-176` — `chaosInit()`, `gcInit()` **and** the `ignis_publish_stats` probe — but `awaitOp:78-79` calls `chaosInit()` alone, which sets the flag (`:368`). So on any path where `awaitOp` runs before `runUntil` — a script that awaits before serving — `IGNIS_LOOP_GC` never initialises and `$publishStats` stays false, which means that loop **never publishes its metrics** and `/_ignis/metrics` reports it as one whose numbers are ageing without bound. A flag standing in for a state machine. **The rest:** `runUntil` wants to be the same `while` over four named calls — `startPending()` (180-189), `resumeReady()` (191-203), `idle()` and `dispatchEvents($events)` (252-278); the six-term idle condition is written twice (`:209`, `:249`) and must be one predicate; the event demux decodes a Rust tagged union with an `is_array`/`isset`/string-ladder instead of one `match ($payload['kind'] ?? null)`; `$budgetInit` (`:50`) is checked per request (`:410-412`) for an environment variable that cannot change; `$requestHandler`/`$rawRequestHandler` (`:30`, `:402`) are two nullable callables tested together in three places where one typed handler would say which mode it is. All of it belongs in one `boot()` called from `serve()`.

### R-NOT-DOING Decided against, so nobody re-litigates it `main` `closed 2026-09-17 — both architecture reviews agreed independently`. **The three `FUNCTIONS` tables** (`module.rs:829-940`, ~100 duplicated lines): the explicit array length makes a mistake a compile error, and the macro that removes the repetition is harder to read at 3am than the repetition. **A shared base class or interface for the three fiber-scoped services** (`FiberRequestStack`, `FiberTokenStorage`, `FiberEntityManager`): the shared idea is already extracted — it is `Scope` plus `Scope::clear()` at the request boundary — and what remains in each is the *shape* of the state (a stack, a slot, a lazily-built resource with a rollback). A base class would save three lines in two of three and buy an abstraction. **Shrinking `FiberEntityManager`'s 35 generated forwarders**: its docblock argues the case and is right. **The `hrtime` phase timers in `Loop`** and `Published::set`'s string match: measurement is this project's currency and they are cheap. **`Output::capture()`'s `ob_start` fallback** despite having no production caller: it is what lets the unit suite run under plain php-cli.

### H36 The lock hazard has a number `main` `done (V-51, 2026-09-17)`: shim + harness written and run — `park` breaks (fiber 1 sees the mutex held by a parked fiber), `block` serializes (400 ms). The four internal functions live in `crates/ignis/src/php/locklib.rs` and are registered only when `IGNIS_LOCKLIB` names the .so.

### H-13 `php/packages/runtime/src/ignis.php` at phpstan level 6 (filed as a second `H-12` until 2026-09-18) `agent` `done 2026-09-17 (V-79 addendum): level 6 is 0 across every package, not just this file; the entry's path was stale after the package split`
**What.** The H-11 agent's run reported ~30 level-6 findings in `php/packages/runtime/src/ignis.php` (generics on
`Fiber`/`WeakMap`, untyped iterables, always-true conditions); main's raw-format count read 0, so
the number is not established. Establish it, then fix the ones that are real without changing
behaviour. **Acceptance.** phpstan L6 on `php/packages/runtime/src/ignis.php php/pg/*.php php/offload/*.php` with the
stub loaded: 0 errors, and `scripts/smoke.sh` still GREEN.

### H-8 Retire the `IGNIS_ADDR` name `agent` `done (validated by main: no code hits, classic_server answers on IGNIS_LISTEN)`
**What.** `bench/e15-frankenphp.sh` sets `IGNIS_ADDR`; `examples/classic_server.php` and
`examples/grpc_server.php` fall back to it (H-1). One name: `IGNIS_LISTEN` everywhere, fallback
removed. **Acceptance.** `grep -rn IGNIS_ADDR --include=*.sh --include=*.php --include=*.md . | grep -v JOURNAL | grep -v VALIDATION` is empty.

### H-6 Delete `bench/php/pg_async_probe.php`'s hardcoded DSN default `agent` `done (validated by main: rc=2 on both probes)`
It defaults to `127.0.0.1` with user/password baked in. Make `PG_DSN` required with a one-line
message. **Acceptance.** Running without `PG_DSN` exits 2 with the message.

---

### S-DBAL-POOL A connection per request is parity with php-fpm, not the answer `main` `built 2026-09-18 (V-85 addendum 2, rewired to container configuration in addendum 3): `ignis_doctrine.pool.size: N` gives each thread a pool of N, warmed at boot, leased per fiber, returned with the V-21 reset; per-fiber stays the default. What is left is the number below.` A pool of 4 serves 30 sequential requests on one backend where the per-fiber mode opens 30, and a pool of 2 serves six overlapping requests correctly and in turn. **Still unmeasured, and it is the number that should decide the default:** `pdo_pgsql` per query against `Ignis\Pg`'s 112 µs on the same box — V-21 recorded "Not measured yet" and it still is. Also unbuilt, in rough order of appetite: a pool shared across threads (today it is per thread, so the process opens `threads × N`), `stats()` on `/_ignis/stats` next to the `Ignis\Pg` numbers, and a reset for drivers other than PostgreSQL — MySQL and SQLite get none today and carry their session settings into the next request, which the documentation states rather than hides.

### S-RELOAD Code reloading without restarting the process `main` `DONE 2026-09-19 (V-90): IGNIS_WATCH over get_included_files(), workers one at a time, SIGHUP, gate bench/e25-reload.sh. What is left is the watcher's blind spot — a file never loaded is never watched — for which Node's answer is --watch-path and ours is not built.` In worker mode a file change does **nothing, ever**: the kernel is booted once per thread and its classes live in that engine until the thread dies. Classic mode has no such problem — the script is included per request and opcache's defaults pick a change up within ~2 s — so this is specifically the Symfony path, and today the only answer is restarting the process. **Most of the machinery already exists:** `spawn_worker` gives a respawned thread a fresh TSRM context (new class table, new statics), the listener is process-wide so a thread can leave and return without dropping a connection (V-17: recovery inside the 50 ms tick, 95.7 % of baseline), and `http_unregister_current()` takes one thread out of dispatch while the others serve. **What is missing is the trigger** — a worker's script ends only on a fatal or by returning, and `serve()` never returns (`S-SERVE-STOP`). **The order to build it:** (1) `Ignis\stop()`, with unregister → drain → return rather than today's return → unregister; (2) `SIGHUP` as a rolling restart, one worker at a time, which is the half of M4-5 that has been waiting for it — gate: `wrk` across a reload with zero non-2xx and the new code answering afterwards; (3) **a watcher over `get_included_files()`** — Node's model (`--watch` follows "the entry point and any required or imported module"), and for us the *primary* rather than the fallback, because the owner named the deciding property: restarting the worker after each request — RoadRunner's `pool.debug`, where they landed after deleting their own watcher — **destroys concurrency in development**, and every bug of the last week (V-68, V-69, V-85) needs two requests overlapping to appear. Measured on our own fixture: one request includes **283 files** (258 vendor, 23 `var/cache`, 2 own) against **4,621 PHP files under `vendor/`** — 16× fewer inotify watches, the compiled container watched for free because the application loaded it, and no ignore list to maintain. The gap is Node's gap: a file never loaded is never watched, answered by an explicit `--watch-path` that adds to the set rather than replacing it. PHP reports the set through a zif after boot and after each request (first call 283 strings, later ones an almost-always-empty delta); Rust owns the watches, the debounce and the grace period, and the workers stop one at a time so requests keep overlapping through the reload; (3a) per-request restart stays available as an opt-in for people who want RoadRunner's behaviour, documented with its cost — it serialises development, so a fiber-scope bug will not show up there; (4) `opcache_reset()` on an **explicit** reload while a respawn-after-fatal keeps leaving SHM untouched — the two events mean different things, and a production ini with `validate_timestamps=0` would otherwise make reload silently do nothing. **Rejected:** rebuilding the kernel per request in debug mode — it costs a boot per request, which is what worker mode exists to avoid, and it only reloads what the kernel rebuilds, so the illusion holds until it does not.

### E18-A ADR-0020 `main` `done (accepted; policy table from research 27). H32/H33 CONFIRMED by V-45, H35 by research 28, H36 by V-51; H34 answered "not as written" — this box's libcurl uses a threaded resolver (R-DNS)`
Symbol list, gate, policy table with defaults, reentrancy guard (our own reactor calls `poll`;
nested calls must fall through), `connect` via temporary `O_NONBLOCK` + park-on-writable + flag
restore, `getaddrinfo` via a resolver `Op` with glibc-compatible `addrinfo` allocation (and
`freeaddrinfo` interposed), the kill criterion verbatim from the owner, and the five acceptances as
H32–H36 with their benches: `bench/php/e18_curl.php` (100 × 200 ms `curl_exec`, WRITEFUNCTION
fiber identity), `bench/php/e18_pgsql.php` (offload off), `bench/php/e18_dns.php`,
`bench/e18-overhead.sh` (two builds, 10M zero-length `read`s, < 20 ns delta), `bench/e18-deadlock.sh`
(the R2 shim under `park` deadlocks with a timeout, under `block` passes).

### H-12 E4 hello throughput is 58k req/s on this box today, V-6 measured 128k `main` `CLOSED 2026-09-18 — V-82 (the trailing "open" this status also carried is struck: the residual 4-5% is recorded, not open work): the bisect is refused with a number, the effect is under the instrument's noise`. `open — measured (V-46 addendum 3): V-6's own commit gives 61.5–63.1k on this box, so 128k → 62k is the box; the code-side drop 2026-09-15 → HEAD is ≈ 4–5 % (58.3–60.0k) and still worth one bisect in a quiet slot` — V-46 addendum 2: park on and off both ~58k, p99 1.8 ms, quiet box, same wrk shape as V-6 (`-t2 -c64 -d10s`, 1 PHP thread). Either the box changed (WSL2 kernel 6.18 now; V-6's kernel not recorded) or something landed between 2026-09-15 and cycle 1 (budget admission, health route, superglobals lazy swap, log floor). Bisect with `git bisect run` over `bench/wrk-hello.sh` before any perf claim cites V-6 again.

### S0-RESPOND-START `ignis_respond_start` is registered twice and called by nobody `main` `DONE 2026-09-18 — removed as an intentional API change (V-92 addendum); php-api.md records it`
**What.** Reported by the documentation session and confirmed here: `grep` across `php/`, `examples/`,
`bench/` and `scripts/` finds no caller. The Rust side registers it in **both** `FUNCTIONS` tables
(`module.rs:902` and `:953`) and implements `zif_ignis_respond_start`; userland reaches streaming
through `ignis_stream_bind` instead.
**Why it is not just dead code.** It is a public `ignis_*` function, so deleting it is an API change,
and `R-NOT-DOING` settled that the duplicated `FUNCTIONS` tables stay as they are — which means the
registration is deliberate in shape even where the entry is not.
**Done 2026-09-18.** Removal, taken as the intentional API change this asks for: no caller exists in
`php/`, `examples/`, `bench/` or `scripts/`, the streaming path settled on `ignis_stream_bind`, and a
public function nobody can reach is surface without a contract. `php-api.md` no longer lists it, and
`StubsMatchTheBinaryTest` reads `module.rs` now, so the next registration without a stub is a failing
test rather than a paragraph explaining itself. The three tables keep their shape — `R-NOT-DOING`
stands and the explicit array length is what made the three-way edit safe.

### A-OUTPUT-FIBERKEY `output.rs` keys per-fiber state by address with no destroy hook `main` `DONE 2026-09-19 (V-94)` — keyed by `zend_fiber_context` now and dropped by a destroy observer registered at MINIT; probe `bench/php/output_abandoned_fiber.php` in smoke, 2000/2000 leaked before the fix and 0/2000 after
**What.** `SINKS` and `BOUND` (`crates/ignis/src/php/output.rs:31,40`) are keyed by
`active_fiber as usize`. The `// SAFETY:` note argues a stale key "cannot collide, because a context
is only reused once its entries are gone (`zif_capture_reset` at request end)" — which is a guarantee
made by PHP code, not an invariant of the map. `superglobals.rs` has the same problem and solves it
properly, with `zend_observer_fiber_destroy_register` (`:245`, `on_destroy` at `:203`).
**Why it matters.** A fiber destroyed on a path that skips the reset (a fatal, an unwind) leaves its
entry behind, and the next fiber allocated at the same address inherits a live response binding. That
is the class V-72 and V-76 are about: one request's output in another's body.
**Acceptance.** `output.rs` registers a destroy hook that drops the fiber's entries, as
`superglobals.rs` does, and a test kills a fiber mid-capture and shows the next one at that address
starting clean. Not a rename of the key: the address is fine once something removes it.
**Constraints.** `main` (FFI, an observer registration).

### A-REACTOR-POISON A panic in the dispatcher poisons a mutex and every later op panics with it `main` `DONE 2026-09-19 (V-94)` — one `lock_unpoisoned()` in `crates/ignis/src/lock.rs`, 42 sites across eight files, with a test that poisons a real mutex and takes the data anyway
**What.** `reactor.rs:211,215,227,230,233` take `cancellable_tasks_by_op` with `.lock().unwrap()`
inside spawned tasks. A panic anywhere in one of those critical sections poisons the mutex, and from
then on every `Op::Sleep`, `Op::Watch` and `Op::CancelWatch` on that reactor panics on the lock —
inside a tokio task, so the failure is a dead dispatcher rather than an error anyone sees.
`watch.rs:90` and `:111` already do the right thing: `unwrap_or_else(|e| e.into_inner())`.
**Why it matters.** The map holds abort handles, nothing whose invariants a panic could break, so
poisoning protects nothing here and costs the whole reactor. Same shape at `http.rs:283,297` (per
connection), `offload.rs:73-131`, `grpc.rs:155-222` and `route.rs:150,208` (per routed call).
**Acceptance.** Every `.lock().unwrap()` on a map of handles becomes `into_inner()` on a poisoned
lock, with one line saying why that is safe for this data; a test that panics inside a spawned task
and then submits an op successfully.

### A-ARGINFO Four PHP functions reflect somebody else's parameter names `main` `DONE 2026-09-19 (V-94 addendum)` — one table per function, declared by an `arginfo!` macro; `bench/php/arginfo_names.php` reflects all 37 against the stubs and is in smoke
**What.** The arg-info tables in `module.rs:79-97` are keyed by **arity**, not by function
(`ARGINFO_ONE`, `ARGINFO_GRPC2/3/4`, `ARGINFO_T2/T3`), so any function borrowing a table of the right
size inherits another's parameter names and `required_num_args`. Concretely: `ignis_respond_chunk`
reflects as `$id, $status` (should be `$id, $bytes`) with `required_num_args = 4` against
`num_args = 2`; `ignis_offload_submit` as `$id, $code, $message` instead of `$fn, $args, $affinity`,
required 3 although the third is optional (`ss|l`); `ignis_stream_bind` declares 3 args and requires
4; `ignis_submit_sleep`, `ignis_cancel`, `ignis_grpc_recv`, `ignis_watch_files` and
`ignis_publish_stats` all reflect their one parameter as `$value`.
**Why it matters.** Named arguments do not work on those functions and `ReflectionFunction` lies
about them — and a `required_num_args` above `num_args` is a contradiction the engine is being told.
Nothing calls them by name today, which is why nobody noticed; the stubs are what every analyser
reads and they disagree with the binary.
**Acceptance.** One arg-info per function with its real names and required count, or a comment on
each shared table naming every function allowed to use it and why the names fit. A test that
reflects each `ignis_*` function and compares against `stubs/ignis.php` — `StubsMatchTheBinaryTest`
is the place.

### S-RESET-FIBER Symfony's service reset is process-wide, and a fiber can be inside the services it resets `main` `DONE 2026-09-19 (V-95)` — `services_resetter` is a no-op in fiber mode; E21's `/reset` arm, control 3/3 and fixed 0/3
**What the owner raised.** `services_resetter` is process-wide; a reset driven by one request destroys
the request state of fibers parked on the same thread — `RequestStack` cleared, `EntityManager`
closed. Proposed fix: in fiber mode `ignis/symfony-runtime` removes the reset listener and replaces
`services_resetter` with a no-op (scope death is the reset); a `ResetInterface` tag auto-registers the
service as fiber-scoped and takes it out of the resetter; a dev-mode guard throws when `reset()` hits a
service that has a fiber-scoped proxy. Classic mode keeps the resetter as it is.

**Corrected before building, by reading the installed Symfony.** There is no `ResetServicesListener`
in this version and nothing resets on `kernel.terminate`. The reset is driven by `Kernel::handle()`
itself (`php/vendor/symfony/http-kernel/Kernel.php:66-80,124-146`): `handle()` sets
`resetServices = true` and increments `requestStackSize`; the **next** `handle()` calls `boot()`,
which resets **only if `requestStackSize` is 0**.

That guard is why this has not already been seen: while any request is inside `handle()` the counter
is non-zero and no reset happens. The hole is everything a request does **after** `handle()` returns,
where the counter is back down:

- a `StreamedResponse` — `IgnisWorkerRunner.php:33` hands `sendContent(...)` to the loop, which drives
  it *later*, and Doctrine, Twig and the token storage are all live inside it;
- `kernel.terminate` listeners (`:39`), which under universal park can suspend on any I/O they do.

In both, a second request entering `handle()` finds `requestStackSize == 0`, resets every resettable
service, and the first fiber resumes into the wreckage. So the owner's conclusion stands and the
trigger does not: it is `boot()` at the start of the next request, not a terminate listener.

**Acceptance.** The owner's test, with the shape corrected: two interleaved requests with Doctrine
where A is **streaming or terminating** (not merely finished) while B enters `handle()`; A must
complete. Plus a control on the unfixed build that must fail, or the test proves nothing — and a
counter-example is required for the guard above, because a test that cannot fail is this cycle's
recurring finding (V-93, V-94).
**Constraints.** `main`. Classic mode keeps the resetter. The no-op must not silently swallow a reset
an application asked for by hand (`$container->get('services_resetter')->reset()` in a console
command is legitimate and shares the process).

## Cycle 2026-09-19 — closed by the agent round and the main queue

### M4-6 Held-resource logging audit `agent` `DONE 2026-09-19 — docs/research/42-observability.md, re-run by main (metric count and the discarded age both re-measured)`
**What.** After M4-1: an inventory `docs/research/42-observability.md` of every wait a fiber can
be in (stream read/write/connect, sleep, pg lease, offload job, watch, gRPC call, Temporal
activation) and, for each, whether its age is visible in `/_ignis/stats`, in a log line, in both,
or in neither. Propose the minimal set to close the "neither" rows.
**Acceptance.** Table complete against `grep -n "Op::" crates/ignis/src/reactor.rs`; every "neither"
row has a proposed metric name.
**Result.** 13 rows, **zero** of them visible in both channels and zero visible in a log line alone;
12 genuine "neither". Five proposed metrics close 11 of the 12 — `ignis_op_oldest_age_seconds`
alone covers rows 1-7, because every one of those waits, universal park included, bottoms out in
the same `Op::Sleep`/`Op::Watch` id space. Row 9 is deliberately left open: it is self-bounded by
`PoolTimeoutException`.
**Two claims main re-measured before accepting.** `/_ignis/metrics` serves **19** metric families,
not V-55's 22 — the three that went are the deleted pool's (V-87), so this is drift in the citation,
not a regression; `docs/operate.md` and ADR-0022 corrected, and V-55, JOURNAL and the closed-index
row left alone because they record what was true when written. And the one age this system already
computes is thrown away: `module.rs:268` calculates a disconnect's `age_us`, `Loop.php:1007-1009`
stores it in `$cancelAgeUsMax`, and `Loop::publishStats()` (`Loop.php:398-414`) publishes ten fields,
none of them that one — confirmed by reading the call.

### A-DUPES Two copies of the same thing, in three places `agent` `DONE 2026-09-19 — (a) and (b) fixed, (c) kept as two shapes with the reason; re-run by main, including a falsification of the new gate`
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
**Done 2026-09-19. (a) the defect was real and is fixed:** `Client::runCallback()` serialised a
three-element envelope `[class, message, code]` where the job path sends four, and
`WorkerRuntime::unpackCallbackAnswer()` never read a fourth element anyway — so `remoteTrace` was
empty by construction on both sides at once. Both halves fixed; `EnvelopeTest` drives a real
throwing callback through `runCallback()` and asserts the trace arrives with the throwing frame in
it.
**"One declaration each" is not reachable from PHP, and that is a finding, not an excuse.**
`worker.php` is `include_str!`ed into the binary (`main.rs`) and evaluated under a synthetic
filename, so `__DIR__` inside it resolves to the process's working directory, not to the package —
a `require` of a shared file would find nothing in a real deployment. Merging the declarations
needs a second `include_str!`, i.e. a Rust change, which is outside an `agent`'s reach. The
contract was made real the other way instead: `EnvelopeTest` tokenises both files and asserts the
two declarations are identical token for token.
**Main falsified that gate rather than trusting it:** renaming `$remoteTrace` to
`$remoteTraceDrifted` in `worker.php` alone makes the test fail and print the differing token
(`23 => '$remoteTrace'` against `23 => '$remoteTraceDrifted'`); restored, 12/12 green again. It can
fail, which is the whole point of adding it.
**(b)** the eight checks now live once, in `Ignis\Http\Request::validateRaw()`, with each caller
passing its own two exception strings — `Loop`'s wording is asserted verbatim by an existing test
and `Runner`'s by nothing, so both were preserved rather than unified on a guess. Dead
`asStringHeaders()`/`stringHeaders()` removed. Checked against the removed code: neither copy
lower-cased header names before, and neither does now.
**(c) left as two shapes, with the reason on each.** `publishStats()`'s ten keys are a fixed wire
contract matched 1:1 by `metrics.rs`'s `Published::set()`; `budgetStats()` is the public surface the
examples compose their own `/stats` from, deliberately narrower and carrying `inflight`, which the
Rust side tracks itself. Merging would either widen the hot-path array or strip callers. This is
the case the acceptance explicitly permitted.
**Gate, re-run by main:** PHPStan level 9 both configs `[OK] No errors` (still exactly two
`ignoreErrors`), php-cs-fixer `0 of 150`, PHPUnit `320 tests, 757 assertions` green.

### A-RUST-DEAD Three pieces of machinery kept alive by an empty default `main` `DONE 2026-09-19 — one was dead and is deleted; the other two were reachable and the item's premise was wrong about them`
**What.** (a) `route.rs:45` `const DEFAULT_FUNCTIONS: &str = ""` means the loop at `:82-92` never
runs, so `trampoline`, `call_original`, `frame_name` and `ORIG_FN` — about 80 lines — are unreachable
unless `IGNIS_OFFLOAD_FUNCTIONS` is set by hand. (b) `locklib.rs` is 202 lines of the H36 test harness
compiled into the production binary, reachable only via `IGNIS_LOCKLIB`; its own doc says "a normal
build has no trace of them", which is true of the function table and not of the binary. (c)
`temporal.rs:348` is `#[allow(dead_code)] fn _unused(_: c_int) {}`, a placeholder keeping an import
alive.
**Acceptance.** For each: a caller, a feature gate, or deletion. (b) behind a `cfg(feature)` would
also make the claim in its doc block true.
**Done 2026-09-19, and two thirds of this item were wrong.** Checked each against the tree rather
than against the entry.
**(a) is not dead: it has a caller, and that caller is documented.** `DEFAULT_FUNCTIONS` is empty,
but `install()` reads `IGNIS_OFFLOAD_FUNCTIONS` first, and that variable is a documented escape
hatch — ADR-0016 §66, `docs/reference/php-api.md:184`, `DECISIONS.md:178`, and the V-59 addendum
that made the default empty all describe it. `trampoline`/`call_original`/`frame_name`/`ORIG_FN` are
reached through it. Nothing changed; the acceptance's "a caller" is satisfied. What is true and
worth keeping separate: **nothing exercises that path**, so those ~80 lines can rot the way backend
(b) did — that is `A-RUST-TESTS`'s shape, not this item's.
**(b) is not dead either, and a feature gate would have made things worse.** `locklib` is
registered only when `IGNIS_LOCKLIB` names the shared object (`superglobals.rs:246`) and
`bench/e18-deadlock.sh` is its caller. A `cfg(feature)` would make the doc block's claim true and
would also mean that bench needed a special build to run — and it is in **no gate today**
(`smoke.sh`, `gate.sh` and `ci.yml` were all checked), so gating it would turn an unrun bench into
an unrunnable one. Declined with the reason; the doc block was corrected instead, because what it
claimed ("a normal build has no trace of them") is true of the function table and false of the
binary, and the two are not the same claim.
**(c) was genuinely dead and is gone.** `_unused(_: c_int)` existed to keep `c_int` imported, and
`c_int` was imported only for `_unused` — a closed loop referenced by nothing. Both deleted.

### A-SWALLOWED-RUST Errors dropped where the drop changes behaviour `agent` `DONE 2026-09-19 — three Rust sites log what was lost; the PHP site documents why its silence is correct and a test pins it; all re-run by main`
**What.** `offload.rs:57` `let _ = POOL.set(…)` — a second `initialize(n)` is silently ignored, so
`--offload N` after a pool exists keeps the old width and says nothing. `main.rs:319`
`let _ = h.join()` — an offload thread that panicked is indistinguishable from one that exited
cleanly. `http.rs:248` `let _ = stream.set_nodelay(true)`. And on the PHP side, `Loop::dispatchUnawaited()`
(`Loop.php:425`) drops an unawaited array payload matching none of its three tags with no log at all —
`Router::release()` relies on exactly that, which makes the silence load-bearing and undocumented.
**Acceptance.** Each either logs at `warn` with what was lost, or carries one line saying why losing
it is correct. The `dispatchUnawaited` case needs the second, and then a test pinning it.
**Done 2026-09-19.** The three Rust sites name what was lost (`offload.rs` second `initialize`,
`main.rs` panicked worker, `http.rs` `set_nodelay`). `dispatchUnawaited` took the second option as
the acceptance directed — no log — and now names who depends on the silence:
`Ignis\Offload\Router::release()`'s fire-and-forget free job, whose failure arrives tagged
`kind => 'error'`, matching none of the three dispatched tags, with nobody left to tell because the
caller already dropped the handle. Pinned by
`LoopTest::testAnUnawaitedCompletionMatchingNoneOfTheThreeTagsIsSilentlyDropped`.

## Merged duplicates, 2026-09-19 (reconciliation against the code)

### S1-CAP Cap concurrent connections at the listener (M4-3/B8) `main` `MERGED into M4-3 on 2026-09-19 — the same defect filed twice; this id is kept only so links to it resolve`
**What.** ADR-0025. Nothing bounds accepted connections, and V-37 measured ~33 kB per held one, so
RSS is unbounded under load — the half of B1's acceptance a fiber budget cannot reach.
**Acceptance.** `limits.max_connections` enforced at accept; over the cap the listener stops
accepting rather than queueing unboundedly; RSS at 2× the cap is flat. Lands with S3-LIMITS.

### S4-ANSWER-MAP One `HashMap<u64, Answer>` instead of three `main` `MERGED into R-ANSWER-MAP on 2026-09-19 — its own text already said "Already filed as R-ANSWER-MAP"; this id is kept only so links resolve`
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

## Cycle 2026-09-20 — fiber-scoped objects, and what the round before it finished

### R-SESS A file lock held across a yield DEADLOCKS the thread `main` `CLOSED 2026-09-19 — the deadlock was fixed by a6c1a56 and gated in smoke.sh; the residual (the retry loop's missing deadline) was discharged on its own terms in A-PARK-ARITHMETIC (d)`. Not a stall: a hang. `ext/session`'s files handler takes a blocking `flock(LOCK_EX)` (`mod_files.c:210`) and holds it from `session_start()` to `session_write_close()`. A regular file cannot be parked (epoll refuses it), so a second fiber's `flock` blocks the **OS thread**, so the loop can never resume the holder, so the lock is never released — measured: two fibers, killed at 12 s with no progress. Park makes it *more* likely, not less: `usleep`, `sleep` and stream I/O all yield now, so the holder is suspended far more often than a php-fpm developer would expect. **Symfony's cache lock is NOT affected** (V-58): `LockRegistry` uses non-blocking `flock` plus `usleep(100 ms)` polling, and `usleep` parks — measured 60/60 ticks during a 600 ms contended wait. **The rule:** a lock that can be held across a yield must live on a socket (Redis, PostgreSQL) or in the runtime, never on a file. Fixes to choose from, none built: (1) ship a PostgreSQL session handler on `Ignis\Pg` using `pg_advisory_xact_lock` — the wait is server-side and the client waits on a socket, which parks; (2) document Redis/PDO handlers and refuse to start with `session.save_handler=files` (cheapest, turns a mysterious hang into a message); (3) a runtime-owned session store with the lock as a reactor op — fastest and dependency-free, but new Rust and no durability across restarts (ADR-0024). Also unmeasured: whether an actual Symfony session reaches that `flock` under the embed SAPI at all — `session_start()` hit `headers already sent` in the probe.
**Reconciled 2026-09-19.** This item says "fixes to choose from, **none built**". That is false and has been since the day after it was written: `ignis_park_flock` interposes the blocking `flock` so it parks instead of holding the OS thread — commit `a6c1a56`, "interpose flock so a blocking lock inside a fiber parks (S1-FLOCK, V-58)", +49 lines in `park.rs`, +5 in `csrc/park.c`, **+10 in `scripts/smoke.sh`, so it is gated**. The deadlock this item is named for is therefore closed by shipped code, not by one of its three proposals. **The residual, and the only reason this is not `CLOSED`:** that retry loop has no overall deadline, so a lock held by a crashed holder parks the fiber for the life of the process — filed as `A-PARK-ARITHMETIC` (d), where it belongs. Close this item when that lands.

### A-BACKEND-B-CI Nothing anywhere builds backend (b) `main` `DONE 2026-09-20 — .github/workflows/backend-b.yml, scheduled plus workflow_dispatch (owner decision 2026-09-20, DECISIONS.md)`
**What.** `crates/ignis/src/backend/async_core.rs` is behind `cfg(php_async_abi)`, which needs the
true-async engine (`scripts/build-php-async.sh`). No CI job builds it and this box has no such engine,
which is why it sat with a two-arm `match` against a nine-variant enum until V-91. The tripwire now in
`reactor.rs` catches the *enum* growing; it cannot catch anything else in that file.
**Built 2026-09-20.** A scheduled job, not a push job: building php-src takes minutes and this
backend changes on the fork's schedule rather than ours. It is not in `nightly.yml` either, whose
own header says correctness lives in `ci.yml` — so it is its own workflow. The fork prefix is
cached and keyed on the branch, because a dispatch with a different branch must not silently reuse
another one's build.
**Compiling is the floor, not the gate.** The job runs `cargo check`, then `clippy -D warnings` —
the same gate `ci.yml` uses, and the one that actually caught the last breakage in feature-gated
code on 2026-09-19 — then a release build, then the unit suite against the fork, because a binary
that links is not yet a binary that works.
**And it answers a second question this item did not know it had.** `R-TA-CONTEXT` and
`R-TA-REQUEST-SCOPE` both need to read the fork's headers, and on 2026-09-19 there was no checkout
on this box, so an owner note had to be verified over the network against a branch nobody could
name. The job uploads `zend_async_*.h` as an artifact, tagged with the branch it built, so the
next person answering those reads a revision this job names.
**Still open, deliberately:** which branch is the right one. Three names are in play — `async-core`
(the script's default, PR #22561 head), `PHP-8.6-true-async`, and whatever revision php-async's
CHANGELOG #105 requires for `request_scope`. The job takes the branch as a dispatch input rather
than pretending to know; settling it is `R-TA-REQUEST-SCOPE`'s first job.

**Acceptance.** A CI job that runs `scripts/build-php-async.sh` and
`PHP_CONFIG=/opt/php86-async-zts/bin/php-config CARGO_TARGET_DIR=target-async cargo check -p ignis`,
on a schedule rather than per push if the engine build is too slow for the main gate. Or ADR-0003 is
amended to say backend (b) is a recorded experiment that is not kept compiling, and the file says so
at the top.

### A-PARK-ARITHMETIC Overflow before the clamp, and two syscall shims that answer wrongly `main` `DONE 2026-09-19 — all four parts, with the seven tests park.rs never had`
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
**Done 2026-09-19, and the test was written before the fix so it could be watched failing** —
`attempt to multiply with overflow` at `park.rs:536`, which is what the release build turns into a
spin. (a) `module.rs`'s `ignis_submit_sleep` saturates. (b) the three clamp-after-multiply sites
became one helper, `milliseconds_ceil`, used by both `ms_ceil` and `sock_timeout_ms`; the third,
`ignis_park_nanosleep`, got a validator instead — `tv_sec as u64` turned a negative interval into a
wait of roughly 584,000 years, and POSIX calls that `EINVAL`, so it is refused and the real syscall
produces the error itself. (c) `sleep()` now answers with the unslept remainder, rounded up, via
`unslept_seconds`; it returned a flat 0 on every path, telling a caller its sleep completed when a
signal had cut it short. (d) the `flock` retry loop takes the second branch: a `ponytail:` line
names the ceiling and says why a deadline would be wrong — a blocking `flock` has no timeout in
POSIX, so returning `EWOULDBLOCK` after an interval of our choosing is an error no caller expects.
The ceiling named there is real and its upgrade path is `S-POOL-LEASE-AGE` fix 3, one watchdog
instead of a timeout per call site.
**Seven tests**, `park.rs`'s first: saturation, round-up, zero and negative, seconds-plus-nanoseconds,
the two `EINVAL` shapes, valid-interval saturation, and the interrupted-sleep remainder.

### A-UNSAFE-CONTRACTS Four `// SAFETY:` notes that do not justify their code `main` `DONE 2026-09-19 for correctness — all five false notes (four filed, one found) now describe their code; the de-duplication half is folded into research 43's park_gate merge`
**What.** The four-line paragraph "Nothing here dereferences the caller's buffer — it is handed
straight back to the kernel" appears verbatim **17×** in `park.rs` (counted 2026-09-19; this entry said 14) and is **false** at **three** of them (this entry said two) — the third, `ignis_park_nanosleep`, dereferences `(*req).tv_sec` and `(*req).tv_nsec` at `park.rs:730` under that exact paragraph, and was missed both by the audit that filed this item and by main reading the same function aloud on 2026-09-19. The two the entry did name:
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
**Two of four done 2026-09-19.** `locklib::install` now states the real reason it is sound: `fns` is
a stack copy that does **not** need to outlive the call, because `zend_register_functions` copies
each entry before returning — what must be `'static` is what the entries point at, every `fname`
and arginfo being a literal. `worker_arg` (`backend/temporal.rs`) became an `unsafe fn` with a
`# Safety` block, and its six call sites were wrapped; it was a safe function whose comment
asserted a caller contract its signature did not require, so any caller could hand it a null.
**All five done 2026-09-19.** `ignis_park_ppoll` says it dereferences the caller's `timespec` to
compute the wait; `ignis_park_select` says it both reads **and writes** the caller's fd sets —
`ptr::read`, `FD_ISSET`, `ptr::write` — which the shared paragraph flatly denied; `ignis_park_nanosleep`,
the fifth site and not in this item's original count, says it reads `*req`. The paragraph now
appears **14 times instead of 17, and every remaining copy is true** — each of those sits over a
shim that really does hand the buffer straight to the kernel.
**The de-duplication half stays open, deliberately, and moves to research 43.** This item's own
acceptance asks that whatever is genuinely common go in the module doc once. Doing that now would
be done twice: research 43 proposes collapsing 11 of the 14 io-shaped shims into one `park_gate`
helper (≈−80 lines), which deletes those copies structurally rather than editing them. The correctness
defect — notes that lie about their code — is what made this red, and it is closed.

### A-LEAKS-RUST Three thread-local and process-wide maps that only grow `main` `DONE 2026-09-19 — all three, with the arm the acceptance asked for, falsified before it was trusted`
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
**Landed 2026-09-19, re-run by main (fmt, clippy `-D warnings`, 60/60 workspace, 8/8 offload).**
(b) `JOBS` values became `RunningJob { caller, op, worker }`, stamped by `next()`; `worker_gone(i)`
fails whatever worker `i` still held through the same `caller.complete(op, Outcome::Failed(..))`
path a rejected submit already used, and `main.rs` calls it after the worker loop returns for any
reason. `CALLBACKS` entries are now removed win or lose and the reply wait is bounded
(`recv_timeout`, 30 s). (c) both queues are `bounded(4096)` with `try_send`, and a full queue is
refused as a distinct reason rather than blocking the submitter. `submit()`'s error text changed
from `"worker gone"` to `"offload worker gone"` / `"offload queue is full"`; checked, nothing
matches on it.
**(a) done 2026-09-19:** `zif_shutdown` now calls `forget_worker(id)` after `initiate_shutdown()`,
so the map keeps the promise its own module doc makes — entries live for the process lifetime *or
until shutdown*, where only the first half was true.
**The arm exists now, and it is the whole point of this entry.** `bench/php/offload_worker_dies.php`
plus its prelude offloads a job that calls `exit()` — uncatchable, so `WorkerRuntime::run`'s own
try/catch never sees it and the worker loop unwinds with the job still marked running, which is the
shape a PHP fatal produces. Gated in `scripts/smoke.sh`. Measured with the fix:
`inflight_before=0 inflight_after=0 failed=true`, `reason=offload: offload worker gone`.
**Falsified before being trusted**, which is what the previous note on this item said was missing:
with `offload::worker_gone(index)` deleted from `run_offload_worker` and the binary rebuilt, the
probe does not fail — it **hangs**, `exit=124`, because the calling fiber waits on a completion
nothing will ever send. That is the defect, reproduced on demand, so the timeout is part of the
assertion rather than a safety net around it.

### S-SAPI-REQUEST-INFO A form body on PUT/PATCH never reaches the framework `main` `DONE 2026-09-20 — V-102, gated as its own E21 arm`
**What.** The embed SAPI's `SG(request_info)` is not filled from the request, so PHP's own
`request_parse_body()` refuses: **`RequestParseBodyException: Request does not provide a content
type`** — measured under `Ignis\serve()` for both POST and PUT. Symfony 8's
`Request::createFromGlobals()` (`http-foundation/Request.php:338-352`) calls exactly that function
for `PUT`, `DELETE`, `PATCH` and `QUERY` and falls back to `$_POST` when it throws; the runtime fills
`$_POST` for `POST` alone, so `$request->request` is **empty** for a `PUT` with an
`application/x-www-form-urlencoded` body. Measured: `{"method":"PUT","parsed":[],"content_length":7}`
— the body is there, nothing parsed it.
**Why it was invisible.** `IgnisWorkerRunner::toSymfony` used to build a Request and throw it away
for any non-urlencoded body, which covered JSON. A **urlencoded** body on PUT matched the
first branch, so it never got the raw body either — the one shape the workaround did not cover was
the one it looked like it covered.
**Not fixed by the 2026-09-20 `php://input` work, and that is the point.** `Loop::enterRequest()`
now backs `php://input` for every method, so `$request->getContent()` returns the body where it used
to return nothing. Symfony 8 does not read `php://input` for this any more — it asks the SAPI. The
stream wrapper cannot reach that.
**Fix, unbuilt.** Fill `SG(request_info).content_type` (and the post-data path) when a request enters
a PHP thread, next to where the superglobals are set. `main` lane, `crates/ignis/src/php/`.
**Acceptance.** `bench/e21`'s `/body` route: a `PUT` with `a=1&b=2` reports
`parsed={"a":"1","b":"2"}`, and a control without the fix reports `parsed=[]` — it does today, so
write the arm first and watch it fail.
**Done 2026-09-20 (V-102).** `php/post.rs` holds the request's bytes and hands them to the engine
when it asks — per fiber, because `createFromGlobals()` runs inside the request's fiber and a
handler may await before it parses. PUT and PATCH form bodies now reach `$request->request`; POST
and JSON are unchanged. Three sequential PUTs each parse their own, which is the check that matters
when one `php_request_startup` covers the whole `serve()` script and `sapi_read_post_block` sets
`SG(post_read) = 1` on a spent body.
**The mistake kept for the next person:** filling `SG(request_info)` removed the exception but left
`parsed=[]`, and the reader **was never called once** — `read_post` was installed at MINIT, after
`sapi_startup` had copied the module struct. `embed.rs` sets `ub_write` and `flush` before
`php_embed_init` for exactly that reason.

### R-TA-CONTEXT Can our per-scope storage sit on upstream's `internal_context` `research` `DONE 2026-09-20 — docs/research/48, read at async-core@14af3cb2; ADR-0003 amended. Answered, not satisfied: the design does not change` — it no longer blocks `S-SCOPED-CLASS`
**What.** Read and write up `zend_async_context_t` (find/set/unset/dispose plus `offset`), the
coroutine's two fields `context` and `internal_context` (HashTable, numeric keys) with the
`zend_async_internal_context_key_alloc`/`_find`/`_set`/`_unset` and
`zend_async_coroutine_internal_context_dispose` surface, `zend_coroutine_switch_handlers_vector_t`,
and the userland surface — whose stub file main could not locate (finding 3 above), so **find it
first and record where it is**: `current_context`, `coroutine_context`, `root_context`,
`request_context`, Context keys `string|object`, the Scope-chain walk and the one-level `*Local`
forms.
**Three questions, each answered with a citation.** (1) Can our per-scope storage sit on
`internal_context` under a key from `key_alloc`, so backend (b) inherits context for free?
(2) Does their per-coroutine `context` cover what our superglobal slots do (`superglobals.rs`), or
is it strictly a user-facing map? (3) What do their `switch_handlers` give that our
`zend_observer_fiber_switch_register` observer does not?
**Evidence.** The header symbols and both lookup rules are verified (see the section preamble); the
`offset` member's purpose and the numeric-key discipline are `owner report, unverified`.
**Acceptance.** `docs/research/NN-*.md` answering all three with file-and-line citations into the
fork, **plus** an amendment to ADR-0003 stating what backend (b) would inherit and what it would
still have to build. A question answered "probably" is not answered.
**Constraints.** `research` lane; read-only. Needs a checkout — `scripts/build-php-async.sh` clones
one, and which branch it should clone is `R-TA-REQUEST-SCOPE`'s finding, so do that item's branch
question first or state the revision you read.

### S-OWNERSHIP Every value a fiber-scoped service hands out carries its owner `main` `KILLED 2026-09-20 (DECISIONS.md) — it is a fourth mechanism, which ADR-0037 forbids; the three reds it targeted stay open with their cheaper fixes`
**What.** A value handed out by a fiber-scoped service carries `owner_scope_id` in the object's GC
bits; taint is transitive through property writes and array element writes. Violations: writing a
tainted value into a longer-lived holder; a tainted object outliving its scope with a refcount above
expected; entering a pinned object from a foreign fiber. Release is explicit and is exactly one of
`Scope::escape` (untaint, caller takes responsibility), `Scope::share` (read-only cross-fiber),
`Scope::pin` (owner-only, foreign entry throws). `#[Scoped(escape: 'never')]` forbids release
outright for connections, EntityManager and Request. Dev and chaos modes throw, naming **both**
holder and value; production counts and warns.
**Why it matters.** Three open items are the same defect seen three times — `S-DBAL-DIRECT`,
`S-EXCLUSIVE` and `S-RESET-ARRAYPOOL` — and the static rule shipped for `S-SINGLETON-CAPTURE` (V-96)
catches only the property-assignment shape. This is the `context` mechanism (ADR-0006), not a fourth
one.
**Prior art to cite, not copy.** Upstream has no general ownership mechanism but hit this class
per-resource: php-async CHANGELOG #200, verified verbatim — a `PDOStatement` outliving a pooled
`PDO` "was keyed to a context that no longer matched", the destructor saw it as orphaned and
returned it to the pool "while the coroutine binding still owned it and returned it a second time —
the pool destroyed the same connection twice", fixed so that "every release path detaches the owning
binding first". The lesson is the shape of the fix, not its scope.
**And we are already one enforcement short of it, checked 2026-09-19.** `PooledConnection` has the
idempotence — `$returned` with a doc block naming exactly the case ("a caller may return the lease
early, and the destructor still runs afterwards") — so we arrived at #200's fix independently. But
ours guards the **wrapper** and theirs guards the **binding**: our safety holds only while exactly
one `PooledConnection` exists per `DriverConnection`, and `ConnectionPool::release()` is public, so
nothing enforces that. A service holding the raw connection and releasing it is `S-DBAL-DIRECT`'s
shape reaching the pool. #200 is therefore evidence **for** this mechanism, not merely prior art
beside it.
**Acceptance.** V-96's capture cases caught 6/6 **including the array-write and setter forms the
static rule misses**; `S-DBAL-DIRECT`, `S-EXCLUSIVE` and `S-RESET-ARRAYPOOL` detected by this
mechanism with no code of their own; per-property-write overhead measured, target within the
observer's 100 ns.
**Explicitly out of scope** (owner): non-object statics — those are context slots — and C-extension
state, which is offload's.
**Constraints.** `main` lane, FFI territory. Blocked by `R-TA-CONTEXT` question (1): if per-scope
storage can sit on `internal_context`, backend (b) gets this for free and the design changes.
**Reentrancy, checked 2026-09-19 and currently free.** Upstream's
`zend_coroutine_switch_handlers_vector_t` carries an `in_execution` flag; our two switch handlers
(`superglobals.rs:180`, `park.rs:143`) have no such guard and do not need one today, because one
swaps zvals and the other sets a thread-local and neither can itself cause a switch. A taint check
on every property write is the first thing that puts real work on that path, so this item inherits
the guard as a requirement, not as an optimisation — and it is cheaper to design in than to
retrofit after a handler recurses.
**Unverified.** The GC-bits carrier, the transitivity rule and the three release verbs are the
owner's design, not read from any source.

### S-SCOPED-CLASS `#[FiberScoped]` moves instance properties into per-scope storage `main` `severity: planned` `DONE 2026-09-20 — ADR-0042 accepted; all three acceptance steps and all eight named tests pass (V-97, V-99, V-100, V-101); FiberRequestStack and FiberTokenStorage deleted. The per-switch cost stays unmeasured (V-98, owner decision) and the Doctrine pair is kept on V-85's measurement` — **unblocked 2026-09-20**: `R-TA-CONTEXT` is answered (research 48) and the answer does not change the design — upstream's `internal_context` is a future substrate for our storage under backend (b), not an alternative to building it, so the engine half is written against our own storage either way
**What.** A class-level `#[FiberScoped]` moves all instance properties into per-scope storage:
`create_object` returns a façade with no properties table; `read_property`, `write_property`,
`has_property`, `unset_property`, `get_property_ptr_ptr` and `get_properties` address
`[scope_id][slot]`, with slots resolved from `ce->properties_info` **at class link time** into a
dense array and never by name at runtime; a zero scope holds constructor defaults and copies on
write into a new scope; scope death frees the row, destructors running on the service fiber per
`A-DESTRUCTOR-IO`.
**Why it matters.** A singleton holding the façade becomes safe by construction — this closes the
façade half of V-96 **without proxies**. The value-capture half stays with `S-OWNERSHIP`; the two
items are halves of one defect and neither closes it alone.
**Settled by the owner 2026-09-20 (DECISIONS.md), replacing what this entry used to say.**
*Inheritance:* a scoped class's **children are scoped**, and that is correct rather than a hazard.
A hierarchy needing both shapes leaves the parent non-scoped and scopes a **branch of descendants**.
This entry previously claimed the reverse — that a scoped class's parent must be scoped too — and
that claim is why research 44 ruled `FiberRequestStack` (which extends Symfony's `RequestStack`)
illegal and recommended killing the whole item. Under the real rule it is the sanctioned pattern,
so the legal target set is all four façades, **447 lines**, not the 53 research 44 counted nor the
358 main corrected it to. That is the set that may carry the attribute; it is **not** a deletion
estimate, and no third guess is offered — `FiberEntityManager`'s ~30 interface forwarders are a
decorator and stay regardless. The acceptance below settles it empirically instead.
*Control level:* a class-level call before the first instance — `Ignis\Scope::scopeClass(X::class)`
from a bootstrap or container factory. Not the attribute (cannot reach a vendor class, arrives only
at autoload) and not MINIT, where `route.rs` already shows the failure mode: it looks the class up
in the class table and `if zv.is_null() { continue; }`, so a userland name silently does nothing.
Not from the constructor either — `create_proxy` shows `(*obj).handlers` is assigned inside
`create_object`, so handlers are per object and a constructor runs too late; the class would convert
from its second instance onward.
*Layout:* keep the properties table as the zero scope's defaults and swap only the handlers, so the
allocation size never changes. This entry's "a façade with no properties table" is withdrawn.
**Still open for the ADR:** instantiation outside a request; clone, serialize and reflection;
`get_property_ptr_ptr` correctness for `$this->arr[] =` and `$this->n++`; and the property-offset
runtime cache, which is the kill criterion — `$this->x = 1` memoises an offset per class in the
opcode's cache slot, and if scoping happens after code touching the class has compiled and run,
those cached offsets may bypass `write_property`. That must be read in php-src, not assumed.
**Acceptance, in this order.** A prototype on a standalone class with two interleaved fibers
**before any Symfony work**; then `FiberRequestStack` rewritten on it — **if that class disappears
the mechanism is right, and if it does not, say what is missing**. Read cost measured against a
plain property. **This module ships with its tests in the same commit** — `A-RUST-TESTS` is the
reason that sentence is here.
**The tests, named (owner, 2026-09-20), because "ships with tests" is not a list.** Each must be
able to fail, and the fourth is the one that decides whether the control level above survives.
1. **Two interleaved fibers** on a standalone scoped class each see their own property values, and
   neither sees the other's — the base claim, and worthless without chaos mode (`IGNIS_CHAOS`) on
   a second arm.
2. **Inheritance, both directions.** A scoped parent's child is scoped without asking for it. A
   non-scoped parent with a scoped descendant branch: instances of the parent are unaffected and
   instances of the descendant are per-scope, including the properties it inherited.
3. **Scope death frees the row**, and the destructors run on the service fiber rather than from the
   loop's idle point (`A-DESTRUCTOR-IO`); a fiber reused for the next request starts from the zero
   scope's defaults, not from the previous request's values — this is V-67's shape and the reason
   `Scope` is cleared at request end.
4. **A class scoped *after* code has already touched it**, cold and with opcache warm. This is the
   property-offset cache test and it is the kill criterion: if a write through a memoised offset
   bypasses `write_property`, the class-level call must move to boot time and the control-level
   decision is wrong.
5. **`get_property_ptr_ptr`**: `$this->arr[] = x` and `$this->n++` land in the right scope's row —
   these take a pointer to the slot and write through it, past `write_property`.
6. **Outside a request**: instantiation and property access with no fiber, landing in `Scope`'s
   `{main}` fallback bag rather than throwing.
7. **`clone`, `serialize`, reflection**: each either behaves or refuses with a message naming the
   class; silently copying another scope's row is the failure this catches.
8. **Read cost** against a plain property, and the fiber-switch cost against E2's 3.83 µs warm —
   the standing constraint that no `context` work may move.
**Proven on a real application, 2026-09-20 (V-99).** `bench/e21` marks an ordinary service
`ignis.scoped` in a real Symfony kernel — Framework, Security and Doctrine bundles, `APP_ENV=prod`,
three pairs of overlapping requests. Control, unmarked: **leaks 3/3**. Marked: **0/3**. The rest of
E21 stayed green, so the mechanism disturbs neither the token storage (V-68) nor the Doctrine
identity map (V-69). The first attempt **segfaulted** on `request_stack`: the zif returned the object
with `type_info = IS_OBJECT` where an object zval needs `IS_OBJECT_EX`, so it was marked neither
refcounted nor collectable and was freed under the container holding it. Every unit test and both
smoke arms passed while that was live — it took a real framework outliving a request to find it.
**Where it stands, 2026-09-20.** ADR-0042 written; userland half in `c4f1f92`; engine half in
`crates/ignis/src/php/scoped.rs` — `ignis_scope_allocate` and `ignis_scope_rows_clear`, four
overridden handlers, and `get_property_ptr_ptr` returning null so `$this->list[] =` degrades to
read-then-write rather than writing past the handlers. **Acceptance step 1 passes on the real
binary and is gated in `scripts/smoke.sh`:** two interleaved fibers each read their own value back,
and the arm was falsified before being trusted — removing the row-zero read-through makes it print
`constructor_value_inside_a_fiber=NULL` and go red.
**What building it found that the design had not.** Row zero was specified and not implemented, and
without it a scoped service lost every constructor-injected dependency the moment a fiber touched
it: `$service->shared` measured `'built-once'` outside a fiber and **`NULL`** inside one. The
fallback now makes "what the constructor stores is process-wide, what a method reads is per-scope"
mechanical rather than a rule to remember, and `rows_clear()` refuses to clear row zero because it
outlives every request.
**Acceptance step 2 met, 2026-09-20 (V-100): the façade disappeared.** Symfony's own `RequestStack`
with nothing but the container's `ignis.scoped` mark measured identically to the 89-line
`FiberRequestStack` — 0/3 leaks either way — so the class and its test were deleted. It existed for
one reason, stated in its own doc block: `RequestStack` keeps a private array and an inherited
method reads that one, which under fibers is always the wrong one (V-88). With the array itself
per-scope there is nothing left to override. `resetRequestFormats()` is unaffected either way: it
clears a **static**, which is `S-REQUEST-FORMATS` and exactly as open as before.
**Two façades deleted, one kept with a measurement behind it.** `FiberRequestStack` (89) went with
V-100. `FiberTokenStorage` (53) went the same day and needed no arm of its own: the pass marks
`security.token_storage` **by id**, so Symfony's own `TokenStorage` is what the container builds,
and E21's V-68 probe was already passing 0/3 through it — the class had no instantiator left.
**`FiberEntityManager` (247) and `FiberManager` (58) stay, and this is a measured refusal rather
than work not done.** `FiberManager` is not a scoping façade at all: it is a cycle-breaking release
handle. Its own doc block records why — `EntityManager` and `UnitOfWork` hold each other, so
dropping the last outside reference leaves a cycle that only a collection frees, and a collection is
scheduled by root-buffer pressure rather than by the request boundary. **V-85 measured the cost of
getting this wrong: with the manager stored directly, 30 sequential requests left 8 PostgreSQL
backends open under the loop collector and 31 under PHP's own**, against a stock `max_connections`
of 100. Per-scope storage does not change that — a cycle survives whichever reference is dropped —
so scoping the manager would silently stop releasing pooled connections.
What *could* still change is `FiberEntityManager`'s `Scope::get`/`set` plumbing becoming a per-scope
property, which is worth roughly ten lines and leaves the holder and every release path exactly
where they are. It is not done here: ten lines is not worth touching the pool without an E24 arm
proving the release, and that arm is the price of the change rather than an afterthought.
**Open, and each is a named ceiling rather than an omission.** Rows are keyed by property name, not
by a dense slot array resolved at class link time — `ponytail:` in the module, to land with the
read-cost measurement ADR-0042's kill criterion already demands. `get_properties` is still the
standard handler, so `var_dump`, `foreach` over an instance and `get_object_vars` do not see scoped
properties. `unset` on a property that row zero holds removes only this scope's shadow. And steps 2
and 3 of the acceptance — `FiberRequestStack` rewritten on the mechanism, read cost against a plain
property — are not started.
**Constraints.** `main` lane, FFI territory, needs an ADR before code.
**Unverified.** The handler list and the slot-resolution scheme are the owner's design; what
upstream offers instead is `R-TA-CONTEXT`'s question (1).
