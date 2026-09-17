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
(`php/packages/pg/src/ignis-pg.php` is agent-safe).

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

### M4-4 `/_ignis/metrics` (Prometheus) `main` `done (V-55): 22 metrics, promtool clean, 1.9-5.5 ms under wrk -c200; per-reactor publication from each PHP loop`
**What.** The `/_ignis/stats` fields as Prometheus text format: gauges for threads/stalled/in-flight/
queued/idle fibers/oldest lease age, counters for requests/restarts/rejected/cancelled/offload jobs,
a histogram of request duration if cheap (per-thread, merged). Answered by Rust, exempt by
construction.
**Acceptance.** `promtool check metrics < <(curl -s /_ignis/metrics)` passes (an agent can run
promtool in a container); every counter in `/_ignis/stats` has a metric; the endpoint answers
under `wrk -c 200` load within 10 ms.

### M4-5 Graceful reload on `SIGHUP` `main` `SIGTERM/SIGINT drain DONE (V-56): two-phase — health says draining while still accepting for IGNIS_DRAIN_DELAY_MS, then the listener closes and in-flight requests get IGNIS_DRAIN_TIMEOUT_MS. SIGHUP reload-without-restart still open.` `main` `open` — note (E18-B, 2026-09-16): under 100 keep-alive connections `hello_server` outlived `kill` + `wait`; hyper's graceful shutdown waits on idle keep-alive connections, so `SIGTERM` needs a bounded drain, not just a signal handler
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

### R-MAIN-RED `main` has been red since before the quality work, on two gates `main` `open — evidence 2026-09-17`
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
`file-upload.php` (no `Upload OK`). One of those five is newer than the baseline. The window points
at the stream/multipart refactors of 2026-09-17 16:50–18:26 (V-76/V-77 and the multipart work), which
a parallel session landed.

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

### R-LINT-GATE Blocking fmt/clippy/deny + real coverage, Rust side `main` `in progress`
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

### R-PHP-GATE Blocking php -l/phpstan/cs-fixer + phpunit with coverage `agent` `in progress`
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

### R-GLOBALS A legacy entry script loses its globals and cannot redeclare its functions `main` `half done (V-54): globals fixed by the top-level worker loop (Ignis\Classic\listen/accept/respond, examples/classic_worker.php) — $GLOBALS and `global $x` now behave as under stock php -S, 10.5k req/s on one thread, FrankenPHP testdata still 29. Still open: an unguarded top-level `function foo() {}` survives into the next request and PHP's uncatchable "Cannot redeclare" fatal ends the worker (the supervisor respawns, the request is lost). Only a per-request PHP request cycle (RINIT/RSHUTDOWN) fixes that — the bootstrap cost the worker model exists to avoid, so it is an ADR, not a patch; documented as the worker-runtime rule (require_once / function_exists) for now. NOTE: the option once written here — "run the entry on the thread's main context, not in a fiber" — is WRONG and V-54 measured why: a method's scope is no more global than a fiber's; only the top level of the main script is.`

## E19 — Boot-heap snapshot, classic mode `REJECTED 2026-09-17 (owner), ADR-0039`

Measured before deciding, and the numbers are kept because they are the point: the `mprotect`+`SIGSEGV` barrier costs **7.1 µs per fault**; a real Symfony request dirties **11–12 boot pages** (not the assumed 50) against a **6 MiB** boot heap; so the proposal costs **71 µs against its own 100 µs budget** and is the only one of three mechanisms that does not grow with the heap (whole-arena memcpy 120 µs, soft-dirty 141 µs and process-wide). Rejected not for cost but for the constraint: the undo log is per thread, so a restore is only safe with one request in flight, i.e. `budget.fibers = 1` — php-fpm's concurrency with a faster bootstrap, which switches off the parking this runtime is built on, for exactly the applications that need it most. The variant that keeps fibers (restore when the thread goes idle) bounds RSS but fails "identical response bytes". Research: `docs/research/34-e19-boot-heap-snapshot-feasibility.md`. Instruments kept: `bench/e19/barrier.c`, `bench/e19/compare.c`, `bench/e19/dirty-probe.php`.

