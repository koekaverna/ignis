# ADR-0043 — Stall detection, stuck-fiber recovery and blocking alerts, on both carriers (ZTS threads and NTS prefork)

Status: **proposed** (main agent, 2026-09-23, owner brief of the same day: "must work on NTS and
ZTS — another agent is finishing the NTS process fork, FPM-style, for a shared opcache; warn when
a thread/process is blocked, by timer, deduplicated, with a warning/error scheme; under load tests
and live runs blocking logic must be detected at once and logged — that is how every blocking site
is found before production; think through validation and controller/function testing; add
recovery of stuck fibers with flexible configuration"). Rests on research 49 (the engine hooks,
every claim verified against php-src 8.5.10), ADR-0009 (cancellation), ADR-0012 (supervisor),
ADR-0020/0037 (park, the two-mechanism budget), ADR-0022 (observability contract), ADR-0030
(preemption, stays proposed), ADR-0034 (destructors), S-NTS-MODE and S-FIBER-TIMEOUT.
Validation scenarios: research 50. Nothing here is measured yet; every number is a default or an
estimate and says so.

## 1. Context

Today a worker that stops yielding is *counted*: `ignis_poll` stamps `last_active_us`, a tokio
task logs `stalled=N` once a second, health answers 503 when every thread is stalled, and
least-inflight dispatch routes new work around it (ADR-0012, V-17). Nothing names the request,
the fiber, the library or the syscall; nothing distinguishes "computing" from "blocked in a
syscall"; nothing recovers the thread or its other fibers; a fiber parked forever has no ceiling
(S-FIBER-TIMEOUT, the reason the chaos gate is off); and a blocking call inside a fiber under
policy `block` is only visible as a `false` in `ignis_park_inventory()` — no duration, no request,
no PHP frame, no log line. The owner's requirement is the inverse: **every blocking site announces
itself the first time it runs under load, once, with enough context to fix it**, and a fiber that
is stuck is recovered by policy rather than by a process restart.

Two carriers must be covered with one design: **ZTS** — N PHP threads in one process, the watchdog
is a tokio task in the same address space; **NTS prefork** (in progress on another branch) — a Rust
master process forks N children after MINIT so the opcache SHM is shared, each child has one PHP
interpreter, the master owns the listener and the request map. The difference is where memory is
shared and how a signal is addressed; nothing else.

## 2. Decision in one paragraph

One **scoreboard** (per-worker slot of plain atomics, written by the worker, read by a master-side
ticker; a static array under ZTS, one `MAP_SHARED|MAP_ANONYMOUS` page mapped before the first fork
under NTS). One **ticker** (100 ms) that turns slot age into events by threshold and classifies a
stuck worker from `/proc/<pid>/task/<tid>/{syscall,wchan,stat}`. One **blocking detector** in the
interposer that times every blocking forward made inside a fiber and reports the site. One
**alert module** (pure Rust, no `unsafe`) that deduplicates, aggregates, escalates and rate-limits
everything the ticker and the detector emit, into `tracing` lines, Prometheus counters, health,
`ignis_stats()` and a shutdown report. One **recovery ladder** (research 49 L0–L5) whose delivery
to a worker is always **one real-time signal** — `pthread_kill` under ZTS, `kill` under NTS — whose
handler stores two atomics and nothing else; the engine's own force-close does the unwinding.
Everything is configured by four durations, one mode and one profile, with per-route overrides.

## 3. Scoreboard

```
WorkerSlot {                      // one per worker; 64 bytes; written only by its worker
  pid, tid: u32                   // NTS: child pid; ZTS: process pid + thread tid
  state: AtomicU8                 // 0 IDLE (in ignis_poll)  1 PHP  2 BLOCKING_FORWARD (in the shim)  3 ABANDONED
  since_ns: AtomicU64             // CLOCK_MONOTONIC at the last state change
  heartbeat_ns: AtomicU64         // last poll entry/exit (today's last_active_us, moved here)
  request_id: AtomicU64           // current request, 0 = none  (enterRequest / releaseRequest)
  fiber: AtomicUsize              // EG(active_fiber) at the last state change (identity only, never dereferenced by the reader)
  site: AtomicU32                 // BLOCKING_FORWARD: index into the worker's (library, symbol) table, published through the alert module
  interrupt_acks: AtomicU64       // incremented by the interrupt callback each time it ran (proves the PC was in the VM)
  kills, cancels: AtomicU64
}
```

