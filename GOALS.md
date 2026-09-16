# GOALS

Ranked. Targets are floors; when met they get raised here with a reason.

## Expectations (from the brief, plus E9/E10 added by the owner mid-night)

| id | target | status |
|---|---|---|
| E1 | 10,000 concurrent requests each doing `Ignis\sleep(1000)` on ONE thread finish in < 1.2 s wall | **CONFIRMED** in-process at 1.17 s (V-2), marginal. Raised: E1' = same over real HTTP (10k connections) in < 1.1 s with a warm fiber pool; overhead < 50 ms |
| E2 | `Ignis\all()` of three 200 ms calls returns in < 230 ms; per-fiber overhead < 100 µs | **CONFIRMED** 202 ms, 22 µs (V-3). Raised: E2' = per-fiber overhead < 5 µs with a warm fiber pool → met with the superglobals observer off (4.5 µs), **not met with it on** (8–11 µs, V-11 addendum): E13' lazy swap + loop-scheduled GC are the fix |
| E3 | RSS flat (±2%) over 1,000,000 requests in worker mode | **CONFIRMED** (V-10): 1.5M hello, RSS −2.5%, PHP heap flat to the byte; 4.6M requests total. Raised: E3' = re-run with streams (E6) and state swap (E13) enabled, 4 threads, 10M requests |
| E4 | Hello-world throughput ≥ FrankenPHP worker mode on the same box, p99 lower | **CONFIRMED** on 1 thread (V-6): 4.6× throughput, p99 5.8× lower. Raised: E4' = same with Ignis@4 threads vs FrankenPHP@4 workers, and with `$_SERVER` populated (E13) so the work is comparable |
| E5 | 8 threads ≥ 6.5× single-thread throughput on CPU-bound work (this box has 4 vCPU: target is scaled to 4 threads ≥ 3.25×, see note) | **CONFIRMED** scaled (V-9): 3.7–3.98× in-process, 3.49× over HTTP. Raised: E5' = least-inflight dispatch so /cpu p99 at 4 threads ≤ FrankenPHP's → **DONE** (V-15: 12.4–12.8 ms vs 15.6 ms) |
| E6 | php_stream hook: unmodified `file_get_contents('http://…')` and PDO suspend the fiber | **CONFIRMED for tcp streams** (V-12: 3 × 200 ms fetches in 203 ms on one thread, hook-disabled control stalls). **REFUTED for PDO sqlite via hooks** (no stream layer). Raised: E6' = `ssl://` (TLS on tokio) + PDO pgsql over the hooked tcp transport (pgsql's libpq uses its own sockets, so that needs the native driver, E14) |
| E7 | Revolt-compatible driver over the Ignis reactor runs AMPHP examples unchanged | **CONFIRMED** (V-13): 7/8 examples byte-identical, 1 timing race in the example itself |
| E8 | symfony/runtime adapter boots symfony/skeleton in worker mode; RequestStack fiber-scoped | **CONFIRMED** (V-16): 0/100 RequestStack mismatches, sessions on |
| E9 | Temporal: link temporal sdk-core (Rust) in-process; workflow on Fibers with deterministic replay; one workflow with two activities and a timer passes a replay test. Research first: how the Python SDK bridges core ↔ asyncio | **CONFIRMED** (V-18, V-19; research 12, ADR-0013) — prototype scope: no signals/queries/cancel |
| E10 | gRPC: tonic server + client on the shared hyper/h2 stack; unary and server-streaming handlers in PHP; client call suspends the fiber. Compare against RoadRunner grpc plugin and ext-grpc on latency and build complexity | **CONFIRMED** (V-20): unary + server-streaming in PHP on the shared listener, client call parks the fiber (100 × 200 ms in 217 ms); 16.7k req/s vs RR 5.1k–11.4k on the same box; the absolute p99 < 5 ms is unmeasurable here (ceiling 6.3 ms) |
| E11 | Cancellation: client disconnect cancels the request fiber and its futures within 10 ms; one wall-clock deadline per request inherited by children | **CONFIRMED** (V-14): 0.78 ms worst case, 504 at 102 ms for a 100 ms deadline |
| E12 | Isolation: fatal / 30 s CPU loop affects one thread; supervisor restarts it without an opcache reset; pools survive | **CONFIRMED** for threads (V-17); pools do not exist yet (E14) |
| E13 | State: superglobals + fiber-scoped container swapped on every fiber switch via zend_observer; leak detector | **CONFIRMED** (V-11): 0 mismatches, +100 ns/switch; leak detector is the `Ignis\Scope` dev check |
| E14 | Connections: runtime-owned pool, lease per fiber, transaction pins the lease, session reset on return | **CONFIRMED** (V-21): 200 fibers over 20 connections in 1046 ms, transaction pins one backend, LeaseError without an op, reset verified; pdo_pgsql comparison pending |
| E15 | Compat: php-src suites (Zend/tests/fibers, ext/standard/tests/streams, ext/sockets/tests) under ignis run-tests with every failure classified; Revolt DriverTest on IgnisDriver 100%; Swoole swoole_runtime hook tests through a shim (report missing hooks); FrankenPHP testdata as integration tests; Symfony + Doctrine suites in chaos mode with zero new failures vs stock PHP | PARTIAL (V-23): phpt main mode 100%/100%/94.9% of stock, Swoole shim 44/153 with the missing-hook ranking, FrankenPHP 29/33 applicable; Revolt DriverTest and chaos mode pending |
| E16 | Offload: pool of synchronous PHP worker threads with their own TSRM context; `Ignis\offload(fn)` copies scalar/array args in and the result back while the fiber sleeps; config-driven auto-routing of curl_exec / PDO pgsql / SQLite3 / Redis with no code changes; 100 concurrent 200 ms pdo_pgsql queries limited by the pool size, never by the fiber thread; curl_exec with CURLOPT_WRITEFUNCTION works; copy overhead per call recorded | **CONFIRMED** (V-24): pool bounded by its size (2604 ms / 8 workers, 291 ms / 100), auto-routed PDO pgsql + SQLite3 + curl with WRITEFUNCTION, copy 13–67 µs, routed call 17–45 µs |

Note on E5: the machine has 4 vCPUs (`nproc`), so "8 threads ≥ 6.5×" cannot
be measured as written. The proportional target (≥ 81% scaling efficiency)
is used: 4 threads ≥ 3.25× single-thread.

## Ranking (after Cycle 2)

~~Cycle 3: N PHP threads (E5)~~ DONE (V-9).

~~Cycle 4: E3~~ DONE (V-10).

~~Cycle 5: E13~~ DONE (V-11). ~~Cycle 6: E6 tcp~~ DONE (V-12); sqlite REFUTED for hooks. ~~Cycle 7: E7~~ DONE (V-13). ~~Cycle 8: E11~~ DONE (V-14). ~~Cycle 9: E5'~~ DONE (V-15). ~~Cycle 10: E8~~ DONE (V-16).

~~Cycle 11: E12~~ DONE (V-17). ~~Cycles 12–13: E9~~ DONE (V-18, V-19). ~~Cycle 14: E10~~ DONE (V-20). ~~Cycle 16: E14~~ DONE (V-21). ~~Cycle 18: E16~~ DONE (V-24). ~~E6' ssl~~ DONE (V-25). Remaining OPEN: E15e (chaos mode, porter running); raised targets E2'/E13', E12' — each a multi-hour build; see STATUS.md ranking.

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
