# VALIDATION

Raw numbers, exact commands, machine state. Every STATUS.md claim links here.

Machine (all entries unless stated): Intel Xeon @ 2.80GHz, 4 vCPU, 15 GiB RAM,
no swap, Linux 6.18.44, Ubuntu 24.04.4. Shared cloud VM; noisy-neighbour
effects possible, so every benchmark is run ≥ 3 times and all runs are listed.

## V-0 — H0: PHP 8.5.10 ZTS + embed builds (CONFIRMED)

Date: 2026-09-15T22:26:50Z. Command: `scripts/build-php.sh` (first attempt failed: `--with-pdo-sqlite` needs explicit `--enable-pdo` after `--disable-all`; script has it).

```
$ /opt/php85-zts/bin/php -v | head -1
PHP 8.5.10 (cli) (built: Sep 15 2026 22:17:46) (ZTS)
$ /opt/php85-zts/bin/php -r 'var_dump(PHP_ZTS, extension_loaded("pdo_sqlite"), extension_loaded("mbstring"), extension_loaded("sockets"), class_exists("Fiber"), extension_loaded("json"), extension_loaded("Zend OPcache"));'
bool(true) x7
```

Build wall time: ~6 min at -j4 (two configure runs). Result: CONFIRMED.

## V-1 — H1: Rust host embeds libphp ZTS and registers an internal module (CONFIRMED)

Date: 2026-09-15T22:26:50Z. Commit: see `git log` for "feat(embed)".

```
$ cargo build -p ignis && time ./target/debug/ignis examples/hello.php
hello from rust: 42
zts=1 fibers=1 php=8.5.10
real 0m0.016s
```

Startup + MINIT + RINIT + script + shutdown = 16 ms wall (debug build), target
was < 50 ms. `cargo nextest run --workspace`: 7 tests, 7 passed (reactor
channel semantics, zval layout, module entry constants). Result: CONFIRMED.

## V-2 — H2 (E1) and H4: 10,000 fibers sleeping 1000 ms on one thread (CONFIRMED, marginal)

Date: 2026-09-15T22:5xZ. Build: `cargo build --release -p ignis` (lto=thin, mimalloc).
Command: `N=10000 MS=1000 ./target/release/ignis bench/php/e1_sleep_10k.php` (3 runs, then 2 with phase timing).
Machine: idle apart from this process; 4 vCPU; tokio runtime with 2 worker threads on the side.

```
n=10000 sleep_ms=1000 completed=10000 wall_ms=1177.7 spawn_ms=15.6 run_ms=1162.1 overhead_ms=177.7 resumes=20000 peak_rss_kb=184320
n=10000 sleep_ms=1000 completed=10000 wall_ms=1171.4 spawn_ms=14.8 run_ms=1156.5 overhead_ms=171.4 resumes=20000 peak_rss_kb=184320
n=10000 sleep_ms=1000 completed=10000 wall_ms=1167.7 spawn_ms=14.6 run_ms=1153.2 overhead_ms=167.7 resumes=20000 peak_rss_kb=184320
phases_ms start=140.8 ready=0.0 poll=911.1 resume=90.0   wall_ms=1162.8
phases_ms start=138.4 ready=0.0 poll=914.4 resume=86.8   wall_ms=1159.1
```

| metric | value | target |
|---|---|---|
| wall (3 runs) | 1168 / 1171 / 1178 ms | < 1200 ms → **CONFIRMED** (margin 2–3%) |
| non-sleep overhead | 160–178 ms | — |
| start phase (10k `Fiber::start`) | 138–141 ms = 14 µs / fiber | — |
| resume phase (10k `Fiber::resume` incl. fiber teardown) | 87–90 ms = 8.8 µs / fiber | H4: < 100 ms → **CONFIRMED** (marginal) |
| peak RSS | 184 MB (10k × 16 KiB VM stack = 160 MB is the floor set by `ZEND_FIBER_VM_STACK_SIZE`) | — |

`fiber.stack_size` 2M / 256K / 64K: wall 1161–1173 / 1158–1161 / 1151–1155 ms. Stack size is irrelevant (< 1%): the cost is per-syscall, not per-byte.

Profile (`perf record -F 4000 -g`, `perf report --no-children --sort comm,dso`), PHP thread (`ignis`) only:

| where | share of PHP-thread samples |
|---|---|
| kernel, page faults (`do_user_addr_fault` and children) | 20.9% |
| kernel, `munmap` (fiber stack free, incl. TLB shootdown IPIs to tokio threads) | 19.7% |
| kernel, `mprotect` (guard page) + `mmap` | 9.1% |
| libphp.so total (VM, `zend_fiber_execute`, `zend_fiber_init_context`) | 14.4% |
| ignis (Rust) | 0.3% |

Conclusion: Zend allocates and frees a fresh mmap'd C stack per fiber; with a
multi-threaded process every munmap costs cross-CPU TLB flushes. **The
userland scheduler is not the bottleneck** (Rust + PHP loop code is a rounding
error); fiber lifecycle is. This is the strongest argument for a **fiber
pool** (keep N fibers alive across requests, dispatch work into them) in
Cycle 1. Kill criterion of ADR-0001 (c) is not met: no reason to move the
scheduler into Rust.

Note: `$argv` is not registered under the embed SAPI; benches take env vars.
Default `memory_limit=128M` cannot hold 10k started fibers (16 KiB VM stack
each); the bench sets 1G.

## V-3 — H3 (E2): `Ignis\all()` of three 200 ms sleeps; per-fiber overhead (CONFIRMED)

Date: 2026-09-15T22:5xZ. Command: `./target/release/ignis bench/php/e2_all.php` (3 runs).

```
all3x200_ms=201.90 result=abc n=10000 per_fiber_us=21.77
all3x200_ms=201.12 result=abc n=10000 per_fiber_us=24.19
all3x200_ms=201.98 result=abc n=10000 per_fiber_us=21.62
```

| metric | value | target |
|---|---|---|
| `all()` of 3 × 200 ms | 201.1–202.0 ms | < 230 ms → **CONFIRMED** |
| per-fiber overhead (spawn + submit 0 ms sleep + poll + resume + teardown, amortised over 10k) | 21.6–24.2 µs | < 100 µs → **CONFIRMED** |

The 1–2 ms above 200 ms is tokio's 1 ms timer-wheel granularity plus one poll round trip.

### V-2 addendum — E1 under CPU contention (REFUTED under load)

`scripts/smoke.sh` run while two background builds (php-fpm `make -j2`, FrankenPHP `go build`) were using the other cores:

```
n=10000 sleep_ms=1000 completed=10000 wall_ms=1301.6 spawn_ms=57.1 run_ms=1244.5 overhead_ms=301.6 resumes=20000 peak_rss_kb=184320
```

Same binary, same script, 1302 ms: the 2–3% margin of V-2 does not survive a
loaded machine. E1 is therefore CONFIRMED only on an idle box; the raised
target E1' (fiber pool, overhead < 50 ms) is what makes it robust. Recorded
rather than re-run quietly.

## V-4 — H5 (E2'): fiber pool (CONFIRMED)

Date: 2026-09-15T22:45Z. Command: `ROUNDS=2 N=10000 MS=1000 ./target/release/ignis bench/php/e1_sleep_10k.php` (2 runs) and `./target/release/ignis bench/php/e2_all.php` (3 runs). Idle box (server stopped).

```
round=1 phases_ms start=135.5 ready=0.0 poll=975.9 resume=25.0
round=1 n=10000 sleep_ms=1000 completed=10000 wall_ms=1153.2 ... overhead_ms=153.2 fibers_created=10000 peak_rss_kb=184320
round=2 phases_ms start=0.0 ready=20.9 poll=988.2 resume=13.7
round=2 n=10000 sleep_ms=1000 completed=10000 wall_ms=1041.3 ... overhead_ms=41.3 fibers_created=10000 peak_rss_kb=196608
round=1 ... wall_ms=1145.9 overhead_ms=145.9
round=2 ... wall_ms=1037.1 overhead_ms=37.1
all3x200_ms=201.78 result=abc n=10000 per_fiber_us_cold=16.33 per_fiber_us_warm=4.52 fibers_created=10000
all3x200_ms=202.01 result=abc n=10000 per_fiber_us_cold=16.03 per_fiber_us_warm=4.38 fibers_created=10000
all3x200_ms=201.81 result=abc n=10000 per_fiber_us_cold=16.43 per_fiber_us_warm=4.56 fibers_created=10000
```