Writers, all on the worker: `ignis_poll` (IDLE ↔ PHP), the shim around a blocking forward
(PHP ↔ BLOCKING_FORWARD, ~4 relaxed stores per forward — only on the path that already blocks the
thread, never on a park), `enterRequest`/`releaseRequest` (request id, already crossing the FFI
for `ignis_set_request_info`). Reader: the ticker. Under NTS the page is created by the master
before the first `fork()` and inherited; a slot whose `pid` no longer answers `kill(pid, 0)` is
stale and is reported once as such. The request id → uri map is the master's in both carriers
(the reactor's `answers` map under ZTS; the front's map under NTS), so the ticker can name the uri
without the worker publishing strings. FPM's `fpm_scoreboard` is the precedent; this one is
smaller because the events carry the strings, not the slots.

## 4. Ticker — detection by timer, classified

Every `watch_tick_ms` (default 100) the ticker reads every slot. For a slot in state PHP or
BLOCKING_FORWARD, `age = now − since_ns`:

| age past | event | level | action |
|---|---|---|---|
| `busy_warn_ms` (default 500) | `stall` — worker, state, age, request id + uri, and for BLOCKING_FORWARD the library:symbol | warn | log (deduped, §6) |
| `stall_kill_ms` (default 10 000; 0 = off) | `stall` escalates | error | recovery ladder L3/L4 (§7) |
| `stall_abandon_ms` (default 30 000; 0 = off) | `abandon` | critical | ladder L5 |

Classification, read at every escalation and attached to the event: `/proc/<pid>/task/<tid>/syscall`
(`running`, or the syscall number and its arguments — argument 0 is the fd for `read`/`poll`),
`/wchan` (`hrtimer_nanosleep`, `futex_wait_queue` = a lock, `nfs_…`), `/stat` field 3 (`R`/`S`/`D`)
and fields 14–15 (CPU ticks: advancing = computing, flat = waiting). Under NTS the child is a
descendant, so Yama `ptrace_scope = 1` permits it; under ZTS it is the same thread group. A `D`
state disables signalling for that worker (nothing is delivered until the syscall returns) and goes
straight to the abandon timer. Demonstrated on this box in research 49 §4 O1.

Idle-with-pending is not a stall: a slot in IDLE with `pending_requests > 0` is a worker whose
fibers are all parked, which is the design; the S-FIBER-TIMEOUT ceiling (L0) covers a fiber parked
forever, not the ticker.

## 5. Blocking detector — the load-test instrument

The interposer already decides, per call, "park" or "forward blockingly" (`park.rs`: gate → policy
→ readiness). Every **blocking forward made inside a fiber** — policy `block`, park refused, a
descriptor that cannot be watched — is timed with two `clock_gettime(CLOCK_MONOTONIC)` (vDSO, ~20 ns)
and, when it exceeds `blocking.threshold_us` (default 1 000; profiles below), emits
`blocking_call{library, symbol, duration_us, fd_kind, request_id, fiber}`. With `blocking.trace`
on, the first occurrence per site also carries a PHP backtrace: the shim runs on the PHP thread
inside an internal function's frame, after the syscall returned and before any fiber switch, so
`zend_fetch_debug_backtrace` is sound there and costs only on the reporting path. The CPU-bound
case has no syscall and is caught by the ticker's `busy_warn_ms` instead; a profile sets both.

Modes (`blocking.mode`, env `IGNIS_BLOCKING`):

| mode | what happens on a blocking call over the threshold |
|---|---|
| `off` | nothing (the counters still count) |
| `warn` (production default) | deduped warn line, counters, health field, shutdown report |
| `strict` (tests, load tests) | as `warn`, at error, and the call is recorded on the request so `ignis_stats()['blocking']` and `Ignis\Testing` can fail the test that caused it; `blocking.allow` sites are downgraded to info |
| `fatal` (CI benches) | as `strict`, and the process exits non-zero at the first unallowed site, printing it |

`blocking.allow` is a list of `library:symbol[@route-prefix]` patterns for sites an application
accepts (SQLite under a documented route, a config file read at first request). From PHP,
`Ignis\allowBlocking(callable)` marks the current fiber (its `reserved[]` slot, research 49 E5) so
the detector reports its calls at info — the per-call-site answer for code the table cannot name.
Neither changes what the runtime does; they change the level.

The report (`blocking.report = path`, written at shutdown and on `SIGUSR2`): one JSON object per
site — library, symbol, route(s), count, max and p50 duration, first-seen backtrace when traced —
the artifact a load test produces and a gate can diff against an allow file.

## 6. Alert module — warning / error scheme with deduplication

A pure-Rust module (`alerts.rs`, no `unsafe`, unit-tested) that every emitter feeds and that owns
the log lines. Events carry a **key** and **fields**; the key decides deduplication:

