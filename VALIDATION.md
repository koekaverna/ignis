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

**E15c — Swoole `tests/swoole_runtime` (153 tests) through `php/swoole/shim.php`** (`bench/e15-swoole.sh --all`): **PASS 44 / FAIL 79 / SKIP 30** (agent, pre-fix: 42 / 81 / 30; run under load 25 with 15 tests at the 20 s hang timeout, so a quiet re-run is owed). The hooks Ignis lacks, by tests blocked: `Swoole\Coroutine\Socket` / ext-sockets (36), accept and `stream_select` inside fibers (19), file hooks (10), proc/pcntl (10), udp/unix/udg transports (7); `sleep`/`usleep` are hooked now (V-22), `Runtime::enableCoroutine`/`Co\run`/`go`/`Co::sleep`/`WaitGroup`/`Channel`/`Timer` exist in the shim. Skips: 18 external hosts, 5 missing extensions (openssl/curl/pcntl), 7 Redis/MySQL/FTP fixtures.

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

Not yet: config-driven auto-routing of `curl_*`/`PDO`/`SQLite3`/`Redis` (proxies pinned to a worker, function-handler trampolines) and the pdo_pgsql/curl_exec tests — the libphp rebuild with `--with-pdo-pgsql --with-pgsql --with-curl --with-openssl` is running.

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