### R-DNS Name resolution blocks the whole PHP thread `main` `open — owner decision 2026-09-17: record the risk, do not build yet`. `getaddrinfo()` has no file descriptor, so universal park cannot park it: park's mechanism is "wait for fd readiness, then make the real call", and there is nothing to wait on. Every name lookup therefore blocks its OS thread for the whole resolve — microseconds against a warm cache, seconds against a sick resolver, and with N threads that is N requests stalled, not one fiber. **What is affected:** libpq (`pg_getaddrinfo_all` calls it synchronously on the caller's thread, research 27), and PHP's own `fsockopen`/`stream_socket_client`/`gethostbyname` to a hostname. **What is not:** libcurl in this build uses its *threaded* resolver — it resolves on its own pthread and the fiber waits in `poll` on a socketpair, which park already handles (research 26, 28). **The plan when it is built** (research 31, and ADR-0020 corrected to match): interpose the symbol and run the **real** `getaddrinfo` on `tokio::task::spawn_blocking` behind an `Op::Custom`, suspending the fiber on that completion exactly as `pg.rs` does; hand the caller glibc's own pointer untouched, so nothing fabricates an `addrinfo` chain and `freeaddrinfo` needs no interposition at all. Known costs: a hung resolver holds a blocking-pool thread (the pool is bounded — needs a cap and a metric, or the stall just moves one floor down), and a lookup already started cannot be cancelled. **Rejected for now: writing our own resolver in Rust** (hickory-dns / `lookup_host`). It would be genuinely async and cancellable, but it has to reproduce `nsswitch.conf`, `/etc/hosts`, `resolv.conf`'s `search`/`ndots`/`options`/`timeout`/`attempts`, `AI_ADDRCONFIG`/`AI_V4MAPPED`/`AI_CANONNAME`, service names from `/etc/services`, IDN and RFC 6724 address sorting — and being 99 % right there fails as "connected to the wrong address" or "a Kubernetes service name does not resolve" (ndots:5 and search domains), not as "slow". musl and systemd-resolved both carry famous divergences here. **Operational mitigation available today, no code:** run a local caching resolver (nscd, systemd-resolved, dnsmasq in the pod) so the call returns from cache in microseconds. Documented in docs/operate.md. A middle option if measurement ever demands it: keep the real call and put a TTL-respecting cache in front of it — most of the benefit, none of the semantics risk.

### R-PDO-SQLITE `new PDO('sqlite:…')` blocks the thread and cannot be routed per driver `main` `open — owner decision 2026-09-17: document only, do not build yet`. The runtime picks the mechanism in `create_object`, which the VM calls when it executes `new` — before the constructor's arguments exist, so `pgsql:` and `sqlite:` are indistinguishable at the only moment it can choose. Routing the `PDO` class therefore sends `pgsql` to a worker too (2,753 ms against 303 ms parked for 100 × 200 ms queries, V-59 addendum), and not routing it leaves `pdo_sqlite` blocking the OS thread for the file access. Today the choice is `IGNIS_OFFLOAD_CLASSES`, per class and not per driver, and an application using both under concurrency cannot have both right. **The designed fix, ~60 lines, unbuilt:** give the proxy class the *original* `create_object` instead of plain object creation, so the proxy carries a real PDO payload; then decide inside `Proxy\PDO::__construct`, where the DSN is visible — `sqlite:` keeps today's worker handle, everything else calls `parent::__construct()` on the calling thread and every forwarder goes to `parent::` instead of the worker, i.e. it parks. `instanceof PDO`, type hints and constants are unaffected (the proxy already extends PDO); the only visible difference stays `$pdo::class`. Needs a suppression flag in `route.rs` so the nested `new` inside the constructor is not re-proxied (the `PASS` thread-local already exists for functions). **Gate before it lands:** `pdo_pgsql` still 303 ms (the extra forwarding layer must not cost), `pdo_sqlite` reaches the pool, and `PDOStatement`/`fetch()` behave as V-24 measured.