| kind | key | level | first line says |
|---|---|---|---|
| `blocking_call` | library:symbol, route | warn (strict: error) | site, duration, request, fd kind, backtrace if traced |
| `stall` | route, state, library:symbol | warn → error at `stall_kill_ms` | worker, age, request, `/proc` classification |
| `park_failed` | library:symbol | error | policy said park, the call blocked (today's `PARK_FAILED`, given a key and a line) |
| `fiber_timeout` / `cancelled` | route | warn | request, where it was parked (file:line via `zend_fiber.execute_data`) |
| `fiber_killed` | route, level (L2/L3/L4) | error | request, how (force-close on re-park / interrupt / signal), `finally` ran |
| `worker_abandoned` / `worker_killed` | worker | critical | in-flight failed, replacement slot, leaked count |
| `recovered` | the key that went quiet | info | after `alerts.recover_windows` silent windows |

Rules: the **first** occurrence of a key is logged at once with every field; **repeats inside
`alerts.window_s`** (default 60) are counted, not logged; at the window's end one **summary line
per key** with ≥ 2 events: count, max, p50, last seen; a key **escalates** warn → error when its
count reaches `alerts.escalate_count` (default 100) in a window or its max crosses the next
threshold, and the escalation is one line; **critical** means an error line plus health 503 for
the worker (ZTS: that thread; NTS: that child) and the `ignis_workers_unhealthy` gauge; a global
token bucket of `alerts.max_lines_per_s` (default 20) protects the log under a storm and counts
what it dropped. Every kind is also a Prometheus counter with the key as labels
(`ignis_blocking_calls_total{library,symbol,route}`, `ignis_blocking_seconds_max`,
`ignis_stalls_total{state}`, `ignis_fibers_killed_total{level}`, `ignis_workers_abandoned_total`,
`ignis_alerts_dropped_total`), a field in `/_ignis/health` (`blocked_workers`, `leaked_workers`,
`blocking_sites`) and an entry in `ignis_stats()` for the PHP side. This is ADR-0022's contract
made concrete for the rows it left unbuilt; the "every park is a span" row stays unbuilt.

## 7. Recovery ladder — one delivery, two carriers

Research 49 L0–L5, with delivery unified. The handler is installed once per process (inherited by
NTS children), for `SIGRTMIN+2` (research 49 H4: `SIGRTMIN` is PHP's), flags
`SA_SIGINFO|SA_RESTART|SA_ONSTACK`, and does exactly this, async-signal-safe: under ZTS check
`tsrm_is_managed_thread()` as PHP's own timeout handler does; store `kill_pending = 1` (a per-worker
atomic in the slot); store `EG(vm_interrupt) = 1`. Nothing else, ever.

| level | trigger | ZTS | NTS prefork |
|---|---|---|---|
| **L0** fiber timeout | `fiber_timeout_ms` per request (route override), armed by `admitRequest` via `deadline()`'s timer; children inherit | same code | same code |
| **L1** cancel | disconnect / deadline / L0 → `Fiber::throw` or `ignis_cancel_parked_any` (built, V-14) | same | same |
| **L2** force-close | the fiber parks again after L1, or L0 fires twice: 504 answered by the loop, references dropped, `unset` → engine graceful exit (research 49 E1); `wait.rs` refuses to park a `DESTROYED` fiber and answers `ECANCELED` through the shim | same | same |
| **L3** kill running PHP | ticker: `stall_kill_ms`, state PHP, `/proc` says `running` | `pthread_kill(tid, SIGRTMIN+2)` | `kill(pid, SIGRTMIN+2)` |
| | the interrupt callback (chained `zend_interrupt_function`): if `kill_pending` and `EG(active_fiber)` is the recorded fiber → set `ZEND_FIBER_FLAG_DESTROYED`, `zend_throw_graceful_exit()`, `interrupt_acks++`; the loop answers 504 and forgets the fiber | same callback | same callback |
| **L4** blocked in the shim | state BLOCKING_FORWARD | same signal → `EINTR` in `poll`/`select`/`nanosleep` (never restarted) → the shim sees `kill_pending` on re-entry and returns `-1/ECANCELED` → the library errors → PHP throws → L1/L2 finish | same | same |
| **L5** abandon | no ack within `stall_abandon_ms`, or `/proc` says `D`, or `wchan` is a futex | deregister the thread, 504 its in-flight requests (E12' path), spawn a replacement (ADR-0012's respawn), leak the thread, `leaked_workers++`; health 503 at `leaked_workers_max` | `SIGKILL` the child, reap, respawn, 504 its in-flight from the master; the kernel releases its `fcntl` record locks — opcache's SHM lock included (FPM's `request_terminate_timeout` relies on the same); a child killed inside opcache's critical section is the same exposure FPM carries, documented not solved |

`kill = "graceful"` (default) throws the engine's uncatchable `GracefulExit`; `kill = "exception"`
throws `Ignis\KilledException` (catchable, for applications that want to log it) — the callback
is the same, the object differs. `on_swallowed_cancel = "force-close" | "log"` decides L2.