| metric | cold (round 1) | warm (round 2) | target |
|---|---|---|---|
| E1 wall, 10k × 1000 ms | 1146–1153 ms | **1037–1041 ms** | < 1050 ms → CONFIRMED |
| non-sleep overhead | 146–153 ms | **37–41 ms** | < 50 ms → CONFIRMED |
| per-job overhead (0 ms sleep, 10k) | 16.0–16.4 µs | **4.4–4.6 µs** | < 5 µs → CONFIRMED (marginal) |
| fibers created for 20k jobs | 10,000 | 0 new | — |

Also visible: the cold round's resume phase dropped from 87–90 ms (V-2) to 25 ms
because pooled fibers park instead of terminating (no munmap per fiber). What
remains warm is ~2 µs `Fiber::resume` + ~1 µs channel/poll + array building.
Peak RSS grows 12 MB between rounds (Future objects + result arrays retained by
the bench itself).

## V-5 — H6: `Ignis\serve()` over hyper, 1 PHP thread (CONFIRMED; 10k-connection p99 miss noted)

Date: 2026-09-15T22:41–22:45Z. Server: `./target/release/ignis examples/hello_server.php` (release, tokio 2 workers, 1 PHP thread). Load generator on the same 4-vCPU box.

Hello-world, `wrk -t2 -c64 -d10s --latency http://127.0.0.1:8080/` (2 runs):

```
Latency 511.82us avg, 99% 1.28ms, Requests/sec: 122299.10
Latency 472.37us avg, 99% 1.07ms, Requests/sec: 130467.67
/stats after: {"resumes":2540957,"fibers":64,"idle":63}   (pool = peak concurrency, no growth)
```

Concurrent sleeps `/sleep?ms=1000`:

| load | p50 | p99 | max | errors | note |
|---|---|---|---|---|---|
| `wrk -t2 -c1000 -d4s` | 1.00 s | 1.01 s | 1.20 s | 0 | 1000 concurrent: 10 ms overhead |
| `wrk -t2 -c10000 -d5s` run 1 (cold pool) | 1.01 s | 1.42 s | 1.86 s | 86 timeouts | pool grows 0→10k during the run |
| `wrk -t2 -c10000 -d5s` run 2 (warm pool) | 1.03 s | 1.21 s | 1.25 s | 0 | server RSS 346 MB with 10k parked fibers |
| `ab -k -c1000 -n1000` | — | — | 1.04 s per request | 0 | ab's "time taken" (2.06–3.26 s) is its own serial connect loop, not server time; ab is not used further |

| target | result |
|---|---|
| ≥ 20k req/s hello, 0 errors | **122k–130k req/s** → CONFIRMED; target raised (see GOALS) |
| 1000 concurrent × 1000 ms < 1.1 s | p99 1.01 s → CONFIRMED |
| E1' (10k connections < 1.1 s) | p50 yes, **p99 1.21 s → NOT MET**; wrk with 10k connections on the same 4-core box is a confounder (2 wrk threads + 2 tokio + 1 PHP > 4 cores). Recorded as INCONCLUSIVE, re-test needs a separate load box or fewer wrk threads. |

`ulimit -n` cannot be raised above 20000 in this container; 10k connections worked within it.
Memory: `memory_limit` must be ≥ ~200 MB for 10k in-flight requests (16 KiB VM stack per pooled fiber); hello_server.php sets 1G. A pool cap with request queueing is the correct long-term answer (GOALS).

## V-6 — H7 (E4): hello-world, Ignis vs FrankenPHP worker mode vs php-fpm + nginx (CONFIRMED)

Date: 2026-09-15T22:53–23:12Z. Command: `bench/compare.sh 2 64 10s` (`wrk -t2 -c64 -d10s --latency`, load generator on the same 4-vCPU box).
All three servers use **the same libphp 8.5.10 build flags** (ZTS, opcache, `--disable-zend-signals`; php-fpm is the NTS twin from the same source tree). FrankenPHP v2.11.4 was cgo-compiled against this libphp. Full table: `bench/results/compare.md`.

| server | req/s | p50 | p99 | errors |
|---|---|---|---|---|
| **ignis (1 PHP thread + 2 tokio)** | **128,072** (134,253 in the earlier run) | 453 µs | **1.11 ms** | 0 |
| frankenphp worker (num=1, num_threads=2) | 27,627 | 2.12 ms | 6.42 ms | 0 |
| frankenphp worker (num=4, num_threads=5) | 16,583 | 3.77 ms | 10.35 ms | 0 |
| php-fpm (pm.max_children=1) + nginx | 9,853 | 6.30 ms | 8.68 ms | 0 |
| php-fpm (pm.max_children=4) + nginx | 11,106 | 5.69 ms | 7.41 ms | 0 |

