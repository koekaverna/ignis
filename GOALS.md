# GOALS

Ranked. Targets are floors; when met they get raised here with a reason.

## Expectations (from the brief, plus E9/E10 added by the owner mid-night)

| id | target | status |
|---|---|---|
| E1 | 10,000 concurrent requests each doing `Ignis\sleep(1000)` on ONE thread finish in < 1.2 s wall | **CONFIRMED** in-process at 1.17 s (V-2), marginal; warm pool 1037–1041 ms, overhead 37–41 ms (V-4). REFUTED under CPU contention (1302 ms, V-2 addendum). Raised: E1' = same over real HTTP (10k connections) in < 1.1 s → **INCONCLUSIVE** (V-5: p99 1.21 s warm, load generator shares the box); no quiet-box re-run after the E13'/GC changes |
| E2 | `Ignis\all()` of three 200 ms calls returns in < 230 ms; per-fiber overhead < 100 µs | **CONFIRMED** 202 ms, 22 µs (V-3). Raised: E2' = per-fiber overhead < 5 µs with a warm fiber pool → met with the superglobals observer off (4.5 µs), **not met with it on** (8.0–11.4 µs, V-11 addendum): E13' lazy swap + loop-scheduled GC landed afterwards and their effect is **not measured yet** on a quiet box (no V-n) |
| E3 | RSS flat (±2%) over 1,000,000 requests in worker mode | **CONFIRMED** (V-10): 1.5M hello, RSS −2.5%, PHP heap flat to the byte; 4.6M requests total. Raised: E3' = re-run with streams (E6) and state swap (E13) enabled, 4 threads, 10M requests |
| E4 | Hello-world throughput ≥ FrankenPHP worker mode on the same box, p99 lower | **CONFIRMED** on 1 thread (V-6): 4.6× throughput, p99 5.8× lower. Raised: E4' = same with Ignis@4 threads vs FrankenPHP@4 workers and `$_SERVER` populated (E13) → **DONE** (V-15: 112.5k vs 16.6k req/s, p99 2.41 ms, with the observer, streams and cancellation active) |
| E5 | 8 threads ≥ 6.5× single-thread throughput on CPU-bound work (this box has 4 vCPU: target is scaled to 4 threads ≥ 3.25×, see note) | **CONFIRMED** scaled (V-9): 3.7–3.98× in-process, 3.49× over HTTP. Raised: E5' = least-inflight dispatch so /cpu p99 at 4 threads ≤ FrankenPHP's → **DONE** (V-15: 12.4–12.8 ms vs 15.6 ms) |
| E6 | php_stream hook: unmodified `file_get_contents('http://…')` and PDO suspend the fiber | **CONFIRMED for tcp streams** (V-12: 3 × 200 ms fetches in 203 ms on one thread, hook-disabled control stalls). **REFUTED for PDO sqlite via hooks** (no stream layer). Raised: E6' = `ssl://` (TLS on tokio) → **DONE** (V-25: 210–231 ms hooked vs 613–623 ms unhooked, STARTTLS in place); PDO pgsql needs the native driver (E14, V-21) or the offload router (E16, V-24), not a transport hook |
| E7 | Revolt-compatible driver over the Ignis reactor runs AMPHP examples unchanged | **CONFIRMED** (V-13): 7/8 examples byte-identical, 1 timing race in the example itself; `DriverTest` identical to StreamSelectDriver (V-23 addendum). Raised E7' = AMPHP on the hooked transports + signals: OPEN, not measured |
| E8 | symfony/runtime adapter boots symfony/skeleton in worker mode; RequestStack fiber-scoped | **CONFIRMED** (V-16 + addendum): 0/100 RequestStack mismatches, sessions on, 7.2k req/s (1 thread) / 25.2k (4 threads). Raised E8' = multi-value `Set-Cookie` header path: OPEN, not measured |
| E9 | Temporal: link temporal sdk-core (Rust) in-process; workflow on Fibers with deterministic replay; one workflow with two activities and a timer passes a replay test. Research first: how the Python SDK bridges core ↔ asyncio | **CONFIRMED** (V-18, V-19; research 12, ADR-0013) — prototype scope: no signals/queries/cancel |
| E10 | gRPC: tonic server + client on the shared hyper/h2 stack; unary and server-streaming handlers in PHP; client call suspends the fiber. Compare against RoadRunner grpc plugin and ext-grpc on latency and build complexity | **CONFIRMED** (V-20): unary + server-streaming in PHP on the shared listener, client call parks the fiber (100 × 200 ms in 217 ms); 16.7k req/s vs RR 5.1k–11.4k on the same box; the absolute p99 < 5 ms is unmeasurable here (ceiling 6.3 ms) |
| E11 | Cancellation: client disconnect cancels the request fiber and its futures within 10 ms; one wall-clock deadline per request inherited by children | **CONFIRMED** (V-14): 0.78 ms worst case, 504 at 102 ms for a 100 ms deadline |
| E12 | Isolation: fatal / 30 s CPU loop affects one thread; supervisor restarts it without an opcache reset; pools survive | **CONFIRMED** for threads (V-17). The pool now exists and is owned by the runtime, not by a thread's TSRM context (V-21), but its survival across a respawn is not measured. Raised E12' = in-flight requests on a dying thread get 500 instead of a closed connection: code pushed, **not measured yet** (no V-n) |
| E13 | State: superglobals + fiber-scoped container swapped on every fiber switch via zend_observer; leak detector | **CONFIRMED** (V-11): 0 mismatches, +100 ns/switch; leak detector is the `Ignis\Scope` dev check. Raised E13' = lazy swap + loop-scheduled GC: landed, **not measured yet** (no V-n); `PG(http_globals)` still not swapped |
| E14 | Connections: runtime-owned pool, lease per fiber, transaction pins the lease, session reset on return | **CONFIRMED** (V-21): 200 fibers over 20 connections in 1046 ms, transaction pins one backend, LeaseError without an op, reset verified; 112 µs/query, 7.3k q/s. `pdo_pgsql` per-query comparison still **not measured**; auto-routed `pdo_pgsql` through the offload pool is in V-24 addendum |
| E15 | Compat: php-src suites (Zend/tests/fibers, ext/standard/tests/streams, ext/sockets/tests) under ignis run-tests with every failure classified; Revolt DriverTest on IgnisDriver 100%; Swoole swoole_runtime hook tests through a shim (report missing hooks); FrankenPHP testdata as integration tests; Symfony + Doctrine suites in chaos mode with zero new failures vs stock PHP | **DONE** (V-22, V-23 + addendum, V-26 addendum, V-27): E15a phpt main mode 108/108, 80/80, 132/138 of stock (fiber mode 78/75/120) with every failure classified, E15b Revolt `DriverTest` 81/222 with 0 failures = StreamSelectDriver, E15c Swoole shim 44/153 with the missing-hook ranking, E15d FrankenPHP 29 pass / 4 fail / 33 skip. E15e Symfony/Doctrine chaos mode **0 new failures** in 10 849 tests per mode (V-27) |
| E16 | Offload: pool of synchronous PHP worker threads with their own TSRM context; `Ignis\offload(fn)` copies scalar/array args in and the result back while the fiber sleeps; config-driven auto-routing of curl_exec / PDO pgsql / SQLite3 / Redis with no code changes; 100 concurrent 200 ms pdo_pgsql queries limited by the pool size, never by the fiber thread; curl_exec with CURLOPT_WRITEFUNCTION works; copy overhead per call recorded | **CONFIRMED** (V-24): pool bounded by its size (2608 ms / 8 workers, 243 ms / 100), auto-routed PDO pgsql + SQLite3 + curl with WRITEFUNCTION, copy 13–67 µs, routed call 17–45 µs |