### R-FOREIGN-FIBER Park suspends a fiber that no Ignis loop is driving `main` `open — found and measured 2026-09-17 (V-63) while fixing E7`. The gate in `park.rs` is "we are in a fiber": `on_switch` sets it to 1 for any context that is not `EG(main_fiber_context)`. That is right for fibers `Ignis\Loop` started and wrong for anyone else's. A fiber created by a **foreign scheduler** — Revolt's own `StreamSelectDriver`, a library's `new Fiber`, a test harness — that makes a parkable syscall is suspended into our reactor, and in that program nothing calls `ignis_poll()`, so it is never resumed. Measured: `vendor/revolt/event-loop/examples/fiber-local-automatic.php` under `REVOLT_DRIVER=StreamSelectDriver` dies with Revolt's own "Event loop terminated without resuming the current suspension"; with `IGNIS_NO_UNIVERSAL_PARK=1` the same program prints the correct four lines. **Blast radius is narrower than it looks:** every fiber the runtime itself uses comes from `Ignis\async()`/the pool (`ignis.php:237`), including `scripts/phpt-harness.php` and `php/packages/runtime/src/classic.php`, and `Ignis\Revolt\IgnisDriver` awaits through `Loop::awaitOp` rather than the C hook — so the failure needs a *foreign* scheduler, which today means running AMPHP on a non-Ignis driver under our binary. **The fix, unbuilt:** park only in fibers the runtime owns — a thread-local set of owned `zend_fiber_context` pointers, filled by a one-line internal call at the top of the pool body and of `Loop::spawn`'s one-off fiber, with `on_switch` consulting it instead of `to != main`. Two things to settle before it lands: the set's lifetime (contexts are freed and their addresses reused — E1 creates 10k fibers, so a stale entry could make an unowned fiber park), and the cost on the switch path, which E2 measures at 3.83 µs warm and must not move. An opt-in for deliberate cases (a harness that does drive the loop from a hand-made fiber) is a table row, not a second mechanism. **Until then** the honest answer is the documented one: under Ignis, AMPHP runs on `Ignis\Revolt\IgnisDriver` — which is what E7 exists to prove — and `bench/e7-revolt.sh` runs its stock-driver control with park off for exactly this reason.

### R-STREAM There is no chunked HTTP response, so a streamed body is collected in memory `main` `DONE 2026-09-17 (V-74): built as designed — ignis_respond_start/chunk/end over a hyper channel body, the chunk op awaited for back-pressure, Ignis\Http\Stream in userland, Symfony's StreamedResponse wired through it. TTFB 906 ms -> 1.7 ms, transfer-encoding chunked, the thread keeps serving (3/3 ticks). Gated by bench/e23-stream.sh. What remains is the ob_start() lock on the Symfony path, below.` The only response primitive is `ignis_respond(id, status, headers, body)`: one call, whole body. gRPC has a streaming pair (`ignis_grpc_send`/`ignis_grpc_end`, E10) but that is tonic, not hyper. So `Symfony\Component\HttpFoundation\StreamedResponse` — the class whose entire purpose is not holding the body — is collected into a string by `Ignis\Output::capture()` and handed over in one piece. A 1 GB export is 1 GB of PHP memory and then 1 GB of Rust memory, silently. Same for `BinaryFileResponse`. **What it needs:** `ignis_respond_start(id, status, headers)` / `ignis_respond_chunk(id, bytes)` / `ignis_respond_end(id)` over a hyper streaming body, with `ignis_respond_chunk` returning an op the fiber **awaits** — a bounded channel plus an awaited op is real back-pressure, and it keeps the thread free while the client drains. **The PHP side is already possible:** measured (V-72) that a fiber CAN suspend inside an `ob_start()` handler, so the handler can forward each chunk and await the op; memory stays at one chunk. **What does not change:** the output-buffer stack is per thread whatever the transport is, so `Output::capture()`'s exclusivity is still required — streaming does not remove it, it only bounds the memory. **Gate before it lands:** a 1 GB streamed response with RSS flat, a slow client applying back-pressure without stalling the thread (a ticker fiber must keep running), and `Content-Length` absent with `Transfer-Encoding: chunked` present.