Why a signal even under ZTS, where the ticker could store `vm_interrupt` directly: one code path
for both carriers, the store lands on the target thread (its TSRM cache, its `EG`), and the same
signal is what L4 needs anyway. Cost: one signal per escalation, none in steady state.

## 8. Configuration — flexible, one knob for the common case

```toml
[recovery]
profile = "production"        # production | load-test | test — sets every default below; explicit keys win
fiber_timeout_ms = 0          # L0 per request; 0 = off. Children inherit. Ignis\deadline() overrides per request
busy_warn_ms = 500            # warn when a worker runs PHP or a blocking forward this long without yielding
stall_kill_ms = 10000         # L3/L4; 0 = off
stall_abandon_ms = 30000      # L5; 0 = off
leaked_workers_max = 3        # ZTS: health 503 past this many leaked threads; NTS: respawn storm limit stays ADR-0012's 10/min
kill = "graceful"             # graceful | exception
on_swallowed_cancel = "force-close"   # force-close | log

[recovery.routes."/export/"]  # longest-prefix match; every key above may be overridden
fiber_timeout_ms = 120000
stall_kill_ms = 0

[blocking]
mode = "warn"                 # off | warn | strict | fatal
threshold_us = 1000
trace = false                 # PHP backtrace on the first occurrence per site
report = ""                   # JSON report path, written at shutdown and on SIGUSR2
allow = ["libphp:read@/config", "libphp:fdatasync"]

[alerts]
window_s = 60
escalate_count = 100
recover_windows = 5
max_lines_per_s = 20
```

Profiles: `production` = the defaults above; `load-test` = `busy_warn_ms 50`, `blocking.mode strict`,
`threshold_us 200`, `trace on`, `fiber_timeout_ms 30000`; `test` = `busy_warn_ms 20`, `strict`,
`threshold_us 100`, `trace on`, `fiber_timeout_ms 5000`, `stall_kill_ms 2000`. Every key has an
`IGNIS_*` environment form through `config.rs`'s precedence (CLI > env > file > profile > default):
`IGNIS_PROFILE`, `IGNIS_FIBER_TIMEOUT_MS`, `IGNIS_BUSY_WARN_MS`, `IGNIS_STALL_KILL_MS`,
`IGNIS_STALL_ABANDON_MS`, `IGNIS_BLOCKING`, `IGNIS_BLOCKING_US`, `IGNIS_BLOCKING_TRACE`,
`IGNIS_BLOCKING_REPORT`. From PHP: `Ignis\deadline(ms)` (exists), `Ignis\allowBlocking(callable)`,
and `ignis_stats()` to read the counters.

## 9. Mechanism budget and invariants

Nothing here is a wait mechanism: the scoreboard, ticker and alert module are observability
(ADR-0022); L0–L2 are the fiber lifecycle the `context` mechanism owns; the detector, L3 and L4
are attributes of `park`'s rows (a timed forward, an errno rewrite); L5 is the supervisor
(ADR-0012). No Zend pointer crosses to tokio — the slot holds a `pid`, a `tid` and a fiber
*address* used only for equality; the reader never dereferences it. The worker still has exactly
one wait point. `EG(timed_out)` is never set (research 49 H5). The handler never allocates, locks
or logs.

## 10. Consequences

Better: the first blocking site under a load test is one warn line with the library, the symbol,
the route and a PHP frame, and the shutdown report lists all of them — the owner's "find every
place before production". A stuck fiber costs its request, not its thread; a stuck thread costs
its thread, not the process; both are one error line and one counter, never silence. The same
binary behaves the same under threads and under prefork. The chaos gate becomes affordable (L0).

Worse: ~250 lines plus ~100 for L5 (research 49's estimate) across `park.rs`, `wait.rs`,
`module.rs`, `park.c`, `main.rs`, `http.rs`, a new `alerts.rs`, `config.rs`, `Loop.php`; four
relaxed stores on the blocking-forward path (which already blocks the thread, so the cost is
noise); one process-global `zend_interrupt_function` to chain if pcntl ever loads; an NTS child
killed inside an opcache critical section can leave the SHM in the state FPM also risks.

## 11. Kill criterion

Any of: the graceful exit thrown at L2 or L3 reaching the loop's frame and ending the worker's
script (research 49 H-INT-1/2 controls) → L2/L3 revert to `Fiber::throw` semantics; a library
that turns `ECANCELED` into corrupted state (a reused connection returning another request's
bytes) → its row is `block` and L4 skips it (ADR-0009's criterion); the detector costing more
than 1 % on E4 hello-world with `mode = warn` and no blocking sites (research 50 S-11) → the
timing moves behind the `strict` mode only; more than one `stall` warn line per key per window in
the dedup tests → the module is wrong before it ships.