Result: Ignis ≥ FrankenPHP worker mode by **4.6×** throughput and p99 **5.8× lower** → E4 CONFIRMED on 1 thread.

Honesty notes:
- FrankenPHP with 4 workers is *slower* than with 1 here: the box has 4 vCPUs shared with wrk (2 threads) and the Go runtime; this is a small-box artefact, not FrankenPHP's ceiling. Ignis has not been measured multi-threaded yet (E5, Cycle 2).
- FrankenPHP builds a full `$_SERVER`, runs its partial request startup and header handling per request; Ignis builds one small PHP array (method, uri, headers, body) and returns a value object. Part of the gap is work Ignis does not do yet (superglobals, E13). The gap that is architectural: no cgo/thread hand-off per request, one syscall-free channel op, pooled fibers.
- FrankenPHP required `--disable-zend-signals` in libphp; the rebuilt libphp was then used for all three rows, and Ignis re-measured on it (128k vs 134k before: within run-to-run noise).

## V-7 — H8 and H9a: the true-async fork builds; E6 is not in the ABI (H8 CONFIRMED, H9a REFUTED by inspection)

Date: 2026-09-16T00:0xZ. Source: `true-async/php-src` branch `async-core` = php-src PR #22561 head `14af3cb` (2026-07-16), version 8.6.0-dev.
Command: `scripts/build-php-async.sh` (ZTS, embed, opcache, `--disable-zend-signals`, `--enable-test-scheduler`, same extension set) → `/opt/php86-async-zts`; then `patches/0001-test-scheduler-idle-hook.patch` (+14 lines) applied and rebuilt.

```
$ /opt/php86-async-zts/bin/php -c bench/php/async-core.ini -r 'var_dump(PHP_VERSION, extension_loaded("test_scheduler"));'
string(9) "8.6.0-dev"  bool(true)
$ make test TESTS=ext/test_scheduler   (with the idle-hook patch applied)
Number of tests :    61   Tests passed : 61 (100.0%)   Tests failed : 0   Time taken : 0.288 seconds
```

H8: builds, 61/61 (target ≥ 55) → CONFIRMED. The patch changes nothing when no hook is set.

H9a (E6 on backend (b)): `grep -rl 'ZEND_ASYNC_ON\|ZEND_ASYNC_SUSPEND\|ZEND_ASYNC_IS_ACTIVE' --include=*.c --include=*.h .` minus `Zend/` and `ext/test_scheduler` → **no files**. Only `Zend/zend_fibers.c`, `zend_gc.c`, `zend_execute_API.c`, `zend_objects_API.c` consult the ABI. A blocking `fread()`/`usleep()` inside a coroutine still blocks the thread → REFUTED by inspection (no run needed; the code path does not exist). Stream hooks are required on both backends (ADR-0003).

## V-8 — H9b: E1 on backend (b), engine coroutines driven by the tokio reactor (CONFIRMED)

Date: 2026-09-16T00:1xZ. Build: `PHP_CONFIG=/opt/php86-async-zts/bin/php-config CARGO_TARGET_DIR=target-async cargo build --release -p ignis` (cfg `php_async_abi` on).
Command: `N=10000 MS=1000 IGNIS_PHP_INI=bench/php/async-core.ini ./target-async/release/ignis bench/php/e1_async_core.php` (3 runs; idle box).
Mechanism: `Fiber::start()` on the fork = enqueue coroutine + await first yield; each coroutine does `ignis_park_on($id); Fiber::suspend();`; when the reference scheduler's run queue is empty it calls the Ignis idle hook, which blocks in `Reactor::poll()` and `ZEND_ASYNC_ENQUEUE_COROUTINE`s every coroutine whose timer fired.

```
N=10  MS=100:  spawned=10 in 0.3 ms;  backend=b n=10 sleep_ms=100 wall_ms=102.0 overhead_ms=2.0
backend=b n=10000 sleep_ms=1000 wall_ms=1164.9 overhead_ms=164.9 last_timer_late_us=1896
backend=b n=10000 sleep_ms=1000 wall_ms=1170.6 overhead_ms=170.6 last_timer_late_us=846
backend=b n=10000 sleep_ms=1000 wall_ms=1177.0 overhead_ms=177.0 last_timer_late_us=1895
```

