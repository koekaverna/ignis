# 50 — Validation scenarios for ADR-0043: stall detection, stuck-fiber recovery and blocking alerts, on ZTS and NTS

Date: 2026-09-23, main agent. Status: **specification, nothing run**. Companion to ADR-0043 and
research 49. Every scenario names its fixture, its command, its expected numbers and its control,
on both carriers where the carrier matters. Run order and gates are at the end. Numbers here are
targets; a V-n entry decides each one.

Conventions: `IGNIS_BIN` selects the binary (`target/release/ignis` for ZTS,
`target-nts/release/ignis` for NTS — the NTS binary runs with `LD_LIBRARY_PATH` unset, S-NTS-MODE
(e)); `WORKERS` is `--threads N` under ZTS and the prefork count under NTS; every server takes
`IGNIS_LISTEN`; readiness is the expected body; kill by PID (owner rules, `docs/orchestration.md`).
Every scenario runs with `profile = "test"` unless it says otherwise, so the thresholds are short
and the run is bounded.

## A. Fixtures — one file per way of being stuck (`bench/php/stall/`)

| fixture | state it produces | how |
|---|---|---|
| `spin.php` | Running PHP (VM) | `while (true) { $i++; }` on request; `?jit=1` variant runs under `opcache.jit=tracing` |
| `block-sleep.php` | Blocked in the shim, policy `block` | `sleep(30)` with `IGNIS_PARK=` (empty policy — nothing parks) |
| `block-curl.php` | Blocked in the shim inside a third-party library | `curl_exec` to a blackhole address (`10.255.255.1`, 30 s timeout) with `IGNIS_PARK=libpq` (curl under `block`) |
| `c-loop.php` | Running C, no interrupt checks | `password_hash('x', PASSWORD_BCRYPT, ['cost' => 20])` — 61.5 s on this box (the fixture's doc block); every +1 doubles it, so calibrate `?cost=` to the machine rather than raising it: the run only has to outlast `stall_abandon_ms` |
| `file-read.php` | Blocking forward on a regular file | `file_get_contents` of a 256 MB file on a `dd`-throttled loop device, or `/dev/zero` read of 1 GB — a call the shim forwards and that takes > 100 ms |
| `sqlite.php` | Blocking forward inside an extension under `block` | `SQLite3::query` on a 2 s `WITH RECURSIVE` — the documented "cannot park" case (MVP scope) |
| `flock-hold.php` | Lock held across a yield (R-SESS shape) | fiber A `flock(LOCK_EX)` + `usleep`; fiber B `flock(LOCK_EX)` — must park, not stall (V-58 regression) |
| `futex.php` | Running C, lock wait | two fibers inside one libmongoc client (V-114's shape) or a PECL that takes a mutex; `wchan` = `futex_wait_queue` |
| `swallow-cancel.php` | Cancellation swallowed | `try { PDO query } catch (\Throwable) { sleep-park again; }` |
| `park-forever.php` | Parked forever | `Ignis\await(new Future)` that nobody resolves |
| `dtor-park.php` | GC destructor fiber parks | 10 000 cycles whose `__destruct` calls `usleep(1000)`; `gc_collect_cycles()` in a loop |
| `finally-io.php` | `finally` does I/O during a force-close | `finally { file_get_contents('http://…') }` after a cancel |
| `dstate.sh` | D-state | a FUSE mount whose daemon is `SIGSTOP`ped, then `file_get_contents` on it (opt-in, needs `fusermount`) |

Each fixture serves `/stuck` and `/hello`; `/hello` on the *same* worker is what proves "the other
fibers continue". The harness pins a request to a worker by filling every other worker first
(`WORKERS - 1` parked `/sleep?ms=5000` requests), the technique `bench/e12-isolation.sh` uses.

## B. Scenarios

### S-1 Detection by timer names the request (M4-7 closed)

`spin.php`, `WORKERS=4`, `busy_warn_ms=100`. Expected: within 1.5 s **one** warn line `stall`
with `worker`, `state=php`, `age_ms ≥ 100`, `request_id`, `uri=/stuck`, `/proc: running`, `cpu:
advancing`; the same request produces **no second line** inside the window; the window summary
says `count=1`. Control: `busy_warn_ms=0` → no line. On NTS the line carries the child `pid`; the
classification is read from `/proc/<child>/task/<child>/…`. Same on `block-sleep.php`: `state=
blocking_forward`, `library=libphp symbol=nanosleep`, `/proc: syscall 230 wchan=hrtimer_nanosleep`.

### S-2 Blocking detector under load finds every site, once each

`examples/app.php` plus the Symfony skeleton (V-40) under `profile = load-test`, `wrk -c 64 -d 30s`
over the route list (`bin/console debug:router --format=json` → `bench/blocking-audit.sh`).
Expected: the report lists every blocking site the app has (`sqlite.php`'s query, the session file
`flock` if enabled, a config read) with `count`, `max_us`, `route`, and a backtrace on the first
occurrence; the log has **one** warn per site plus one summary per window; `ignis_blocking_calls_total`
equals the report's sum. Gate: `bench/blocking-audit.sh --allow bench/results/blocking-allow.txt`
exits non-zero on any site not in the allow file. Control: `IGNIS_PARK` default policy and a route
that only parks → zero sites; `IGNIS_NO_UNIVERSAL_PARK=1` → every socket read becomes a site
(the detector must see the hook-off world as all-blocking, which is what it is).

### S-3 Strict mode fails the test that blocked (controller/function testing)

`php/packages/runtime/src/Testing/` (new): `BlockingAssertions` trait —
`assertNoBlockingCalls(callable $fn)` runs `$fn` in a fiber on the loop under `strict`, reads
`ignis_blocking_sequence()`/`ignis_blocking_records()` before and after, and fails with the site
list (library:symbol, duration, PHP file:line). For an existing suite, the `DetectsBlocking`
trait: a `#[Before]`/`#[After]` pair that fails the test on every unallowed record the detector
made on the thread during it. What it cannot do is wrap the test body itself — PHPUnit 12's
`TestCase::runTest()` is private, and an extension only observes — and the detector's gate is the
fiber, so code the test drives on its own context (a `KernelBrowser::request()`) is audited only
through `$this->ignisAudited(fn () => …)`, one `request()` override in a `WebTestCase` base class
(the earlier draft of this note promised a `WebTestCaseListener` extension that would do it
without changes; it cannot). Expected on the skeleton's own tests, once wrapped: 0 failures with
the allow file, N failures without it, each naming the controller and the line. Unit level:
`assertNoBlockingCalls(fn () => file_get_contents('/etc/hostname'))` fails (regular file);
`assertNoBlockingCalls(fn () => file_get_contents('http://127.0.0.1:$port/'))` passes (parks).

### S-4 Dedup and escalation (pure Rust, `cargo nextest`)

`alerts.rs` tests, no engine needed: the first event of a key logs; 999 repeats in the window
log nothing and count; the window end emits one summary with `count=1000 max p50`; the 100th
event escalates warn → error exactly once; a key silent for `recover_windows` emits one info; two
keys differing only in route are two lines; the token bucket drops the 21st line in a second and
counts it; `critical` flips the worker's health field. Property test: for any event sequence, lines
per key per window ≤ 3 (first, escalation, summary).

### S-5 L0 — fiber timeout bounds a hung run (S-FIBER-TIMEOUT)

`park-forever.php`, `fiber_timeout_ms=2000`: 504 within 2.5 s, the `fiber_timeout` line names the
park's file:line (from `zend_fiber.execute_data`), `finally` ran. Then `bench/e15-chaos.sh` with
`SUITES=symfony-http-foundation` and the timeout on: finishes in bounded time; a fixture that
parks forever is reported as *that* test failing, not the job timing out. Control:
`fiber_timeout_ms=0` hangs until the job timeout (the 2026-09-22 state). Per-route override:
`[recovery.routes."/export/"] fiber_timeout_ms=10000` → a 5 s park on `/export/x` completes, on `/x`
it is cut at 2 s.

### S-6 L2 — a swallowed cancellation is force-closed

`swallow-cancel.php` with a client disconnect at 100 ms: the fiber is force-closed at its next
park within 1 ms of that park; `finally` ran exactly once; the C op was answered `ECANCELED` (no
`could not park, blocking` in `IGNIS_PARK_TRACE`); `ignis_fibers_killed_total{level="L2"}` = 1; the
worker's script is alive (`/hello` on that worker answers). `finally-io.php`: the I/O in `finally`
gets `FiberError`/`ECANCELED`, never parks, never blocks the thread for its timeout.
`on_swallowed_cancel=log` → the fiber runs on and one warn line says so.

### S-7 L3 — a spinning fiber is killed, siblings survive, on both carriers

`spin.php` (and `?jit=1`), `stall_kill_ms=1000`, two parked siblings on the same worker: the fiber
dies within 2 s (`interrupt_acks` = 1, `fibers_killed{L3}` = 1); `finally` ran; a surrounding
`catch (Throwable)` did not intercept; both siblings completed; the loop's `Fiber::resume` returned
without an exception; 504 answered; the worker keeps serving `/hello`. Under NTS the signal is
`kill(child_pid)`. `kill=exception` → the same, and the fiber's `catch (Ignis\KilledException)`
ran. Control: `stall_kill_ms=0` → V-17's behaviour, the worker never polls again.

### S-8 L4 — a fiber blocked in the shim is cancelled by signal

`block-curl.php` and `block-sleep.php`, `stall_kill_ms=1000`: the call returns an error within
10 ms of the signal; PHP sees `CurlException`/a `false` with `ECANCELED` in `error_get_last()`;
`/hello` on the worker answers; `fibers_killed{L4}` = 1. Report per library which retry re-entered
the shim (libcurl, libpq, libphp streams). Control for `SA_RESTART`: a second worker reading a
regular file at the same instant is undisturbed (`file-read.php` completes with the right byte
count).

### S-9 Classification picks the level (`/proc`)

`spin.php`, `block-sleep.php`, `c-loop.php` in turn, `WORKERS=4`: the `stall` line at
`stall_kill_ms` says `running ack=yes` / `syscall=230 wchan=hrtimer_nanosleep` / `running ack=no`
and the ladder chose L3 / L4 / L5 respectively. `futex.php`: `wchan=futex_wait_queue` → L5 without
a signal. `dstate.sh` (opt-in): `state=D` → no signal, L5 at `stall_abandon_ms`.

### S-10 L5 — abandon a live worker, both carriers

`c-loop.php`, `stall_abandon_ms=2000`, `WORKERS=4`: the worker is deregistered within 2.5 s; its
three other in-flight requests get 504 within 0.5 s of that; a replacement serves within 1 s;
health reports `leaked_workers=1` (ZTS) / the child is `SIGKILL`ed and reaped, `workers_killed=1`
(NTS); after `leaked_workers_max` stalls health is 503 (ZTS). NTS-specific: the killed child held
the opcache SHM lock at the moment of the kill (fixture compiles a 2 MB file in a loop) → the next
child compiles and serves; `opcache_get_status()` shows no restart pending. Control: today (V-17)
the thread stays registered and its in-flight requests wait forever.

### S-11 Cost — the instruments are free when nothing is wrong

E4 hello-world (`bench/wrk-hello.sh`), `profile = production`, no blocking sites: throughput and
p99 within noise (V-122's 11 %) of the tree before ADR-0043; `strace -c` on a worker shows no new
syscalls per request (the slot stores are not syscalls; `clock_gettime` is vDSO). E6 (three parked
fetches, V-12) unchanged: the detector never times a park. Kill criterion of the ADR if > 1 %.

### S-12 The dtor-fiber hazard is closed (research 49 H1/H2)

`dtor-park.php` on an ASAN build (`scripts/build-php.sh` has no ASAN arm — `USE_ZEND_ALLOC=0` +
valgrind on the binary is the fallback): 0 reports; `gc_destructor_fiber_parked` ≥ 10 000;
memory flat over the run. Control: the tree at `e909c86` under the same fixture reports a
use-after-free or leaks the fiber (the scenario that turns research 49 H1 from a reading into a
number).

### S-13 R-SESS does not regress and the detector does not cry wolf on it

`flock-hold.php`: no stall line, no blocking line — the waiter parks on a timer (V-58's
mechanism), which the detector must not count as a blocking forward. Control:
`IGNIS_NO_UNIVERSAL_PARK=1` → a blocking line for `flock` and a stall.

### S-14 Warnings are stable under a storm

`spin.php` on all workers at once, `wrk -c 256` on `/stuck` for 60 s: the log has ≤
`max_lines_per_s` lines per second, `ignis_alerts_dropped_total` > 0, one summary per key per
window, health 503, and after the storm (`/stuck` stopped) one `recovered` line per key within
`recover_windows` windows and health 200 after the replacements are serving.

### S-15 Configuration precedence and profiles

`config.rs` tests (no engine): `profile = test` sets the seven derived keys; an explicit key beats
the profile; `IGNIS_STALL_KILL_MS` beats the file; the CLI flag beats the env; an unknown key under
`[recovery]` is an error; `routes."/export/"` longest-prefix wins over `routes."/"`; `blocking.allow`
patterns match `library:symbol@prefix`.

### S-16 NTS scoreboard survives a child death

Kill a child with `SIGKILL` from outside during load: its slot is reported stale once, the master
respawns, `pending` for its in-flight requests is answered 504, no slot is ever reused while its
`pid` is alive, and the shared page is not remapped (address stable across respawns).

## C. Controller and function testing — what an application developer runs

1. **Unit:** `Ignis\Testing\BlockingAssertions::assertNoBlockingCalls(callable)` around any
   function; the failure message is the site list. Runs under the ZTS or NTS binary through
   `scripts/ignis-php` (the phpt runner's entry), because plain `php` has no loop.
2. **Controller:** the PHPUnit extension wraps `KernelBrowser::request()`; every existing
   `WebTestCase` test becomes a blocking audit. `#[Ignis\AllowBlocking]` on a test method, or
   `Ignis\allowBlocking(fn)` in the code under test, downgrades documented sites.
3. **Route audit under load:** `bench/blocking-audit.sh` — server under `profile = load-test`,
   `wrk` over every route, the JSON report diffed against `blocking-allow.txt`; exits non-zero on
   new sites. This is the pre-production gate the owner asked for.
4. **Production:** `profile = production` — warn lines deduped, counters, health, the report on
   `SIGUSR2`. A stall or a kill is never silent; a known site is one line per window.

## D. Order, gates and where each number lands

Build order follows ADR-0043 §7/§8 and research 49 §6: S-4 and S-15 first (pure Rust, no
engine — an `agent` lane item), then S-1/S-2/S-3 (detector and scoreboard; `main` lane for
`park.rs`), then S-5/S-6 (L0/L2, mostly PHP), S-7/S-8/S-9 (L3/L4, `main`), S-10/S-16 (L5, `main`,
NTS half after the prefork lands), S-11/S-12/S-13/S-14 as the cost and regression arms. Each
scenario is one V-n. CI: `ci.yml` gets a `stall` job running S-1, S-5, S-6, S-7, S-13 on the ZTS
binary under `profile = test` (bounded by design); `nts.yml` gets the same leg plus S-10/S-16;
`e15-chaos` returns as a gate once S-5 is green; `bench/blocking-audit.sh` runs in the smoke job
against `examples/app.php` with an allow file that starts empty and must stay empty.
