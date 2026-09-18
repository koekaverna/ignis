# Operating Ignis

For a team running `ignis serve` in production: how it starts, what to watch, what to do when
something is wrong, and where the edges of the runtime are. See
[ignis.toml.example](https://github.com/koekaverna/ignis/blob/main/ignis.toml.example) for the config file and [README.md](https://github.com/koekaverna/ignis/blob/main/README.md) for
install/run. Numbers here link to [VALIDATION.md](https://github.com/koekaverna/ignis/blob/main/VALIDATION.md) (`V-n`); none are guessed —
where nothing has been measured or wired up yet, this file says "not yet exposed" instead.

## Start and stop

```
$ ignis serve --config ignis.toml           # entry script + flags come from the file
$ ignis serve app.php                       # entry script as a positional arg, defaults for the rest
$ ignis [--threads N] [--offload M] [--supervise] script.php [args...]   # the pre-config form
$ ignis --version
```

`serve` bridges `ignis.toml` into the environment (file value, then product default, each only
where the environment is silent) before rewriting itself into the second form above, so
`IGNIS_THREADS=8 ignis serve` beats the file (V-38). Precedence overall: CLI flag > environment
variable > `ignis.toml` key > built-in default; an unknown key in the file is a parse error, not a
silently ignored one.

**What a clean start looks like.** `ignis serve` prints one line to stderr unconditionally, at any
log level (V-55) — the pre-config script form prints nothing, because the phpt harness treats a
single unexpected stderr line as a test failure. Verified directly against this build:

```
$ ignis serve --config ignis.toml
ignis 0.1.0-rc.1 — threads=2 listen=127.0.0.1:8096 park=libcurl,libpq,libssl,libcrypto,libphp:16 symbols — ready
```

That banner is the only output at the default log floor (`warn`) — V-39 measured a plain script run
as "log at the default warn floor: empty", and nothing below `serve`'s own banner has changed since.
The three lines an operator might expect to see beyond it — `universal park installed`, the boot
self-check's `park self-check ok`, and `listening` — are all logged at `info`, so they only appear
with `RUST_LOG=info` or `log = "info"` in the file. `/_ignis/health` returning `200` (below) is still
the positive confirmation to script against — the banner says the process started, not that a thread
is healthy.

**What a refusal looks like.** The boot self-check (ADR-0037 §4(a)) runs once, after PHP's MINIT
and before any worker thread exists: for every third-party library the park policy names and that
is actually loaded, it makes that library's own code call an interposed symbol and checks the call
reached the runtime. If it did not, the process **refuses to start: exit code 2**, with a message
printed to stderr unconditionally (not gated by log level) naming the library, e.g.:

```
ignis: universal park is enabled and the policy names ["libpq"], but a call made by its own code
did not reach the interposed symbols — the library is loaded and its blocking calls would silently
block the thread. Check that the binary exports them (`nm -D`) and that no LD_PRELOAD shadows
them; IGNIS_SKIP_PARK_SELFCHECK=1 starts anyway, IGNIS_PARK= disables the policy.
```

This is not a warning to triage later — it is the difference between "this policy will park
correctly" and "this policy will silently block a worker thread the first time it's used", proven
at boot instead of discovered in an incident (V-52: the same class of blind spot that let research
28 read a `getaddrinfo` probe that hit 0 times as success when it should have read as "not tested
yet"). Two escape hatches, both loud: `IGNIS_SKIP_PARK_SELFCHECK=1` starts anyway and logs a `warn`
line; `IGNIS_PARK=` (empty) removes the policy so there is nothing to probe. Other loud-refusal
exits at `2`: a misspelled `ignis.toml` key, a missing `entry`, or an entry script that does not
exist (V-38).

**Other invalid-argument and misconfiguration errors** — no entry script given, an entry file that
does not exist — print a one-line usage/error message to stderr and exit `2` (V-38); these are
caught before any thread is created.

## What to watch

| signal | threshold that means trouble | where visible today |
|---|---|---|
| threads stalled | any thread that has not called `ignis_poll` for **> 1 s** is counted `stalled` (the watchdog, ADR-0012); `/_ignis/health` flips from `200` to `503` when no thread is both alive and unstalled (V-38) | `/_ignis/health` (`stalled` field, answered by the runtime, exempt from the budget by construction); `/_ignis/metrics`'s `ignis_threads_stalled` gauge (V-55); log `WARN php threads busy for > 1 s without polling` (V-17) |
| worker restarts | any nonzero and climbing `restarts`; a slot that hits **10 restarts/minute** (ADR-0012's own cap) stops being respawned, so capacity for that slot is gone until the process restarts | `/_ignis/health` (`restarts` field, cumulative); `/_ignis/metrics`'s `ignis_thread_restarts_total` counter (V-55); log `WARN worker script ended; respawning … status=NNN` for a fatal, `DEBUG script called exit()` for a clean exit (V-17, H-10) |
| requests queued and rejected | a `503` with header `retry-after: 1` is the budget shedding load by design (ADR-0019), not a crash; watch `rejected` trending up against sustained legitimate traffic, not a single spike | `/_ignis/metrics` (`ignis_requests_queued`, `ignis_requests_queued_peak`, `ignis_requests_queued_admitted_total`, `ignis_requests_rejected_total`, `ignis_fiber_budget`, `ignis_queue_depth_limit` — summed/maxed over threads, V-55); an application's own `/stats` route calling `Ignis\Loop`'s counters gives the same numbers per thread (V-38 shows the shape: `budget`, `queue_depth`, `inflight`, `queued`, `queued_peak`, `queued_admitted`, `rejected`) |
| park failures | **any nonzero value** means a fiber thread blocked where the policy said it should have parked (V-52) — this is always worth paging on, not a threshold to tune | `/_ignis/metrics`'s `ignis_park_failed_total` counter (V-55), and the log line: `WARN universal park: policy says park but the call could not park — it blocked the thread` (`what=<symbol>`) |
| PostgreSQL lease age | a lease older than `IGNIS_PG_LEASE_WARN_MS` (default 5000 ms) is a held connection (V-42/V-43/V-44) | `/_ignis/metrics` (`ignis_pg_lease_age_seconds_max`, `ignis_pg_leases_over_warn`, `ignis_pg_leases`, V-55); log: `WARN pg lease held longer than IGNIS_PG_LEASE_WARN_MS lease=N held_ms=N` at release (V-44); `ignis_pg_stats($pool_id)` from PHP gives the same two fields per pool (`oldest_lease_ms`, `leases_over_warn`) for an application's own `/stats` route |
| RSS | `memory_limit` is per thread (ADR-0025) — an OOM in one fiber ends that thread's script and every other fiber on it; size against the marginal-cost formula in `ignis.toml.example`'s comments and V-37 (**~14.7 kB per admitted fiber, ~33 kB per held connection whether admitted or queued**) | **not exposed as a runtime metric** — `/_ignis/metrics` answers 22 metrics (V-55: threads, park failures, queue/budget, fiber pool, PostgreSQL leases) but none of them is RSS, so read the process's own RSS externally (`ps`, cgroup accounting, a node exporter) |

`/_ignis/health` itself: answered by the Rust runtime before dispatch, never by PHP, so it keeps
answering while every PHP thread is wedged (ADR-0022). Example:

```
$ curl -w ' [%{http_code}]' http://127.0.0.1:8096/_ignis/health
{"status":"ok","threads":2,"stalled":0,"restarts":0} [200]
```

`/_ignis/metrics` is the same shape (Rust-answered, never PHP): Prometheus text, 22 metrics,
`promtool check metrics` clean, answered in 1.9–5.5 ms under `wrk -c200` (V-55; the count and route
were re-verified directly against this build with `curl http://127.0.0.1:8096/_ignis/metrics`). It
carries the thread/queue/park/PostgreSQL counters named in the watch table above, plus one freshness
gauge, `ignis_stats_published_age_seconds`, that grows without bound if a PHP loop stops publishing —
a wedged loop shows up here even though every other number on this endpoint keeps answering.

## Symptom → cause → action

**A thread is wedged in a blocking call that never returns.** Symptom: `stalled` in
`/_ignis/health` stays at 1+ and does not clear; the watchdog's `WARN` line repeats. Cause: this is
what universal park cannot cover — a blocking *data* call on a **regular file** (epoll refuses
regular files, so `would_block` forwards it and the OS thread genuinely waits: `file_get_contents()`
on disk, the opcache file cache — ADR-0024, research 30 group (d); a blocking `flock()` on a regular
file is the one exception, parked rather than blocked since V-81, see the next row), or a library
whose policy row is deliberately `block` because it holds a lock across the call (ADR-0020; H36/V-51
proved the alternative — `park` — deadlocks such a library). Action: this thread is not crashed, so
the supervisor does not help; identify the call with `IGNIS_PARK_TRACE=1` on a reproduction (one
stderr line per park decision — expensive, use off of production traffic), then either move the call
to an offload worker, avoid the blocking resource, or accept the stall as a known limit of that code
path.

**A blocking file lock parks instead of stalling a thread — with one policy caveat.** `flock` is
interposed since V-81: a genuinely blocking `LOCK_EX` becomes `LOCK_NB` plus a parked retry (200 µs
doubling to a 20 ms ceiling), so the holder's thread stays free and the waiter is resumed once the
lock is released — this closes the deadlock a file-based session handler used to cause under
concurrent requests sharing a session id (`ext/session/mod_files.c`'s `flock(LOCK_EX)`, the case
BACKLOG R-SESS was filed against). V-80 found the exposure narrower still: two fibers on the *same*
thread never even contend, because `ext/session` is a per-thread singleton and the second
`session_start()` joins the first fiber's session; across threads a shared session id serialises and
completes, exactly like php-fpm — no thread is lost either way. The one way a lock can still stall a
thread: a custom `IGNIS_PARK` policy with `libphp:flock` removed (it is in the default seed), or a
call with no reactor to park on — both fall back to the real blocking `flock` silently. Action: leave
`flock` in the park policy; a stall that traces to it under a custom policy is a policy
misconfiguration, not a runtime limit.

**PostgreSQL is slow or unreachable, and callers fail fast instead of piling up.** Symptom:
`Ignis\Pg\PoolError` (not `QueryError`) out of `Pool::acquire()`, with one of three log lines. Cause:
`pg::acquire` is bounded (S1-BULKHEAD/M4-2, closed 2026-09-18): past `IGNIS_PG_ACQUIRE_TIMEOUT_MS`
(default 5000 ms) an acquire that has not found a connection fails with "acquire timed out after N
ms" instead of waiting forever and stalling every fiber that wants a lease on every thread; after
`IGNIS_PG_BREAKER_FAILURES` (default 5, `0` disables) consecutive failures the breaker opens and
refuses immediately for `IGNIS_PG_BREAKER_COOLDOWN_MS` (default 5000 ms) with "circuit breaker open …
not connecting for another N ms", then lets one probe through ("circuit breaker half-open … one probe
is already in flight"). Action: this is the bulkhead working as designed — one sick dependency fails
its own requests instead of stalling the process; read a run of these log lines as "the database is
down", not "the runtime is broken", and size the timeout/cooldown to what the application can
tolerate waiting.

**The request queue is filling up.** Symptom: rising `ignis_requests_queued`/
`ignis_requests_rejected_total` on `/_ignis/metrics`, or `queued`/`rejected` on an application's own
`/stats` route (see the watch table); clients see `503` with `retry-after: 1`. Cause: offered
concurrency exceeds `threads × budget.fibers` plus
`budget.queue` (ADR-0019) — either a legitimate burst, or fibers being held open longer than
expected by a slow downstream (check the PostgreSQL lease age and park-failure rows first). Action:
if the load is legitimate and sustained, raise `budget.fibers`/`budget.queue` per the sizing
formula (V-37) and recheck RSS headroom; if a downstream is the real cause, fix that — raising the
budget only defers the same problem to a bigger queue.

**A fatal ends a worker.** Symptom: log `WARN worker script ended; respawning (opcache SHM
untouched) slot=N status=255`; `/_ignis/health`'s `restarts` increments. Cause: an uncaught error,
exception, or bailout in a request fiber (ADR-0012). Action: the supervisor respawns the thread
automatically, inside its ~50 ms tick, with opcache SHM untouched — no recompilation, and
throughput on the rest of the process is unaffected while the respawn happens (V-17: 95.7% of
baseline recovery, other threads kept serving at 90,108 req/s / p99 2.67 ms). Nothing to do for
availability by itself, **but the request that was in flight on that thread is lost** — it receives
no response (ADR-0024 §1: no durability across crashes) — so the client-side fix is an idempotency
key or retry, and the operator-side fix is finding and patching whatever fataled. Watch for the
10-restarts-per-minute-per-slot cap: past it, that slot stops respawning and stays down until the
process itself restarts.

**An unguarded top-level function kills a classic worker.** Symptom: in classic mode, a second (or
later) request to the same worker fails with an uncatchable `Fatal error: Cannot redeclare function
…`, and that worker's script ends (the supervisor respawns it — see above, and the request that hit
the redeclare is lost). Cause: classic mode has two shapes, and this is specific to the newer one.
`Ignis\Classic\serve()` (fiber-based) never lets entry-script top-level state reach request code at
all: a top-level `$x = ...` never becomes `$GLOBALS['x']`, and `global $x` inside a function reads
`NULL` — surprising, but harmless, because nothing accumulates. The classic **worker loop**
(`Ignis\Classic\listen()`/`accept()`/`respond()`, `examples/classic_worker.php`) fixes that half —
entry-script globals behave exactly like stock `php -S` (V-54) — by including the entry script at
the true top level of one long-lived PHP execution, which is also why the *other* half breaks: a
top-level `function foo() {}` or `class Foo {}` from request 1 is still in the function/class table
on request 2, and PHP's redeclare fatal cannot be caught by any handler. Action: guard every
top-level declaration in a classic entry script with `function_exists()`/`class_exists()`, or
`require_once` it — the same discipline RoadRunner's and FrankenPHP's worker modes require of any
script that is included more than once in a process. There is no runtime fix; see V-54 for why a
fix would cost the worker model's own bootstrap saving.

## Known limits

- **No durability across crashes.** In-flight requests live in memory only. A worker that dies
  answers its own in-flight requests with nothing (client sees a dropped connection) and the
  supervisor respawns the thread (V-17); a process that dies loses everything the process was
  holding. Use idempotency keys with upstreams; use a queue for replay/audit/fan-out, not as a
  substitute for "don't hold a worker" (ADR-0024 §1).
- **One application per process.** The PHP kernel boots once per thread (V-16); there is no
  per-route or per-tenant isolation inside one `ignis serve` — run separate processes for separate
  applications (ADR-0024 §3).
- **Blocking data calls on regular files are never made asynchronous.** `file_get_contents()` on
  disk and the opcache file cache stall the OS thread they run on, not a fiber — epoll cannot watch
  a regular file, so there is no way to park a `read`/`write` on one (ADR-0024, added 2026-09-17). A
  blocking `flock()` on a regular file is the one exception: it parks by retrying non-blocking, not
  by watching the file (V-81), so `ext/session`'s file-lock handler no longer belongs on this list.
- **Classic mode has two shapes with different rules, and neither is free of a gap** (V-53, V-54):
  `serve()` loses entry-script globals but never corrupts state across requests; the worker loop
  keeps entry-script globals but fatals on an unguarded top-level function/class redeclaration on
  the second request. Pick the shape that matches the application, and guard top-level
  declarations either way.
- **`memory_limit` is per thread, not per fiber or per request.** One fiber's OOM ends the whole
  thread's script, taking every other in-flight fiber on that thread with it (ADR-0025); there is
  no per-fiber memory accounting.
- **A held file lock no longer takes its thread down, under the default policy.** V-58 found a
  blocking `flock` that another fiber on the same thread wanted would deadlock that thread
  permanently (ADR-0038); V-81 closed it by interposing `flock` (`LOCK_NB` plus a parked,
  backing-off retry). A policy with `libphp:flock` removed — or a call outside any fiber's reactor —
  still blocks the thread with no warning, so keep it in `IGNIS_PARK` (it is in the default seed).
  `session.save_handler=files` no longer deadlocks a thread either way (V-80: two fibers on one
  thread never even take a second lock, because `ext/session` is a per-thread singleton). Symfony's
  *cache* lock was always safe — non-blocking plus a `usleep` poll, which parks.
- **Name resolution blocks a thread.** `getaddrinfo()` has no file descriptor, so nothing can park
  it: every hostname lookup holds its OS thread for the whole resolve. Against a warm cache that is
  microseconds; against a sick or unreachable resolver it is seconds, and with N threads that is N
  requests stalled rather than one fiber. libpq and PHP's `fsockopen`/`stream_socket_client`/
  `gethostbyname` are affected; libcurl is not (it resolves on its own thread). **Run a local
  caching resolver** — nscd, systemd-resolved, or dnsmasq in the pod — and this stops mattering;
  that is the recommended production setup today. Tracked as BACKLOG R-DNS with the planned fix.
- **Linux-only, by construction.** Universal park depends on `epoll`, raw `syscall` numbers and
  `dladdr` semantics that are Linux-specific (ADR-0037's build/distribution table); there is no
  other-OS target.
- **`SIGTERM` and `SIGINT` drain; `SIGHUP` reload is not built.** On either signal the runtime
  reports `503 {"status":"draining"}` from `/_ignis/health` for `IGNIS_DRAIN_DELAY_MS` (default 0,
  env-only) while still accepting, then closes the listener and gives in-flight requests
  `limits.drain_timeout_ms` / `IGNIS_DRAIN_TIMEOUT_MS` (default 10 s, in `ignis.toml` since
  `c031408`) to finish before exiting 0 — measured in V-56. What is still missing is reload
  *without* a restart: a config change needs a new process, so a rolling deploy behind a balancer is
  the way to change configuration without a gap (BACKLOG M4-5).

## Configuration reference

Every key in `ignis.toml` and every `IGNIS_*` / `RUST_LOG` environment variable
`crates/ignis/src/config.rs` reads, with its default. Precedence, highest first: CLI flag >
environment variable > file > default (`deny_unknown_fields`: a misspelled key is a parse error,
not a silently ignored one).

| toml key | env var | default | what it does |
|---|---|---|---|
| `entry` | — (positional arg to `ignis serve`) | none, required | PHP entry script every worker thread runs |
| `listen` | `IGNIS_LISTEN` | `127.0.0.1:8080` | listener address |
| `threads` | `IGNIS_THREADS` | available parallelism (cores) | PHP worker threads |
| `offload` | `IGNIS_OFFLOAD` | `0` | synchronous workers for what cannot park — `SQLite3` and CPU-bound work; `curl_*` and socket-backed `PDO` park (V-59) |
| `supervise` | — (bridged to the `--supervise` CLI flag, no env var) | `true` | respawn a worker whose script ends |
| `php_ini` | `IGNIS_PHP_INI` | none | extra php.ini (the embed SAPI has no `-c`/`-d`) |
| `log` | `RUST_LOG` | `warn` | log filter (`tracing_subscriber::EnvFilter` syntax) |
| `budget.fibers` | `IGNIS_FIBER_BUDGET` | `1024` | request fibers admitted per thread; `0` = unlimited |
| `budget.queue` | `IGNIS_QUEUE_DEPTH` | `4096` | requests allowed to wait before `503`; `0` = unbounded |
| `exempt` | `IGNIS_BUDGET_EXEMPT` | `["/_ignis/"]` | path prefixes admitted regardless of the budget |
| `limits.max_body_bytes` | `IGNIS_MAX_BODY_BYTES` | `8388608` (8 MiB) | largest request body accepted; a bigger one is answered `413` |
| `limits.max_connections` | `IGNIS_MAX_CONNECTIONS` | `8192` | connections held at once; past it the listener refuses (accepts-then-refuses today, not delayed-accept — M4-3 open, V-56) |
| `limits.header_timeout_ms` | `IGNIS_HEADER_TIMEOUT_MS` | `10000` | time a connection may take to send its request head (slowloris) |
| `limits.idle_timeout_ms` | `IGNIS_IDLE_TIMEOUT_MS` | `60000` | time an idle keep-alive connection is kept |
| `limits.drain_timeout_ms` | `IGNIS_DRAIN_TIMEOUT_MS` | `10000` | time in-flight requests get to finish after `SIGTERM`/`SIGINT` before the process exits (V-56) |

The `[limits]` table (`c031408`) is new this cycle — these five were environment-only before.

Eight more env vars are read directly, not through `ignis.toml` (no file key exists for them):
`IGNIS_PARK` (the park policy table, `lib` or `lib:symbol` rows, ADR-0020/ADR-0037),
`IGNIS_SKIP_PARK_SELFCHECK` (bypass the boot self-check above), `IGNIS_PARK_TRACE` (one stderr line
per park decision — a diagnostic tool, not for production traffic), `IGNIS_PG_LEASE_WARN_MS`
(default `5000`, the threshold in the watch table above), `IGNIS_DRAIN_DELAY_MS` (default `0` — how
long `/_ignis/health` answers `draining` while the listener keeps accepting, before
`limits.drain_timeout_ms` starts, V-56), and the PostgreSQL bulkhead's three (S1-BULKHEAD/M4-2,
`16e930f`): `IGNIS_PG_ACQUIRE_TIMEOUT_MS` (default `5000`), `IGNIS_PG_BREAKER_FAILURES` (default `5`,
`0` disables the breaker) and `IGNIS_PG_BREAKER_COOLDOWN_MS` (default `5000`).

`ignis --version` and `ignis serve [--config PATH] [entry.php]` are the CLI surface (V-38).