| metric | backend (b) | mainline (a), cold fibers (V-2) | target |
|---|---|---|---|
| E1 wall, 10k × 1000 ms | **1165–1177 ms** | 1168–1178 ms | < 1200 ms → CONFIRMED |
| overhead | 165–177 ms | 160–178 ms | — |

Reading: the engine ABI's idle point is exactly where a reactor plugs in (14-line hook); the cost is identical to mainline because the reference provider also mmaps one C stack per coroutine (`ts_context_create` → `zend_fiber_init_context`), so the V-2 finding (fiber lifecycle, not scheduling, is the cost) holds on (b) too. A first attempt that called `ZEND_ASYNC_SUSPEND()` directly from `ignis_await_op` without enqueuing `fiber->caller_coroutine` serialised everything (1013 ms for 10 × 100 ms): on this ABI a suspension must hand control back to the starter the way `zend_fiber_coroutine_yield` does. Numbers are tied to fork commit `14af3cb`.

## V-9 — H10 (E5 in-process) and H11 (E5/E4' over HTTP): N PHP threads (CONFIRMED)

Date: 2026-09-16T00:4xZ. Build: `cargo build --release -p ignis` (ADR-0004: `--threads N`, one reactor per PHP thread, round-robin dispatch).

### In-process CPU scaling (no HTTP, no wrk)

`IGNIS_THREADS=$t ./target/release/ignis --threads $t bench/php/e5_cpu.php` — every thread runs the same fixed workload (md5 + array + usort) and prints its own wall time.

| threads | per-thread wall (ms), ITERS=300k, 3 runs | ITERS=3M, 1 run |
|---|---|---|
| 1 | 61.0 / 62.0 / 61.8 | 603.1 |
| 2 | 60.5, 60.7 / 60.1, 61.8 / 61.0, 61.1 | — |
| 4 | 59.9–61.1 / 60.0–62.3 / 60.0–66.2 | 603.1, 613.0, 622.3, 634.6 |

4× the work in ≤ 1.07× (300k) / 1.05× (3M) the 1-thread time → **3.7–3.98× throughput** (target ≥ 3.25×) → H10 CONFIRMED. No ZTS contention visible in libphp for this workload.

### Over HTTP, load generator on the same 4-vCPU box

Server: `./target/release/ignis --threads T examples/hello_server.php`. FrankenPHP rows: `bench/frankenphp/index.php` with the same `/cpu` loop, `wrk -t1 -c64 -d10s`.

| server | route | wrk | req/s | p99 |
|---|---|---|---|---|
| ignis 1 thread | `/` | -t2 -c64 | 125,907 | 1.34 ms |
| ignis 4 threads | `/` | -t2 -c64 | 105,200 | 2.12 ms |
| ignis 1 thread | `/cpu` (~0.37 ms PHP) | -t1 -c64 | 2,660 | 25.5 ms |
| **ignis 4 threads** | `/cpu` | -t1 -c64 | **9,285** | 17.5 ms |
| frankenphp worker num=1 | `/cpu` | -t1 -c64 | 2,558 | 30.6 ms |
| frankenphp worker num=4 | `/cpu` | -t1 -c64 | 7,278 | 15.6 ms |
| frankenphp worker num=4 (V-6) | `/` | -t2 -c64 | 16,583 | 10.35 ms |

- `/cpu` 4 threads vs 1: **3.49×** (target ≥ 2.5×) → H11 part 1 CONFIRMED.
- hello at 4 threads (105k) ≥ FrankenPHP at 4 workers (16.6k) → H11 part 2 CONFIRMED; hello is *slower* than at 1 thread (126k) because it is not CPU-bound in PHP and 4 PHP threads + 2 tokio + 2 wrk oversubscribe 4 vCPUs.
- Honesty: on `/cpu` at 4 workers FrankenPHP's p99 (15.6 ms) beats Ignis's (17.5 ms) while Ignis has 28% more throughput. Round-robin dispatch sends 1/N of requests to a thread that is busy; least-inflight dispatch is the obvious fix (ADR-0004 lists it).
- Per-request CPU is PHP's: at 1 thread Ignis and FrankenPHP are within 4% on `/cpu`.
