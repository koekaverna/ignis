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

## V-10 — H12 (E3): RSS over 4.6 million requests in worker mode (CONFIRMED)

Date: 2026-09-16T01:0xZ. Command: `bench/rss-1m.sh` (1 PHP thread, release build; `/stats` reads VmRSS from `/proc/self/status` and `memory_get_usage()`).

```
warm-up 5s: 759158 requests
sample 0 (post warm-up): {"resumes":759220,"fibers":64,"idle":63,"mem":1652312,"mem_real":2097152,"rss_kb":26920}
sample 1: +500322 (total  500322): {"fibers":64,"mem":1652432,"mem_real":2097152,"rss_kb":26420}
sample 2: +487093 (total  987415): {"fibers":64,"mem":1652432,"mem_real":2097152,"rss_kb":26184}
sample 3: +513332 (total 1500747): {"fibers":64,"mem":1652432,"mem_real":2097152,"rss_kb":26244}
sleep workload /sleep?ms=1, 500 connections, 20s: 2619271 requests: {"fibers":500,"idle":499,"mem":10245448,"mem_real":12582912,"rss_kb":46976}
cpu workload /cpu 5s: 13155 requests:            {"fibers":500,"mem":10219816,"mem_real":12582912,"rss_kb":43168}
hello again 3s: 469197 requests:                 {"fibers":500,"mem":10219400,"mem_real":12582912,"rss_kb":33032}
```

| phase | requests | RSS | PHP heap (`memory_get_usage`) |
|---|---|---|---|
| post warm-up | 0.76M done | 26.9 MB | 1,652,312 B |
| +1.5M hello | 2.26M | **26.2 MB (−2.5%)** | **1,652,432 B (flat to the byte across samples 1–3)** |
| +2.6M `/sleep?ms=1` at 500 conns | 4.88M | 47.0 MB | 10.2 MB (pool grew 64 → 500 fibers: 8 MB of 16 KiB VM stacks + objects) |
| +13k `/cpu`, +0.47M hello | 5.36M | 33.0 MB | 10.2 MB (flat) |

E3 target "±2% over 1M requests": RSS did not grow at all over 1.5M requests (it fell 2.5%); the PHP heap was flat to the byte → **CONFIRMED**. The only step is the fiber pool growing to the peak concurrency of the sleep workload; afterwards nothing grows. Also observed: `/sleep?ms=1` at 500 connections sustained **131k req/s** on one PHP thread (2.62M in 20 s) — timers through tokio are not a bottleneck.

Caveat: 1 thread, ~7 minutes of traffic, no PDO/streams yet; E3 must be re-run when E6 (streams) and E13 (state swap) land, since those add per-request allocations.

## V-11 — H13 (E13): fiber-scoped superglobals via the fiber-switch observer (CONFIRMED)

Date: 2026-09-16T01:4xZ. Build: release, observer registered at MINIT (`IGNIS_NO_SUPERGLOBALS=1` disables it). Saved state lives in `zend_fiber_context.reserved[slot]` (slot from `zend_get_resource_handle`), a HashMap version was measured first and replaced.

(b) in-process: `./target/release/ignis bench/php/e13_isolation.php`
```
e13_isolation fibers=3 checks=300 mismatches=0 main_leak=0
```
Three fibers interleaved 50 times each through `Ignis\sleep(1)`; each always saw its own `$_GET['x']`, `$_SERVER['REQUEST_URI']`, `$_COOKIE['sid']`, `Ignis\Scope` value; writes to `$_GET` did not leak; `{main}` never saw any of them.

(a) over HTTP: `bench/e13-http.sh` (200 concurrent `curl "/echo?x=i&ms=20"`, handler sleeps twice and returns `$_GET['x']`, `$_SERVER['REQUEST_URI']`, `Ignis\Scope::get('x')`)
```
e13_http n=200 mismatches=0
```

(c) cost, `bench/php/e13_switch_cost.php` (1M `Fiber::suspend`/`resume` pairs, 2 runs each):

| variant | ns per switch |
|---|---|
| observer off | 51–55 |
| observer on, HashMap keyed by context (first version) | 183–193 |
| **observer on, reserved-slot storage (final)** | **152–155** → +100 ns per switch |

Amortised in the pooled 10k-fiber workload (E2 warm per job, 4 switches per job; 3 runs each): off 5.2–5.9 µs, on 7.2–7.6 µs → ≈ +0.5 µs per switch (cache effects on 10k live contexts). E1 warm round: 1046 → 1066 ms. Hello-world throughput unchanged (134k req/s with the observer, V-5/V-6 range).

Target < 1 µs per switch → CONFIRMED (marginal in the pooled case). Follow-up E13': swap lazily (only when some fiber on the thread has ever set superglobals) so compute-only fibers pay nothing. Limitation (research 05): `PG(http_globals)` is not swapped; `filter_input()`/phar read thread state.

## V-12 — H14 (E6, tcp) and H14b (E6, sqlite): unmodified stream I/O suspends the fiber (H14 CONFIRMED, H14b REFUTED for hooks)

Date: 2026-09-16T02:2xZ. Build: release; `tcp://` factory replaced at MINIT (ADR-0007), `IGNIS_NO_STREAM_HOOK=1` restores the stock transport.
Test: `bench/e6-fetch.sh` — `/fetch?ms=200` runs three **unmodified** `file_get_contents("http://127.0.0.1:8080/sleep?ms=200")` via `Ignis\all()`; the server serves its own `/sleep` on the **same single PHP thread**, so this can only complete if the calls suspend.

```
single:
{"bodies":["slept\n","slept\n","slept\n"],"ms":202.9}
{"bodies":["slept\n","slept\n","slept\n"],"ms":202.6}
{"bodies":["slept\n","slept\n","slept\n"],"ms":202.5}
concurrent: n=100 ok=100 wall_ms=361      per-request ms: min 207, max 235.8
```

| check | result | target |
|---|---|---|
| 3 × 200 ms fetches in one handler, 1 thread | **202.5–202.9 ms**, bodies correct | < 260 ms → CONFIRMED |
| 100 concurrent `/fetch` (300 self-requests + 300 sleeps in flight on 1 thread) | 100/100 ok, 207–236 ms each, 361 ms wall | 0 failures → CONFIRMED |
| blocking fallback: `file_get_contents` from `{main}` (no fiber), hook installed | body ok, 102.8 ms for a 100 ms sleep, `in_fiber=0` | works → CONFIRMED |
| negative control: `IGNIS_NO_STREAM_HOOK=1`, 1 thread, `/fetch?ms=200` | `curl -m 5` exit 28 (timeout): the thread blocks in `connect()`/`read()` and can never serve its own request | must stall → CONFIRMED (proves the hook is what makes it work) |
| throughput `wrk -t1 -c32 -d5s /fetch?ms=1` | 1,657 req/s (≈ 5k hooked HTTP fetches/s + 5k timers/s on one thread), p99 11.4 ms | — |

Unit tests: 9/9 (new `tcp_connect_write_read_close` reactor test against a tokio echo server).

**H14b (PDO sqlite) REFUTED for the hook approach**: `ext/pdo_sqlite` and `ext/sqlite3` use `php_stream` only for blob streams (`openBlob`), never for database I/O; libsqlite3 (system, dynamically linked: `libsqlite3.so.0`) does its own `read`/`pread`/`fsync` on the database file inside the calling thread. There is no transport or wrapper layer to intercept; a blocking sqlite query stalls the PHP thread (and every fiber on it). The honest options are a blocking-call offload pool (run the PDO call on a helper thread, suspend the fiber, resume with the result — only sound for operations that do not touch Zend state, which PDO does, so it needs a per-connection worker thread model) or the native pgsql driver path (E14 via tokio-postgres). Recorded; not attempted tonight.

## V-13 — H15 (E7): Revolt/AMPHP examples unchanged on `Ignis\Revolt\IgnisDriver` (CONFIRMED)

Date: 2026-09-16T03:2xZ. Command: `bench/e7-revolt.sh` — every script is run twice by the **same ignis binary**, once with `REVOLT_DRIVER=Revolt\EventLoop\Driver\StreamSelectDriver` and once with `REVOLT_DRIVER=Ignis\Revolt\IgnisDriver`; outputs are compared byte-for-byte. No example source was modified (a `vendor` symlink for their relative autoload path, and an `auto_prepend_file` defining the CLI `STDIN/STDOUT/STDERR` constants the embed SAPI lacks). AMPHP runs with the `tcp://` hook off (`IGNIS_NO_STREAM_HOOK=1`, ADR-0008) because its socket layer owns its I/O and needs real fds. libphp rebuilt with `filter`, `ctype`, `tokenizer` (league/uri needs `filter_var`).

Packages (composer `--prefer-source` via the git proxy; dist downloads are blocked): revolt/event-loop 1.x, amphp/amp 3.x, amphp/socket 2.4.1 (+ amphp/dns, byte-stream, league/uri…).

| example | select vs ignis | note |
|---|---|---|
| revolt `timers.php` (repeat + delay + suspension) | SAME | |
| revolt `ticks.php` (defer/queue ordering) | SAME | |
| revolt `fiber-local-automatic.php` | SAME | |
| revolt `fiber-local-manual.php` | **DIFFER** | timing race in the example: `{main}`'s 3 s timer and the callback's 1+1+1 s timer fall due in the same millisecond; Ignis's ms-granularity poll delivers both in one tick so "3: Done." prints before main exits, select's float timeout returns to main first and the script ends. CLI php 8.4 + select matches the select run. Both interleavings are valid Revolt semantics. |
| revolt `consume-stdin.php` (onReadable on STDIN from a file) | SAME ("23 bytes") | needed the regular-file fix: epoll returns EPERM for files, they are reported ready immediately |
| revolt `invalid-callback-return.php` | SAME (both fail on `SIGINT`: no pcntl in this build) | |
| amphp `amp-delay-async.php` (`async()` × 3 + `delay(0.2)` + `Future\await`) | SAME ("abc in 200 ms bucket") | |
| amphp `amp-socket-client.php` (`Amp\Socket\connect('tcp://127.0.0.1:8080')`, write, read loop) | SAME (`status=200 body="Hello, World!\n"`) | real TCP through onReadable/onWritable → `ignis_watch` → tokio `AsyncFd`, against the Ignis hello server |

Benchmarks (wall, same binary): `benchmark-timers.php` select 740 ms / **ignis 723 ms**; `benchmark-ticks-delay.php` 227 / **225 ms**; `benchmark-timers-delay.php` 342 / **325 ms** (target ≤ 2×: CONFIRMED, it is ≤ 1×).

Result: 7/8 byte-identical, the 8th a documented timing race → H15 CONFIRMED. The driver is 122 lines of PHP: ADR-0001 (c)'s claim that the loop shape is Revolt-compatible holds literally.

## V-14 — H16 (E11): client disconnect cancels the request fiber and its children; per-request deadline (CONFIRMED)

Date: 2026-09-16T03:5xZ. Command: `bench/e11-cancel.sh` (1 PHP thread, release). `/slow` sleeps 5 s and spawns an `Ignis\async` child sleeping 5 s, with a `finally` that counts; 20 clients (`curl -m 0.2`) disconnect after 200 ms.

```
(a) after the disconnects (+300 ms):
{"resumes":82,"fibers":40,"idle":39,"cancelled":20,"cancel_age_us_max":760,"cancel_latency_us_max":781,"slow_finally_ran":20}
(b) /deadline?ms=100 around Ignis\sleep(1000):  status=504 time=102.1 ms / 102.3 ms / 102.2 ms
(c) /fetch?ms=100 afterwards: {"bodies":["slept\n","slept\n","slept\n"],"ms":102.6}
(d) 5.5 s later: {"resumes":100,"fibers":40,"idle":39,"cancelled":23,"slow_finally_ran":20}
```

| check | result | target |
|---|---|---|
| requests cancelled on disconnect | 20/20; child fibers cancelled too (40 pooled fibers, 39 idle while `/stats` runs) | all |
| `finally` blocks executed (unwinding, not killing) | 20/20 | — |
| cancel latency, hyper drop → PHP throw (worst of 20) | **0.78 ms** (0.76 ms of it is the poll wake-up; the throw itself is µs) | ≤ 10 ms → CONFIRMED |
| no phantom work | 5.5 s later `resumes` grew only by the 3 deadline requests + stats; the 5 s sleeps never resumed | CONFIRMED |
| `Ignis\deadline(100)` on a 1000 ms handler | 504 in **102.1–102.3 ms** | 100–130 ms → CONFIRMED |
| E6 after cancellations | works (102.6 ms) | green → CONFIRMED |

Mechanism (ADR-0009): a `Drop` guard in hyper's service future emits `Outcome::Cancelled { dropped_at }`; the loop throws `Ignis\CancelledException` into the request fiber and every child spawned under its fiber-scoped request id (`Fiber::throw` for userland parks, `ignis_cancel_parked_any` → `zend_fiber_resume_exception` for C stream parks). Deadlines are timer ops tagged with the request id; `DeadlineExceededException` becomes 504.

## V-15 — H17 (E5'): least-inflight dispatch; two multi-thread bugs found and fixed on the way (CONFIRMED)

Date: 2026-09-16T04:3xZ. Build: release, ADR-0010 dispatch. Load: `wrk -t1 -c64 -d10s` on `/cpu`, `wrk -t2 -c64 -d10s` on `/`, server `--threads 4`, same box.

| metric | round-robin (V-9) | **least-inflight** | FrankenPHP@4 workers (V-9) | target |
|---|---|---|---|---|
| `/cpu` req/s | 9,285 | **9,124 / 9,321** | 7,278 | ≥ 9k → CONFIRMED |
| `/cpu` p99 | 17.5 ms | **12.75 / 12.37 ms** | 15.6 ms | ≤ 15.6 ms → CONFIRMED |
| hello req/s, 4 threads | 105,200 | **112,511** (p99 2.41 ms) | 16,583 | no regression → CONFIRMED |
| `bench/e13-http.sh` at 4 threads | — | 200/200 correct | — | — |

Two bugs surfaced only under sustained 4-thread load (the earlier 4-thread numbers in V-9 predate the superglobals observer, streams and cancellation):

1. **Bind race** (`http.rs`): several PHP threads called `ignis_serve` at once; the bind-once check was not atomic, the loser threw `EADDRINUSE`, its script died, and its already-registered reactor kept receiving requests nobody answered (wrk: hundreds of thousands of write errors). Fixed: bind under a mutex; a thread whose script ends now deregisters its reactor.
2. **Heap corruption from a forged refcount flag** (`superglobals.rs`): `ignis_set_superglobals` stored its array arguments with `IS_ARRAY_EX` (refcounted) type flags regardless of the array. A literal `[]` is the process-shared immutable `zend_empty_array`; four threads incrementing/decrementing its refcount concurrently corrupted it → `zend_mm_heap corrupted` after a few seconds of load. Bisected by disabling subsystems (`IGNIS_NO_SUPERGLOBALS=1` survived, `IGNIS_NO_STREAM_HOOK=1` died). Fixed by copying the argument zvals verbatim (their flags are authoritative). Lesson recorded in DECISIONS.md: never construct a zval's type_info by hand for data PHP handed us.

Both fixed versions ran 2 × 10 s of `/cpu` + 10 s hello at 4 threads with 0 socket errors and no abort.

## V-16 — H18 (E8): symfony/skeleton in worker mode on Ignis with a fiber-scoped RequestStack (CONFIRMED, sessions caveat)

Date: 2026-09-16T05:3xZ. Setup: `symfony/skeleton` (7.x) + `symfony/runtime` installed from source (`composer create-project --prefer-source`); skeleton sources untouched except (1) one service override in `config/services.yaml` (`request_stack` → `Ignis\Symfony\FiberRequestStack`), (2) a demo controller, (3) a PSR-4 autoload line for the adapter. Entry: `php/symfony/worker.php` sets `SCRIPT_FILENAME` and `APP_RUNTIME=Ignis\Symfony\IgnisRuntime` and requires the untouched `public/index.php`. Kernel booted once (`APP_ENV=prod`, warmed cache). Command: `bench/e8-symfony.sh` (1 PHP thread).

```
== /                       HTTP/1.1 200 OK   Hello from Symfony on Ignis
e8 whoami n=100 mismatches=0
== hello throughput (wrk -t2 -c64 -d10s)   99% 12.65ms   Requests/sec: 7769.76
server alive   log critical/fatal lines: 0
```

| check | result | target |
|---|---|---|
| skeleton boots once under the Ignis runtime and serves `/` | 200, body from the controller | boots → CONFIRMED |
| 100 concurrent `/whoami?tag=i&ms=200` (controller reads `RequestStack::getCurrentRequest()`, `Ignis\sleep(200)`, reads it again) | **0 mismatches**: every fiber saw its own Request before and after suspending while 99 others were in flight | 0 → CONFIRMED |
| hello-world through the full Symfony kernel, worker mode, 1 thread | **7,770 req/s**, p99 12.65 ms (V-6: php-fpm+nginx hello *without* a framework = 9.9k; FrankenPHP worker hello 27.6k) | ≥ 5k → CONFIRMED |