### R-ANSWER-MAP One request id, three maps — and the split is what caused V-75 `main` `open — architecture review 2026-09-17`. `Reactor` keys the same thing three ways: `responders` (`reactor.rs:120`) for a whole-body answer, `stream_out` (`:123`) for a streamed one, `streams` (`:125`) for gRPC — all from one `next_id`. The cost is paid everywhere: `cancel_request` takes three locks and three removes (`:320-328`), `fail_pending` drains three maps (`:368-375`), and `pending_requests` counted **two of the three** until V-75 — which is how a graceful shutdown came to truncate a live download. One `HashMap<u64, Answer>` with `enum Answer { Whole(oneshot::Sender<HttpResponse>), Streaming(mpsc::Sender<Bytes>), Grpc(..) }` makes the invariant explicit (an id is in exactly one state) and turns `respond_start` into a **transition** rather than a delete-plus-insert, so the next person cannot forget the third map. **What must stay separate:** the payloads — gRPC's terminal value carries trailers HTTP has no analogue for, and its channel is unbounded while the HTTP stream's bound *is* the back-pressure. **Land it with** the dispatch fix: `Registry::pick` (`http.rs:52-73`) holds `reactors.lock()` while calling `pending_requests()` on each reactor, which takes two more locks each — nested locks held across work on the hottest path, 2N per request. An `AtomicUsize` bumped on deliver and dropped on answer makes `pick` lock-free per candidate.

### R-STREAM-CANCEL A streaming handler never learns the client left `main` `open — architecture review 2026-09-17, unmeasured`. `http::handle` sets `guard.answered = true` the moment the oneshot resolves (`http.rs:348`), and for a streamed response that is when the **headers** go out, not the body. After that a client hang-up is never delivered as `Outcome::Cancelled`. A fiber parked in `Stream::write()` still finds out — the channel closes and the send errors (`module.rs`) — but one parked on a slow query between chunks does not, and keeps producing for a client that is gone. Fix is to keep the cancel path armed for the lifetime of the stream, not just until the first byte. Gate: start a stream, kill the client mid-body, assert the handler's fiber is cancelled within the same bound E11 uses for whole-body responses.

### R-HEADERS-MULTI The response header map cannot hold two headers with the same name `main` `open — architecture review 2026-09-17, confirmed by reading`. `ignis_respond` takes `array<string,string>` and hyper is handed one value per name, so a response with two `Set-Cookie` headers loses all but the last: `IgnisWorkerRunner::headers()` assigns `$headers['set-cookie']` inside a `foreach` over the cookie bag (`IgnisWorkerRunner.php:88-90`), which is a correctness bug, not the "E8 caveat" the comment calls it. Every framework that sets more than one cookie per response is affected, and so is any `Link`/`Vary`/`Warning` pair. Fix is a list-valued header shape across the boundary (`array<string, string|list<string>>`) and `header_pairs` emitting one pair per value. Gate: a response with two cookies arrives with two `Set-Cookie` lines.