Note on E5: the machine has 4 vCPUs (`nproc`), so "8 threads ≥ 6.5×" cannot
be measured as written. The proportional target (≥ 81% scaling efficiency)
is used: 4 threads ≥ 3.25× single-thread.

## Ranking (after Cycle 2)

~~Cycle 3: N PHP threads (E5)~~ DONE (V-9).

~~Cycle 4: E3~~ DONE (V-10).

~~Cycle 5: E13~~ DONE (V-11). ~~Cycle 6: E6 tcp~~ DONE (V-12); sqlite REFUTED for hooks. ~~Cycle 7: E7~~ DONE (V-13). ~~Cycle 8: E11~~ DONE (V-14). ~~Cycle 9: E5'~~ DONE (V-15). ~~Cycle 10: E8~~ DONE (V-16).

~~Cycle 11: E12~~ DONE (V-17). ~~Cycles 12–13: E9~~ DONE (V-18, V-19). ~~Cycle 14: E10~~ DONE (V-20). ~~Cycle 15: E15a/c/d ports (porter + two pre-note agents) and the `$argv`/embed work they needed~~ DONE (V-23). ~~Cycle 16: E14~~ DONE (V-21). ~~Cycle 17: the four runtime defects the E15 ports found (server sockets in fibers, `Loop::run` with C-parked fibers, blocking `sleep`/`usleep`, swallowed exceptions) + STDIN/STDOUT/STDERR and `PHP_BINARY`~~ DONE (V-22). ~~Cycle 18: E16~~ DONE (V-24, + addendum for auto-routing); E15b also confirmed there (V-23 addendum). Cycle 19: ~~E6' ssl~~ DONE (V-25). Cycle 20: ~~E6'' accept/select in fibers, E12' fail-fast~~ DONE (V-26 + addendum); ~~E15e chaos mode~~ DONE (V-27).