Caveats (honest):
- `framework.session` was **disabled** for this run: the libphp used tonight was built without `ext-session` (and `iconv`); a rebuild with both is in progress and the run is repeated with sessions on (see addendum below when present).
- `intl` is not built (Symfony logs a deprecation for performance); `iconv` absent until the rebuild.
- The runner maps one `Set-Cookie` header only; multi-cookie responses need the multi-value header path in `ignis_respond` (E8').
- Debugging note: the first attempt overrode the `RequestStack` *class alias* in services.yaml, which created a second instance (controller got the fiber-scoped one, `HttpKernel` kept the original) — the `request_stack` service id must be overridden.

## V-17 — H19 (E12): thread isolation, supervisor respawn, watchdog (CONFIRMED)

Date: 2026-09-16T05:5xZ. Command: `bench/e12-isolation.sh` — `./target/release/ignis --threads 4 --supervise examples/hello_server.php` (thread 0 supervises, workers 1–4 serve).

```
baseline: {"threads":4,"stalled":0,"restarts":0}   hello 133,153 req/s
(a) /fatal (E_USER_ERROR → bailout) during a 5 s hello load:
    fatal request http=000 (connection closed, no response)   during: {"threads":4,"stalled":0,"restarts":1}
    hello during the fault: 134,451 req/s, p99 2.06 ms, 0 errors
    log: WARN worker script ended; respawning (opcache SHM untouched) slot=3 status=255
(b) /spin?s=5 (CPU loop, no suspension point) on one worker:
    hello on the other threads: 90,108 req/s, p99 2.67 ms, 0 errors   during spin: {"stalled":1}
    log: WARN php threads busy for > 1 s without polling stalled=1 total=4 … stalled=0
(c) recovery: 127,384 req/s (95.7% of baseline)   final: {"threads":4,"stalled":0,"restarts":1}   server alive
```

| check | result | target |
|---|---|---|
| fatal kills only its thread; others serve | 1 worker ended, hello uninterrupted (134k during) | 1 thread → CONFIRMED |
| respawn without opcache reset | restarts=1, threads back to 4 within the 50 ms supervisor tick; no recompilation (opcache SHM is process-wide) | < 1 s → CONFIRMED |
| CPU loop stalls one thread only; watchdog sees it | p99 on the rest 2.67 ms; `stalled=1` reported, cleared after | < 20 ms → CONFIRMED |
| throughput after fault + stall | 95.7% of baseline | ≥ 80% → CONFIRMED |

Caveats: the request that triggered the fatal gets a closed connection (http 000), not a 500 — the dying thread's responders drop and hyper's error path closes; mapping that to 500 is a small follow-up. Requests in flight on the dying thread are lost the same way (E12'). A segfault in C would still take the process down (pain map: "remains true").

### V-16 addendum — sessions enabled, libphp rebuilt with `session` + `iconv` (CONFIRMED)

Date: 2026-09-16T06:0xZ. `config/packages/framework.yaml` restored to the skeleton's `session: true`; cache re-warmed; `bench/e8-symfony.sh` at 1 and 4 threads.

| threads | `/whoami` interleaving (100 concurrent) | hello through the kernel, `wrk -t2 -c64 -d10s` |
|---|---|---|
| 1 | 0 mismatches | **7,205 req/s**, p99 11.74 ms |
| 4 (`--threads 4`) | 0 mismatches | **25,201 req/s**, p99 6.56 ms |

Server alive, 0 critical/fatal log lines. The skeleton is now byte-untouched apart from the `request_stack` service override, the demo controller and the adapter autoload line. `scripts/build-php.sh` carries `--enable-session --with-iconv`.

### V-11 addendum — observer cost re-measured after Cycles 6–11 (variance noted)

Date: 2026-09-16T06:4xZ, idle box (load 0.14), `N=10000 ./target/release/ignis bench/php/e2_all.php` × 3 with the observer on vs `IGNIS_NO_SUPERGLOBALS=1`:

| | cold per fiber | warm per job |
|---|---|---|
| observer on | 23.7 (under perf) / 25.6 / 55.1 / 79.2 µs | 8.0–11.4 µs |
| observer off | 17.9 µs | 4.5 µs |

The warm delta grew from ≈ +2 µs/job (V-11) to +3.5–7 µs/job, and the cold figure now varies 3×. The profile (`perf record`, observer on) shows `zend_fiber_object_gc` at 2.9%: PHP's cycle collector runs when its root buffer fills, and each run walks every pooled fiber; when that happens inside the timed window the per-fiber figure jumps. The observer's saved zvals add refcount traffic that feeds the root buffer. Consequence: E2' (< 5 µs warm) is **not met with the observer on** — it holds only with the observer off. The planned fix is E13' (lazy swap: no bookkeeping for fibers that never touch superglobals) plus scheduling `gc_collect_cycles()` from the loop's idle point instead of inside a request (RoadRunner pain-map item 2, "GC scheduled by the runtime off the hot path").

## V-18 — H20 (E9, step 1): temporal-sdk-core in a Rust binary completes a workflow against a local dev server (CONFIRMED)

Date: 2026-09-16T01:13Z. Setup: `temporalio/sdk-core` git (workspace crates `temporalio-sdk-core`, `-client`, `-protos`, `-common`; edition 2024; `protoc` from apt) linked into `examples/rust/temporal-probe` (346 MB debug binary, ~600 crates, first build ≈ 12 min on 4 vCPU). Dev server: `temporal server start-dev` from `temporalio/cli` built in-tree with Go (`go install @latest` is refused because of replace directives) — "temporal version 0.0.0-DEV (Server 1.32.0, UI 2.54.1)", ready in 600 ms, in-memory persistence. Command: `bench/e9-probe.sh`.

```
dev server up
  Namespace   default        TaskQueue   ignis            (temporal workflow start --type IgnisProbeWorkflow --workflow-id ignis-probe-1)
worker up; waiting for an activation on task queue 'ignis'
activation run_id=01a0a7c6-3ac3-72d9-acbe-30cb6287749f jobs=1
completed run_id=01a0a7c6-3ac3-72d9-acbe-30cb6287749f
temporal workflow describe:   Status COMPLETED   Result {"data":"ImRvbmUgYnkgaWduaXMgcHJvYmUi"}   (= "done by ignis probe")
```

| check | result |
|---|---|
| sdk-core builds here | yes (git dependency; the crates.io `temporal-sdk-core 0.1.0-alpha.1` is a 2021 crate with a different API — do not use) |
| worker polls the first activation of a CLI-started workflow | 1 job (initialize workflow) received |
| completion with `CompleteWorkflowExecution` accepted | run shows COMPLETED with the payload |

API notes for the Ignis integration (ADR-0013): `ConnectionOptions::new(url).client_name(..).client_version(..).identity(..).build()` → `Connection::connect(opts)`; `WorkerConfig::builder().namespace().task_queue().task_types(WorkerTaskTypes::workflow_only()).versioning_strategy(WorkerVersioningStrategy::None { build_id }).build()?`; `init_worker(&CoreRuntime, cfg, connection)`; `poll_workflow_activation()` / `complete_workflow_activation(WorkflowActivationCompletion::from_cmds(run_id, vec![workflow_command::Variant::…]))` are inherent async methods on `Worker`. This is exactly sdk-python's bridge surface (research 12), so `Op::TemporalPoll`/`Op::TemporalComplete` map 1:1.

H20b (PHP workflow on fibers + replay) is the next step; not attempted in this cycle.

## V-19 — H20b (E9, step 2): a PHP workflow on fibers completes on the dev server and passes replay; a mutated workflow fails replay (CONFIRMED)

Date: 2026-09-16T01:37Z. Build: `cargo build --release -p ignis --features temporal` (sdk-core git crates linked into the ignis binary; `Op::Custom` carries the poll/complete futures; JSON boundary via `serde_serialize` on the protos plus Ignis's own PHP command schema translated in Rust). Workflow: `php/temporal/demo.php` — `greet` activity → 500 ms timer → `shout` activity; the workflow function runs as a PHP Fiber, `Context::activity()/timer()` record a command and `Fiber::suspend()`; activities run as ordinary Ignis fibers (one calls `Ignis\sleep(50)`). Command: `bench/e9-temporal.sh`.

```
== live run: 1224 ms from start to COMPLETED (includes 500 ms timer, two activities, CLI polling at 100 ms)
  Status          COMPLETED      ResultEncoding json/plain      ("HELLO ADA!")
history events: 22
== replay (history fetched by Rust over gRPC) — must pass
REPLAY_OK activations=5 eviction_errors=0
== replay with the timer removed (DEMO_MUTATE=1) — must fail
  evicted: reason=3 ... [TMPRL1100] Nondeterminism error: Activity machine does not handle this event: HistoryEvent(id: 11, TimerStarted) ... force_cause: NonDeterministicError
REPLAY_FAILED activations=3 eviction_errors=1
== live worker: 5 activations, 1 evictions   (the one eviction is reason=10 "Workflow completed")
```

| check | result |
|---|---|
| live workflow with two activities and a timer completes | COMPLETED in 1224 ms wall clock (500 ms of it is the timer; the rest is 4 workflow tasks + 2 activity tasks round-tripping through the dev server and 100 ms CLI polling) |
| the workflow fiber stays suspended between activations (sticky cache) | 5 activations, 0 replays during the live run: `InitializeWorkflow → ResolveActivity → FireTimer → ResolveActivity → RemoveFromCache(Workflow completed)` — with `max_cached_workflows = 0` (first attempt) core evicted after every task and the fiber was rebuilt by replay each time (14 activations for the same run) |
| replay of the recorded history (22 events, fetched over gRPC by `ignis_temporal_replay`) | REPLAY_OK, 5 activations, all `replaying=true` until the final eviction, 0 nondeterminism |
| negative control: same history, workflow code without the timer | REPLAY_FAILED at activation 3 with core's `NonDeterministicError` (TMPRL1100) — the replay test detects a changed workflow, so a passing replay means something |

What was wrong on the way (each a refuted sub-hypothesis, all fixed in this commit): (1) completing activations with hand-written JSON fails on prost's serde derive, which requires every field (`missing field headers`) — the PHP side now speaks a small command schema (`StartTimer`, `ScheduleActivity`, `CompleteWorkflow`, `FailWorkflow`) translated to protos in Rust; (2) the `temporal workflow show --output json` history is protojson (camelCase, string int64) and does not round-trip through prost serde (`missing field event_id`) — the replay worker fetches the history over gRPC (`GetWorkflowExecutionHistory`) and hands `HistoryForReplay` to `init_replay_worker`; (3) `Ignis\Loop::runUntil` routed any array payload with keys to `dispatchRequest`, so a `['kind' => 'error']` result from the Temporal ops was treated as an HTTP request — payload routing now checks the waiting map first, then cancellation, then `method`.

Not covered (prototype scope per ADR-0013): signals/queries/updates, cancellation, child workflows, local activities, heartbeats, retries beyond core defaults, payload codecs beyond `json/plain`, one worker per process. Binary size with the feature: the `temporal` build adds ≈ 600 crates and ≈ 90 s to the release build on this box.

## V-20 — H21/H21b/H21c (E10): gRPC unary + server-streaming handlers in PHP on the shared listener; client call parks the fiber; three-way comparison (CONFIRMED with one target restated)

Date: 2026-09-16T02:02:22Z. Build: `cargo build --release -p ignis` (tonic 0.14 with `server` + `channel` features only, no codegen; +28 crates in the closure: 89 vs 61 for a hyper-only tree). Load tool: `ghz` (Go) on the same 4-vCPU box, `-c 64 -n 100000 --connections 8`, request `HelloRequest{name:"ada"}`, reply `HelloReply{message:"hello ada"}`; the background C-core build was SIGSTOPped during every measurement. Commands: `bench/e10-grpc.sh`, `bench/e10-compare.sh`.

Functional (grpcurl, `examples/grpc/greeter.proto`, handlers in `examples/grpc_server.php`):

```
== grpcurl unary       {"message": "hello ada"}
== grpcurl streaming   {"i":3,"at":"01:53:44.846"}{"i":2,"at":"01:53:44.859"}{"i":1,"at":"01:53:44.870"}   (3 messages, 10 ms apart, order preserved)
== HTTP on the same port   Ignis gRPC demo: use grpcurl ...   (plain HTTP and gRPC share the listener)
```

Three servers, same load (`bench/e10-compare.sh`):

| server | req/s | avg | p99 | max | OK |
|---|---|---|---|---|---|
| **Ignis, PHP handler, 1 PHP thread** | **16.7k** | 2.90 ms | **7.8 ms** | 15.8 ms | 100000/100000 |
| Ignis, PHP handler, 4 PHP threads | 17.6k | 2.74 ms | 8.0 ms | 16.7 ms | 100000/100000 |
| pure tonic, Rust handler, same codec + listener code (`examples/rust/grpc-baseline`) — the ceiling | 21.1k | 2.02 ms | 6.3 ms | 12.4 ms | 100000/100000 |
| RoadRunner v2025 grpc plugin, 1 PHP worker (php 8.4 NTS, google/protobuf pure PHP) | 5.1k | 12.3 ms | 17.8 ms | 28.0 ms | 100000/100000 |
| RoadRunner grpc plugin, 4 PHP workers | 10.2k | 5.57 ms | 12.6 ms | 26.6 ms | 100000/100000 |
| RoadRunner grpc plugin, 16 PHP workers | 11.4k | 4.50 ms | 13.4 ms | 45.6 ms | 100000/100000 |

100 concurrent calls to `Slow` (each sleeps 200 ms in the handler; `-c 100 -n 100`):

| server | total | slowest | avg |
|---|---|---|---|
| **Ignis, 1 PHP thread** (`Ignis\sleep` parks the fiber) | **215 ms** | 209 ms | 204 ms |
| RoadRunner, 4 workers (`usleep` blocks the worker) | 5.03 s | 5.02 s | 2.61 s |
| RoadRunner, 16 workers | 1.43 s | 1.42 s | 756 ms |

H21b (client call parks the fiber): 100 concurrent `Proxy` calls, each making a `Slow` call to the same server through `Ignis\Grpc\Client` (h2 channel owned by the runtime), 1 PHP thread: **217 ms total**, slowest 216 ms, 100/100 OK (three runs: 218.9 / 217.4 / 217.6 ms). Sequential would be 20 s.

Server-streaming under load (`Countdown n=3`, 3 messages 10 ms apart, `-c 64 -n 5000`): 1.8k calls/s, avg 34.6 ms, p99 38.3 ms, 5000/5000 OK; expected floor is 20 ms of sleeps + 3 message round trips.

| claim | result |
|---|---|
| unary + server-streaming handlers in PHP, same listener as HTTP | CONFIRMED (grpcurl and ghz; `Unimplemented` for unknown methods) |
| p99 < 5 ms at c=64 (H21 as written) | **NOT MET by anyone on this box**: the Rust-only ceiling is p99 6.3 ms because ghz shares the 4 vCPUs. Restated: Ignis p99 is 1.23× the pure-tonic ceiling and throughput is 79% of it with a PHP handler in the path; RoadRunner with 16 workers reaches 54% of the ceiling at 2.1× the p99. INCONCLUSIVE as an absolute number until measured with an external load box (same caveat as V-5). |
| client call suspends the fiber (H21b) | CONFIRMED: 100 × 200 ms in 217 ms on one thread |
| 4 PHP threads help unary hello | NO (17.6k vs 16.7k): the PHP hop is not the bottleneck at this load; ghz + tokio + hyper are |

Build complexity (H21c), measured on this box:

| | Ignis (tonic in-process) | RoadRunner grpc plugin | ext-grpc |
|---|---|---|---|
| toolchain | the Rust toolchain already required | Go 1.26 (auto-downloaded by Go 1.24 because the module demands ≥ 1.26.4); `go install …@latest` is refused (exclude directives), so clone + `go build ./cmd/rr` | C++ toolchain + cmake for grpc C-core (BoringSSL, abseil, protobuf, re2, c-ares, upb, zlib), then phpize; `pecl.php.net` is blocked here (403) and the in-tree ext `configure` fails without an installed C-core ("Please reinstall the grpc distribution") |
| build time here | +23 s incremental for the ignis binary; the baseline crate builds from scratch in 23.5 s | 91 s `go build` after module download (clone 1 min) | C-core: 778 objects compiled at 24% after 12 min at `nice -j2` — still building when this entry was written (final time added below when it finishes) |
| artifact | ignis binary 24.1 MB total (was 24.1 MB before: tonic shares hyper/h2) | `rr` 96 MB + php-cli + `spiral/roadrunner-grpc` + `google/protobuf` (pure PHP, or ext-protobuf for speed) + generated PHP classes (`protoc --php_out`; the RR `protoc-gen-php-grpc` plugin is not in the v2025 module tree, the interface was written by hand) | libgrpc + `grpc.so` (historically > 100 MB with debug info) — **client only**: ext-grpc has no server, so it cannot serve E10's handlers at all |
| what PHP code needs | `require php/grpc/ignis-grpc.php`, handlers as closures on `/pkg.Svc/Method`, opaque bytes (`google/protobuf` or the 60-line `Proto` helper) | worker.php with `Spiral\RoadRunner\GRPC\Server`, service classes implementing generated interfaces, `.rr.yaml` with the proto path | `Grpc\BaseStub` subclasses generated by `protoc-gen-grpc-php` |

Not covered: client-streaming/bidi, TLS, deadlines beyond tonic's `grpc-timeout`, client metadata, compression (ADR-0014).

## V-21 — H23 (E14): runtime-owned PostgreSQL pool with per-fiber leases (CONFIRMED)

Date: 2026-09-16T02:24:48Z. Setup: PostgreSQL 16.15 (Ubuntu package, default config, `max_connections = 100`) on 127.0.0.1, user/db `ignis`; `tokio-postgres 0.7` in the ignis binary (`crates/ignis/src/pg.rs`, ADR-0015), PHP API `php/pg/ignis-pg.php`. Command: `bench/e14-pg.sh` (runs `bench/php/e14_pg.php` twice). Load average during the run: 8.85 (the E15 porter, a C-core build at nice 19 and the FrankenPHP agent were running; every number below is therefore pessimistic).

```
== pool 20, 200 fibers
concurrency cold: 200 fibers x pg_sleep(0.1) through pool=20: 1230 ms (ideal 1000 ms) stats={"idle":20,"created":20,"available":20}
concurrency warm: 200 fibers x pg_sleep(0.1) through pool=20: 1046 ms (ideal 1000 ms)
transaction: pid 14640 == 14640 (same); second acquire: LeaseError in 38.6 us, ops submitted: 0; rows in temp table: 2
reset: same backend 14640: search_path="$user", public (leaked? no), temp table rows visible: 0
types: {"b":true,"f":1.5,"i":42,"j":{"k":[1,2]},"s":"h\u00e9llo","y":"AQID","z":null}
unsupported type is an error: pg: $1: unsupported parameter type numeric (cast it in SQL, e.g. $n::text)
per query: 112.1 us sequential on one lease; 476.4 us per pool->query (acquire+query+reset+release); 2000 concurrent queries in 272.5 ms = 7339 q/s on one PHP thread
== pool 50, 200 fibers
concurrency cold: 770 ms (ideal 400 ms)     concurrency warm: 429 ms (ideal 400 ms)
```

| E14 rule | result |
|---|---|
| pool owned by the runtime, lease per fiber | 200 fibers share 20 connections: **1046 ms warm** for 10 rounds of 100 ms sleeps (1230 ms cold, connects included); pool 50: 429 ms warm for 4 rounds. `created` never exceeds the pool size |
| a transaction pins the lease | both statements and the count on one backend pid; temp table visible inside, gone after release |
| second acquire in the same fiber is an error, not a wait | `LeaseError` in 36–39 µs with **0 ops submitted** (the check is a Scope lookup in PHP) |
| session reset on return | `SET search_path` inside a lease is not visible on the next lease of the same backend; the temp table is gone. Reset is `ROLLBACK; CLOSE ALL; SET SESSION AUTHORIZATION DEFAULT; RESET ALL; UNLISTEN *; pg_advisory_unlock_all(); DISCARD TEMP; DISCARD SEQUENCES` in one round trip — `DISCARD ALL` itself cannot run inside the implicit transaction block of a multi-statement string (first version dropped every connection because of that: 200 connects for 200 queries, 1982 ms) and it would also `DEALLOCATE ALL` the statement cache |
| types | int/text/bool/float8/jsonb/null/bytea round-trip; `numeric` parameters are a named error, not a silent string |
| per-query cost on one PHP thread | 112 µs sequential (prepared-statement cache per connection: 362 µs before it); 476 µs for acquire+query+reset+release (985 µs before the one-round-trip reset and the no-hop acquire); **7.3–8.1k q/s** with 2000 concurrent fibers |

Not measured yet: `pdo_pgsql` on the same box for the per-query comparison (needs `--with-pdo-pgsql` in the PHP build; the E15 suites are running against the current libphp, rebuild queued for bencher). Not covered: prepared statements across pools, `COPY`, `LISTEN/NOTIFY`, TLS, MySQL/Redis (ADR-0015).

## V-22 — Cycle 17: runtime defects found by the E15 ports, fixed and measured (CONFIRMED)

Date: 2026-09-16T02:54:47Z. Source of the defects: the Swoole runtime-hook port (research 15, model=main-spawned), the php-src phpt port (research 17, model=porter) and the FrankenPHP testdata port (research 16, model=main-spawned). Commands: `/tmp/c17a.php`, `/tmp/c17b.php` (kept as `bench/php/e15_fixes.php` below), `bench/e15-swoole.sh --all`.

| defect | fix | number |
|---|---|---|
| `stream_socket_server('tcp://…')` inside a fiber returned false (the tcp hook claimed server sockets and answered BIND/LISTEN with NOTIMPL) — 14 phpt tests + 8 Swoole tests | the factory leaves `STREAM_XPORT_SERVER` (= 1 in `php_stream_transport.h`; my first patch used 2 = CONNECT, which the porter caught before it shipped: it would have silently unhooked every client connect) on the stock transport | server in {main} (stock, `stream_type=tcp_socket`) + hooked client fiber (`stream_type=ignis_tcp`) on one thread exchange ping/pong; accept still blocks the thread like stock PHP |
| `Loop::runUntil()` returned while a fiber was parked inside a C stream op (userland wait map empty) — every socket phpt in fiber mode died silently with exit 0 | the idle check also requires `ignis_inflight() === 0` | the porter's fiber-mode socket tests run (see V-23) |
| `sleep()` / `usleep()` blocked the thread inside fibers (13 Swoole tests) | internal-function handlers swapped at MINIT (`crates/ignis/src/php/sleep.rs`): inside a fiber with a reactor they park on a µs timer op; outside they call the originals; `IGNIS_NO_SLEEP_HOOK=1` disables | **10 fibers × `usleep(200000)` = 201 ms** (blocking: 2000), **3 × `sleep(1)` = 1001 ms**; `usleep(20000)` outside a fiber = 20 ms via the original handler |
| an exception in a fiber whose Future nobody awaits was lost (hid the bytea failure in V-21's first run) | `Future` registers unobserved rejections; `Loop::run()` rethrows the first one when it stops; `await()` un-registers | `Ignis\async(fn() => throw …); Loop::run()` → the exception surfaces |
| `STDIN`/`STDOUT`/`STDERR` undefined under embed (13 of 15 main-mode phpt failures were this) | RINIT on every thread opens `php://stdin|stdout|stderr` and registers the three constants like sapi/cli | defined on main and worker threads, `fwrite(STDERR, …)` works |
| `PHP_BINARY === ''` | `executable_location` set from `current_exe()` before `php_embed_init` | **not yet effective** (still empty); open |

Swoole `swoole_runtime` (153 tests through `php/swoole/shim.php`), re-run by main after the first four fixes, under load average 25 (a C-core build and the phpt re-run shared the box, 15 tests hit the 20 s hang timeout): **PASS 44 / FAIL 79 / SKIP 30** (agent's pre-fix run: 42 / 81 / 30). The remaining blockers are the ones research 15 ranks: `Swoole\Coroutine\Socket` (36 tests), accept/stream_select inside fibers (19), file hooks (10), proc/pcntl (10), udp/unix transports (7). The sleep hook alone does not move the count because those tests also need the other hooks.

## V-23 — E15a/c/d compat suites, agent-ported, re-run by the main agent (PARTIAL: E15b/e pending)

Date: 2026-09-16T03:00:09Z. Ports: research 17 (php-src phpt, model=porter), research 15 (Swoole, model=main-spawned), research 16 (FrankenPHP, model=main-spawned). Every number below is from the main agent's own re-run on the live binary after the Cycle-17 fixes (V-22); the agents' pre-fix numbers are in their research docs.

**E15a — php-src suites through `scripts/ignis-php` + `run-tests.php`** (`bench/e15-phpt.sh`; stock = `/opt/php85-zts/bin/php`, main = test in `{main}` under ignis, fiber = test included inside an Ignis fiber with hooks active):

| suite | stock | ignis main | ignis fiber | main / stock |
|---|---|---|---|---|
| Zend/tests/fibers (110) | 108 pass, 0 fail, 2 skip | **108 / 0 / 2** | 77 / 31 / 2 | **100%** |
| ext/sockets/tests (118) | 80 / 0 / 38 | **80 / 0 / 38** | 75 / 5 / 38 | **100%** |
| ext/standard/tests/streams (160) | 138 / 0 / 22 | **131 / 7 / 22** | 116 / 22 / 22 | **94.9%** |

Before the Cycle-17 fixes (porter's pinned run): main 108 / 79 / 124, fiber 77 / 74 / 102 — the server-socket fix and the STDIN/STDOUT/STDERR constants moved 8 main-mode and 15 fiber-mode tests to PASS. H22a's "≥ 95% of stock in main mode" holds for fibers and sockets and misses streams by one test (131 vs 131.1). Stock fails nothing → no *upstream* bucket. Remaining main-mode failures (7, all classified in research 17): `PHP_BINARY` empty (1, open in V-22), `open_basedir` applied to the primary script (1), proc_open of the php binary / CLI built-in server (5, not applicable). Fiber-mode failures are dominated by harness artifacts of include-inside-fiber (stack-trace tail, function scope for `global`, object ids) — 41 of the 73 distinct failures; real ones left: `stream_socket_get_name()`/`stream_select()` on hooked streams (`OP_GET_NAME` NOTIMPL, `cast` FAILURE) and `stream_type` reported as `ignis_tcp` instead of `tcp_socket`.

**E15c — Swoole `tests/swoole_runtime` (153 tests) through `php/swoole/shim.php`** (`bench/e15-swoole.sh --all`): **PASS 44 / FAIL 79 / SKIP 30** (agent, pre-fix: 42 / 81 / 30; first re-run under load 25; a quiet re-run at load 3.4 at 03:47Z gave the identical 44 / 79 / 30, so the count is stable and the 15 hangs are real waits on missing hooks, not load). The hooks Ignis lacks, by tests blocked: `Swoole\Coroutine\Socket` / ext-sockets (36), accept and `stream_select` inside fibers (19), file hooks (10), proc/pcntl (10), udp/unix/udg transports (7); `sleep`/`usleep` are hooked now (V-22), `Runtime::enableCoroutine`/`Co\run`/`go`/`Co::sleep`/`WaitGroup`/`Channel`/`Timer` exist in the shim. Skips: 18 external hosts, 5 missing extensions (openssl/curl/pcntl), 7 Redis/MySQL/FTP fixtures.

**E15d — FrankenPHP `testdata/*.php` through `php/classic.php`** (`bench/e15-frankenphp.sh`; three consecutive runs identical): **passed 29 / failed 4 / skipped 33**. The four failures are runtime gaps, not adapter bugs: no peer address in the request payload (`REMOTE_ADDR`/`REMOTE_PORT`), a single `Cookie` header kept and no PHP-style cookie-name mangling, `putenv()` persisting across requests, no multipart/`$_FILES`. Skips: 18 worker-mode/resident-state tests, 5 FrankenPHP-only functions, 8 Caddy directives or php.ini variants, 2 fixtures not reproducible over plain HTTP. The classic-mode adapter itself (script included per request in the fiber, output buffered, `header()`/`http_response_code()` mapped, `php://input` userland wrapper) is a new deliverable: Ignis can serve classic scripts.

E15b (Revolt DriverTest) and E15e (Symfony/Doctrine chaos mode) are in progress / not started; CI (`.github/workflows/ci.yml`) now runs the phpt, Swoole and FrankenPHP suites with `scripts/ci-gate.sh` guarding these pass counts.

## V-23 addendum — E15b Revolt `DriverTest` on `IgnisDriver` (CONFIRMED after two driver fixes)

Date: 2026-09-16T03:20:33Z. Port: research 19 (model=porter): `php/amphp/test/IgnisDriverTest.php` extends Revolt's abstract `DriverTest` (3 tests repaired with `#[DataProvider]` because revolt ships a PHPUnit-9 suite; the same repaired class runs over `StreamSelectDriver` as the baseline). Runner: `bench/e15-revolt.sh` — phpunit 12 runs *inside* the ignis binary (needed: `$argv`, shebang skip, `$_SERVER['PHP_SELF']`, STDIN/STDOUT/STDERR — all added this night).

| run (main agent's own) | result |
|---|---|
| stock CLI, `StreamSelectDriver` (baseline) | Tests: 81, Assertions: 222, Errors: 1, Skipped: 8 |
| ignis, `IgnisDriver`, before the fixes (3 runs) | 81 / 222 / Errors 1 / **Failures 1–2** / Skipped 8 |
| ignis, `IgnisDriver`, after the fixes (3 runs) | **81 / 222 / Errors 1 / Failures 0 / Skipped 8** — identical to the baseline |

The porter's root cause held: a one-shot `ignis_watch` is completed on the tokio side, so an fd that is *already* ready was not dispatched in the tick that armed it (`ignis_poll(0)` returned first). Fixes: (1) `ignis_watch` probes the fd with `poll(2)` timeout 0 and completes the op synchronously when it is ready; (2) `ignis_cancel(op)` (new `Op::CancelWatch`) aborts the tokio watch task from `IgnisDriver::deactivate()`, which closes the dup'd fd — the porter's probe (200 arm/cancel cycles) went from **+110 fds to +0**. Classification: the 1 error is upstream (`testNoMemoryLeak` uses `getTestResultObject()`, removed in PHPUnit 10; identical on the baseline); the 8 skips are signal tests (ext-posix not built; identical on the baseline); signals remain unsupported in `IgnisDriver` (`UnsupportedFeatureException`). One of the four environment variants (`IGNIS_NO_STREAM_HOOK=1`) still showed a single timing failure in one run; the default configuration was clean 3/3.

## V-24 — H24 (E16, part 1): offload pool of synchronous PHP threads (CONFIRMED for the pool; pdo_pgsql/curl routing pending the libphp rebuild)

Date: 2026-09-16T03:20:33Z. Command: `bench/e16-offload.sh 8` (`ignis --offload 8 bench/php/e16_offload.php`, prelude `bench/php/e16_prelude.php`); load average 7 (a C-core build in the background).

```
blocking calls: 100 x 200 ms through 8 workers: 2608 ms wall (bound = ceil(100/8) x 200 = 2600 ms); distinct worker threads used: 8; fiber thread ticked 233 times (10 ms sleeps) meanwhile
copy overhead: empty args, 2000 sequential calls: 13.3 us per call round trip (serialized size 6 B)
copy overhead: 1KB args, 2000 sequential calls: 27.2 us per call round trip (serialized size 1597 B)
copy overhead: 64KB args, 2000 sequential calls: 431.0 us per call round trip (serialized size 75699 B)
callbacks: worker called back 5 times, sum=30 (expect 30), callbacks run on the caller: 5
exception: DomainException(boom from the worker, code 42) propagated as RemoteException
--offload 100: 100 x 200 ms through 100 workers: 243 ms wall (bound 200 ms)
```

| claim | result |
|---|---|
| wall time is bounded by the pool size, never by the fiber thread | 8 workers: **2608 ms** for 100 × 200 ms (bound 2600); 100 workers: **243 ms** (bound 200; thread start-up and 100 TSRM contexts); the fiber thread kept ticking (233 × 10 ms sleeps during the 2.6 s) |
| copy-in/copy-out cost per call | **13 µs** (no args), **27 µs** (1 KB array), 431 µs (64 KB): `serialize` + crossbeam channel + `unserialize`, both ways, plus one reactor hop. For scale: a native pool query is 112 µs (V-21) |
| callbacks from the worker run on the calling thread | 5/5, in a fiber of the caller, worker blocked meanwhile |
| exceptions | class/message/code/trace cross back as `RemoteException` |
| shutdown | workers leave their PHP request before `php_embed_shutdown` (poison job + join); the first version aborted with `zend_mm_heap corrupted` at exit |

Second run of the same script (03:30Z, load average 3.4, right before the E16 commit; source of the ranges quoted in HYPOTHESES/STATUS):

```
blocking calls: 100 x 200 ms through 8 workers: 2604 ms wall (bound = ceil(100/8) x 200 = 2600 ms); distinct worker threads used: 8; fiber thread ticked 229 times (10 ms sleeps) meanwhile
copy overhead: 1KB args, 2000 sequential calls: 67.4 us per call round trip (serialized size 1597 B)
blocking calls: 100 x 200 ms through 100 workers: 291 ms wall (bound = ceil(100/100) x 200 = 200 ms); distinct worker threads used: 100; fiber thread ticked 25 times (10 ms sleeps) meanwhile
```

So: 8 workers 2604–2608 ms, 100 workers 243–291 ms, 1 KB copy 27–67 µs across two runs on a loaded box.

Auto-routing (`curl_*`/`PDO`/`SQLite3`) is in the V-24 addendum below.

## V-24 addendum — H24 (E16, part 2): config-driven auto-routing with no code changes (CONFIRMED)

Date: 2026-09-16T03:35:12Z. libphp rebuilt with `--with-pdo-pgsql --with-pgsql --with-curl --with-openssl` (`scripts/build-php.sh`, 412 s at `-j2`). Routing: `IGNIS_OFFLOAD_FUNCTIONS` (default: the `curl_*` family) get a trampoline handler at MINIT (`crates/ignis/src/php/route.rs`); `IGNIS_OFFLOAD_CLASSES` (default `PDO,SQLite3`) get a `create_object` hook; inside a fiber on a thread with a reactor the call goes to `Ignis\Offload\Router` (PHP), which runs it on an offload worker pinned to the handle and gives the fiber a proxy (`Ignis\Offload\Proxy\PDO extends PDO`, generated by reflection with LSP-compatible signatures, so `instanceof` and class constants work; final classes such as `CurlHandle` get a `Handle` with `__call`). Outside fibers and on the workers the originals run. Command: `bench/e16-offload.sh 8` (two runs, load average 3–7; the numbers move with the box).

```
sqlite3: object is Ignis\Offload\Proxy\SQLite3, instanceof SQLite3: true
sqlite3: [{"id":1,"name":"ada"},{"id":2,"name":"grace"}] (result proxy Ignis\Offload\Proxy\SQLite3Result), routed calls so far: 7
pdo_pgsql auto-routed: 100 x 200 ms `new PDO` + query in fibers, 8 workers: 3454 ms wall (bound ceil(100/8) x 200 = 2600 ms); distinct backend pids: 100
pdo_pgsql auto-routed:  64 x 200 ms `new PDO` + query in fibers, 8 workers: 2019 ms wall (bound 1600 ms); distinct backend pids: 64
curl_exec: true, http 200, WRITEFUNCTION called 1 time(s) on the caller for 14 bytes (errno 0 )
routed call overhead: curl_getinfo x 1000 = 16.7 us per call (second run: 45.4 us)
```

| brief item | result |
|---|---|
| 100 concurrent pdo_pgsql queries of 200 ms on one fiber thread, 8-thread pool: limited by the pool, never by the fiber thread | **3454 ms** wall (64 fibers: 2019 ms). The ideal 2600 ms assumes only the 100 sleeps; each fiber also does `new PDO` (100 SCRAM handshakes, 100 distinct backends) and `fetch()`, three routed jobs per fiber through the same 8 workers. The fiber thread was free throughout (the pool test in V-24 shows it ticking). PostgreSQL `max_connections` raised to 300 locally for the 100 simultaneous connections; CI runs this step with `FIBERS=64` |
| `curl_exec` with `CURLOPT_WRITEFUNCTION` works | the closure runs on the calling thread, in a fiber, once per chunk (1 × 14 bytes here); the worker's stub blocks on `ignis_offload_callback` meanwhile; the `CurlHandle` argument reaches the closure as a `Handle` ref |
| copy overhead per call | plain offload: 13 µs (no args), 27–67 µs (1 KB) — V-24; routed call (`curl_getinfo`, proxy → worker → back): **17–45 µs** |
| no code changes | `new SQLite3(':memory:')`, `new PDO($dsn)`, `curl_init()` in ordinary PHP inside a fiber; the seam is `$db::class` = `Ignis\Offload\Proxy\SQLite3` (`instanceof SQLite3` true) and `curl_init()` returning a `Handle` instead of a `CurlHandle` |

Bugs found on the way: proxies inherited the parent's `default_object_handlers` (SQLite3's `free_obj` on a bare object → segfault; now `std_object_handlers`); internal classes with real (non-tentative) return types need them copied onto the generated overrides; the embedded worker loop is compiled into the binary (`include_str!`), so a PHP-side change there needs a `cargo build`.

## V-20 corrections (from the bencher's measured table, `bench/results/e10-build-complexity.md`)

- ext-grpc **does build and load on PHP 8.5.10 ZTS** (`grpc module version => 1.85.0dev`): C-core 4648 s (77 min, `nice -j2`, 3066 objects, 277 MB installed, `libgrpc.a` 49.7 MiB), the PHP extension 11.9 s on top, `grpc.so` 47.6 MiB (19.5 MiB stripped). It needed three workarounds: pecl is blocked, `configure` fails against a static C-core (`-lgrpc` alone), and libtool strips `--start-group` so the `.so` had to be linked by hand. Correction to the table's wording: not "client only" but **no supported server**: `Grpc\Server` primitives exist (`requestCall` is a blocking single-threaded completion-queue pull) with no server runtime or PHP server codegen upstream; the conclusion that it cannot serve E10's handlers stands.
- RoadRunner: cold clone+build 91 s; warm re-link 6.4 s bought with 8.6 GB of Go module + build cache; 480 modules; binary 91.5 MiB.
- Ignis: the `grpc-baseline` crate builds **cold in 56.9 s** (V-20's 23.5 s was the incremental rebuild); the ignis binary is 27.7 MiB after E14/E16 (V-20 measured 24.1 MiB before them).

## V-25 — H25 (E6'): ssl:// / tls:// / https:// and STARTTLS through the stream hook (CONFIRMED)

Date: 2026-09-16T03:50:09Z. Build: libphp with `--with-openssl` (ext/openssl now registers the stock `ssl`/`tls` transports and overrides `tcp`; Ignis's MINIT replaces `tcp`, `ssl`, `tls`, `tlsv1.2`, `tlsv1.3` with its factory and keeps the originals as fallbacks — `sslv3`, `tlsv1.0`, `tlsv1.1`, persistent and server sockets stay on openssl); rustls 0.23 (`ring` provider) + tokio-rustls 0.26 + webpki-roots in the reactor (ADR-0017). Test rig: three stock-PHP TLS servers (`bench/php/e6_ssl_server.php`, one connection at a time, 200 ms sleep, a leaf certificate for `localhost` signed by a throwaway CA). Command: `bench/e6-ssl.sh`.

```
== hook on
hook on: 3 concurrent https fetches (200 ms each) in 217 ms; bodies: ["hello over tls from 8441","hello over tls from 8442","hello over tls from 8443"]
verify [default (verify_peer on)]: FAIL — file_get_contents(https://127.0.0.1:8441/): Failed to open stream: connect 127.0.0.1:8441: tls handshake with 127.0.0.1: invalid peer certificate: UnknownIssuer
verify [cafile]: ok
verify [cafile + wrong peer_name]: FAIL — file_get_contents(https://127.0.0.1:8441/): Failed to open stream: connect 127.0.0.1:8441: tls handshake with example.invalid: invalid peer certificate: certificate not valid for name "example.invalid"; certificate is only valid for DnsName("localhost") or IpAddress(127.0.0.1)
verify [cafile + verify_peer_name=false]: ok
verify [allow_self_signed]: ok
starttls on a ignis_tcp stream: enable_crypto=true, response="hello over tls from 8442"
client exit=0
== hook off (control)
hook off: 3 concurrent https fetches (200 ms each) in 614 ms; bodies: ["hello over tls from 8441","hello over tls from 8442","hello over tls from 8443"]
```

| claim | result |
|---|---|
| 3 concurrent `file_get_contents('https://…')` on one PHP thread, servers sleep 200 ms each | **210–231 ms with the hook**, **613–623 ms with `IGNIS_NO_STREAM_HOOK=1`** (same code, same servers) |
| verification follows the context options like ext/openssl | default (verify on, unknown CA): rejected `UnknownIssuer`; `cafile`: ok; wrong `peer_name`: rejected with the SAN list in the message; `verify_peer_name=false`: ok; `allow_self_signed`: ok |
| STARTTLS: `stream_socket_enable_crypto()` on a hooked `tcp://` stream | upgrades in place (`Op::Upgrade`, rustls handshake on the tokio side), request/response then flow through the TLS session |

Three defects fixed on the way: `Outcome::Ready` was not routed to a C-parked fiber (the STARTTLS fiber was left suspended and the script ended silently — `Future::await()` from `{main}` now throws when the loop stops with the future unsettled instead of returning null); TLS records were buffered until the next read (`flush()` after every write now); a self-signed leaf is `CaUsedAsEndEntity` for webpki, so the bench builds a CA + leaf.

Not covered (ADR-0017): client certificates, `sslv3`/`tlsv1.0`/`1.1`, TLS downgrade, `peer_certificate` capture, server-side TLS on the Ignis listener.

## V-26 — Cycle 20: accept and select inside fibers (E6''), E12' fail-fast, and the E13' regression the CI gate caught (CONFIRMED)

Date: 2026-09-16T04:09:34Z. Binary: a side build of the same commit (`CARGO_TARGET_DIR=target-c20`) because the E15e porter was still using `target/release/ignis`; the main binary is rebuilt from the same sources at the next check-in and `scripts/smoke.sh` re-run then.

**E6'' — server sockets inside fibers** (`bench/php/e6_accept.php`: one fiber runs an accept loop for 3 clients with a 100 ms `Ignis\sleep` per client; 3 client fibers connect, `stream_select()` for the reply, `fgets`; all on one PHP thread):

```
accept in a fiber: 3 clients served in 305 ms (3 x 100 ms sequential sleeps in the one server fiber)
  server: tcp_socket peer=ok got=hello 0 / hello 2 / hello 1
  client: select=1 local=ok reply='echo: hello 0'   (x3)
```

| mechanism | before | after |
|---|---|---|
| `stream_socket_accept()` in a fiber | blocks the thread (stock accept); with clients on the same thread the earlier test needed the accept in `{main}` | the handler is swapped at MINIT: inside a fiber it parks on the listener's readiness (`Op::Watch`), then runs the original (returns at once) and adopts the accepted socket into the reactor (`Op::Adopt`: dup'd fd → tokio `TcpStream` → connection actor), so reads/writes on it park too |
| `stream_select()` in a fiber | blocks the thread; every client's 3 s select timed out (9315 ms for the test) because the server fiber could not run meanwhile | parks on every fd's readiness plus the timeout (new `await_any`: one fiber parked on several op ids, the first completion wins, the rest are cancelled), then runs the original with a zero timeout to fill the ready sets: `select=1`, 305 ms total |
| `stream_socket_get_name()` on hooked streams | false (NOTIMPL) | local/peer "ip:port" captured at connect/adopt time |
| `stream_get_meta_data()['stream_type']` | `ignis_tcp` | `tcp_socket` (5 phpt tests check the stock label) |
| `socket_import_stream()` / select fd | no fd (cast FAILURE) | a dup of the socket (owned by the PHP stream, closed with it); the first version wrote a pointer into the int-sized cast slot and smashed the stack — the cast protocol writes a `php_socket_t` |

Swoole `swoole_runtime` re-run on this build: still **44 / 79 / 30** — the accept/select hooks alone move no Swoole test, because the blocked tests also need `Swoole\Coroutine\Socket`, file hooks or process control (research 15's ranking holds).

Under `IGNIS_CHAOS=1` the same test passes (310 ms). Not covered: the `stream_socket_accept()` timeout argument only applies once the listener is readable (the park itself has no timeout yet); UDP/unix transports; `socket_*` (ext/sockets) calls remain blocking.

**E12' — in-flight requests on a dying thread** (`bench/e12-inflight.sh`, `--supervise --threads 1`):

```
/fatal -> 500 after 0.000767s
in-flight /sleep?ms=3000 -> 500 after 0.303214s     (sent 0.3 s before the fatal; a hang would be >= 3 s)
after respawn: / -> 200 after 0.000749s
wall: 823 ms
```

`http::unregister()` now drains the dying reactor's responders and gRPC streams (`Reactor::fail_pending`), so hyper answers 500 at once and the supervisor's respawn serves the next request.

**E13' regression caught by CI** (`scripts/ci-gate.sh`, FrankenPHP baseline 29 → 27 on the first lazy-swap commit): a user `Fiber` started inside a request saw the base world's empty `$_GET` (`fiber-basic.php`, `fiber-no-cgo.php`: "Fiber " instead of "Fiber 1"), because the lazy swap restored the base world on every switch to a fiber without a slot. Fix: the observer tracks the *owner* of the installed view; a switch to a slot-less fiber leaves the view alone (children inherit their parent's request view, pool fibers inherit the base world), only a switch to `{main}` restores the base world, and a dying owner releases the view. After the fix: FrankenPHP 29 / 4 / 33 (the four known runtime gaps), `e13_isolation` 0 mismatches / no main leak, 200 concurrent HTTP 0 mismatches — with and without `IGNIS_CHAOS=1`. The quiet-box E2' figure after E13' is still owed.

### V-26 addendum (2026-09-16T05:01:43Z) — phpt fiber-mode streams: 111 → 120 on the accept/select build

The CI gate (`scripts/ci-gate.sh phpt`, run #23/#25) flagged fiber-mode `ext/standard/tests/streams` at **111 < 116** after the accept/select hooks landed. Bisection with `IGNIS_NO_ACCEPT_HOOK=1` pinned it on the hooks; the eight regressed tests exposed four distinct gaps, each fixed in `crates/ignis/src/php/{accept,stream}.rs` / `reactor.rs` and re-run one by one with `run-tests.php -p scripts/ignis-php` (`IGNIS_PHPT_MODE=fiber`, binary `target-c20/release/ignis`):

| gap | tests it broke | fix |
|---|---|---|
| `stream_select` parked before PHP's own buffered-data check, and cast the fds without `PHP_STREAM_CAST_INTERNAL` ("bytes of buffered data lost" warning) | bug46024, stream_select_preserve_keys, bug60602, bug64770, proc_open_bug60120 | non-blocking probe first (original with tv = 0, arrays copied and restored when nothing is ready); internal cast |
| a losing `stream_select` timeout stayed in flight until it lapsed, so `Loop::runUntil` could not stop | proc_open_bug64438 (60 s select, process timed out after its output was complete) | `Op::Sleep` is cancellable like a watch; select/accept cancel the loser |
| `stream_socket_accept` ignored its timeout and the non-finite check, parking forever | non_finite_values (hung, listener held port 14781 for the next run) | timeout computed like PHP (null → `FG(default_socket_timeout)` via the TSRM id storage vector, < 0 → forever, non-finite → the original throws); on expiry the original runs with a zero timeout → stock `false` + "Accept failed: Connection timed out" (measured: 200 ms / 1001 ms for 0.2 s / 1.0 s, thread free) |
| adopted sockets ignored `stream_set_blocking(false)`; `stream_get_meta_data()` had no blocked/timed_out/eof | gh8472 (second `fread` parked forever), gh16889, stream_get_meta_data_socket_variation1/3/4 | `Sock.blocking`, `Op::TryRead` (one poll with a no-op waker → `Data` or `WouldBlock`, returns 0 bytes without EOF), `PHP_STREAM_OPTION_META_DATA_API` |

Full matrix (`IGNIS_BIN=target-c20/release/ignis bench/e15-phpt.sh`, 2026-09-16T05:01:43Z, load ~1.5):

```
stock  Zend/tests/fibers            110  108 pass   0 fail  2 skip
main   Zend/tests/fibers            110  108        0       2
fiber  Zend/tests/fibers            110   78       30       2      (baseline 77)
stock  ext/standard/tests/streams   160  138        0      22
main   ext/standard/tests/streams   160  132        6      22      (baseline 131)
fiber  ext/standard/tests/streams   160  120       18      22      (baseline 116; 111 before this fix)
stock  ext/sockets/tests            118   80        0      38
main   ext/sockets/tests            118   80        0      38
fiber  ext/sockets/tests            118   75        5      38      (baseline 75)
gate: all six >= baseline  (rc=0)
```

`bench/results/e15-baseline.txt` raised to the new counts (fiber fibers 78, main streams 132, fiber streams 120). The 12 fiber-only stream failures that remain, classified: harness (stack traces include `phpt-harness.php`/`ignis.php` frames: bug77664, gh8409, user_streams_context_001; `open_basedir=.` rejects the harness path: bug70362) — *not applicable*; ours — bug60106-001/002 (unix-socket `stream_socket_get_name` on a hooked server socket), bug69521 and ghsa-3cr5-j632-f35r (error text for an invalid port / NUL host differs from stock), stream_get_meta_data_socket_variation2 (`timed_out` after a read timeout is never set), gh14506 (`fclose` on STDIN/STDOUT should warn); **fixed right after this run** (2026-09-16T05:07:47Z): bug70198 — `feof()` on a hooked stream never asked the peer (`PHP_STREAM_OPTION_CHECK_LIVENESS` answered OK), so `while (!feof($fp))` spun at 100 % CPU forever (two such harness processes were found still running after the matrix; now poll + `MSG_PEEK` like xp_socket → PASS); stream_select_null_usec — the hook now lets the original raise the ValueError (the remaining diff is the harness frames in the stack trace → *not applicable*). The "not covered" line of V-26 no longer applies to the accept timeout.

## V-27 — H22e (E15e): Symfony and Doctrine suites under chaos scheduling (CONFIRMED)

Date: 2026-09-16T05:01:43Z. Port and first full pass by the porter (research 20, `bench/e15-chaos.sh`, 04:18–04:41Z, 10 849 tests per mode, 5 suites × 4 modes: stock CLI, plain `ignis`, chaos seed 1, chaos seed 20260916, `IGNIS_NOISE=4` background fibers). Main re-ran the two suites that carry the claim once (2026-09-16T05:01:43Z, `SUITES="symfony-http-foundation dbal"`), numbers identical to the porter's:

```
suite=symfony-http-foundation  stock                 tests=1815 failures=0 errors=62 skipped=126
suite=symfony-http-foundation  ignis                 tests=1815 failures=0 errors=62 skipped=126  chaosYields=0
suite=symfony-http-foundation  chaos-seed-1          tests=1815 failures=0 errors=62 skipped=126  chaosYields=10756 noiseTicks=21624
suite=symfony-http-foundation  chaos-seed-20260916   tests=1815 failures=0 errors=62 skipped=126
suite=dbal                     stock/ignis/chaos×2   tests=3901 failures=1 errors=0 skipped=633   (same test in every mode)
```

Porter's full pass (same binary): http-kernel 1389 tests, 2 failures stock vs **3** under ignis/chaos; httpcache 103/103 in every mode with **222 539** forced yields and **445 363** noise interleavings under chaos (5 min of real `sleep()` parked on reactor timers); orm 3641 tests, 16 failures / 62 errors identical in every mode.

**Claim: zero new failures from chaos** — every chaos count equals the plain-ignis count, both seeds agree. **One** test fails under ignis at all and not under stock: `CacheWarmerAggregateTest::testWarmupRecoversFromCorruptedDeprecationLog` runs `PHP_BINARY -- <script on stdin>`, a php-cli feature the embed binary lacks → *not applicable* (fails with chaos off too). Honest limits: dbal, orm and http-kernel never enter the Ignis loop (CPU + pdo_sqlite + files: 1–4 yields in total), so for them the result says only that the embed environment behaves; the scheduling claim rests on httpcache and http-foundation (sleep + ~30 hooked `tcp://` requests; the attribution probe with the hooks off drops the yields from 10 844 to 4). The 21 `PHP_BINARY -S` failures are worked around symmetrically (the two fixture servers are started on the stock CLI for every mode). 58/19 "separate process" errors come from the custom entry point (no `PHPUNIT_COMPOSER_INSTALL`) and are identical in all modes. Baseline failures (dbal 1, orm 16+62, http-kernel 2+26) are classified in research 20.

## V-28 — quiet-box re-measurement after E13' (lazy swap), loop-scheduled GC and E12' (E1, E2', E12')

Date: 2026-09-16T05:07:47Z. Binary: `target/release/ignis` rebuilt from `night-1` HEAD `b7fe7ca` (+ docs) once the E15e porter had released it; load average 1.2–2.0 on 4 vCPU (the two spinning bug70198 harness processes were killed first; before that E1 read 1294 ms and the smoke step failed). Every figure below is a fresh run, not a re-use.

**E1** (`bench/php/e1_sleep_10k.php`, 10 000 fibers × 1000 ms, 3 reps):

```
wall_ms=1194.9 overhead_ms=194.9 peak_rss_kb=186368
wall_ms=1175.4 overhead_ms=175.4 peak_rss_kb=186368
wall_ms=1193.2 overhead_ms=193.2 peak_rss_kb=186368
```

Target < 1200 ms: met, by 5–25 ms. The cold margin has not improved since V-2 (1170–1190 ms then); under any contention it fails (V-2 addendum, and 1294 ms above). The warm pool remains the answer for services (V-4).

**E2'** (`bench/php/e2_all.php`, N=10000, superglobals observer on/off × loop-scheduled GC on/off, 3 reps each; `all()` of 3 × 200 ms was 201.9–202.0 ms in every run):

| observer | loop GC | per-fiber warm (µs) | per-fiber cold (µs) |
|---|---|---|---|
| on (default) | on (default) | 6.35 / 6.10 / 5.52 | 18.9 / 19.2 / 16.5 |
| off (`IGNIS_NO_SUPERGLOBALS=1`) | on | 4.94 / 5.43 / 5.66 | 18.4 / 16.1 / 17.5 |
| on | off (`IGNIS_LOOP_GC=0`) | 5.47 / 6.38 / 6.48 | 16.7 / 17.7 / 17.5 |
| off | off | 6.71 / 5.76 / 6.18 | 19.0 / 17.3 / 17.6 |

Reading: the observer's cost, **8.0–11.4 µs vs 4.5 µs** before the lazy swap (V-11 addendum), is now **≤ 0.7 µs and inside the run-to-run spread** (5.5–6.4 on vs 4.9–5.7 off) — E13' did what ADR-0011's follow-up asked. The E2' target itself (< 5 µs warm) is **not met** on this box in any combination (best single run 4.94 µs, medians 5.5–6.2 µs); the remaining cost is the pool/loop path, not the observer. Loop-scheduled GC makes no measurable difference here (this workload creates no cycles); the 3× cold variance of V-11 is gone (16–19 µs). Recorded as-is: E2' stays OPEN with the observer no longer the reason.

**E12'** (`bench/e12-inflight.sh`, `--supervise --threads 1`, main binary):

```
/fatal -> 500 after 0.000877s
in-flight /sleep?ms=3000 -> 500 after 0.303638s
after respawn: / -> 200 after 0.000772s
wall: 821 ms (a hang would be >= 3000 ms for the sleeping request)
```

Same as the side build in V-26 (0.30 s): the in-flight request on the dying thread gets its 500 the moment the thread unregisters, not after its 3 s sleep. E12' CONFIRMED on the main binary.

`scripts/smoke.sh` on this binary: build, unit tests, hello, app.php, E2 green; E1 failed only while the two spinning harness processes loaded the box (1294 ms) and passed once they were gone (above); the remaining steps were not re-run inside smoke because it stops at the first failure — E12'/E13/E6/E11/E14 have their own fresh numbers tonight (V-26, V-28, V-25, V-21).

### V-28 addendum (2026-09-16T05:10:48Z) — E2' met: `sleep(0)` completes in the reactor without a timer task (H28)

The phase breakdown of the warm round trip (`e2_phase.php`, same binary as V-28: async() 0.8 µs, ready phase 2.3–2.8 µs, poll 0.1–1.0 µs, resume phase 1.7 µs, await 0.05 µs) showed the reactor side spending a `tokio::spawn` + timer + cancel-map insert/remove per `Op::Sleep { us: 0 }` — a yield to the loop that needs no timer at all. The reactor now completes a zero sleep inline (`Slept { late_us: 0 }` straight onto the completion channel); real sleeps are unchanged. Rebuilt `target/release/ignis` (HEAD + this change), same quiet box (load 1.2–1.9), `bench/php/e2_all.php` N=10000:

| observer | per-fiber warm (µs) × 3 | per-fiber cold (µs) × 3 | `all()` 3 × 200 ms |
|---|---|---|---|
| on (default) | **4.54 / 4.67 / 4.51** | 17.9 / 20.8 / 17.6 | 201.9–202.0 ms |
| off (`IGNIS_NO_SUPERGLOBALS=1`) | 4.71 / 4.40 / 4.37 | 17.8 / 19.3 / 17.9 | — |
| on, `IGNIS_CHAOS=1` (extra 0 ms yield after every op, p = 0.5) | 8.76 / 9.20 | 23.1 / 21.3 | 201.1 ms |

Phase breakdown after: ready 1.85–2.03 µs, resume 1.26–1.36 µs, poll 0.10–0.14 µs, async() 0.5–0.8 µs, total 3.9–4.5 µs (the e2_all figure includes its own bookkeeping). **E2' (< 5 µs per warm job, observer on) is met: 4.5–4.7 µs**, 3 of 3 runs, observer on or off. What it is and is not: the number measures a pooled fiber doing one `Ignis\sleep(0)` — i.e. one loop round trip (resume → job → submit → suspend → poll → resume → settle → suspend). A fiber parked on a real timer or socket still pays the tokio task and wake (5.5–6.4 µs warm before this change; unchanged for `sleep(1)`). E1 on the same binary: 1193 ms. Chaos mode doubles the per-fiber cost by construction (an extra yield per op), which is the intended price of the switch.


## V-29 — H29 (A4, ADR-0018): ext/sockets parks the fiber (CONFIRMED for concurrency and parity; the overhead kill criterion is breached and was mis-specified)

Date: 2026-09-16T09:13:45Z. Box: 24-thread Ryzen AI 9 HX 370 / 30 GB, WSL2 — **not** the 4 vCPU box every
earlier V-n was taken on. PHP 8.5.10 ZTS+embed at /opt/php85-zts, `LD_LIBRARY_PATH` set, binary built
from this tree. Every number below was produced by the main agent directly.

### Concurrency — the claim

`N=20 DELAY_MS=200 ./target/release/ignis bench/php/a4_sockets.php` (20 concurrent `socket_read`,
200 ms each, one PHP thread; the server half uses `stream_socket_server`/`stream_socket_accept`,
already hooked since E6'', so the only thing under test is the ext/sockets client):

| run | wall_ms | ok |
|---|---|---|
| 1 | 265.6 | 20/20 |
| 2 | 249.1 | 20/20 |
| 3 | 254.2 | 20/20 |

Control, `IGNIS_NO_SOCKETS_HOOK=1`: **stalls** (killed at 15 s, rc=124). That is the point — the
first `socket_read` blocks the OS thread, so the server fibers never run and nothing completes.

Isolated probe (socketpair, one fiber reads while another sleeps 150 ms then writes):
`got='ok' wall_ms=151.9`.

### Parity — php-src ext/sockets/tests, fiber mode

| | passed | failed |
|---|---|---|
| C22 local baseline (no hook) | 85 | 7 |
| with the hook, first attempt | 80 | 12 |
| with the hook, after the `can_block` fix | **86** | **6** |

`comm` against the baseline failing list: **zero new failures**, one test recovered.

The first attempt was a real regression and is worth recording rather than hiding: six tests
(`socket_read_params`, `socket_send_params`, `socket_sendto_params`, `socket_sendto_invalid_port`,
`socket_recv_overflow`, `gh17921`) timed out. Root cause: **poll readiness is not the same as "the
call would succeed"**. Five of them call a data op on a *listening* socket (`socket_create_listen(0)`),
where stock PHP fails with ENOTCONN at once while `POLLIN` never fires; the sixth reads from an
unbound, unconnected AF_UNIX dgram socket. All six also pass deliberately invalid lengths, which the
original rejects *before* any syscall — so parking before the original validates is wrong by
construction. Fix: `can_block(fd, hook)` refuses to park on a listening socket (for data ops), on an
unconnected socket, and on an unbound one, and delegates instead. The rule applied is "delegating is
always semantically correct; parking wrongly is a hang", so anything uncertain goes to the original.

### Overhead — kill criterion 2 is BREACHED, and the criterion itself was wrong

`bench/php/a4_overhead.php` (100 000 `socket_sendto` on an always-writable UDP socket bound in-process,
so every call takes the `ready_now()` fast path and never parks), µs per call:

| load avg | hook on | hook off |
|---|---|---|
| 0.92 | 9.27 / 7.71 / 8.38 | 7.53 / 7.56 / 7.42 |
| 0.85 | 13.68 / 11.57 / 7.93 | 9.26 / 7.76 / 9.51 |

Honest reading: the difference is roughly **1–2 µs**, but the spread *within* each group (7.9–13.7)
is as large as the effect, so **this bench cannot resolve the hook's cost on this box** and no
single number from it should be quoted. What is certain is that the cost exceeds ADR-0018's
0.36 µs bar.

The bar was mis-specified. It was set at 10 % of the *fiber round trip* (3.6 µs), but the hook adds
one `poll` syscall to an operation that is itself syscall-bound, and a syscall on this WSL2 box costs
on the order of 0.5–1 µs. No readiness-probe design can meet 0.36 µs here; the criterion was
unattainable by construction rather than by implementation. One real improvement was made from it:
`fcntl` was moved off the hot path (it is now paid only when about to park), so the ready path costs
one syscall, not two.

**Proposed replacement criterion, for the owner to accept or reject** (not applied unilaterally): the
hook's added cost must stay under 25 % of the wrapped operation, measured on a box where a syscall
can be resolved, and the comparison of record is against *blocking the entire thread*, which is what
the alternative does.

Earlier bench artefacts, recorded so they are not repeated: the first overhead bench wrote to a
socketpair whose peer never read, so the buffer filled and writes began to park (it measured the
parking path, not the ready path); the second sent UDP to a *closed* port, and a few hundred thousand
ICMP port-unreachable replies slowed the whole box by ~10x. The committed version sends to a bound
socket in the same process.

## V-30 — A3 fallout: a client disconnect could kill a whole worker thread (CONFIRMED defect, fixed and verified by main)

Date: 2026-09-16T10:46:59Z. Box: 24-thread Ryzen AI 9 HX 370 / 30 GB, WSL2. Found by the bencher during A3 calibration;
reproduced, root-caused, fixed and re-verified by the main agent — the numbers below are the main
agent's own runs.

### The defect

A client disconnect while a request is awaiting child fibers throws `Ignis\CancelledException` into a
child that has no handler for it. `Fiber::throw()` on an unguarded fiber re-throws straight back out
at the caller, and — the part that actually bit — `ignis_cancel_parked_any()` (the Rust path for a
fiber parked in a C stream op) leaves the throwable **pending in C** via
`zend_fiber_resume_exception`, so it surfaces after the call returns, inside `Loop::runUntil()`, with
no `throwInto` frame in the trace. Uncaught there, it ends the worker thread's whole script.

Unguarded user code is the normal case, so one disconnected client took out a quarter of the
server's capacity. Rate under sustained mixed load: **6 dead threads in 10,081,952 requests** and
4 in 9,169,719 (bencher's two soak runs). Without `--supervise` the process itself dies.

Reproduction (main agent, before the fix):

```
IGNIS_LISTEN=127.0.0.1:8099 ./target/release/ignis --threads 4 --offload 4 bench/php/a3-soak.php &
for i in $(seq 1 8); do wrk -t4 -c200 -d5s -s mix.lua http://127.0.0.1:8099/; done
→ Fatal error: Uncaught Ignis\CancelledException: client disconnected in php/ignis.php:303
```

Short chained bursts are what trigger it — each `wrk` run closes all its connections at once. A
single long run essentially never hits it, which is why 10M requests found it only 6 times while a
60-second burst sequence finds it every time.

### The fix

`Loop::throwAndAbsorb()` guards both `Fiber::throw()` sites, and the `ignis_cancel_parked_any()` call
is guarded the same way. The exception we injected is absorbed — that is the whole point of
cancelling unguarded code. Anything **else** (a `finally` that throws while unwinding) is kept in
`Loop::$unobserved` rather than swallowed.

Guarding only the two `Fiber::throw()` calls was NOT enough and the first verification run still died
(4 fatals, server gone by burst 12). The stack trace said why — the exception surfaced directly in
`runUntil` with no `throwInto` frame — which is what pointed at the C path.

### After the fix (main agent)

| check | result |
|---|---|
| 12 burst rounds × `wrk -t4 -c200 -d5s` | server **alive**, **0** fatals, **0** supervisor restarts |
| 442,120 resumes, 224 fibers, 4 threads | `stalled: 0` |
| 20 requests aborted at 200 ms | **20/20 cancelled** |
| worst cancel latency | **279 µs** (E11 requires < 10 ms; V-14 recorded 0.78 ms) |
| `finally` blocks run | **40** = 20 × (handler + child fiber) — cancellation still unwinds user code |
| `Ignis\deadline(100)` around a 1000 ms sleep | **504 in 103.7 ms** |
| unit tests | 10/10 |

So the fix removes the thread kill without weakening cancellation: every `finally` still runs and the
latency is 2.8× better than the recorded E11 worst case.

### Note on the A3 soak numbers themselves

The RSS curve from the two soak runs is the **bencher's** measurement and has NOT been re-run by the
main agent, so by the C15 rule it stays out of this entry as a validated number. It is recorded in
JOURNAL and summarised here only as context: RSS grew ~+50 % between the 1M and 10M checkpoints, but
the growth is front-loaded and stops trending at roughly 5M, after which readings oscillate in an
87–110 MB band. A duplicate re-run was deliberately not performed: RSS is not a noisy quantity across
runs, so repeating an identical configuration would have re-measured what was never in doubt.

## V-31 — A1: three stream defects the C22 triage classified as 'ours', fixed (CONFIRMED)

Date: 2026-09-16T09:19:55Z (part 1) – 2026-09-16T09:23:43Z (part 2, complete). Box: 24-thread Ryzen
AI 9 HX 370 / 30 GB, WSL2 (research 21 machine state: `Linux 6.18.33.2-microsoft-standard-WSL2`) —
**not** the 4 vCPU box every pre-Phase-A V-n was taken on. PHP 8.5.10 ZTS+embed at `/opt/php85-zts`.
Commits: `17aaa0f` (part 1), `fdeff4a` (part 2).

### The three defects

1. **`stream_set_timeout()` was accepted and ignored** (`PHP_STREAM_OPTION_READ_TIMEOUT` returned OK,
   `timed_out` was hard-coded `false`), so a `fread()` with no data parked the fiber forever — the
   defect that hung the porter's whole suite run and forced a manual SIGKILL
   (`stream_get_meta_data_socket_variation2`). Fix: `Sock` gains `read_timeout_us`/`timed_out`; with a
   deadline set, `op_read` waits on a cancellable `Op::Watch` + `Op::Sleep` and then takes the bytes
   with a non-blocking `Op::TryRead` (an `Op::Read` cannot be cancelled, so a winning timer would
   strand it and lose the bytes it later delivers). Untimed reads keep the old single-hop path.
   `stream_get_meta_data_socket_variation2` now passes in 0.123 s.
2. **Port literals above 65535 were rejected instead of wrapping** (bug69521): `parse_host_port`
   parsed the port straight into `u16`, so `tcp://127.0.0.1:74321` failed with errno 0 inside a fiber
   while `{main}` and the stock CLI connected fine (stock does `atoi()` into an unsigned short, i.e.
   it wraps: 74321 → 8785). Fix: the parse goes through `i64` and truncates like the C cast.
3. **Connect failures left `$errstr`/`$errno` empty** (ghsa-3cr5-j632-f35r): an unparseable address
   set `returncode = -1` with no `error_text`, and a NUL byte in the host reached the resolver instead
   of being rejected up front. Fix: both paths now set `error_text`, the NUL case with stock's exact
   wording ("The hostname must not contain null bytes").

### Command

```
PHPSRC=/home/koe/php-src bash bench/e15-phpt.sh
```
(`docs/research/21-phase-a-phpt-triage.md`; suites: `Zend/tests/fibers`, `ext/standard/tests/streams`,
`ext/sockets/tests`, modes stock/main/fiber via `scripts/ignis-php` + `IGNIS_PHPT_MODE=fiber`.)

### Full-suite A/B, `ext/standard/tests/streams`, this box

| stage | main (pass/fail) | fiber (pass/fail) |
|---|---|---|
| before any A1 patch | 130/9 | 122/17 |
| after part 1 (`stream_set_timeout`, `17aaa0f`) | 131/8 (`bug60106-001` recovered) | not measured standalone — part 2 landed next |
| after part 2 (port wrap + errstr/errno, `fdeff4a`) | **131/8** (unchanged) | **124/15** |

Zero new failures at any stage. The roadmap's stated target ("fiber streams ≥ 128/138") is **not
comparable**: it was set against the old 4 vCPU box's 120/138, while this box runs 160
`ext/standard/tests/streams` tests and starts from 122/17. The three defects the C22 triage (research
21) classified as "ours" are now fixed; the remaining 15 fiber-mode failures are harness artefacts
(stack frames from `phpt-harness.php`/`ignis.php`, `open_basedir` rejecting the harness path) and
CLI-only semantics, classified separately in the research-21/V-26-addendum triage.

Method note carried over from the JOURNAL: some of these tests are **not reproducible in isolation**
(`bug46024`/`bug70362` fail alone, pass in the suite) and the suite itself has a few tests of
run-to-run variance, so only full-suite A/B on the same box is valid — single-test or cross-run
comparisons are not.

Known gap left open (roadmap A6): on a TLS stream the part-1 timeout path watches the raw fd, which is
not the same as plaintext being available, because rustls buffers whole records.

## V-32 — A5: php-cli parity for `-r <code>` and `--` (script on stdin) in the embed SAPI (CONFIRMED)

Date: 2026-09-16T09:37:50Z. Box: 24-thread Ryzen AI 9 HX 370 / 30 GB, WSL2 (same box as V-29/V-30/V-31).
PHP 8.5.10 ZTS+embed at `/opt/php85-zts`. Commit: `3685afd`.

### What was added

`Engine::eval` (`crates/ignis/src/php/embed.rs`), wired to `-r <code>` and `--` (script on stdin) in
`crates/ignis/src/main.rs`. Two corrections forced by measurement, both recorded in the commit and
JOURNAL: `zend_eval_stringl` leaves the error pending and prints nothing, so a parse error exited 255
in silence where php-cli reports it — the `_ex` form with `handle_exceptions=true` is required; and
with that form an `exit()` also returns `FAILURE`, so the return code alone cannot separate `exit(0)`
from a parse error — a sentinel written into `EG(exit_status)` before the call (via `set_exit_status`)
distinguishes them.

### Exit-code parity

Six cases checked against the stock CLI, all matching: `exit(0)`, `exit(7)`, `exit(255)`, a clean run,
a parse error, an uncaught throw. The exact shell invocations for each of the six are **not recorded**
verbatim in JOURNAL or the commit — only the case list and "exit codes match the stock CLI on all six
cases" are. The general invocation shapes (from `main.rs`'s `-r`/`--` argument handling, sourced) are
`./target/release/ignis -r '<code>'` and `./target/release/ignis --` with the script on stdin.

### Re-exec verified

A script running under ignis can re-exec `PHP_BINARY -r ...` and feed `PHP_BINARY --` a script on
stdin; both were verified end to end (exact command text not recorded).

### Deliberately not implemented

`-S` (php-cli's development server): Ignis has its own HTTP front door, and re-creating php-cli's dev
server was not justified by any test here. The Symfony acceptance test named in ROADMAP.md
(`CacheWarmerAggregateTest::testWarmupRecoversFromCorruptedDeprecationLog`, which runs
`PHP_BINARY -- <script on stdin>`) **cannot run on this box** — no composer vendor tree; see V-27's
"not applicable" classification of the same test under chaos mode, for the same underlying reason
before A5 landed.

### Suites after the change (unaffected by A5, recorded as a regression check)

| suite | main | fiber |
|---|---|---|
| `ext/standard/tests/streams` | 130/9 | 124/15 |
| `ext/sockets/tests` | 91/1 | 86/6 |

Unit tests (nextest-equivalent): 10/10.

## V-33 — reactor round-trip latency is a wakeup pair, and `IGNIS_POLL_SPIN_US` (H30)

Machine: this box, 4 vCPU, WSL2 (`Linux 6.18.33.2-microsoft-standard-WSL2`), quiet, load < 0.5.
PHP 8.5.10 ZTS+embed, `cargo build --release -p ignis`. Every figure is the median of 3 reps of
N=5000 ops unless stated.

**Decomposition** — `./target/release/ignis bench/php/reactor_latency.php`

| leg | what it crosses | µs/op |
|---|---|---|
| php-only: `ignis_watch` on an always-writable fd | zif only, tokio never sees it | 13.9 |
| channels: `Ignis\sleep(0)` | mpsc → dispatcher → crossbeam; no timer, no epoll, no dup | 110.1–124.8 |
| epoll: two-fiber socketpair ping-pong | real `Op::Watch`: dup + `AsyncFd` + wait + drop | 55.3 |

The epoll leg is **cheaper** than the channels leg — a ping-pong has two fibers, so its wakeups
already batch. Registration is not the cost.

**Amortization** (`Ignis\sleep(0)`, spin off): 1 fiber **93.1–96.1 µs**, 2 → 49.3, 4 → 22.6,
8 → 11.0, 32 → 2.64, 128 → **0.58 µs/op**. Cost × batch = 74–98 µs throughout: one fixed wakeup per
`poll()`, shared by everything that wakeup drains.

**Platform floor** — two threads, two `std::sync::mpsc` channels, 20 000 round trips, `rustc -O`:
**57.5 / 57.3 µs** per round trip (the same two wakeups). So over half of our fixed cost is the
scheduler on this box; the remainder is tokio's wake + the dispatcher.

**`IGNIS_POLL_SPIN_US` sweep**, concurrency 1: 0 → 96.1, 20 → 63.2, 50 → 36.8, **100 → 30.0**,
200 → 30.3 µs/op. Plateau at 100; the floor is one remaining wakeup (the submit still wakes tokio).

**Spin on (100) vs off, throughput and CPU** — 20 000 ops, `getrusage`, one process:

| fibers | µs/op off → on | cpu µs/op off → on |
|---|---|---|
| 1 | 109.66 → 37.44 | 121.02 → 71.21 |
| 8 | 14.40 → 3.75 | 16.73 → 7.02 |
| 32 | 3.53 → 0.95 | 4.66 → 1.83 |
| 128 | 0.72 → 0.36 | 1.37 → 0.72 |

CPU per op is *lower* with the spin at every concurrency: a futex sleep and wake costs the kernel
more than a short spin. **Saturated box** (4 parallel processes, all 4 vCPU busy) — conc 1:
131.2–132.3 → 42.7–43.8 µs/op; conc 8: 17.05–17.25 → 5.17–5.36. No contention penalty.
**Idle server** (`--threads 4`, a listener, no traffic, 5 s): **0 CPU ticks** either way — the
`inflight() > 0` guard means an idle thread never spins.

**Where the spin does not pay.** It only wins if the completion lands inside the window:

| workload | off | on |
|---|---|---|
| serial `SELECT 1` over the libpq path (research 24) | 231.0 / 242.2 µs | **172.4 / 190.9 µs** |
| concurrent pg, 20 fibers × 100 queries | 22 112 q/s | 21 410 q/s |
| sequential `GET /` hello, 30 reqs, median | 1.31 / 1.37 ms | 1.48 / 1.56 ms |
| sequential `GET /sleep?ms=1`, 30 reqs, median | 3.68 / 3.76 ms | 3.84 / 3.97 ms |

A millisecond-scale wait pays the 100 µs and then sleeps anyway. Hence **off by default**.

**Regression net for the reactor change**: `cargo nextest run --workspace` 10/10;
`bench/e15-phpt.sh` locally — fibers main 108 / fiber 78, sockets main 91 / fiber 84, streams main
133 / fiber 125, all at or above the baseline raised this morning. `scripts/smoke.sh` could not
complete: an unrelated container holds `:8080`, which the app.php leg hardcodes.

## V-34 — E6 under inbound load: ~0.1–0.3 % of requests accepted and never answered (H31)

Machine: this box, quiet apart from the test. `./target/release/ignis --threads 1
examples/hello_server.php` on `127.0.0.1:8099`, `IGNIS_POLL_SPIN_US` unset.

| condition | result |
|---|---|
| hooked client, idle server (`bench/php/e6_underload.php`, N=150 × 10 rounds) | **1500 / 1500 ok** |
| same client, server under 50 concurrent `curl /sleep?ms=200` | **1 failure in 1200**, then **2 in 750** on a second run |
| `bench/e6-fetch.sh` (self-call shape, N=50) | `ok=50` / `ok=49` / `ok=50` / `ok=50` / `ok=50`, and `ok=50` in the green smoke run |
| six consecutive batches of 50 against one server | batch 1 ok, **batch 2 `ok=49`**, batches 3–6 ok |

Captured failing body: `{"bodies":["slept\n",false,"slept\n"],"ms":203.9}` with
`Warning: file_get_contents(...): Failed to open stream: HTTP request failed!` — PHP's wording for
"the connection opened but no status line could be read". Server log empty, including at
`RUST_LOG=debug` (which emitted only the two startup lines, so hyper's own logs are filtered out at
our subscriber level — worth fixing before the next attempt).

Excluded by the table above: the hooked client on its own, the self-call shape, and a startup race.

## V-35 — A3 re-run by main: RSS over 10.27M requests shows no monotonic trend past 5M (CONFIRMED)

Date: 2026-09-16T14:2xZ. Box: 24-thread Ryzen AI 9 HX 370 / 30 GB, WSL2, quiet. Command:

```
TARGET=10000000 DUR=30s bench/a3-soak.sh
# ./target/release/ignis --threads 4 --offload 4 --supervise bench/php/a3-soak.php on 127.0.0.1:8099
# wrk -t4 -c200 -d30s cycling /whoami?x=1 -> /dashboard?user=7 -> /offload
```

Acceptance is the criterion the owner restated on this date ("no monotonic trend past 5M"),
replacing "RSS within ±2 % between 1M and 10M". This run is the main agent's own; the earlier curve
in V-30 was the bencher's and rule C15 does not admit it.

| requests | RSS kB | fibers | idle | restarts | stalled |
|---|---|---|---|---|---|
| 1,778,047 | 54,280 | 43 | 42 | 0 | 0 |
| 1,807,647 | 70,004 | 172 | 171 | 0 | 0 |
| 2,918,790 | 62,960 | 172 | 171 | 0 | 0 |
| 4,641,175 | 62,572 | 172 | 171 | 0 | 0 |
| 4,670,575 | 65,508 | 177 | 174 | 0 | 0 |
| 5,760,577 | 64,612 | 174 | 173 | 0 | 0 |
| 7,489,789 | 62,492 | 187 | 186 | 0 | 0 |
| 7,519,189 | 65,824 | 179 | 178 | 0 | 0 |
| 8,589,143 | 63,220 | 184 | 183 | 0 | 0 |
| **10,266,805** | **63,320** | 184 | 183 | **0** | **0** |

**Past 5M: 64,612 → 62,492 → 65,824 → 63,220 → 63,320 — oscillation in a 62.5–65.8 MB band with no
trend. Criterion met.** 0 supervisor restarts, 0 stalled threads, no non-2xx reported by wrk in any
chunk, server log empty.

The step at the second checkpoint is the fiber pool sizing itself to the concurrency of the
`/dashboard` route (43 → 172 fibers), exactly the shape V-10 recorded at 1 thread; afterwards the
pool is flat and so is RSS.

**This is not the same curve the bencher measured** (V-30 context: +50 % between 1M and 10M, an
87–110 MB band). Between the same two points here it is 54,280 → 63,320 kB, +16.7 %, in a 62–70 MB
band. Different driver: this one has no PostgreSQL leg and weights the routes differently. The two
runs agree on the thing the criterion now asks about — the growth is front-loaded and stops
trending — and disagree on magnitude, which is why the criterion is about the trend and not about a
pair of endpoints.

## V-36 — H31 root-caused and fixed: a timed read abandoned a response that was already on its way

Date: 2026-09-16T15:1xZ. Box: this one, quiet. Two `ignis` processes, server `--threads 1`.

### The defect

On a stream with `stream_set_timeout()` the read path (A1) waits for **readiness of the dup'd fd**
(`Op::Watch`) against the timer, then takes the data with a non-blocking `Op::TryRead` on the
connection actor. Readiness of the fd is not the same as the actor holding the bytes: the kernel can
have them while the actor has not been polled yet. `TryRead` then answers `WouldBlock`, `op_read`
returned **0**, and PHP's `php_stream_get_line` reads 0 bytes as "no line" — so a *blocking* caller
abandons a response that is already arriving. `fgets()` returns `false` with `eof:false` and
`timed_out:false`, which is what made it look like the server had dropped the request.

PHP's `http` fopen wrapper always sets a read timeout, so every `file_get_contents('http://…')`
takes this path. `curl` is unaffected — it is not our transport.

### How it was localised

| test | result |
|---|---|
| server counts every request that reaches PHP, client counts responses | server **1550**, client **1499 of 1500** — the request *did* reach PHP, the response was lost after it |
| same load shape with `curl` as the client | **1500 / 1500 = 200**, 0 failures → not the server |
| hand-rolled HTTP over the hooked transport, retry on failure | `first=false`, then `retries=["GOT:HTTP/1.0 200 OK"]`, `unread_bytes` 0 → 102 — **the bytes were there; the first read gave up** |
| probe on every no-data exit of `op_read`, `RUST_LOG=warn` | 3 × `TryRead WouldBlock on a BLOCKING stream` against exactly **3** failures — 1:1 |

Method note, because it cost three wrong conclusions: the first probe runs printed nothing and I
read that as "this path never fires". `EnvFilter::from_default_env()` with `RUST_LOG` unset passes
only `ERROR`, so every `warn!` was being discarded. The hypothesis those silent runs "refuted" was
the correct one.

### The fix

In the timed path `WouldBlock` now returns to the wait with the remaining deadline instead of
returning 0; `timed_out` is set and 0 returned only when the deadline is actually reached. The
untimed path is untouched.

| measurement | before | after |
|---|---|---|
| hand-rolled client, 150 concurrent under load | 2–3 failures per ~900–1500 | **0 / 6000** |
| `file_get_contents` (`bench/php/e6_underload.php`), same shape | 1–2 per 750–1500 | **0 / 6000** |
| `bench/e6-fetch.sh`, 5 consecutive runs | `ok=49` in ~1 run of 3 | **50/50 five times**, 315–345 ms |
| `cargo nextest run --workspace` | — | 10/10 |
| `scripts/smoke.sh` | stopped at E6 | **GREEN** (E12 respawn 1, recovery 52 332 vs 54 231 rps) |
| `bench/e15-phpt.sh` (local) | fibers 108/78, sockets 91/84, streams 133/125 | **identical** — no regression, all at or above the baseline raised today |

## V-37 — B1 (ADR-0019): the fiber budget bounds fibers and sheds load; it does not bound RSS

Date: 2026-09-16T16:0xZ. Box: this one, quiet. `./target/release/ignis --threads 1
examples/hello_server.php`, `bench/b1-budget.sh`. RSS read from `/proc/<pid>/status`; the queue depth
is read live from `/stats`, which is admitted regardless of the budget (`IGNIS_BUDGET_EXEMPT=/stats`)
— without that, the stats endpoint queues behind the load it is meant to report.

**(1) The budget serialises, nothing is lost.** `IGNIS_FIBER_BUDGET=2`, 10 concurrent
`/sleep?ms=200`: **1028 ms** (ideal 1000) and **10 × 200**.

**(2) Past the queue it sheds.** `IGNIS_FIBER_BUDGET=2 IGNIS_QUEUE_DEPTH=3`, 12 concurrent
`/sleep?ms=300`: **5 × 200 and 7 × 503** — exactly the 2 admitted + 3 queued the configuration
allows.

**(3) What a held request costs.** Same load both arms (`wrk -t4 -c<N> -d20s` on `/sleep?ms=30000`),
RSS sampled at 12 s, queue/fiber counts observed rather than assumed:

| arm | conns | observed | ΔRSS kB | marginal per held request |
|---|---|---|---|---|
| no budget | 2000 | 2001 fibers | 93,456 | — |
| no budget | 4000 | 4001 fibers | 188,920 | **47.7 kB** |
| budget 4, depth 10⁶ | 2000 | 1996 queued | 66,448 | — |
| budget 4, depth 10⁶ | 4000 | 3996 queued | 132,484 | **33.0 kB** |

So the budget removes **14.7 kB** per held request — the fiber — and not the **~33 kB** the held
*connection* costs (hyper buffers plus the kernel socket). At 4000 held requests that is 188,920 kB
against 132,484 kB, **≈30 % less RSS for the same offered load**, with 4 fibers instead of 4001.

**This does not meet the roadmap's "100k queued requests never exceed the configured RSS".** A fiber
budget cannot: the dominant term is the connection. Bounding RSS needs a cap on concurrent
connections at the listener, which is a separate change.

Note against V-5's "34 kB per parked fiber": the *marginal* fiber cost measured here is 14.7 kB.
V-5 measured 10k parked fibers including the 16 KiB VM stack under a different workload; the two are
not the same quantity and this entry does not restate V-5.

**(4) p99 of admitted requests, load inside the budget** (`wrk -t2 -c64 -d8s` on `/`, 3 paired reps):

| rep | budget 0 | budget 512 |
|---|---|---|
| 1 | 59,019 rps, p99 **1.79 ms** | 58,775 rps, p99 **1.85 ms** |
| 2 | 58,380 rps, p99 **1.82 ms** | 57,488 rps, p99 **1.89 ms** |
| 3 | 57,718 rps, p99 **1.90 ms** | 56,979 rps, p99 **1.78 ms** |

Ranges overlap completely; throughput is within ~1 %. Unchanged, as the kill criterion requires.

**Regression net:** `cargo nextest run --workspace` 10/10; `scripts/smoke.sh` **GREEN** (E12 respawn
1, recovery 51,614 vs 53,221 rps).

## V-38 — M1 "Run": `ignis serve`, `ignis.toml`, `/_ignis/health` (product milestone, CONFIRMED)

Date: 2026-09-16T17:5xZ. Box: this one. First milestone under the product mission (DECISIONS,
same date). Acceptance is a user action, not a benchmark: serve an app with a config file and no
environment variables, probe health, and have precedence and errors behave.

```
$ ignis --version
ignis 0.0.1
$ cat ignis.toml
entry = "examples/hello_server.php"
listen = "127.0.0.1:8096"
threads = 2
[budget]
fibers = 7
queue = 9
$ ignis serve --config ignis.toml &
$ curl -w ' [%{http_code}]' http://127.0.0.1:8096/_ignis/health
{"status":"ok","threads":2,"stalled":0,"restarts":0} [200]
$ curl http://127.0.0.1:8096/stats | grep -o '"budget":{[^}]*}'
"budget":{"budget":7,"queue_depth":9,"inflight":1,"queued":0,"queued_peak":0,"queued_admitted":0,"rejected":0}
```

| check | result |
|---|---|
| file values reach the PHP scheduler | budget 7 / queue 9 / threads 2 read back from `/stats` |
| environment beats the file | `IGNIS_FIBER_BUDGET=3 ignis serve --config …` → `/stats` reports budget **3** |
| unknown key is loud | `threds = 2` → `TOML parse error at line 2, column 1`, exit **2** |
| missing entry is loud | `ignis serve` with no file → one-line message naming both ways to give it, exit 2 |
| health is answered by the runtime, not PHP | `/_ignis/health` is served in `http.rs` before dispatch; exempt from the budget by construction |
| defaults without a file | threads = available parallelism, supervise on, budget 1024, queue 4096, exempt `/_ignis/`, listen `127.0.0.1:8080` |

**Defect found by the milestone**: `/stats` read `stalled: 1` on an idle 2-thread server, three
samples in a row, while `/_ignis/health` read 0 at the same instants. `Reactor::touch()` ran only
on `poll` entry, so a thread that had slept 3 s in `recv_timeout` counted as "3 s in PHP" the
moment its first request arrived. Fixed: `poll` also touches when it leaves with work. After the
fix `/stats` and `/_ignis/health` agree at 0.

**Not run here**: the Symfony leg (serve a `symfony/skeleton` with no env vars) — this box has no
composer vendor tree. Carried into M3, where the recipe gets built and verified together.

## V-39 — M2 "Install": the runtime image serves with nothing but itself (CONFIRMED locally; published by CI)

Date: 2026-09-16T18:4xZ. Box: this one. `docker build -f docker/Dockerfile -t ignis:local .` from
the repo root: **55.6 s wall** (build stage FROM `ghcr.io/koekaverna/ignis-php:8.5.10-zts` + rustup,
cache mounts for the cargo registry and `target`; runtime stage `ubuntu:24.04`). Port publishing is
broken on this docker daemon (bindings recorded, never applied — noted in research 24), so the
probe runs inside the container over bash's `/dev/tcp`, which is also how `.github/workflows/image.yml`
checks the image it pushes.

```
$ docker run -d --name ignis-m2 ignis:local
$ docker exec ignis-m2 bash -c 'exec 3<>/dev/tcp/127.0.0.1/8080; printf "GET /_ignis/health HTTP/1.0\r\n\r\n" >&3; cat <&3'
{"status":"ok","threads":24,"stalled":0,"restarts":0}
$ … GET / …
Hello, World!
```

| check | result |
|---|---|
| answers `/_ignis/health` from a cold start | **ok** within the 20 s probe window, 24 threads (the host's cores; `available_parallelism` reports a cgroup CPU quota when one is set — std's documented behaviour, not measured here) |
| serves the entry script | `/` → `Hello, World!` |
| depends on nothing outside the image | `ldd /usr/local/bin/ignis`: **0** "not found"; `libphp.so => /opt/php85-zts/lib/libphp.so` — the rpath compiled in by `build.rs`, so no `LD_LIBRARY_PATH` |
| runs unprivileged | `whoami` → `ignis` |
| size | **64 MB** (runtime stage: six apt libraries, `libphp.so`, the binary, 336 K of userland, the examples) |
| log at the default `warn` floor | empty |

The other half of the acceptance — "from a downloaded artifact on a fresh box" — is what
`.github/workflows/image.yml` does on this push: build, push `ghcr.io/koekaverna/ignis:{latest,sha}`,
then run the pushed image and repeat the two checks above against it. M2 is claimed only when that
run is green.

Not done, kept on M2's list: a static binary. There is no `libphp.a` (`--enable-embed=shared`), and
`libphp.so` pulls ~35 shared libraries through libcurl (krb5, gnutls, ldap, ssh2, rtmp…), so a
`-static-pie` build is a rebuild of PHP with a trimmed curl — a separate piece of work, not a
blocker for "install in under two minutes".

### V-39 addendum — the published image passes the same checks in CI (M2 CONFIRMED)

`.github/workflows/image.yml` run on `574b231`: **success**. It built the image, pushed
`ghcr.io/koekaverna/ignis:latest` and `:574b231…`, started the pushed image and, from inside it,
read `{"status":"ok"…}` from `/_ignis/health` and found no "not found" in `ldd /usr/local/bin/ignis`.
That is the "downloaded artifact on a fresh box" half; with the local half above, M2 is met.

## V-40 — M3, Symfony leg: an untouched `symfony/skeleton` served by the runtime image (CONFIRMED)

Date: 2026-09-16T19:1xZ. Skeleton created by `composer create-project symfony/skeleton` inside the
PHP builder image (`php-cli` there is 8.3.6 NTS — composer's host, not the app's runtime), then
`composer require symfony/runtime`. The Ignis side is a composer package, `php/composer.json`
(`ignis/runtime`: `files` autoload for `ignis.php`/pg/offload/grpc, psr-4 for `Ignis\Symfony\` and
`Ignis\Revolt\`), installed as a path repository with `symlink: false`.

The recipe, and the two things that did NOT work first:

```
composer config platform.php 8.5.10                       # resolve for the app's PHP, not the CLI's
composer config repositories.ignis '{"type":"path","url":"/opt/ignis/php","options":{"symlink":false}}'
composer require ignis/runtime:@dev
composer config extra.runtime.class 'Ignis\Symfony\IgnisRuntime'
composer dump-autoload                                    # regenerates vendor/autoload_runtime.php
# ignis.toml: entry = "/app/public/index.php", listen = "0.0.0.0:8080"
```

- `composer require` failed until `platform.php` was pinned (the builder's CLI is 8.3, the
  package wants ≥ 8.4).
- `APP_RUNTIME=…` in `.env` does **nothing**: symfony/runtime reads it from `$_SERVER` before
  `.env` is loaded, so `public/index.php` ran as one CGI request, printed the page and exited 0 —
  and the supervisor respawned it ten times and gave up ("restart budget exhausted"). The runtime
  class belongs in `composer.json` `extra.runtime.class`.
- `var/` must be writable by the image's `ignis` user (`chmod -R a+rwX var` after composer, which
  ran as root).

Served with `ignis:local`, `threads = 2`, the skeleton at `/app`:

| check | result |
|---|---|
| `/_ignis/health` | `{"status":"ok","threads":2,"stalled":0,"restarts":0}` |
| `GET /` ×3 | `404 Not Found`, body "Welcome to Symfony!", 39 390–39 391 bytes — the bare skeleton's own welcome page (no routes defined) |
| 20 concurrent `GET /` | 20 × `404 Not Found`, all answered |
| health after load | unchanged, restarts 0 |
| container log | only Symfony's own `[error] … NotFoundHttpException: No route found for "GET http://localhost/"` — the welcome 404 in dev mode |

Not done: the Laravel leg, and publishing `ignis/runtime` to Packagist (today it is a path
repository).

### V-40 addendum — three corrections to the recipe, found by M3-3 (agent), confirmed from the mechanism

1. `composer require ignis/runtime:@dev` needs `--no-scripts` when composer runs on a PHP older
   than 8.4 (the builder image's CLI is 8.3.6): `platform.php` satisfies the solver, but the
   generated `vendor/composer/platform_check.php` checks the interpreter running Flex's
   post-install `cache:clear`/`assets:install` hooks and fatals with "require a PHP version
   >= 8.4.0". The V-40 run above did not hit it only because `symfony/runtime` had been required
   before `ignis/runtime` entered the graph; the bench does it in the order a user would.
2. With `--no-scripts` nothing creates `var/`; `mkdir -p var` before the `chmod`.
3. Files composer writes as root in the bind-mounted directory cannot be removed from the host
   without root; `bench/e8-symfony.sh` cleans up through the builder image.

Numbers from the agent's run are **not** recorded here (rule C15); the bare skeleton answers `/`
with the dev-mode welcome 404, so its throughput is a different quantity from V-16's prod-mode 200
route — BACKLOG M3-8 adds the prod-mode leg before any comparison is drawn.

## V-41 — M3-3: E8 bench on the package route, bare skeleton in dev mode (CONFIRMED as a script; number recorded with its caveat)

Date: 2026-09-16T21:5xZ. Box: this one, load 0.99 before the run, no other benchmark running.
`IGNIS_LISTEN=127.0.0.1:8120 bench/e8-symfony.sh`: skeleton built inside the builder image the V-40
way (`--no-scripts`, `platform.php`, path repo, `extra.runtime.class`, `dump-autoload`,
`mkdir -p var`), served by `./target/release/ignis` on the skeleton's own `public/index.php`,
`wrk -t2 -c64 -d10s` on `/`.

| threads | agent's run (sonnet, M3-3) | **main's re-run** | p99 (main) |
|---|---|---|---|
| 1 | 4,663.14 req/s | **4,680.64 req/s** | 57.93 ms |
| 4 | 13,360.23 req/s | **13,629.61 req/s** | 17.72 ms |

Reproduces within 2 %. Server alive after each leg, 0 critical/fatal log lines.

**Caveat that is the point of recording it this way:** every response is the skeleton's dev-mode
welcome page — `404 Not Found`, 39 KB, profiler on (wrk counts all of them as non-2xx). That is a
different quantity from V-16's prod-mode 200 on a real route (7.2k / 25.2k), so no comparison is
drawn here. BACKLOG M3-8 adds the prod-mode leg; until then this is the floor for "the Symfony
kernel handling a request and rendering its error page" on this box.

## V-42 — a PHP thread that dies holding a pg lease leaks the permit for the life of the process (CONFIRMED defect; M4-8)

Date: 2026-09-16T23:0xZ. Found by the M4-8 bencher agent, **re-run by main** (numbers below are
main's; the agent's three runs were identical). `bench/m4-pool-survives.sh` /
`bench/php/m4_pool_survives.php`: `--threads 2 --supervise`, one pool of max 2 (elected through
`flock` because of V-43), two concurrent `/lease-hold?ms=3000`, then `/fatal`.

| moment | created | available | restarts |
|---|---|---|---|
| boot | 0 | 2 | 0 |
| both holds live | 2 | 0 | 0 |
| after `/fatal` and the respawn | 2 | **1** | 1 |

`hold1` got `500 no response from php` (its thread died), `hold2` returned `held 3000 ms`. The
recycled connection was reset correctly — `SHOW search_path` → `"$user", public`, the temp table
is gone — but the dead thread's lease is orphaned inside `LEASES` with its semaphore permit:
`available` never returns to 2. Under load, every worker crash costs the pool one connection until
it is empty. ADR-0015's "pools survive a thread restart" holds for the *connections* and not for
the *permits*. Fix: BACKLOG M4-11.

A dispatcher fact the agent had to work around, worth recording: with one thread busy and one
idle, `Registry::pick()` always chooses the idle one, so a single held lease can never be hit by
`/fatal`; two concurrent holds make the tie-break land on a holder deterministically.

## V-43 — every worker thread opens its own pool; "process-wide" is not what runs (CONFIRMED defect; M4-8)

Same date. `ignis_pg_open($dsn, 2)` at the top of a script served with `--threads 3 --supervise`,
12 requests returning the thread's pool id:

```
      4 pool_id=1
      4 pool_id=2
      4 pool_id=3
```

Three pools, because every thread runs the script and `pg::open` mints a new id per call with no
DSN dedup. ADR-0015 §1 says a pool "is process-wide (shared by all PHP threads)" — true of the
Rust object's ownership, false of what an application gets: `threads × max` connections. At this
box's default of 24 threads and the README's example `max = 20` that is 480 connections against
PostgreSQL's default `max_connections = 100`. Fix: BACKLOG M4-12.

## V-44 — M4-1: a held pg lease is visible and logged (CONFIRMED)

Date: 2026-09-16T23:2xZ. `Lease` carries its acquire `Instant`; `ignis_pg_stats()` gains
`oldest_lease_ms` and `leases_over_warn`; `release` warns past `IGNIS_PG_LEASE_WARN_MS` (default
5000). Reproducer: one fiber holds a lease across `Ignis\sleep(6000)`, another samples the pool
half-way.

```
before  {"idle":1,"created":1,"available":4,"oldest_lease_ms":0,"leases_over_warn":0}
during  {"idle":0,"created":1,"available":3,"oldest_lease_ms":3001,"leases_over_warn":0}
WARN ignis::pg: pg lease held longer than IGNIS_PG_LEASE_WARN_MS lease=3 held_ms=6002
after   {"idle":1,"created":1,"available":4,"oldest_lease_ms":0,"leases_over_warn":0}
```

The owner's question of 17:05Z ("will we see in the logs if someone is holding?") is now yes for
PostgreSQL leases: the age is a gauge while held and a warn line at release. Offload jobs and
stream waits are still not covered — BACKLOG M4-6 inventories them.

### V-42 addendum — fixed (M4-11)

`OWNERS` maps lease id → the acquiring thread's reactor; `http_unregister_current` calls
`pg::release_owned_by`, which spawns `release(id, reset=true)` on the runtime for each — the reset
and the permit return never wait on the dying thread. `bench/m4-pool-survives.sh` after the fix:

```
before /fatal (both holds live): created=2 available=0
after:                           created=2 available=2   idle=2   restarts=1
available back to max: PASS (2 == 2)   search_path default: PASS   temp table gone: PASS
```

### V-43 addendum — fixed (M4-12)

`pg::open` dedupes by DSN (`BY_DSN`); a second opener with a different `max` is warned and gets the
first one's. The V-43 probe with `--threads 3`: **12 × `pool_id=1`** where it was 4 × 1, 4 × 2, 4 × 3.

## V-45 — E18 stage 1: `curl_exec` and `pdo_pgsql` park with no PHP hook and no offload (H32, H33 CONFIRMED with the measured slope)

Date: 2026-09-17T00:5xZ. Box: this one, quiet. Build: `CARGO_TARGET_DIR=target-park cargo build
--release -p ignis --features universal-park`; the default build in `target/` is the control and
serves as the hello_server in every curl run. Policy `IGNIS_PARK=libcurl,libpq`,
`IGNIS_NO_OFFLOAD_ROUTE=1` (so nothing goes to offload workers), hook-off control
`IGNIS_NO_UNIVERSAL_PARK=1`.

**H32 — `curl_exec`, N fibers × `GET /sleep?ms=200`, one PHP thread** (`scratchpad/park_curl.php`,
then the project bench `bench/php/e18_curl.php` via `bench/e18.sh`):

| N | park on | control (no universal park) | WRITEFUNCTION fiber |
|---|---|---|---|
| 1 | 205 ms | — | same |
| 20 | 237 ms | **4,070 ms** | same ×20 |
| 100, my probe, 3 reps | **313 / 326 / 308 ms** | (20 × 200 ms serialise: 4,070 at N=20) | same ×100 |
| 100, `bench/e18.sh` | **279 ms** | **20,337 ms** (agent's run: 20,490) | `writefn_fibers=100 same_fiber=yes` |

**H33 — `pdo_pgsql`, N fresh connections × `SELECT pg_sleep(0.2)`** (`scratchpad/park_pg.php`):

| N | park on | control |
|---|---|---|
| 20 | **214 ms** | **4,110 ms** |
| 100, 3 reps | **296 / 314 / 333 ms** | `bench/e18.sh` control: **20,558 ms** (agent's: 20,551) |

**The slope, and where it is.** 100 concurrent 200 ms waits take ~300 ms, not 200. It is not
the server: the same 100-fiber curl probe against a **4-thread** hello_server gives 337 / 316 ms
and against the 1-thread server 326 / 308 ms. It is not the parking either: ~200 reactor round
trips at 100 fibers cost well under a millisecond (V-33: 0.58 µs at 128 in flight). It is the
libraries' own CPU work serialised on one core — 100 TCP connects plus HTTP parsing (curl), 100
SCRAM handshakes (libpq) — about 1 ms per operation. For scale, the native stream hook's E6 path
measured 206–292 ms per request at 50 concurrent (V-36): the same band.

**What the first attempt taught (recorded because it is the design rule):** the first `curl_exec`
under park hung. Trace: `connect` on curl's *non-blocking* socket forwarded (correct), `poll`
parked and woke (correct), then a `recv` parked on the keep-alive socket after the body had
already arrived — for data the server would never send. curl had asked "is there data now?"
expecting `EAGAIN`; I had turned it into a wait. Rule now in `would_block()`: a data call on a
non-blocking fd always forwards — the library blocks in its own `poll`, and *that* parks. H31
at the syscall layer.

Incidental: the H34 stub-not-wired dns leg (50 libpq connects to a port that hangs on this box)
took 50,067 ms in the control and **1,051 ms** under park — 50 `connect` timeouts ran concurrently
because the connect wait parked. Not an H34 result (ok=0 either way), but the connect path works.

Also measured: the `pgsql` leg of `bench/e18.sh` under park — see the addendum below once
diagnosed. `nextest` on the park build: 10/10. phpt and smoke on the park build: below.

### V-45 addendum — the park build's own gates

`cargo nextest run --features universal-park`: 10/10. E1 on the park build (gate on, every syscall
pays the ~8 ns check): **1,175.9 ms** for 10k × 1000 ms (threshold 1,200; default build today
1,144–1,159); E2 **201.03 ms**, warm per-fiber **3.60 µs** (default 3.48–3.67). phpt: the first run
read 0 passed in every ignis mode — a harness slip, not the build: `IGNIS_BIN` was given as a
relative path and `scripts/ignis-php` runs from the php-src tree (`timeout: failed to execute
process`). Re-run with an absolute path: recorded below when it lands.

Open from this stage: E18-I1 (`bench/php/e18_pgsql.php` exits 0 silently under park; a fiber
writing to stdout works, so the cause is elsewhere).
phpt on the park build, absolute `IGNIS_BIN`, gate on, `IGNIS_PARK=libcurl,libpq`: **identical to
the default build** — fibers 108/78, sockets 91/84, streams 133/125. Stage 1 is gated.

## V-46 — ADR-0037 cycle 1: universal park is the default build, `sleep.rs` deleted, E18-I1 fixed (CONFIRMED)

Date: 2026-09-16T17:43:48Z. Machine: WSL2 (6.18.33), quiet except where noted; `df` 10 % used. Binary:
`target/release/ignis` from the cycle-1 tree (feature `universal-park` default; `park.c` linked;
`nm` shows 11 `ignis_park_*` handlers and the interposed `read/poll/usleep/nanosleep/sleep` in
dynsym). PHP 8.5.10 ZTS at `/opt/php85-zts`.

**Why the policy key is `lib:symbol`.** `nm -D /opt/php85-zts/lib/libphp.so` has no `zif_usleep`,
`zif_sleep`, `php_sleep`, `zif_socket_recv`, `php_sockop_read`, `php_select` (only `PHPAPI` names
such as `_php_stream_read`, `php_network_connect_socket`); the static symtab has 1,937 `zif_*`.
libphp is built `-fvisibility=hidden`, so `dladdr` resolves a libphp return address to the library
only. The interposed symbol is known at the call, and a call site calls exactly one symbol, so the
per-site cache keyed by return address still holds.

**V-22's gate through park, hook deleted** (`bench/php/e15_fixes_sleep.php`, 10 fibers ×
`usleep(200000)`; 3 × `sleep(1)`):

| env | usleep leg | sleep leg |
|---|---|---|
| none (seed) — 5 runs | **202, 202, 202, 202, 202 ms** | **1001 ms** |
| `IGNIS_NO_UNIVERSAL_PARK=1` (runtime off) | 2002 ms | 3000 ms |
| `IGNIS_PARK=` (empty table) | 2002 ms | 3001 ms |

Runs made with `env "$multi_word"` were discarded: zsh does not word-split an unquoted variable,
so `IGNIS_NO_SLEEP_HOOK` received the value `1 IGNIS_PARK=libphp` and the table stayed empty — the
"anomaly" those runs showed was the shell, not the binary. Every number above uses explicit
assignments.

**Suites on the new binary.** `cargo nextest run --workspace`: 10/10 (twice). `bench/e15-phpt.sh` +
`scripts/ci-gate.sh phpt` (run twice, before and after the `ignis.php` fix; identical):

| suite | stock | main (baseline) | fiber (baseline) |
|---|---:|---:|---:|
| Zend/tests/fibers | 108 | 108 (108) | 78 (78) |
| ext/standard/tests/streams | 139 | 133 (133) | 125 (124) |
| ext/sockets/tests | 91 | 91 (89) | 84 (83) |

Gate exit 0, six cells ≥ baseline, three above it (baseline left as is; CI decides whether the
gain is stable).

**Deletion, measured.** `php/sleep.rs`: −112 lines, −7 `unsafe {`, −8 `unsafe fn`. Tree after:
Rust **6,230** lines (from 6,326; `park.rs` grew by the grammar and a per-site trace), **180**
`unsafe {` (from 187), **146** `unsafe fn` (from 154). `IGNIS_NO_SLEEP_HOOK` is gone; the revolt
bench's "no sleep hook" variant became `IGNIS_NO_UNIVERSAL_PARK=1`.

**Research 30 group (b)** (libphp's `sleep`/`usleep`/`nanosleep` call sites, php-8.5.10): three in
`ext/standard/basic_functions.c` PHP-function bodies with no lock; `main/streams/plain_wrapper.c:446`
is Windows-only; `ext/opcache/ZendAccelerator.c:863/874` sits under `zend_shared_alloc_lock()` in
`ZEND_RINIT_FUNCTION(zend_accelerator)` — RINIT, main fiber, gate 0, so it forwards. Verdict `park`
for the group.

**E18-I1, found and fixed.** `bench/php/e18_pgsql.php` (outer fiber → 100 inner fibers → `new PDO`
+ `pg_sleep(0.2)` → `Ignis\all()` inside the outer fiber) exited 0 with no output under park.
`RUST_LOG=ignis=debug`: "stream completion with no parked fiber" ×100 after the loop had left.
Cause: both idle checks in `Loop::runUntil()` tested `$waiting`, `$requestHandler` and
`ignis_inflight()` only; `ignis_poll()` resumes C-parked fibers itself, the inner fiber's
`resolve()` puts the outer fiber in `$ready`, and with nothing in flight the loop broke out.
Reproduced with `usleep` under park, with the *old* sleep hook (pre-existing, not cycle 1's) and
with the offload route off (not an offload interaction); V-45's flat script never hit it because
its top-level `all()` stops on `$stop()`. Fix: the checks also require `$ready === [] && $pending
=== []`. After:

| script | env | wall |
|---|---|---|
| nested probe, 10 × `usleep(200000)` | seed | 201 ms (before: silent exit) |
| nested probe, 10 × pgsql `pg_sleep(0.2)` | seed, `IGNIS_NO_OFFLOAD_ROUTE=1` | 208, 209 ms (before: silent exit) |
| `e18_pgsql.php` n=100 | `IGNIS_PARK=libpq IGNIS_NO_OFFLOAD_ROUTE=1` ×3 | **308, 301, 303 ms**, ok=100 (H33 through the project bench, not only V-45's scratch script) |
| `e18_pgsql.php` n=100 | seed, route off | 303 ms |
| `e18_pgsql.php` n=100 | seed, offload route on (the default product path) | 326 ms |
| `e18_pgsql.php` n=10 | `IGNIS_PARK=` (off) | 2055 ms, ok=10 (blocks: control) |

**Perf gate (ADR-0037 §4).** E1/E2 on the park build: V-45 addendum (1,175.9 ms / 201.03 ms,
inside V-28's ±1 % band). E4/E5 on vs off: addendum below.

### V-46 addendum — park on vs off, same box, back to back (2026-09-17)

Off build: `CARGO_TARGET_DIR=target-nopark cargo build --release -p ignis --no-default-features`
(`nm`: 0 `ignis_park_*`). Load average 3.6–4.2 during the runs (smoke and the off build had just
finished) — **not a quiet box**; the on/off comparison is back-to-back under the same conditions,
the absolute numbers are not the V-6/V-28 ones.

| expectation | park on (default) | park off | note |
|---|---|---|---|
| E1 `e1_sleep_10k.php` N=10000 MS=1000, wall ms ×3 | 1154.6 / 1143.4 / 1145.2 | 1188.0 / 1165.0 / 1144.5 | both < 1200; on ≤ off |
| E2 `e2_all.php` N=10000: all3x200 ms, warm µs/fiber ×3 | 201.72 / 201.61 / 200.86; 3.68 / 3.69 / 3.62 | 200.71 / 201.77 / 200.73; 3.32 / 3.72 / 3.64 | identical within noise |
| E5 `e5_cpu.php` 4 threads, ms per thread ×3 | 38.8–42.1 (one outlier), typically 38.8–39.5 | 38.6–39.8 | identical |
| E4 `hello_server` 1 thread, `wrk -t2 -c64 -d10s`, 1 run | **58,208 req/s**, p99 1.78 ms | **59,115 req/s**, p99 1.78 ms (60 timeouts) | −1.5 % on, single run, loaded box: indicative; the quiet three-run band is the next addendum |

Gate cost stays consistent with research 28 (≈8 ns per interposed call): nothing here is
distinguishable from noise. ADR-0037 §7's E4/E5 band from three quiet runs: pending.

### V-46 addendum 2 — E4 quiet band, park on vs off, alternating ×3 (2026-09-16T17:52Z)

Waited for load < 1.0 (start 0.93 1.76 1.38; end 3.91 2.45 1.65); `hello_server` 1 thread on
127.0.0.1:8181, `wrk -t2 -c64 -d10s --latency`, arms alternated on/off/on/off/on/off.

| arm | req/s (p99 ms) ×3 |
|---|---|
| park on (default build) | 57808.43 (1.86) / 58044.54 (1.80) / 58563.78 (1.77) |
| park off (`--no-default-features`) | 57692.79 (1.86) / 58057.23 (1.85) / 56958.18 (1.84) |

The bands overlap completely: **no measurable gate cost on the hello path** (ADR-0037 §4/§7
E4 gate met). Separate finding: both arms sit at ~58k req/s where V-6 measured 128k on this box
(2026-09-15) — equal arms mean it is not park; BACKLOG H-12.

## V-47 — E18 stage 2 symbols: accept/accept4, select, ppoll, __poll_chk, recvmsg/sendmsg, readv/writev (CONFIRMED for `select`; the rest built and gated)

Date: 2026-09-16T17:57:25Z. Commit 6586626. `nm target/release/ignis`: 18 `ignis_park_*` handlers; the nine new
names exported in dynsym. Gates on this binary: `cargo nextest run --workspace` 10/10;
`bench/e15-phpt.sh` + `scripts/ci-gate.sh phpt` — fibers 108/78, streams 133/125, sockets 91/84
(main/fiber), all ≥ baseline, exit 0.

**`select` gate** (`scratchpad/select_probe2.php`: 3 fibers, each `stream_select()` on a silent
`stream_socket_pair` with a 200 ms timeout; `IGNIS_NO_ACCEPT_HOOK=1` turns off the PHP-level
`stream_select` hook, which lives in `accept.rs` — the record said `stream.rs`, corrected):

| policy | total | evidence |
|---|---|---|
| hook off, `IGNIS_PARK=` | **601 ms** | blocks: three sequential waits |
| hook off, `IGNIS_PARK=libphp:select` | **201 ms** | trace: 3 × `select n=… timeout=200: not ready, parking on 1 fds`, 3 × `woke by timer, fill r=0` |
| hook off, `IGNIS_PARK=libphp:poll` | 601 ms | `php_select` waits in `select(2)`, not `poll` — the row must name `select` |
| hook on (today) | 202 ms | the PHP-level hook, for reference |

**Cycle-2 preview** (`bench/php/e15_fixes_server.php`: server socket + client on one thread):
`IGNIS_NO_STREAM_HOOK=1 IGNIS_NO_ACCEPT_HOOK=1 IGNIS_PARK=libphp:select,libphp:accept,libphp:poll,
libphp:recv,libphp:send,libphp:connect,libphp:read,libphp:write` → `accepted / server got 'ping' /
client got 'pong'`, exit 0; sites parked from libphp: connect ×1, poll ×2, recv ×1, send ×1 (the
binary's own `write` forwards). The rows that enter the seed are decided by research 30 groups
(a)/(c), not by this preview.

### V-46 addendum 3 — H-12: the V-6 commit rebuilt on today's box (2026-09-16T18:01Z)

`git worktree add /tmp/cmp/v6 db2df39` (the commit V-6 measured, 2026-09-15 22:54), built with its
own target dir, `hello_server` on :8182 (that binary has no `--threads`; single thread as in V-6),
HEAD (`fb83df1`, park default) on :8181 with `--threads 1`; `wrk -t2 -c64 -d10s --latency`,
alternating, load 0.39 at start.

| arm | rep 1 | rep 2 |
|---|---|---|
| db2df39 (V-6's code) | **63,055 req/s**, p99 1.67 ms | **61,542**, p99 1.71 ms |
| HEAD | **60,035**, p99 1.81 ms | **58,267**, p99 1.82 ms |

So V-6's 128k is the 4-vCPU box of 2026-09-15, not this 24-vCPU WSL2 (6.18) box: the same code
gives ~62k here. The code-side difference 2026-09-15 → today is **≈ 4–5 %** (61.5–63.1k vs
58.3–60.0k, p99 +0.1 ms) — not universal park (on/off equal, addendum 2). Tree deleted after
recording (disk rule).

## V-48 — ADR-0037 cycle 2: `sockets.rs` and `accept.rs` deleted, the audited libphp rows carry their calls (CONFIRMED)

Date: 2026-09-16T18:25:37Z. Two phases on the same tree, quiet box, suites one at a time.
**Phase 1** — hooks compiled but off (`IGNIS_NO_SOCKETS_HOOK=1 IGNIS_NO_ACCEPT_HOOK=1`), rows via
`IGNIS_PARK=libphp:sleep,usleep,nanosleep,select,accept,poll,recv,send,recvfrom,sendto,recvmsg,sendmsg,connect,read,write,libcurl,libpq,libssl,libcrypto`.
**Phase 2** — the two files deleted, the same rows in the built-in seed, no env.

| gate (the test that created the hook) | phase 1 | phase 2 |
|---|---|---|
| `a4_sockets.php` N=20 DELAY_MS=200 (V-29: 20 concurrent `socket_read`) | 243.7 ms, ok=20 | **246.6 ms, ok=20** (V-29 with the hook: ~222; without: stalls) |
| `a4_unix.php` (V-29) | 202.8 ms, ok=10 | 201.6 ms, ok=10 |
| `a4_overhead.php` 100k `socket_sendto`, µs/call | 8.04 | 13.57 (V-29's own spread is 7.4–13.7: this bench cannot resolve it, as V-29 says) |
| `e18_timeo.php` — 3 fibers, `SO_RCVTIMEO`=200 ms on `socket_recv` (new; research 30's regression risk) | 3 × `false`/EAGAIN after 201 ms, total 201 ms | **3 × `false`/EAGAIN after 200 ms, total 201 ms** (blocked would be 600, a naive park never returns) |
| `e15_fixes_server.php` (V-22/V-26: listen + accept + client on one thread) | ping/pong | ping/pong |
| `e15_fixes_sleep.php` (V-22) | 201 / 1002 ms | 202 / 1001 ms |
| select probe, 3 × `stream_select` 200 ms | 202 ms | 202 ms |
| nested `all()` probe (E18-I1) | 202 ms | 202 ms |
| nextest | 10/10 | 10/10 |
| phpt gate (main/fiber): fibers, streams, sockets | 108/78, 133/124, 91/83 — all ≥ baseline | **108/78, 133/124, 91/83 — all ≥ baseline** (fiber sockets 84→83 and streams 125→124 against the hook-on run of V-46; both at the CI baseline) |
| Swoole shim `--all` (gate 54) | 54/153 | **54/153** |
| Revolt DriverTest (gate 80) | 80/81 (`testNoMemoryLeak`, the known race) | **80/81** |

**Deleted, measured:** `php/sockets.rs` 326 lines / 21 `unsafe {` / 16 `unsafe fn`; `php/accept.rs` 315 /
11 / 12 → **−641 lines, −32 blocks, −28 fns**. Tree after phase 2: Rust **5,817** lines, **161**
`unsafe {`, **129** `unsafe fn` (from 6,230 / 180 / 146 after cycle 1; park grew by the SO_*TIMEO race
and the stage-2 handlers). The dead adoption path the hooks fed (`stream.rs::adopt_fd`,
`has_buffered`, `reactor::Op::Adopt` + its arm, `zval::set_double`) is cut after this table; the
final recount is the addendum.

**Local-gate fixes made on the way** (environment, not runtime): `bench/e15-revolt.sh` regenerates
`ignis.ini` per checkout (the committed one hardcodes `/home/user/…`, rc=255 on any other box);
Swoole needs `~/cmp/swoole-src` (cloned, 25 MB); AMPHP deps via the `composer` Docker image (no
php-cli, no `ext/phar` here).

### V-48 addendum — final recount after the dead adoption path was cut

Build clean (no warnings), nextest 10/10. Tree: Rust **5,753** lines, **157** `unsafe {`, **126**
`unsafe fn` — against 6,326 / 187 / 154 before ADR-0037 cycle 1: **−573 lines, −30 `unsafe {`,
−28 `unsafe fn` net** (three hooks and their adoption path deleted, 1,121 lines; park grew by the
policy grammar, stage-2 symbols and the SO_*TIMEO race). Research 29's measured "deletes" for
rows 2–4 (753 lines) are now real; row 1 (the stream factory, 705 lines + the actor arms) is §6
step 4.

### V-48 addendum 2 — smoke on this box, and a gate defect

`scripts/smoke.sh` exit 0 on the final binary with `IGNIS_LISTEN=127.0.0.1:8183`. Found on the
way: smoke's default was `:8080`, which another project holds on the owner's box and which answers
`/` with 200 — the app.php leg's readiness check (`curl -sf`, not content-based) accepted the
stranger, so the morning's "green" smoke had printed that project's `NotFoundHttpException` for
`/sleep?ms=5` as if it were ours, and the E13 HTTP leg (content-based) was the one that failed
honestly. The default is now `:8183`; every bench and example reads `IGNIS_LISTEN`.

## V-49 — ADR-0037 §6 step 4: the stream transport factory and the rustls path are deleted; PHP's own TLS parks (CONFIRMED)

Date: 2026-09-16T18:57:02Z. The last of the four point mechanisms. `php/stream.rs` (705 lines: `tcp://`/`ssl://`/
`tls://`/`unix://` factories, `op_read/write/connect/cast/set_option`, timeouts, META_DATA_API) is
gone; its **park registry** — the part that is not a transport, `(op id → suspended fiber)` +
`await_op`/`await_any`/`resume_parked`/`ignis_cancel_parked_any` — moved verbatim to
`php/wait.rs` (151 lines), which is what universal park and `ignis_watch` have been using all
along. In `reactor.rs`: `Op::Connect/ConnectUnix/Upgrade/Read/TryRead/Write/Close`, `ConnCmd`, the
connection actor, `adopt`, `forward`, `socket_meta`/`unix_meta`, `tls_config`/`tls_wrap` and the
two certificate verifiers (`NoVerify`, `NoNameCheck`) are deleted — 812 → **450 lines**.
`rustls`, `tokio-rustls`, `webpki-roots`, `rustls-pemfile` are out of `crates/ignis/Cargo.toml`;
`cargo tree -i rustls` no longer resolves (the lockfile still lists them as tonic's *optional*
feature and h2's dev-dependency, neither built).

Two phases, as in cycle 2. **Phase 1** — factory compiled but off (`IGNIS_NO_STREAM_HOOK=1
IGNIS_NO_SSL_HOOK=1 IGNIS_NO_UNIX_HOOK=1`), seed policy. **Phase 2** — files deleted, seed only.

| gate (the test that created the factory) | phase 1 | phase 2 |
|---|---|---|
| E6 `bench/e6-fetch.sh` N=50 (V-12: unmodified `file_get_contents`, 3 × 200 ms) | n=50 ok=50, 317 ms | **n=50 ok=50, 349 ms** (per request 207–275 ms) |
| E6 ssl `bench/e6-ssl.sh` (V-25: `ssl://` + STARTTLS) | 3 concurrent https × 200 ms in **207 ms**, bodies correct | same, client exit 0 |
| `a4_unix.php` (V-31 `unix://`) | 202.6 ms, ok=10 | 201.6 ms, ok=10 |
| `e15_fixes_server.php` (V-26) | ping/pong | ping/pong |
| **A6 / B7** `a6_tls_select.php` — TLS read-ahead visible to `stream_select` (research 23, open since cycle 22) | — | **PASS, select answered in 22.9 µs** (64 KB body; with the rustls factory: PASS in 78–86 µs via `has_buffered`) |
| select probe, 3 × `stream_select` 200 ms | 202 ms | 202 ms |
| `e18_timeo.php` (SO_RCVTIMEO) | 202 ms, 3 × EAGAIN | 202 ms, 3 × EAGAIN |
| phpt main/fiber: fibers, streams, sockets | 108/78, 133/124, 91/84 | **108/78, 133/124, 91/84** — all ≥ baseline |
| Revolt DriverTest (gate 80) | 80/81 | **80/81** |
| Swoole shim `--all` (gate 54) | — | **55/153** (+1 over the baseline) |
| chaos (V-27) | — | **exit 0, no new failures vs stock** |
| `scripts/smoke.sh` | — | **exit 0** |
| `cargo nextest` | — | 9/9 (the actor's own test, `tcp_connect_write_read_close`, went with the actor) |

**B7/A6 closes by disappearance**, as ADR-0037 §4 predicted: PHP's `ext/openssl` holds its own
plaintext buffer, so `stream_select` sees it without the runtime knowing anything about TLS.

**A CI red explained and fixed by the deletion.** Run 35134937803 on `17a2ceb` failed
`phpt.fiber.ext_sockets_tests=82 < 83`: `socket_export_stream-1.phpt` returned `string(0) ""`
instead of `"test message"`. Reproduced locally 20/20 in fiber mode, and bisected to the factory,
not to park: with `IGNIS_NO_STREAM_HOOK=1` it passed, with every `IGNIS_PARK` row and with
`IGNIS_NO_UNIVERSAL_PARK=1` it still failed. `socket_export_stream()` wrapped the socket in the
hooked transport, which read through the reactor's actor and lost the peer's bytes after
`socket_close()`. After step 4 it passes — fiber sockets 83 → **84**.

**Deleted, measured:** `php/stream.rs` 705 lines / 16 `unsafe {` / 17 `unsafe fn`, minus the 151
lines that moved to `wait.rs`; `reactor.rs` −362 lines. Tree: Rust **4,890** lines, **145**
`unsafe {`, **116** `unsafe fn`. Against the state before ADR-0037 cycle 1 (6,326 / 187 / 154):
**−1,436 Rust lines, −42 `unsafe {` blocks, −38 `unsafe fn`**. Release binary **37,180,624 bytes**
(48.7 MB before the cycles — the TLS stack and the actor are gone). Mechanisms a wait can take:
**3** — park, offload, context. ADR-0037's target model is reached.

## V-50 — a regression the count gate hid: `can_block` never moved into `would_block` (CONFIRMED, fixed)

Date: 2026-09-16T19:04:00Z. Found by the porter agent's classification of the remaining phpt failures
(docs/research/33-phpt-remaining-failures.md), re-run by main.

**The defect.** ADR-0037 §6 step 3 says, in its own words, that A4's `can_block` rule (a listening
or unconnected socket forwards) moves into `would_block` *before* the `ext/sockets` hooks go. It
did not. `php-src ext/sockets/tests/socket_read_params.phpt` — `socket_read()` on a socket from
`socket_create_listen(0)` — must print a warning at once (the kernel answers `ENOTCONN`); under
universal park it parked on `POLLIN` for a connection that never comes and the test timed out.

| commit | fiber-mode status of `socket_read_params.phpt` |
|---|---|
| `6780e1b` (hooks present) | PASSED |
| `17a2ceb` (cycle 2: `sockets.rs`/`accept.rs` deleted) | **FAILED** — `** ERROR: process timed out **` |
| `55bb5b4` (cycle 3) | FAILED |
| this fix | **PASSED** (re-run by main, run-tests, fiber mode) |

**Why the gate did not catch it.** `scripts/ci-gate.sh` compares pass *counts*. In the same run
`socket_export_stream-1.phpt` recovered (V-49), so fiber sockets read 83 → 84 while one test broke
and another healed. A swap of equal size is invisible to a total. Fixed in the same commit:
`check_set()` compares the fresh per-test `.tsv` against the one committed in HEAD and fails on any
test that PASSED there and does not now, ignoring tests absent from either side (the CI box skips
more — research 21). It warns instead of failing when `$CI` is set, until a set from the CI box is
committed; the counts stay a hard gate there.

**The fix.** `would_block()` in `crates/ignis/src/php/park.rs` now carries the rule the hooks owned
(`getsockopt_int`, `is_connected`, `is_bound`, copied from the deleted `sockets.rs`): a listening
socket (`SO_ACCEPTCONN == 1`) never parks — a data call on it errors now; a connected socket parks;
an unconnected one parks only if it is a `SOCK_DGRAM` with a local address (`recvfrom` on a bound
UDP socket waits legitimately), otherwise the `ENOTCONN` reaches the caller unparked. `accept` asks
none of this — it has its own handler and parks on a listener by definition.

**After, full suite** (`bench/e15-phpt.sh` + gate, this box):

| suite | stock | main | fiber | baseline (main/fiber) |
|---|---:|---:|---:|---|
| Zend/tests/fibers | 108 | 108 | 78 | 108 / 78 |
| ext/standard/tests/streams | 139 | 133 | **125** | 133 / 124 |
| ext/sockets/tests | 91 | 91 | **85** | 89 / 83 |

Gate exit 0 on both checks, counts and sets. `cargo nextest` 9/9. Fiber sockets 85 is the highest
this suite has been; the baseline file is left alone (ADR-0023 §1: raise only from two CI samples).

**Two rules the audit turned into code comments rather than prose** (research 30 groups (d)/(e),
agent, spot-checked by main): `fcntl` must never join the interposed symbols — the one lock-held
blocking call in libphp is `fcntl(F_SETLKW)` inside opcache's `zend_shared_alloc_lock()`, taken
with the TSRM mutex held on every cache-miss compile, and parking there deadlocks the thread
(warning now sits in `crates/ignis/build.rs` next to the symbol list); and a blocking call on a
*regular file* is nobody's mechanism — epoll refuses regular files, so `ext/session`'s
`flock(LOCK_EX)` on the session file stalls the whole OS thread, not one fiber (ADR-0024 non-goal,
BACKLOG R-SESS).

## V-51 — H36: the lock hazard is real and the policy contains it (CONFIRMED, the last of the owner's five E18 acceptances)

Date: 2026-09-16T19:09:40Z. Found missing by the doc reconciliation earlier today: ADR-0020 acceptance 5,
ADR-0037 §5's risk table and research 30's acceptance all cited "H36's shim", and the shim had
never been written, let alone run — every `park` row rested on the source audit alone.

**What was built.** `bench/e18/locklib.c` (research 27's sketch, now real): `locklib_call(fd, buf,
len, trylock)` takes a non-recursive `pthread_mutex`, calls `read(fd)`, unlocks — the hazard shape
exactly. `nm -D` confirms it imports `read@GLIBC_2.2.5`, so inside the process it binds to the
interposer in the executable (research 28). This build has no `ext/ffi` and PHP hands out no raw
fds, so `crates/ignis/src/php/locklib.rs` registers four internal functions **only when
`IGNIS_LOCKLIB` names the .so** (`zend_register_functions` at MINIT, not the static table, so a
normal build has no trace of them): `ignis_locklib_pipe`, `ignis_locklib_write`,
`ignis_locklib_feed`, `ignis_locklib_call`.

**The test** (`bench/php/e18_deadlock.php`, driven by `bench/e18-deadlock.sh`): one PHP thread, an
empty pipe, two fibers calling `locklib_call`; fiber 1 passes `trylock`, so a mutex held by another
fiber is *reported* (`-2`) instead of hanging the harness. A detached **OS thread** feeds the pipe
one byte every 200 ms — the first version had a fiber do it, and the `block` arms timed out at 30 s
because the PHP thread was inside `read` and the writer fiber could never run: the control was
measuring the stall, not the lock. That version's numbers are discarded.

| policy | fiber 0 | fiber 1 | total |
|---|---|---|---|
| `IGNIS_PARK=liblocklib` (**park**) | returned 1 after 200 ms — it **parked inside `read` holding the mutex** | **`-2` at 0 ms: the mutex is held by a parked fiber** | **200 ms** |
| `IGNIS_PARK=libcurl` (liblocklib absent → **block**) | 1 after 200 ms | 1 after 200 ms | **400 ms**, serialized |
| `IGNIS_NO_UNIVERSAL_PARK=1` | 1 after 200 ms | 1 after 200 ms | 400 ms |

Two runs, identical. `cargo nextest` 9/9, build clean.

**Reading.** H36's statement holds: a library that holds a lock across a blocking call **does**
break under `park` — fiber 0 suspends inside the critical section and the mutex stays owned by a
fiber that will not run until the loop resumes it, so any other fiber on that thread is stuck — and
**does not** break under `block`, where the calls simply serialize. The hazard model behind ADR-0020's
policy table is confirmed by measurement, not only by reading source. It also confirms the
converse: the source audit is not optional decoration — research 27 and 30 are what keep
libcurl/libpq/OpenSSL/libphp off this outcome, and a future row added without an audit gets exactly
this failure, silently (in a real library there is no `trylock` to report it — the thread just
stops).

With this, all five owner acceptances of E18 have a number: (1) (2) V-45, (3) V-45 + research 28
(curl's threaded resolver is caught by `poll`, not by a resolver op — recorded as "not as written"),
(4) research 28's 8.3 ns, (5) here.

## V-52 — the boot self-check refuses to start when interposition does not bind, and the failed-park counter (CONFIRMED)

Date: 2026-09-16T19:21:02Z. ADR-0037 §4(a) and research 32's spec, implemented and both of its arms measured.
Universal park's failure mode is a hang, not an exception, so "it looked fine" must stop being
possible — research 28's mistake (a "0 hits" run that read as success) is now mechanical.

**What it does.** On the main thread, after PHP MINIT (so `dlopen(…, RTLD_NOLOAD)` sees the
extensions' libraries) and before any worker thread exists, for each third-party library the
*resolved* policy names and that is actually loaded, the check makes that library's own compiled
code call an interposed symbol and verifies the call reached us (recorded in `site_parks` behind a
`PROBING` flag, so nothing is added to any hot path). libphp is not probed: it is always loaded and
its call sites are covered by the source audit, not by binding.

| case | behaviour |
|---|---|
| policy names libcurl and libpq, both loaded | `park self-check ok probed=["libcurl","libpq"] hits=3` |
| a probed library makes no interposed call (negative control, `probe_libpq` neutered and rebuilt) | **refuses to start: exit 2**, `ignis: universal park is enabled and the policy names ["libpq"], but a call made by its own code did not reach the interposed symbols …` |
| the same build with `IGNIS_SKIP_PARK_SELFCHECK=1` | starts, runs normally (warned) |
| `IGNIS_PARK=` (empty policy) | `no third-party library in the policy, nothing to probe`, starts |
| `IGNIS_NO_UNIVERSAL_PARK=1` | skipped, starts |

**Boot cost, and a defect found by measuring it.** The first version probed
`connect()` to `127.0.0.1:1`, and cost **1.3 s on every process start** — on this box a `connect`
to a closed loopback port does not answer `ECONNREFUSED`, it hangs until the timeout (a fact
already recorded in `bench/e18.sh`'s comments and not carried into the design). At ~300 short-lived
processes per phpt suite that is five minutes of pure waiting. Both probes now target a
**nonexistent unix socket path** (`CURLOPT_UNIX_SOCKET_PATH=/nonexistent/…`,
libpq `host=/nonexistent`): the kernel answers `ENOENT` at once, no network, no DNS, no listener.

| | trivial script, 3 runs |
|---|---|
| self-check on (unix-socket probes) | **0.01 s** |
| `IGNIS_NO_UNIVERSAL_PARK=1` | 0.01 s |
| self-check on (first version, loopback probes) | 1.3 s |

**The other half of ADR-0037 §4(b).** `PARK_FAILED` counts, and `warn` logs, every call whose policy
says `park` and which could not park and blocked the thread instead — the four helpers
(`park_on`, `park_io`/`park_pollfds`, `park_sleep`) report it at the moment they give up, so the
alarm is immediate and needs no timing on any path. The *other* case in the spec — a `block` row
that blocks longer than N ms — is **not built**, deliberately: a thread stuck in a syscall cannot
report on itself, so that half belongs to the watchdog (ADR-0012) reading a per-thread "forwarding
since" marker, and is recorded as such rather than half-built.

**Gates** (this changes every process start, so the full set): `cargo nextest` 9/9; phpt counts and
per-test sets all ≥ baseline (108/78, 133/125, 91/85), gate exit 0; `scripts/smoke.sh` exit 0;
H36 re-run unchanged (park 200 ms with fiber 1 at `-2`, block and off 400 ms).

## V-53 — the owner's Symfony 8.1 app on the three-mechanism binary, and two classic-mode defects it does NOT hit (CONFIRMED)

Date: 2026-09-16T19:35:09Z. Owner's question: can the Symfony project be tested already. It can — `../symfony-ignis`
is wired to Ignis through `symfony/runtime` (`APP_RUNTIME=Ignis\Symfony\IgnisRuntime`, composer
psr-4 `Ignis\Symfony\ -> ../ignis/php/symfony/src`, `files: ../ignis/php/ignis.php`) and normally
runs in its own container on :8080. Its container was **not touched**: a second instance ran from
the same checkout with `APP_ENV=test` (so `var/cache/test`, a different directory from the running
dev instance) on 127.0.0.1:8187, with today's `target/release/ignis` — universal park default,
three mechanisms, no rustls, boot self-check on.

| | |
|---|---|
| readiness, first request | 200, body `{"hello":"ignis","php":"8.5.10"}` |
| single requests, 3x | 200 in **1.4 / 1.9 / 2.4 ms** |
| `wrk -t2 -c64 -d10s`, 4 PHP threads, 3 rounds | **21,127 / 21,029 / 21,272 req/s**, p99 **28.6 / 28.7 / 27.4 ms**, 0 socket errors, 0 non-2xx |
| RSS | 48.4 MB at start -> **118.5 / 118.7 / 118.6 MB** across the three rounds (flat after the first) |
| fatals / uncaught in the log | 0 |

The box was not quiet (load 1.2) and this is the app's own JSON route, not a template render, so the
throughput is a floor, not a benchmark — what it establishes is that a real Symfony app is unchanged
by three cycles of deletion.

## The two classic-mode defects (found on the way, NOT hit by Symfony)

Both come from the same fact: in `Ignis\Classic` the entry script is `include`d **inside a fiber**,
in one long-lived PHP request. Measured against stock `php -S` on the same two files:

| script shape | stock `php -S` | Ignis classic mode |
|---|---|---|
| `$wpdb = ...` at top level, a function reading `global $wpdb` | `top-level='handle#1172' GLOBALS='handle#1172' global-in-fn='handle#1172'` | `top-level='handle#2868' GLOBALS=NULL global-in-fn=NULL` (3 requests, same every time) |
| an unguarded top-level `function legacy_helper()` | request 1 and 2 both print `fn.php ok` | request 1 ok, **request 2 empty** — `Fatal error: Cannot redeclare function` |

Cause: a file included inside a closure has its top-level variables as *locals of that closure*, so
they never reach `$GLOBALS` and `global $x` finds nothing; and a function declared by the include
stays in the function table for the life of the worker, so the next request redeclares it. php-fpm
and `php -S` are unaffected because each request is a fresh PHP request in a fresh scope.

This is a **product-level compatibility gap for legacy apps** (WordPress's `global $wpdb`, Drupal,
any procedural docroot), not a test artefact — the phpt classification (research 33) had five
`Zend/tests/fibers/destructors_*` rows blamed on "GC + destructor + Fiber"; re-run by main, the
mechanism is this one, and the phpt harness hits it for the same reason classic mode does.
Symfony does not hit it: `symfony/runtime` returns a closure and the Kernel keeps state in objects,
never in entry-script globals — which is exactly why V-53's numbers are clean.

Not fixed here: the fix is an ADR-level choice (run a classic entry on the thread's main context
instead of a fiber, losing in-request concurrency for that mode; or a per-request function-table
and scope reset, losing the worker model's whole point). Recorded as BACKLOG R-GLOBALS with the
options and this reproducer.

## V-54 — classic mode gets a top-level worker loop: entry-script globals work (CONFIRMED); the redeclare half does not and cannot cheaply

Date: 2026-09-16T20:10:04Z. Half of R-GLOBALS (V-53) fixed, and a wrong entry in my own backlog corrected.

**Where an include must run for its top-level assignments to become globals** (`scratchpad/scope_probe.php`,
one script, five placements):

| the include runs... | `global $probe` in a function afterwards |
|---|---|
| at the top level of the main script | **`'value#499'`** |
| inside a plain function | NULL |
| inside a closure | NULL |
| inside a Fiber | NULL |
| inside a closure after `extract($GLOBALS, EXTR_REFS)` | NULL (it aliases *existing* globals; a new variable is still local) |

So BACKLOG R-GLOBALS's option (1) — "run a classic entry on the thread's main context, not in a
fiber" — was wrong: `Loop::runUntil()` is a method, and a method's scope is no more global than a
fiber's. Corrected in place.

**What was built.** `Ignis\Classic\listen()` / `accept()` / `respond()`: the loop belongs to the
user's own script, the shape RoadRunner and FrankenPHP's worker mode use.
`Loop::$rawRequestHandler` hands a request to the loop's caller instead of spawning a fiber for it
(and both idle checks now keep the loop alive when it is set). `examples/classic_worker.php` is the
copy-paste version.

| | stock `php -S` | classic `serve()` (fibers) | classic worker loop (new) |
|---|---|---|---|
| `$wpdb` at top level → `$GLOBALS['wpdb']` | set | **NULL** | **set** |
| `global $wpdb` in a function | set | **NULL** | **set** |
| 3 requests in a row | 3 distinct values | 3 × NULL | **3 distinct values** |
| 200 sequential requests | — | — | **198 distinct of 200** (two `mt_rand` collisions, not shared state) |
| 404 for a missing path / static file / query string | — | — | 404 / served / parsed |
| throughput, 1 thread, `wrk -t1 -c8 -d5s` | — | — | **10,526 req/s** |

**Not fixed: the second half.** An unguarded top-level `function foo() {}` still survives into the
next request, and PHP's "Cannot redeclare function" is a fatal that no handler can catch — it ends
the worker (the supervisor respawns the thread; the in-flight request is lost). The only real fix
is a PHP request cycle per HTTP request (RINIT/RSHUTDOWN), i.e. giving back exactly the bootstrap
cost the worker model exists to avoid — an ADR, not a patch. Documented instead, as every worker
runtime documents it: `require_once`, or guard with `function_exists()`.

**Gates.** `cargo nextest` 9/9; **FrankenPHP testdata 29 ≥ baseline 29** (this is `classic.php`'s
own gate — the first local run read 1 because `~/frankenphp` was not checked out on this box, the
same class of local-gate hole as the Swoole clone earlier; with `testdata` present, 29);
`scripts/smoke.sh` exit 0.

## V-55 — M4-4 `/_ignis/metrics`, the startup banner, and the production deploy path (CONFIRMED)

Date: 2026-09-16T20:20:58Z. The MVP push: what an operator needs to run this in production and see what it is
doing. Agent work re-run by main where it produced a number (C15).

**`/_ignis/metrics`** — Prometheus text, answered by the runtime, never by PHP (ADR-0022), so it
keeps answering while every PHP thread is wedged. 22 metrics, 66 lines. Two sources: what Rust
already knows (threads, stalls, restarts, in-flight requests and ops, failed parks, pool leases)
read from the registry per request, and what only the PHP loop knows (budget, queue, rejections,
fiber pool) published by each loop into **its own reactor's** slots once per loop turn — per
reactor and not global, because one global set would be last-writer-wins across threads, i.e.
wrong exactly under load. A wedged loop stops publishing and
`ignis_stats_published_age_seconds` says so.

| acceptance (BACKLOG M4-4) | result |
|---|---|
| `promtool check metrics` passes | **exit 0**, no warnings (`prom/prometheus:latest`) |
| every counter in `ignis_stats()` has a metric | threads, stalled, restarts — plus 19 more |
| answers under `wrk -c 200` within 10 ms | **1.9–5.5 ms** over 20 scrapes during `wrk -t2 -c200 -d8s` at 55,784 req/s |
| counters actually move | `ignis_requests_handled_total` 450,357 after that run; `fibers_idle`/`resumes` consistent |

**Startup banner.** `docs/operate.md`'s rewrite found that a clean start is invisible: the default
log floor is `warn` (H-10) and every startup line is `info`, so an operator sees an empty screen and
cannot tell whether the park self-check even ran. `ignis serve` now prints one line to stderr:

    ignis 0.0.1 — threads=24 listen=127.0.0.1:8191 park=libcurl,libpq,libssl,libcrypto,libphp:15 symbols — ready

Only for `serve`, never for a script run: the phpt harness fails a test on a single unexpected
stderr line, which is the same trap that cost 108 → 72 passes when the log floor was raised.

**`bench/app-check.sh`** (agent, re-run by main): the post-deploy acceptance check, against the
owner's real Symfony app, its own instance on :8189 with `APP_ENV=test`:

    PASS health 200 {"status":"ok"} | PASS route / 200 | PASS 404 | PASS 1 MB body survives
    PASS 200/200 sequential | INFO rss first=56832 kB last=56852 kB delta=20 kB
    PASS 50/50 concurrent | PASS alive + healthy at end       app_check pass=7 fail=0

3.4 s, exit 0. The 20 kB RSS delta over 200 requests is reported, not asserted — a threshold nobody
has justified is not a gate.

**Two defects in the release path, found before the tag was pushed** (agent audit of
`.github/workflows/release.yml`, which has never run):
1. `workflow_dispatch` was documented as a dry run and was not one: `push: true` was unconditional,
   so a "test" would have published a real `ghcr.io/koekaverna/ignis:<tag>` image, and
   `softprops/action-gh-release` would have created the git tag and the GitHub Release as well.
   Now `push: ${{ github.event_name == 'push' }}`, `load:` on dispatch, and the release step gated.
2. Previous-tag detection used an unfiltered `git tag --sort=-v:refname`; the repo's existing
   `night-1-done` tag would have been picked as "previous" for the first real `v*` release. Now
   `--list 'v*'`.

**Also written** (agents, docs only): `docker/compose.prod.yaml` and `docs/deploy.md` (the
production recipe, with V-40's four holes as ordered steps and an explicit note that `APP_RUNTIME`
in `.env` does nothing — it must be a real environment variable), `docs/release.md` (the tag
procedure plus "what is still unproven" — everything that needs a real tag push), and a rewritten
`docs/operate.md` runbook.

**Still missing for production, named:** graceful reload/drain (M4-5) — a redeploy today is a hard
restart, so in-flight requests are lost; and the HTTP front door has no body-size cap, header
timeout, idle timeout or connection cap.

## V-56 — the front door gets limits, and `SIGTERM` drains instead of dropping (CONFIRMED)

Date: 2026-09-16T20:31:50Z. The two things missing before this could be exposed to real traffic: nothing
bounded what a client could ask for, and a redeploy cut in-flight requests.

### Limits (agent-written, re-run by main)

| limit | env var | default | measured |
|---|---|---|---|
| request body | `IGNIS_MAX_BODY_BYTES` | 8 MiB | over the cap → **413**, refused at the first frame that would exceed it, never buffered past it |
| header read | `IGNIS_HEADER_TIMEOUT_MS` | 10 s | a client dribbling headers is **closed** |
| idle keep-alive | `IGNIS_IDLE_TIMEOUT_MS` | 60 s | an idle connection is **closed** |
| concurrent connections | `IGNIS_MAX_CONNECTIONS` | 8192 | cap 8 under `wrk -t2 -c64 -d5s`: **76,969 non-2xx** (the raw 503 + `Connection: close`), server **alive** and a single request still **200** afterwards |

`bench/limits.sh` → `body cap: got 413 / header timeout: closed as expected / idle timeout: closed
as expected / limits_probe bad=0`. Throughput with the limits in place, same shape, default cap:
**52,747 req/s**, 0 non-2xx.

The first cap test was wrong and is recorded as such: 40 sequential and 30 concurrent `curl`s all
returned 200 against a cap of 8, because a hello request finishes in microseconds and fewer than 8
connections were ever live at once. A cap is only exercised by *held* connections — hence `wrk`.

### Drain (M4-5)

Two phases, because a load balancer has to be told before the socket disappears:

1. `SIGTERM`/`SIGINT` → `/_ignis/health` answers **`503 {"status":"draining"}`** while the listener
   **keeps accepting**, for `IGNIS_DRAIN_DELAY_MS` (default 0; set it above the balancer's check
   interval for a gapless rolling deploy).
2. The listener closes; in-flight requests get `IGNIS_DRAIN_TIMEOUT_MS` (default 10 s); the process
   exits 0 and logs how long it took and what was still pending.

| check (`scratchpad/slow_server.php`, a 1.5 s route) | result |
|---|---|
| 5 requests in flight when `SIGTERM` arrives | **all 200**, at 1.501–1.502 s |
| new connection after the listener closed | refused |
| `/_ignis/health` during the grace window | **`{"status":"draining"}` 503** |
| a **new** request during the grace window | **200** |
| the same after the window | refused |
| process exit | by itself, `drained; exiting signal="SIGTERM" took_ms=1040` |

**A defect in my own first version, found by measuring rather than reading:** one flag served both
phases, so the accept loop saw the drain the instant health did and the grace window collapsed to
nothing (`NEW request in grace: 000`, listener closed after 236 ms of a configured 800). Split into
`SHUTTING_DOWN` (health) and `LISTENER_STOP` (accept loop); re-measured above.

### Gates

`cargo nextest` 9/9; `bench/limits.sh` clean; `bench/app-check.sh` **7/7** against the owner's
Symfony app (RSS delta 20 kB over 200 requests); `scripts/smoke.sh` exit 0; phpt counts **and**
per-test sets all ≥ baseline (12/12 checks), gate exit 0.

### Documentation site

`mkdocs.yml` + `.github/workflows/docs.yml` (GitHub Pages) and `docs/site/**` — concept,
architecture, the three mechanisms, non-goals, install/quickstart/Symfony/legacy, compatibility,
a comparison that says "not measured" wherever no V-n exists, and a reference covering 9 `ignis.toml`
keys, 21 environment variables, 27 public PHP functions and every CLI flag and exit code.
`mkdocs build --strict` passes; the 10 warnings the first build produced were links to repo-root
files that would have been **broken in the published site**, now absolute GitHub URLs.

## V-57 — the first release is published and the runtime holds under a ten-minute soak (CONFIRMED)

Date: 2026-09-17T05:44:02Z.

### A3 soak, current binary (the recorded criterion, V-35's shape)

`bench/a3-soak.sh`, 4 threads, 4 offload workers, 200 connections, 30 s chunks:

| requests | RSS kB | fibers | idle | restarts | stalled |
|---:|---:|---:|---:|---:|---:|
| 1,735,813 | 56,624 | 41 | 40 | 0 | 0 |
| 2,881,668 | 69,756 | 183 | 182 | 0 | 0 |
| 4,638,191 | 64,952 | 169 | 168 | 0 | 0 |
| 5,747,243 | 65,384 | 183 | 182 | 0 | 0 |
| 7,491,428 | 64,960 | 180 | 179 | 0 | 0 |
| 8,629,072 | 66,060 | 184 | 183 | 0 | 0 |
| **10,330,892** | **66,224** | 180 | 179 | **0** | **0** |

No monotonic trend past 5M (the band is 65–70 MB after warm-up), 0 errors, watchdog silent —
acceptance met on the three-mechanism binary, i.e. today's deletions cost nothing in stability.

### Ten-minute soak of the owner's Symfony app

Same binary, `APP_ENV=test` on :8197, 4 threads, `wrk -t4 -c200 -d60s` × 10:

| chunk | req/s | p99 | RSS kB | non-2xx |
|---:|---:|---:|---:|---:|
| 1 | 22,645 | 109 ms | 297,196 | 0 |
| 5 | 22,367 | 123 ms | 296,320 | 0 |
| 10 | 21,161 | 131 ms | 297,292 | 0 |

**13,150,180 requests**, RSS 297.2 → 297.3 MB (band 296.3–298.7, no trend), **0 non-2xx**, and at
the end `{"status":"ok"}` with `ignis_threads_stalled 0`, `ignis_thread_restarts_total 0`,
`ignis_park_failed_total 0`, `ignis_requests_rejected_total 0`. p99 grows 109 → 131 ms across the
run: that is queueing at saturation with 200 connections against 4 threads, not degradation — RSS
and the counters are flat. Throughput drifts down ~6 % over ten minutes on a box that was also
running a release build; treat 21–22 k req/s as a floor.

### The first release, and what the tag proved that no audit could

`v0.1.0-rc.1` — image `ghcr.io/koekaverna/ignis:v0.1.0-rc.1` (62 MB), tarball
`ignis-v0.1.0-rc.1-linux-x86_64.tar.gz` (29,795,977 B: `ignis`, `libphp.so`, `README.txt`),
GitHub Release marked prerelease. Verified as a user: `docker run … --version` → `ignis 0.1.0-rc.1`;
`gh release download` → extract → `./ignis --version` → `ignis 0.1.0-rc.1`.

**The first tag failed** (run 35186470045) at *extract runtime binaries*:
`tar -C /tmp/release -czf "$dir.tar.gz" "$dir"` writes the archive into the **workspace** — `-C`
changes where tar reads, not where it writes — and the `mv "/tmp/release/$dir.tar.gz" .` that
followed had nothing to move. Everything before it had already succeeded: the image was built,
pushed, smoke-tested and its `--version` checked against the tag. `docs/release.md` listed exactly
this step under "what is still unproven", and the audit could not have caught it by reading.

Two process notes, both mine: the repo has a documented "dry-run first" path and I went straight
to a real tag; after fixing, the dispatch dry run (run 35186677545) passed every step including the
one that had failed, and proved the audit's other fix — a dispatch publishes no image and creates
no release. And the session git proxy, recorded in 8ffd758 as refusing tag pushes, accepted this
one; that constraint no longer holds.

## V-58 — a file lock held across a yield deadlocks the thread; Symfony's cache lock does not (CONFIRMED)

Date: 2026-09-17T06:04:24Z. Asked about Symfony's file locks, both paths measured on this build.

### Symfony's cache stampede lock is safe — because `usleep` parks

`vendor/symfony/cache/LockRegistry.php` never takes a blocking lock: the winner races with
`flock(LOCK_EX|LOCK_NB)` (`:110`), and a loser polls `flock(LOCK_SH|LOCK_NB)` with
**`usleep(100_000)`** between attempts (`:144-148`) until a 30 s deadline. `libphp:usleep` is in the
default park policy (V-46), so the poll suspends the fiber.

`scratchpad/lockprobe.php` — one fiber holds the exclusive lock for 600 ms, three run the loser
loop, a ticker counts 10 ms sleeps:

| | losers | ticker |
|---|---|---|
| park on (default) | 607 / 607 / 608 ms, all proceed | **60 of 60** — the thread kept serving |
| `IGNIS_NO_UNIVERSAL_PARK=1` | — | **never finished** (killed at 60 s) |

The control is the interesting half: without park, `usleep` blocks the thread, the winner's timer is
never polled, it never releases, and the losers spin forever. Universal park is what makes Symfony's
stampede protection work in a worker runtime at all.

### A blocking `flock` held across a yield deadlocks the thread — permanently

This is `ext/session`'s files handler: `flock(LOCK_EX)` at `mod_files.c:210`, held from
`session_start()` to `session_write_close()`. Two fibers on one thread, the shape of two requests
from one browser (`scratchpad/flock2.php`):

    [   0 ms] A: holds the lock
    [  51 ms] B: asking for the lock (blocking flock on a regular file)
    <nothing further; killed at 12 s>

A takes the lock and yields; B's blocking `flock` cannot park — a regular file is not epoll-able
(research 30 group (d)) — so it blocks the **OS thread**, so the loop cannot resume A, so the lock
is never released. Not a stall: a **deadlock**, and the thread is gone until the supervisor notices.
Both arms hang identically: the second arm's holder "does not await", it only calls
`usleep(400_000)` — which parks, because park is on. **Park widens the window**: far more code
yields than a php-fpm developer expects, so "I hold this lock only briefly" stops being true.

R-SESS said "stalls a thread for as long as the other request holds the session". That was too
kind and is corrected: it hangs the thread for good.

### The rule this establishes

A lock a fiber can hold across a yield must live where park can see the wait — on a socket
(Redis, PostgreSQL) — or inside the runtime. Never on a file. Symfony's cache obeys it by accident
(non-blocking + `usleep`); `ext/session`'s files handler does not.

Not measured here: whether `session_start()` under the embed SAPI reaches that `flock` at all —
the probe hit `Session cannot be started after headers have already been sent`
(`php_embed_init()` pins `SG(headers_sent)`, which is why `php/classic.php` handles session cookies
itself). The lock mechanism is measured above with `flock` directly; what an actual Symfony session
does end to end still needs its own test before any claim is made about it.

## V-59 — `curl_*` parks instead of routing to the offload pool (CONFIRMED)

Date: 2026-09-17T06:19:52Z. Owner asked why curl "could not be parked". It could, and was (V-45) — but the
**default configuration never sent it there**: ADR-0016's auto-routing claimed `curl_*` for the
offload pool, a default written before universal park existed.

Measured on one PHP thread, 100 concurrent `curl_exec` of a 200 ms endpoint
(`e18_curl.php` with `php/offload/ignis-offload.php` loaded, `--offload 8`):

| path | wall | `CURLOPT_WRITEFUNCTION` |
|---|---|---|
| **park** (`curl_*` reaches libcurl, its syscalls are interposed) | **328 ms** | in the calling fiber, 100 of 100 |
| offload, 8 workers | **2,697 ms** | on a worker — `same_fiber=no`, 69 of 100 |
| neither (control) | **20,346 ms** | — |

Park is **8× faster than offload** here and **62× faster than blocking**, costs no worker thread and
no argument copy, and keeps the write callback where the application expects it. `DEFAULT_FUNCTIONS`
in `route.rs` is now empty; after the change the default run reads **312 ms, `same_fiber=yes`**, and
`IGNIS_OFFLOAD_FUNCTIONS=curl_init,curl_setopt,curl_exec,…` restores the old behaviour at
**2,707 ms** — the escape hatch is measured, not assumed.

**Offload keeps what park cannot reach** and the docs now say exactly that: `SQLite3` and a
file-backed `PDO` — a regular file is not epoll-able (ADR-0024) — and CPU-bound calls.
`DEFAULT_CLASSES` stays `PDO,SQLite3`; a socket-backed PDO driver parks once the route declines.

**A measurement error of mine, corrected here.** The first comparison in this session reported
290 ms for "offload" and 284 ms for "park" and concluded they were equivalent. Both arms were park:
auto-routing only engages when the PHP userland router is loaded (`ignis-offload.php` calls
`ignis_route_enable(true)`), and `bench/php/e18_curl.php` does not load it — its own header says the
driver must set `IGNIS_NO_OFFLOAD_ROUTE=1`, which describes a routing that was never on. The
numbers did not add up (100 × 200 ms across 8 workers cannot finish in 300 ms) and that is what
exposed it. The table above is from a script that loads the router.

Gates: `cargo nextest` 9/9, `bench/app-check.sh` 7/7 against the owner's Symfony app,
`scripts/smoke.sh` exit 0 (its `/offload` route still works — it drives the pool explicitly through
`Ignis\offload()`, not through curl auto-routing).

### V-59 addendum — `PDO` leaves the default routing too; only `SQLite3` stays

Date: 2026-09-17T06:31:09Z. The owner asked what happens to `pdo_pgsql` and `pdo_mysql`. `pdo_mysql` is **not
compiled into this build** (`PDO`, `pdo_pgsql`, `pdo_sqlite`, `pgsql`, `sqlite3` are), so the
question is pgsql against sqlite — and `pdo_pgsql` had the same defect curl had, one level less
visible: routing is by **class name**, so every `PDO` went to a worker, including the socket-backed
driver that parks perfectly well.

100 concurrent `new PDO(pgsql…)` + `SELECT pg_sleep(0.2)` on one thread, router loaded, `--offload 8`:

| path | wall |
|---|---|
| **park** (`PDO` out of the routed list) | **303 ms** |
| offload, 8 workers | **2,753 ms** |
| neither (control, n=20 scaled) | ~20,550 ms |

`DEFAULT_CLASSES` is now `SQLite3`; after the change the default reads **310 ms**, and
`IGNIS_OFFLOAD_CLASSES=PDO,SQLite3` restores routing at **2,756 ms**. Behaviour verified both ways:

    default                        SQLite3: class=Ignis\Offload\Proxy\SQLite3 value=42
                                   PDO sqlite: class=PDO value=7
    IGNIS_OFFLOAD_CLASSES=PDO,…    SQLite3: class=Ignis\Offload\Proxy\SQLite3 value=42
                                   PDO sqlite: class=Ignis\Offload\Proxy\PDO value=7

**Why not decide per driver automatically:** the driver is in the DSN, and `create_object` runs when
the VM executes `NEW` — before the constructor's arguments exist. The runtime cannot see
`pgsql:` versus `sqlite:` at the only moment it gets to choose. Routing the class that is *always*
file-backed is the honest default.

**The price, stated plainly:** `new PDO('sqlite:…')` now blocks the OS thread for the length of the
file access. That is the rule already in force for `file_get_contents`, opcache and sessions
(ADR-0024 — a regular file is not epoll-able); PDO used to be exempt by accident and is not any
more. One environment variable restores it, and the compatibility table, the configuration
reference, `ignis.toml.example` and `docs/migrate.md` all say so.

**Also fixed:** `php/offload/ignis-offload.php` proxied a hard-coded `PDO,SQLite3` of its own, so a
`new PDO('pgsql:…')` would have become a proxy for a class the runtime no longer routes. It now
reads the same `IGNIS_OFFLOAD_CLASSES`.

Gates: `cargo nextest` 9/9, `bench/app-check.sh` 7/7, `scripts/smoke.sh` exit 0, phpt counts and
per-test sets ≥ baseline (gate exit 0), `mkdocs build --strict` clean.

## V-60 — a command-line script overlaps its waits without an async client (CONFIRMED)

Date: 2026-09-17T09:52Z. Owner asked whether the CLI should be made asynchronous. It already is, and
the measurement is the answer: no CLI mode, no flag and no fourth mechanism were needed — the script
opts in with `Ignis\async()`/`Ignis\all()` and universal park does the rest.

Binary `target/release/ignis` (38.4 MB, built 09:27), one PHP thread, `examples/cli.php` and a
20-request I/O arm against `examples/hello_server.php` on `127.0.0.1:8187` (`--threads 2`):

| program | sequential | `Ignis\all()` | `IGNIS_NO_UNIVERSAL_PARK=1` |
|---|---|---|---|
| 10 × `sleep(1)` | 10.00 s | **1.00 s** | 10.00 s |
| 20 × `file_get_contents()` of a 200 ms endpoint (by IP) | 4.07 s | **0.21 s** | — |

Both calls are PHP's own blocking ones; nothing in the script is an async client. The hook-off arm
is the control required of every park claim: with the interposer disabled the same program takes the
sequential time, so the win is parking and not scheduling luck.

**The top level of a CLI script deliberately blocks.** It runs in `{main}`, not a fiber, so the gate
in `park.rs` is 0 and the call is forwarded — measured above as the 10.00 s sequential arm. That is
the correct answer rather than a gap: a single wait has nothing to overlap with, and wrapping every
script in an implicit fiber would add a scheduler to reason about with no measured benefit.

Limits, stated because the docs page now states them: the fibers share one thread, so CPU-bound work
does not parallelize (`--offload`/`--threads`); and `getaddrinfo` is not interposed (BACKLOG R-DNS),
so a fan-out to a *hostname* resolves serially — the I/O arm above uses an IP for that reason.

Commands: `ignis examples/cli.php [seq]`, and the I/O arm in this entry's scratch script.

## V-61 — the official temporalio/sdk-php runs on Ignis through a portable core transport (CONFIRMED)

Date: 2026-09-17T11:4xZ. Owner asked whether we can take the PHP SDK instead of growing our own
workflow runtime, and then — correctly — where the adapter should live. Both answers are measured.

**Seams, probed in the ignis binary.** `temporal/sdk` **v2.19** (installed with composer in docker:
our PHP build has no `ext-phar`, and only `vendor/` is needed).

| seam | result |
|---|---|
| `WorkerFactory::run(?HostConnectionInterface)` | `RoadRunner::create()` is only a default — a host of ours drove the loop, `exit=0`, `GetWorkerInfo` answered with `GreetWorkflow` / `greet` |
| `WorkerFactory::$codec` (`protected`) | replaceable — frames became plain arrays, **no protobuf on the wire** |

**Protobuf, the cost that was avoided** (54-byte `Payloads`, 10k iterations, this binary, no
`ext-protobuf`): pure-PHP encode 35.91 µs + decode 17.53 µs = **53.45 µs**, against **0.90 µs** for
our own JSON payload boundary. Payload *objects* are still constructed, so the DataConverter keeps
owning encode/decode — only the serialize/parse round trip is gone.

**The adapter is a real package**, not a directory: `php/temporal/core/composer.json` declares
`ignis/temporal-core-transport`, PSR-4 `Temporal\Worker\Transport\Core\` → `src/`, `temporal/sdk`
^2.19 as its only dependency. `composer install` in that directory is what `bench/e20-sdkphp.sh`
runs, and the generated `autoload_psr4.php` maps the namespace to the package's own `src/` — so the
test below exercises the package as an installed library, not a pile of `require`s.

**Conformance, `bench/e20-sdkphp.sh` → `php/temporal/core/tests/conformance.php`.** A recorded script of
sdk-core activations is fed to a **stock** sdk-php worker (attributes, `Workflow::newActivityStub()`,
`yield`, `Workflow::timer()` — `php/temporal/demo-sdk.php`, shared with the example so the two
cannot drift):

| activation | completion the transport produced |
|---|---|
| `InitializeWorkflow` | `ScheduleActivity{seq:1, activity_type:"greet", task_queue:"ignis", start_to_close_sec:5}`, argument `"Ada"` intact |
| activity task | result `"Hello, Ada!"`, `task_token` echoed |
| `ResolveActivity{seq:1}` | `StartTimer{seq:2, ms:1000}` |
| `FireTimer{seq:2}` | `CompleteWorkflow{result:"HELLO, ADA!"}` |
| `RemoveFromCache` | no commands |

11 of 11 checks pass **under the ignis binary and under the stock PHP CLI** — the second arm is the
gate on the claim that the package needs no Ignis (ADR-0040 decision 3). No Rust was changed: the
four commands this needs are the four `ignis_temporal_complete()` already accepted (ADR-0013).

**Two undocumented seams, found by failing.** `StartWorkflow`'s `options['info']` must carry the
`#[Marshal]` names of `Temporal\Workflow\WorkflowInfo` with **nanosecond** timeouts; and sdk-php
builds its own responses with `EncodedValues::fromValues()` and no data converter, so `toPayloads()`
throws unless the transport calls `setDataConverter()` first.

**Not measured yet, and not claimed:** a live run against a Temporal server and a replay under this
transport. The dev server is not on this box (the Temporal CLI has to be fetched); V-19's live and
replay numbers are for the prototype runtime, not for this one. Queries, updates, cancellation,
child workflows, local activities and heartbeats are untranslated — `CoreCodec::encode()` throws
by name rather than dropping them.

## V-62 — the PHP userland is one package per integration, and nothing it does changed (CONFIRMED)

Date: 2026-09-17T13:0xZ. Owner asked for the `php/` tree to be packages, Symfony/Tempest style, with
`ignis/symfony-runtime` and the Temporal SDK support as the minimum. Ten packages now live under
`php/packages/*`, each with its own `composer.json`, `src/`, and dependencies on the others; the root
`php/composer.json` is a monorepo aggregate with a `packages/*` path repository and installs nowhere.

`ignis/runtime` · `ignis/symfony-runtime` · `ignis/pg` · `ignis/offload` · `ignis/grpc` ·
`ignis/revolt` · `ignis/swoole` · `ignis/temporal` (host for the official SDK) ·
`ignis/temporal-core-transport` (the portable adapter) · `ignis/temporal-prototype` (ours, frozen).

87 files carried a path into the old layout and were rewritten; `JOURNAL.md`, `VALIDATION.md`,
`DECISIONS.md` and `HYPOTHESES.md` were **not** — they record what was true then, and their commands
name paths that no longer exist by design (CLAUDE.md "do not rewrite history").

Gates after the move, all on this box:

| gate | result |
|---|---|
| `php -l` over every file in `php/`, `examples/`, `bench/php/`, `scripts/` | 0 failures |
| `cargo build --release -p ignis` (`include_str!` of the offload worker moved with it) | ok |
| `cargo nextest run --workspace` | 9/9 |
| `bench/e20-sdkphp.sh` (composer install in the package, then both hosts) | GREEN, 11/11 ×2 |
| `scripts/smoke.sh` | **GREEN** — E1 10k fibers 1151.8 ms, E2 200.99 ms / 3.83 µs warm, E13 0 mismatches, E6 50/50, E11 cancel 273 µs max, E12 restart 1, hello 33,260 rps |
| `mkdocs build --strict` | clean |

The install recipe changed with the layout and is updated in README, `docs/deploy.md` and
`docs/getting-started/symfony.md`: the path repository is now `/opt/ignis/php/packages/*` and an
application requires `ignis/runtime:@dev ignis/symfony-runtime:@dev`.

**Pre-existing and not caused by this** (checked before assuming): `bench/e7-revolt.sh` reports
`differing=6`. The examples it runs are inside `vendor/revolt/event-loop/examples/` and require
`../vendor/autoload.php` — a nested vendor directory that a `--prefer-source` install never creates.
The failing path lies entirely inside the vendor tree, which moved intact, and smoke does not gate on
E7 (`| grep … || true`). Filed as an observation, not fixed here.

**Deleted, not deprecated:** the retired `php/symfony/worker.php` shim is gone rather than kept as a
migration note — owner's call, no back-compat before the first stable release.
