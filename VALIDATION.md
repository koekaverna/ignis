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