Remaining OPEN at 2026-09-16T05:01:43Z: the raised targets **E2'/E13'** (lazy swap + loop-scheduled GC landed, need a quiet-box measurement), **E12'** (fail-fast for in-flight requests on a dying thread, pushed unmeasured), **E3'** (10M requests at 4 threads with streams/state/offload on), **E7'**, **E8'**, **E9'**, **E10'**, **E11'**; plus the structural pain-map items that were never started (fiber budget / pool cap with queueing, per-endpoint budget + circuit breaker, in-process Table, MySQL/Redis drivers, allocator-level leak detector). Ranking in STATUS.md.

## Ranking (after Cycle 0, kept for history)

Cycle 0 result: the thesis holds in-process. The measured bottleneck is not
the scheduler but Zend's per-fiber mmap/munmap (V-2 profile: ~50% of PHP-thread
CPU in kernel stack lifecycle). That reorders the plan: a fiber pool is now
the first engineering item because every later expectation (E3 RSS flatness,
E4 throughput) inherits its cost.

1. ~~E1'/E2' — fiber pool~~ DONE (V-4).
2. ~~E4 — hello-world over hyper~~ DONE on 1 thread (V-6).
2b. DONE (V-7, V-8): fork builds, provider idle-hook prototype runs E1 on engine coroutines; E6 not in the ABI. Owner direction (23:15Z) was: research the async scheduler ABI RFC (wiki.php.net/rfc/async_scheduler_abi), php-src PR #22561 and the true-async/php-src `async-core` branch; ADR: reactor/scheduler behind a trait with two backends — (a) mainline 8.5 via zend_observer fiber-switch + stream hooks, (b) async-core fork via the engine ABI. Prototype E6 on (b) first if it builds; (a) stays the production path until the ABI ships. This is Cycle 2's question; E5 (threads) moves to Cycle 3.
3. E5 — multi-thread ZTS workers.
4. E3 — worker mode leak test.
5. E6 — stream hooks (Swoole route).
6. E7 — Revolt driver (cheap once the reactor exists, see ADR-0001).
7. E10 — gRPC on the same hyper/h2 stack (transport is shared with E4).
8. E8 — Symfony adapter.
9. E9 — Temporal sdk-core (largest unknown; research-first).

## Retired / cut

- "Prototype E6 on backend (b) first": retired after V-7 — the ABI PR has no I/O integration; E6 is stream-hook work on both backends.
- A full Rust re-implementation of the scheduler provider (2k lines of setjmp-heavy C): deferred; `zend_first_try` in the coroutine entry needs a C shim, so a Rust provider is a C-shim + Rust design, not pure Rust.