### R-LOOP-SPLIT `Loop::runUntil` is 124 lines doing seven things — and one flag silently skips two of them `main` `open — architecture review 2026-09-17; the flag defect confirmed by reading`. **The defect first:** `$chaosInit` (`Loop.php:306`) gates three unrelated initialisations at `runUntil:172-176` — `chaosInit()`, `gcInit()` **and** the `ignis_publish_stats` probe — but `awaitOp:78-79` calls `chaosInit()` alone, which sets the flag (`:368`). So on any path where `awaitOp` runs before `runUntil` — a script that awaits before serving — `IGNIS_LOOP_GC` never initialises and `$publishStats` stays false, which means that loop **never publishes its metrics** and `/_ignis/metrics` reports it as one whose numbers are ageing without bound. A flag standing in for a state machine. **The rest:** `runUntil` wants to be the same `while` over four named calls — `startPending()` (180-189), `resumeReady()` (191-203), `idle()` and `dispatchEvents($events)` (252-278); the six-term idle condition is written twice (`:209`, `:249`) and must be one predicate; the event demux decodes a Rust tagged union with an `is_array`/`isset`/string-ladder instead of one `match ($payload['kind'] ?? null)`; `$budgetInit` (`:50`) is checked per request (`:410-412`) for an environment variable that cannot change; `$requestHandler`/`$rawRequestHandler` (`:30`, `:402`) are two nullable callables tested together in three places where one typed handler would say which mode it is. All of it belongs in one `boot()` called from `serve()`.

### R-REVIEW-CHORES Small, safe deletions and allocations, from the same review `agent` `open — architecture review 2026-09-17; every item suspected unless marked`. Each is independent; none changes behaviour. **Rust:** `http::handle` (`http.rs:294-366`) routes two `/_ignis/*` paths with two `if`s that want one `match` above the gRPC check; `CancelOnDrop` is declared inside the function body and wants module scope; header+uri extraction is the same five lines in `http.rs:325-330` and `grpc.rs:133-138`; `zif_ignis_poll`'s match (`module.rs:154-215`) has three guard arms doing two registry lookups where `resume_parked` already returns a bool, and four arms repeating the same zeroed/set_new_array/fill/update block that an `unsafe fn push_event` would collapse; `grpc::is_grpc` (`grpc.rs:64-66`) is a wrapper that exists for a test and should be generic instead. **Allocations, all suspected:** ~12 per request for request headers (`http.rs:325-329`) where `HeaderName` is a cheap copy and `from_utf8_lossy` copies an already-borrowed `Cow`, then `request_to_zval` copies each again; response headers cross `String` and are re-parsed into `HeaderName`/`HeaderValue`; `Arc<Mutex<Instant>>` per connection (`http.rs:223`) locked twice per request where an `AtomicU64` of millis would do; `msg.clone()` per `Error` completion (`module.rs:160-161`) for a `debug!` line that is almost never printed; `output.rs` does a `SINKS` hash lookup per `echo` (`:56-65`) where the fiber-switch observer could keep a `Cell<*mut Vec<u8>>`, and drops the capture `Vec` per request instead of clearing it. **Must stay a copy** and deserves a comment saying so: the response body and each chunk — a ZTS `zend_string` refcount is thread-local, so `Bytes::from_owner` cannot borrow it. **PHP:** `IgnisWorkerRunner::toSymfony` builds a Symfony `Request` with `createFromGlobals()` and then **throws it away and builds a second** for any non-urlencoded body (`:71-76`) — decide first, construct once; `Ignis\Http\Request` scans the URI for `?` three times (`:24`, `:31`, `:52`) and runs `parse_str` twice when a handler uses both `query()` and the superglobals; `Loop::spawn` wraps the callable in a second closure when a request id exists, so a request handler runs two closures deep; `throwInto` scans `$waiting` linearly per cancellation, which is fine normally and suspect in a disconnect storm; `Output.php`'s class docblock still says "neither path streams (BACKLOG R-STREAM)" while `captureChunked()` eighty lines below streams.

