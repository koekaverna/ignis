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

### E18-A ADR-0020 `main` `done (accepted; policy table from research 27; H32–H36 open)`
Symbol list, gate, policy table with defaults, reentrancy guard (our own reactor calls `poll`;
nested calls must fall through), `connect` via temporary `O_NONBLOCK` + park-on-writable + flag
restore, `getaddrinfo` via a resolver `Op` with glibc-compatible `addrinfo` allocation (and
`freeaddrinfo` interposed), the kill criterion verbatim from the owner, and the five acceptances as
H32–H36 with their benches: `bench/php/e18_curl.php` (100 × 200 ms `curl_exec`, WRITEFUNCTION
fiber identity), `bench/php/e18_pgsql.php` (offload off), `bench/php/e18_dns.php`,
`bench/e18-overhead.sh` (two builds, 10M zero-length `read`s, < 20 ns delta), `bench/e18-deadlock.sh`
(the R2 shim under `park` deadlocks with a timeout, under `block` passes).

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

### E18-I1 `bench/php/e18_pgsql.php` exits 0 and prints nothing under the park build `main` `done (2026-09-17, V-46): the loop's idle checks ignored $ready/$pending — ignis_poll() resumes C-parked fibers itself, the fiber they settle waits in $ready with nothing in flight, and a nested all() inside an outer fiber broke out of the loop; pre-existing (the old sleep hook showed it too), not an offload interaction; fixed in php/ignis.php; H33 through the bench script: 308/301/303 ms`
**What.** Under `target-park` with `IGNIS_PARK=libpq` the agent's bench runs its 100 connections
(the trace shows 100 forwarded `connect`s and 398 `poll` wake-ups), then exits 0 with **zero
bytes on stdout and stderr**; the default build prints `e18: pgsql_100x200ms …`. Not a `write`
problem: a fiber writing to stdout/stderr under the same build prints in every configuration
(`fiber_echo.php`). Difference from `scratchpad/park_pg.php`, which prints 296–333 ms: the agent's
script wraps the whole run in an outer `Ignis\async` and prints from inside it. **Acceptance.**
The cause named, the script printing under park, and the number recorded in V-45.

### E18-C Retire the PHP-level wrappers universal park makes redundant `main` `open` — owner question 2026-09-17
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

### E18-I Implementation `main` `default build since ADR-0037 cycle 1 (V-46: sleep.rs deleted, lib:symbol policy, seed table); stage 2 symbols built (V-47: accept/accept4, select, ppoll, __poll_chk, recvmsg/sendmsg, readv/writev; left: getaddrinfo, ECANCELED, boot self-check, blocked-in-fiber detector); stage 1 built (V-45): read/write/recv/send/recvfrom/sendto/poll/connect/nanosleep/usleep/sleep; H32 curl 279–337 ms, H33 pgsql 296–333 ms at N=100 (controls 20 s); feature off by default; stage 2 (getaddrinfo/select/accept/vectored/__poll_chk, ECANCELED, per-symbol policy) open`
C shim per exported symbol (captures `__builtin_return_address(0)`, calls into Rust) built by
`cc` in `crates/ignis/build.rs`; Rust side in `crates/ignis/src/park/`; feature-gated
(`universal-park`) so the overhead bench has its control build; `IGNIS_PARK_POLICY=libcurl=park,libpq=park`
env/toml; `IGNIS_NO_UNIVERSAL_PARK` as the hook-off control (every hook claim needs one).

## M4 — Operate

### M4-1 Hold-time on pool leases `main` `done (V-44)`
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

### M4-5 Graceful reload on `SIGHUP` `main` `open` — note (E18-B, 2026-09-16): under 100 keep-alive connections `hello_server` outlived `kill` + `wait`; hyper's graceful shutdown waits on idle keep-alive connections, so `SIGTERM` needs a bounded drain, not just a signal handler
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
`php/stubs/ignis.php`: one stub per function with the exact signature and return type from
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
Fixed with a cast. Note for any phpstan run: `php/stubs/ignis.php` covers only the `ignis_*` C
functions — pass `php/ignis.php` (and pg/offload files) too, or every `Ignis\*` symbol is "not found". Cast or validate at the
boundary so the example passes PHPStan level 6 with `php/stubs/ignis.php` loaded (H-7).
**Acceptance.** `phpstan analyse -l 6 examples/grpc_server.php --autoload-file php/stubs/ignis.php`
reports 0 errors (phpstan via the builder image's composer, `composer global require phpstan/phpstan`).

### H-12 E4 hello throughput is 58k req/s on this box today, V-6 measured 128k `main` `open` — V-46 addendum 2: park on and off both ~58k, p99 1.8 ms, quiet box, same wrk shape as V-6 (`-t2 -c64 -d10s`, 1 PHP thread). Either the box changed (WSL2 kernel 6.18 now; V-6's kernel not recorded) or something landed between 2026-09-15 and cycle 1 (budget admission, health route, superglobals lazy swap, log floor). Bisect with `git bisect run` over `bench/wrk-hello.sh` before any perf claim cites V-6 again.

### H-12 `php/ignis.php` at phpstan level 6 `agent` `open`
**What.** The H-11 agent's run reported ~30 level-6 findings in `php/ignis.php` (generics on
`Fiber`/`WeakMap`, untyped iterables, always-true conditions); main's raw-format count read 0, so
the number is not established. Establish it, then fix the ones that are real without changing
behaviour. **Acceptance.** phpstan L6 on `php/ignis.php php/pg/*.php php/offload/*.php` with the
stub loaded: 0 errors, and `scripts/smoke.sh` still GREEN.

### H-8 Retire the `IGNIS_ADDR` name `agent` `done (validated by main: no code hits, classic_server answers on IGNIS_LISTEN)`
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
