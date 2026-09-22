# Comparison

Only numbers that exist in VALIDATION.md are used here. Anywhere a comparable number does not
exist, the table says **not measured** — never an estimate. Everything below was measured on one
4 vCPU box, mostly on 2026-09-15/16 (`bench/results/compare.md`); hello-world throughput on this box
varies **±6.7 %** run to run (V-82) — treat a delta smaller than that as noise, not a result.

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

## gRPC unary calls vs RoadRunner and the pure-tonic ceiling (V-20)

`ghz -c 64 -n 100000 --connections 8` on the same 4-vCPU box, PHP unary handler on the shared
hyper/h2 listener (`examples/grpc_server.php`), all six rows answering 100000/100000 OK:

| server | req/s | avg | p99 | max |
|---|---|---|---|---|
| **Ignis, PHP handler, 1 PHP thread** | **16.7k** | 2.90 ms | **7.8 ms** | 15.8 ms |
| Ignis, PHP handler, 4 PHP threads | 17.6k | 2.74 ms | 8.0 ms | 16.7 ms |
| pure tonic, Rust handler, same codec + listener code — the ceiling | 21.1k | 2.02 ms | 6.3 ms | 12.4 ms |
| RoadRunner v2025 grpc plugin, 1 PHP worker | 5.1k | 12.3 ms | 17.8 ms | 28.0 ms |
| RoadRunner grpc plugin, 4 PHP workers | 10.2k | 5.57 ms | 12.6 ms | 26.6 ms |
| RoadRunner grpc plugin, 16 PHP workers | 11.4k | 4.50 ms | 13.4 ms | 45.6 ms |

Ignis with a full PHP handler in the path is 79% of the pure-Rust ceiling's throughput at 1.23× its
p99; RoadRunner needs 16 PHP workers to reach 54% of that same ceiling, at 2.1× the p99. 1 vs 4 PHP
threads on Ignis is a 5% difference (16.7k vs 17.6k) — inside this box's ±6.7% noise band (above), so
read it as "the PHP hop is not the bottleneck here", not as a scaling result.

100 concurrent calls to a handler that sleeps 200 ms (`-c 100 -n 100`):

| server | total | slowest | avg |
|---|---|---|---|
| **Ignis, 1 PHP thread** (`Ignis\sleep` parks the fiber) | **215 ms** | 209 ms | 204 ms |
| RoadRunner, 4 workers (`usleep` blocks the worker) | 5.03 s | 5.02 s | 2.61 s |
| RoadRunner, 16 workers | 1.43 s | 1.42 s | 756 ms |

A client call out of PHP parks the fiber too: 100 concurrent `Ignis\Grpc\Client` calls to the same
server, 1 PHP thread, **217 ms** total (three runs: 218.9 / 217.4 / 217.6 ms) against a sequential
20 s.

**ext-grpc was evaluated as a fourth comparison server and could not be one.** It does build and load
on PHP 8.5.10 ZTS (`grpc module version => 1.85.0dev`; the C-core build alone took 77 minutes at
`nice -j2`), but it ships no supported server runtime and no PHP server codegen upstream —
`Grpc\Server`'s `requestCall` is a blocking single-threaded completion-queue pull, with nothing that
generates handler stubs from a `.proto` file. That is not a benchmark still to run; there is no
server for `ghz` to point at (V-20 + corrections).

## Compat-suite pass rates vs stock PHP (V-23, refreshed)

Not a throughput comparison, but the honest count of what still works when the same test suites
run through Ignis instead of stock PHP or the tool each suite was written for. Counts below are
`bench/results/e15-phpt/summary.md` / `summary.tsv` as committed 2026-09-18 (commit `da4af2a`, this
4-vCPU WSL2 box) — four suites gained since V-23's original run, none lost a PASS. Suite sizes differ
from a run on a different box (CI's, or the one V-23 itself used) because more of each suite runs
here or there; that is an environment/kernel difference, not an Ignis effect (research 21).

| suite | stock / baseline | Ignis (main mode) | Ignis (fiber mode) |
|---|---|---|---|
| Zend/tests/fibers (110) | 108 pass | **108 pass (100%)** | 78 pass* |
| ext/sockets/tests (118) | 91 pass | **91 pass (100%)** | 85 pass |
| ext/standard/tests/streams (160) | 140 pass | **134 pass (95.7%)** | 126 pass |
| Revolt `DriverTest` (81 tests, 222 assertions), vs `StreamSelectDriver` | 1 error, 8 skipped | — | **identical: 1 error, 0 failures, 8 skipped** |
| FrankenPHP `testdata/*.php` through `php/packages/runtime/src/classic.php` | — | **29 passed / 4 failed / 33 skipped** | — |

\* **Open (S0-FIBER):** a same-box re-run on 2026-09-18 found `Zend/tests/fibers/gh9916-009.phpt`
newly failing in fiber mode — 77/110, identical across the HEAD binary and one rebuilt at this
branch's start point, so it is not this cycle's work, and unexplained (the same engine reported 78
earlier the same day). The gate is deliberately left red rather than re-baselined to 77, so the
number above is the last one with a known-good cause, not a claim that the fiber-mode gate is clean
today.

## Universal park against the same calls unparked (V-45)

`curl_exec` and `pdo_pgsql`, 100 concurrent 200 ms operations, one PHP thread. The call parks
directly at the syscall boundary, with no PHP-level hook; the control is the same run with
`IGNIS_NO_UNIVERSAL_PARK=1`, where each call blocks the thread in turn:

| workload | park | neither (control) |
|---|---|---|
| `curl_exec` × 100 | **279–328 ms** | 20,337 ms |
| `pdo_pgsql` × 100 | **296–333 ms** | 20,558 ms |

Park costs no second thread and no argument copy, and keeps `CURLOPT_WRITEFUNCTION` running in the
calling fiber (`same_fiber=yes`). What park cannot reach — `SQLite3`, a file-backed `PDO`, a
CPU-bound call — blocks its PHP thread, as the control column does, since the offload pool that
used to take such calls was deleted on 2026-09-22 (DECISIONS.md).

## A real Symfony app, not a synthetic route (V-53)

The owner's own Symfony 8.1 app, unmodified (`--threads 4`, load average 1.2 on the box, JSON
route not a template render — a floor, not a peak benchmark):

| metric | value |
|---|---|
| `wrk -t2 -c64 -d10s`, 3 rounds | 21,127 / 21,029 / 21,272 req/s |
| p99 | 27.4–28.7 ms |
| RSS | 48.4 MB at start → 118.5–118.7 MB, flat after the first round |
| fatals / uncaught errors | 0 |

No comparable FrankenPHP or RoadRunner run of the same app exists in VALIDATION.md, so no
side-by-side number is given here — see [Why Ignis](concept/why.md) for the qualitative comparison
instead.