### R-NOT-DOING Decided against, so nobody re-litigates it `main` `closed 2026-09-17 — both architecture reviews agreed independently`. **The three `FUNCTIONS` tables** (`module.rs:829-940`, ~100 duplicated lines): the explicit array length makes a mistake a compile error, and the macro that removes the repetition is harder to read at 3am than the repetition. **A shared base class or interface for the three fiber-scoped services** (`FiberRequestStack`, `FiberTokenStorage`, `FiberEntityManager`): the shared idea is already extracted — it is `Scope` plus `Scope::clear()` at the request boundary — and what remains in each is the *shape* of the state (a stack, a slot, a lazily-built resource with a rollback). A base class would save three lines in two of three and buy an abstraction. **Shrinking `FiberEntityManager`'s 35 generated forwarders**: its docblock argues the case and is right. **The `hrtime` phase timers in `Loop`** and `Published::set`'s string match: measurement is this project's currency and they are cheap. **`Output::capture()`'s `ob_start` fallback** despite having no production caller: it is what lets the unit suite run under plain php-cli.

### R-SESS A file lock held across a yield DEADLOCKS the thread `main` `open — severity corrected 2026-09-17 by measurement (V-58); the rule and the options are ADR-0038`. Not a stall: a hang. `ext/session`'s files handler takes a blocking `flock(LOCK_EX)` (`mod_files.c:210`) and holds it from `session_start()` to `session_write_close()`. A regular file cannot be parked (epoll refuses it), so a second fiber's `flock` blocks the **OS thread**, so the loop can never resume the holder, so the lock is never released — measured: two fibers, killed at 12 s with no progress. Park makes it *more* likely, not less: `usleep`, `sleep` and stream I/O all yield now, so the holder is suspended far more often than a php-fpm developer would expect. **Symfony's cache lock is NOT affected** (V-58): `LockRegistry` uses non-blocking `flock` plus `usleep(100 ms)` polling, and `usleep` parks — measured 60/60 ticks during a 600 ms contended wait. **The rule:** a lock that can be held across a yield must live on a socket (Redis, PostgreSQL) or in the runtime, never on a file. Fixes to choose from, none built: (1) ship a PostgreSQL session handler on `Ignis\Pg` using `pg_advisory_xact_lock` — the wait is server-side and the client waits on a socket, which parks; (2) document Redis/PDO handlers and refuse to start with `session.save_handler=files` (cheapest, turns a mysterious hang into a message); (3) a runtime-owned session store with the lock as a reactor op — fastest and dependency-free, but new Rust and no durability across restarts (ADR-0024). Also unmeasured: whether an actual Symfony session reaches that `flock` under the embed SAPI at all — `session_start()` hit `headers already sent` in the probe.

### H36 The lock hazard has a number `main` `done (V-51, 2026-09-17)`: shim + harness written and run — `park` breaks (fiber 1 sees the mutex held by a parked fiber), `block` serializes (400 ms). The four internal functions live in `crates/ignis/src/php/locklib.rs` and are registered only when `IGNIS_LOCKLIB` names the .so.

### H-12 E4 hello throughput is 58k req/s on this box today, V-6 measured 128k `main` `open — measured (V-46 addendum 3): V-6's own commit gives 61.5–63.1k on this box, so 128k → 62k is the box; the code-side drop 2026-09-15 → HEAD is ≈ 4–5 % (58.3–60.0k) and still worth one bisect in a quiet slot` — V-46 addendum 2: park on and off both ~58k, p99 1.8 ms, quiet box, same wrk shape as V-6 (`-t2 -c64 -d10s`, 1 PHP thread). Either the box changed (WSL2 kernel 6.18 now; V-6's kernel not recorded) or something landed between 2026-09-15 and cycle 1 (budget admission, health route, superglobals lazy swap, log floor). Bisect with `git bisect run` over `bench/wrk-hello.sh` before any perf claim cites V-6 again.

### H-12 `php/packages/runtime/src/ignis.php` at phpstan level 6 `agent` `open`
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
