# Comparison

Only numbers that exist in VALIDATION.md are used here. Anywhere a comparable number does not
exist, the table says **not measured** — never an estimate.

## Hello-world throughput and latency (V-6)

Same box, same libphp 8.5.10 build flags (ZTS, opcache, `--disable-zend-signals`), `wrk -t2 -c64
-d10s --latency`:

| server | req/s | p50 | p99 | errors |
|---|---|---|---|---|
| **Ignis** (1 PHP thread + 2 tokio) | **128,072** | 453 µs | **1.11 ms** | 0 |
| FrankenPHP worker mode (num=1, num_threads=2) | 27,627 | 2.12 ms | 6.42 ms | 0 |
| FrankenPHP worker mode (num=4, num_threads=5) | 16,583 | 3.77 ms | 10.35 ms | 0 |
| php-fpm (pm.max_children=1) + nginx | 9,853 | 6.30 ms | 8.68 ms | 0 |
| php-fpm (pm.max_children=4) + nginx | 11,106 | 5.69 ms | 7.41 ms | 0 |
| RoadRunner | not measured on this route | — | — | — |

Ignis is 4.6× FrankenPHP worker mode's throughput with a 5.8× lower p99, on one PHP thread. Part of
the gap is work Ignis does not do at that stage (a full superglobals array, per-request startup
emulation); the architectural part is that FrankenPHP's worker mode still pays a cgo/thread hand-off
and a from-scratch request bootstrap per request, where Ignis pools the Fiber and pays one channel
op (V-6).

## Under sustained multi-thread load, with the full stack active (V-15)

`--threads 4`, superglobals + stream hooks + cancellation all active (a later, heavier build than
V-6's), least-inflight dispatch:

| metric | Ignis (least-inflight) | FrankenPHP@4 workers |
|---|---|---|
| `/cpu` req/s | **9,124 / 9,321** | 7,278 |
| `/cpu` p99 | **12.37–12.75 ms** | 15.6 ms |
| hello req/s | **112,511** (p99 2.41 ms) | 16,583 |

## Compat-suite pass rates vs stock PHP (V-23)

Not a throughput comparison, but the honest count of what still works when the same test suites
run through Ignis instead of stock PHP or the tool each suite was written for:

| suite | stock / baseline | Ignis (main mode) | Ignis (fiber mode) |
|---|---|---|---|
| Zend/tests/fibers (110) | 108 pass | **108 pass (100%)** | 77 pass |
| ext/sockets/tests (118) | 80 pass | **80 pass (100%)** | 75 pass |
| ext/standard/tests/streams (160) | 138 pass | **131 pass (94.9%)** | 116 pass |
| Revolt `DriverTest` (81 tests, 222 assertions), vs `StreamSelectDriver` | 1 error, 8 skipped | — | **identical: 1 error, 0 failures, 8 skipped** |
| FrankenPHP `testdata/*.php` through `php/classic.php` | — | **29 passed / 4 failed / 33 skipped** | — |

## Offload pool bound, vs an unbounded blocking call (V-24)

Not a comparison with another server, but the number that makes the offload pool's purpose
concrete: 100 concurrent 200 ms blocking calls (`curl_*`/`PDO`/`SQLite3`-shaped work) through a pool
of size N are bounded by the pool, never by the fiber thread:

| offload pool size | wall time for 100 × 200 ms | bound |
|---|---|---|
| 8 workers | 2,604–2,608 ms | ceil(100/8) × 200 = 2,600 ms |
| 100 workers | 243–291 ms | 200 ms |

Copy-in/copy-out cost: 13 µs (no args) to 67 µs (1 KB array) per call, both ways.

## Universal park vs the offload path it can replace, for the same libraries (V-45)

`curl_exec` and `pdo_pgsql`, 100 concurrent 200 ms operations, one PHP thread, **no offload pool
configured at all** — the call parks directly at the syscall boundary instead:

| workload | park | no park (control) |
|---|---|---|
| `curl_exec` × 100 | **279 ms** | 20,337 ms |
| `pdo_pgsql` × 100 | **296–333 ms** | 20,558 ms |

## A real Symfony app, not a synthetic route (V-53)

The owner's own Symfony 8.1 app, unmodified, on the three-mechanism binary (`--threads 4`, load
average 1.2 on the box, JSON route not a template render — a floor, not a peak benchmark):

| metric | value |
|---|---|
| `wrk -t2 -c64 -d10s`, 3 rounds | 21,127 / 21,029 / 21,272 req/s |
| p99 | 27.4–28.7 ms |
| RSS | 48.4 MB at start → 118.5–118.7 MB, flat after the first round |
| fatals / uncaught errors | 0 |

No comparable FrankenPHP or RoadRunner run of the same app exists in VALIDATION.md, so no
side-by-side number is given here — see [Why Ignis](concept/why.md) for the qualitative comparison
instead.
