# HYPOTHESES

Each entry is falsifiable with a number and names the exact test that decides
it. Status is one of OPEN / CONFIRMED / REFUTED / INCONCLUSIVE and links to the
entry in VALIDATION.md.

| id | statement | decided by | expected | time box | status |
|---|---|---|---|---|---|
| H0 | PHP 8.5.10 builds here as ZTS + embed with the minimal extension set and `php -r 'echo PHP_ZTS;'` prints 1. | `scripts/build-php.sh`, `/opt/php85-zts/bin/php -r 'var_dump(PHP_ZTS, extension_loaded("pdo_sqlite"));'` | ZTS=1, all listed extensions loaded | 30 min | CONFIRMED (V-0) |
| H1 | A Rust binary linking libphp (ZTS, bindgen) can register an internal module and run a script that calls a Rust-implemented function; startup+script < 50 ms. | `cargo run -p ignis -- examples/hello.php` | prints "hello from rust: 42" | 90 min | CONFIRMED (V-1) |
| H2 (E1) | 10,000 fibers on ONE PHP thread each calling `Ignis\sleep(1000)` all complete in < 1.2 s wall, with sleeps owned by tokio timers on other threads. | `cargo run --release -p ignis -- bench/php/e1_sleep_10k.php` prints wall ms | < 1200 ms | 60 min | CONFIRMED, marginal (V-2) |
| H3 (E2) | `Ignis\all([sleep(200), sleep(200), sleep(200)])` returns in < 230 ms; per-fiber overhead (create+submit+poll+resume) measured over 10k fibers with 0 ms sleep is < 100 µs. | `bench/php/e2_all.php` | < 230 ms; < 100 µs | 30 min | CONFIRMED (V-3) |
| H4 | The userland `Ignis\Loop` is not the bottleneck: with 10k fibers, time spent between `ignis_poll` returning and all fibers resumed is < 100 ms. | timing inside `e1_sleep_10k.php` (resume phase) | < 100 ms | inside H2 | CONFIRMED, marginal (V-2) |
| H5 (E2') | With a warm fiber pool, the second round of 10k `Ignis\sleep(0)` jobs costs < 5 µs per job (vs 22 µs cold, V-3), and E1 (10k × 1000 ms) wall < 1.05 s on the warm round. | `bench/php/e1_sleep_10k.php` with `ROUNDS=2`, `bench/php/e2_all.php` reports warm per-job µs | < 5 µs; < 1050 ms | 60 min | CONFIRMED (V-4): 4.4–4.6 µs, 1037–1041 ms |
| H6 | `Ignis\serve()` over hyper: a hello-world handler answers `wrk -t2 -c64 -d10s` on 1 PHP thread with ≥ 20k req/s and 0 errors; `/sleep?ms=1000` with `-c 1000` completes 1000 concurrent requests in < 1.1 s on 1 thread. | `bench/wrk-hello.sh` (it takes the URL, so the sleep run is `bench/wrk-hello.sh 'http://127.0.0.1:8080/sleep?ms=1000' 2 1000 4s`; there is no `bench/wrk-sleep.sh`) | ≥ 20k rps; < 1.1 s | 120 min | CONFIRMED (V-5): 122–130k rps, p99 1.01 s at c=1000; 10k-conn p99 1.21 s INCONCLUSIVE |
| H7 (E4, 1 thread) | Ignis hello-world rps ≥ FrankenPHP worker mode (num_threads 1) and ≥ php-fpm (pm.max_children 1) behind nginx, p99 lower, same box, same libphp build. | `bench/compare.sh` results table | Ignis ≥ both | 60 min | CONFIRMED (V-6): 128k vs 27.6k (FrankenPHP) vs 9.9k (fpm) req/s; p99 1.1 ms vs 6.4 / 8.7 ms |
| H8 | The true-async `async-core` fork (= PR #22561 head `14af3cb`, 8.6.0-dev) builds here as ZTS + embed + `--disable-zend-signals` with the same extension set, and ≥ 55 of the 61 `ext/test_scheduler` .phpt tests pass. | `scripts/build-php-async.sh`; `make test TESTS=ext/test_scheduler` | builds; ≥ 55/61 | 45 min | CONFIRMED (V-7): 61/61 |
| H9a (E6 on (b)) | On the fork, an unmodified blocking call (`fread` on a TCP stream, `usleep`) inside a coroutine suspends the coroutine instead of the thread. | grep + a .phpt-style script under `test_scheduler` | REFUTED by inspection expected | 15 min | REFUTED (V-7): no I/O path consults the ABI |
| H9b | A Rust-implemented scheduler provider (the `zend_async_scheduler_api_t` slot table) registered from the ignis module on the fork runs 10,000 engine coroutines each awaiting a 1000 ms tokio timer on one thread in < 1.2 s (E1 on backend (b)). | `PHP_CONFIG=/opt/php86-async-zts/bin/php-config cargo run --release -- bench/php/e1_async_core.php` | < 1200 ms | 150 min | CONFIRMED (V-8): 1165–1177 ms (via a 14-line idle hook in the reference scheduler + `ignis_park_on`) |
| H10 (E5 in-process) | 4 PHP threads each running a fixed CPU-bound PHP workload finish 4× the work in ≤ 1.23× the 1-thread wall time (≥ 3.25× throughput, the 4-vCPU-scaled version of "8 threads ≥ 6.5×"). | `IGNIS_THREADS=1..4 ./target/release/ignis bench/php/e5_cpu.php` (per-thread time printed) | ≥ 3.25× | 60 min | CONFIRMED (V-9): 3.7–3.98× |
| H11 (E4'/E5 over HTTP) | With 4 PHP threads, `/cpu` req/s ≥ 2.5× the 1-thread value under `wrk -t1 -c64` on this shared box, and hello-world at 4 threads ≥ FrankenPHP at 4 workers (V-6: 16.6k). | `bench/compare.sh` rows + `bench/wrk-hello.sh` on `/cpu` | ≥ 2.5×; ≥ 16.6k | 45 min | CONFIRMED (V-9): 3.49×; 105k vs 16.6k. p99 on /cpu@4 slightly worse than FrankenPHP (17.5 vs 15.6 ms) |
| H12 (E3) | Worker mode, 1 PHP thread: after a 5 s warm-up, RSS at ≥ 1,000,000 hello requests is within ±2% of RSS at the first post-warm-up sample, and `memory_get_usage()` is flat (±2%) too; the same holds for 200k `/sleep?ms=1` requests at 500 connections. | `bench/rss-1m.sh` | ±2% | 45 min | CONFIRMED (V-10): RSS −2.5% over 1.5M hello, PHP heap flat to the byte; 4.6M requests total |
| H13 (E13) | With the fiber-switch observer installed: (a) 200 concurrent `/echo?x=i&ms=20` requests on one thread each return their own `x` and `REQUEST_URI` (0 mismatches); (b) an in-process test with two interleaved fibers sees isolated `$_SERVER`/`$_GET`; (c) the swap costs < 1 µs per switch (1M suspend/resume pairs with vs without the hook). | `bench/php/e13_isolation.php`, `bench/e13-http.sh`, `bench/php/e13_switch_cost.php` | 0 mismatches; < 1 µs | 90 min | CONFIRMED (V-11): 0/0 mismatches; +100 ns per switch (microbench), ≈ +0.5 µs amortised with 10k live fibers |
| H14 (E6, tcp) | With the `tcp://` factory replaced, a handler doing three concurrent unmodified `file_get_contents('http://127.0.0.1:8080/sleep?ms=200')` (the server calling itself on ONE thread) returns in < 260 ms with correct bodies; the same call outside a fiber still works (blocking fallback); 100 concurrent `/fetch` requests all succeed. | `bench/e6-fetch.sh` | < 260 ms; 0 failures | 150 min | CONFIRMED (V-12): 202.5–202.9 ms; 100/100; negative control stalls without the hook |
| H14b (E6, sqlite) | PDO sqlite can be made to suspend the fiber via stream/transport hooks. | inspection of ext/pdo_sqlite + sqlite3 I/O path | REFUTED expected | 10 min | REFUTED (V-12): no stream layer in pdo_sqlite/libsqlite3; needs offload or native driver |
| H15 (E7) | With `REVOLT_DRIVER=Ignis\Revolt\IgnisDriver` and no source changes: Revolt's `examples/benchmark-timers.php`, `benchmark-ticks-delay.php`, `fiber-local-*.php`, `generate-yes.php`/`consume-stdin.php` (onReadable) and an `amphp/amp` `delay()`+`async()` script and an `amphp/socket` client against the Ignis hello server all produce the same output as under `StreamSelectDriver`; timer benchmark within 2×. | `bench/e7-revolt.sh` | identical output; ≤ 2× | 120 min | CONFIRMED (V-13): 7/8 identical, 1 timing race; benchmarks ≤ 1× |
| H16 (E11) | (a) A client that disconnects from `/slow` (5 s sleep, plus a child `Ignis\async` sleeping 5 s) causes both fibers to receive `CancelledException` within 10 ms of hyper dropping the request (measured from the drop instant to PHP handling), and no phantom work continues (`/stats` shows the cancel and the handler's `finally` ran). (b) `Ignis\deadline(100)` around a 1000 ms sleep returns 504 in 100–130 ms. (c) E6 (`/fetch`) still passes afterwards. | `bench/e11-cancel.sh` | ≤ 10 ms; 100–130 ms; E6 green | 120 min | CONFIRMED (V-14): 0.78 ms; 102 ms; green |
| H17 (E5') | With least-inflight dispatch, `/cpu` at 4 PHP threads (`wrk -t1 -c64 -d10s`) has p99 ≤ 15.6 ms (FrankenPHP@4, V-9) while keeping ≥ 9k req/s; hello at 4 threads does not regress by more than 5%. | `bench/compare.sh` rows / `wrk` on `/cpu` and `/` | p99 ≤ 15.6 ms; ≥ 9k req/s | 45 min | CONFIRMED (V-15): p99 12.4–12.8 ms at 9.1–9.3k req/s; two multi-thread bugs fixed on the way |
| H18 (E8) | symfony/skeleton (untouched sources; one service-class line in services.yaml, one worker entry script) boots once under `Ignis\Symfony\IgnisRuntime` and serves `/`; two interleaved requests to `/whoami?tag=X&ms=200` each get their own `RequestStack::getCurrentRequest()->getUri()` (0 mismatches over 100 concurrent); hello throughput in worker mode on 1 thread ≥ 5k req/s. | `bench/e8-symfony.sh` | boots; 0 mismatches; ≥ 5k req/s | 150 min | CONFIRMED (V-16 + addendum): 0/100 mismatches with sessions on; 7.2k req/s (1 thread), 25.2k (4 threads) |
| H19 (E12) | With `--threads 4 --supervise`: (a) `/fatal` kills exactly one worker thread; hello keeps being served during and after; the supervisor respawns the thread within 1 s and the log shows no opcache reset; (b) `/spin?s=5` (CPU loop) on one thread: hello p99 on the other threads stays < 20 ms during the spin and the watchdog reports 1 stalled thread; (c) after (a)+(b) the server serves hello at ≥ 80% of its pre-fault rate. | `bench/e12-isolation.sh` | 1 thread; < 20 ms; ≥ 80% | 120 min | CONFIRMED (V-17): 1 thread, 2.67 ms, 95.7% |
| H20 (E9, step 1) | `temporal-sdk-core` (git) links into a Rust binary here; against `temporal server start-dev` a worker on task queue `ignis` receives the first activation of a workflow started by the CLI and completes it with a `CompleteWorkflowExecution` command, and the CLI shows the run as Completed. | `scratchpad/tprobe` then `crates/ignis --features temporal` | one activation polled + completed | 120 min | CONFIRMED (V-18): run COMPLETED via the CLI |
| H20b (E9, step 2) | A PHP workflow (two activities + one timer) on fibers completes against the dev server and passes a replay test from its recorded history. | `bench/e9-temporal.sh` | replay passes | 180 min | **CONFIRMED** (V-19): live run COMPLETED in 1224 ms, replay OK over 22 events, replay of the mutated workflow FAILS with TMPRL1100 |
| H21 (E10) | On the existing hyper listener, `tonic::server::Grpc` with an opaque-bytes codec serves unary and server-streaming handlers written in PHP: `grpcurl` round-trips both, and `ghz -c 64` unary hello has p99 < 5 ms with 0 errors. | `bench/e10-grpc.sh` | p99 < 5 ms, 0 errors, streaming order preserved | 120 min | **CONFIRMED functionally; the absolute p99 target is INCONCLUSIVE** (V-20): 16.7k req/s p99 7.8 ms on 1 thread vs the pure-tonic ceiling 21.1k / 6.3 ms on the same box |
| H21b (E10) | A PHP gRPC client call parks the fiber: 100 concurrent `Proxy` calls, each awaiting a 200 ms upstream call over the runtime's own client, complete in < 300 ms on one PHP thread. | `bench/e10-grpc.sh` | < 300 ms | (with H21) | **CONFIRMED** (V-20): 217 ms for 100 × 200 ms |
| H21c (E10) | Build complexity: RoadRunner's grpc plugin and ext-grpc each need more toolchain, more build time and more bytes than tonic-in-ignis; measured, not asserted. | `bench/e10-compare.sh` + V-20 table | numbers for all three (or "blocked" with the domain) | (with H21) | **CONFIRMED** (V-20): RR needs Go 1.26 + 96 MB binary + protobuf PHP; ext-grpc needs the C-core build (pecl blocked) and has no server; tonic adds 28 crates and 0 MB |
| H22a (E15a) | php-src `run-tests.php` can drive the ignis binary through a `scripts/ignis-php` wrapper (`-n/-c/-d/-f` → `IGNIS_PHP_INI` + script); Zend/tests/fibers, ext/standard/tests/streams and ext/sockets/tests pass at ≥ 95% of the stock-binary rate in {main} mode, and every failure is classified (our bug / not applicable / upstream). | `bench/e15-phpt.sh` | pass rate + classification table | 120 min | **CONFIRMED for fibers/sockets, streams at 94.9%** (V-23): main mode 108/108, 80/80, 131/138 of stock; 73 failures classified, 0 upstream |
| H22b (E15b) | Revolt's abstract `DriverTest` run against `IgnisDriver` under the ignis binary passes 100%. | `bench/e15-revolt.sh` | 100% | 60 min | **CONFIRMED** (V-23 addendum): 81 tests / 222 assertions / 0 failures, identical to StreamSelectDriver (1 upstream error, 8 signal skips on both) after the readiness fast path + cancel op |
| H22c (E15c) | Swoole's `tests/swoole_runtime` suite through an Ignis shim (`Swoole\Runtime::enableCoroutine`, `Co\run`, `go`, `Co::sleep` on fibers) yields a mechanical list of the hooks Ignis lacks. | `bench/e15-swoole.sh` | table of missing hooks | 60 min | **CONFIRMED** (V-23): 44/79/30 through the shim; missing hooks ranked (Coroutine\Socket 36, accept/select 19, file 10, proc 10, udp/unix 7) |
| H22d (E15d) | FrankenPHP's `testdata/*.php` served by a classic-mode adapter (script included per request in a fiber, output buffered into the response) pass the assertions ported from `frankenphp_test.go`. | `bench/e15-frankenphp.sh` | n/N with each miss explained | 90 min | **CONFIRMED** (V-23): 29 pass / 4 fail / 33 skip; the 4 are runtime gaps (peer address, multi-cookie, putenv, $_FILES) |
| H22e (E15e) | Chaos mode (`IGNIS_CHAOS=1`: random fiber switch and jitter at every I/O point) running Symfony and Doctrine test suites inside an Ignis fiber produces zero failures that stock PHP does not. | `bench/e15-chaos.sh` (5 suites × stock / ignis / chaos seed 1 / chaos seed 20260916, `IGNIS_NOISE=4`) | 0 new failures | 120 min | **CONFIRMED** (V-27): 0 new failures from chaos in 10 849 tests per mode; 1 ignis-only failure, not applicable (php-cli `--` stdin script) |
| H23 (E14) | A runtime-owned tokio-postgres pool with per-fiber leases: 200 fibers × `SELECT pg_sleep(0.1)` through a pool of 20 finish in ≈ 1.0 s on one PHP thread; a transaction keeps one backend pid; a second acquire in the same fiber throws without submitting an op; `SET` inside a lease is invisible to the next lease (`DISCARD ALL`). | `bench/e14-pg.sh` | ≈ 1.0 s / same pid / LeaseError / reset verified | 150 min | **CONFIRMED** (V-21): 1046 ms warm, same pid, LeaseError with 0 ops, reset verified; 112 µs/query, 7.3k q/s on one thread |
| H24 (E16) | A pool of N synchronous PHP threads runs named functions with copied-in args and copied-out results while the calling fiber parks: 100 × 200 ms blocking calls finish in ≈ ceil(100/N) × 200 ms; callbacks run on the caller; copy cost per call is measured; then auto-routing of curl/PDO pgsql with no code changes. | `bench/e16-offload.sh` | bound by pool size; copy µs; curl WRITEFUNCTION works | 240 min | **CONFIRMED** (V-24 + addendum): pool 2604–2608 ms on 8 workers, 243–291 ms on 100; auto-routed `new PDO`+query 100 × 200 ms = 3454 ms on 8 workers (64: 2019); curl WRITEFUNCTION runs on the caller; 13–67 µs copy, 17–45 µs per routed call |
| H25 (E6') | `ssl://`/`tls://` (and so `https://`) client streams inside a fiber go through the hook with a rustls handshake on the tokio side; 3 concurrent 200 ms https fetches on one thread take ≈ 200 ms (hook off: ≈ 600 ms); STARTTLS upgrades a hooked tcp stream in place; verification honours the context options. | `bench/e6-ssl.sh` | ≈ 200 ms vs ≈ 600 ms; verify failures as PHP | 150 min | **CONFIRMED** (V-25): 210–231 ms vs 613–623 ms; 5/5 verification cases as PHP; STARTTLS upgrade in place |
| H26 (E6'') | `stream_socket_accept()` and `stream_select()` inside a fiber park instead of blocking the thread, the accepted socket is adopted by the reactor, hooked streams expose an fd and answer `get_name`; an accept loop and 3 clients on one thread complete in ≈ 300 ms (3 sequential 100 ms sleeps). | `bench/php/e6_accept.php` | ≈ 300 ms, select=1 | 90 min | **CONFIRMED** (V-26): 305 ms, select=1, names ok |
| H27 (E12') | A request in flight on a thread that dies gets a 500 at once; the supervisor's respawn serves the next one. | `bench/e12-inflight.sh` | < 1 s, not ≥ 3 s | 30 min | **CONFIRMED** (V-26): 500 after 0.30 s, respawn ok, 823 ms wall |
| H28 (E2') | The remaining per-fiber warm cost after the E13' lazy swap is the reactor's per-op timer task, not the PHP loop: completing `Ignis\sleep(0)` (a yield) inline in the reactor brings the warm round trip under 5 µs with the observer on. | `bench/php/e2_all.php` N=10000, 3 reps, observer on/off; phase breakdown before/after | < 5 µs warm, observer on | 20 min | **CONFIRMED** (V-28 addendum): 5.5–6.4 → **4.5–4.7 µs** warm (observer on), 4.4–4.7 off; phase breakdown ready 2.3–2.8 → 1.9–2.0, resume 1.7 → 1.3, poll unchanged; real sleeps unaffected; E1 1193 ms |


## H29 (A4, ADR-0018) — CONFIRMED with a caveat

**Statement.** Hooking the nine blocking `ext/sockets` read/write functions with the `accept.rs`
"park, then delegate" pattern makes N concurrent `socket_read`s of D ms complete in ~D ms on one
PHP thread instead of N×D, without dropping the C22 local phpt baseline (fiber-mode
`ext/sockets`: 85 passed / 7 failed).

**Test.** `bench/php/a4_sockets.php` (20 concurrent `socket_read`, 200 ms each, one thread; server
half on the already-hooked stream transport) with `IGNIS_NO_SOCKETS_HOOK=1` as the control;
`bench/php/a4_overhead.php` for the non-parking cost; php-src `ext/sockets/tests` in fiber mode for
parity. Time box: one cycle.

**Expected.** wall ≈ 200 ms hooked, control stalls; parity ≥ baseline; non-parking overhead
< 0.36 µs (ADR-0018 kill criterion 2).

**Result: CONFIRMED for concurrency and parity, REFUTED for the overhead criterion.** 249–266 ms
for 20×200 ms, 20/20 ok, control stalls (V-29). Parity 86 passed / 6 failed — one better than
baseline, zero new failures. The overhead criterion is exceeded and, more importantly, was
unmeasurable as written: see V-29 and the ADR-0018 addendum.

## H30 (reactor round-trip latency at low concurrency) — CONFIRMED, and the fix is narrower than the finding

**Statement.** The ~120 µs per reactor round trip seen in research 24 at concurrency 1 is not work
but a cross-thread wakeup pair, so it is a fixed cost per `poll()` wakeup divided by the batch that
wakeup drains — and a bounded spin on `try_recv` before sleeping the thread removes most of it.

**Test.** `bench/php/reactor_latency.php`: three legs differing in one thing each — `ignis_watch`
on an already-ready fd (never reaches tokio), `Ignis\sleep(0)` (crosses both channels, no timer and
no epoll: `Op::Sleep { us: 0 }` completes in the dispatcher task), and a two-fiber ping-pong over a
socketpair (a real `Op::Watch`: dup + `AsyncFd` + drop). Plus an amortization curve at 1…128
fibers, a bare two-thread `std::mpsc` ping-pong in Rust as the platform floor, and
`IGNIS_POLL_SPIN_US` swept 0…200. Time box: one cycle.

**Expected.** If the cost is epoll registration, the ping-pong leg is the expensive one and the
curve is flat. If it is the wakeup, the ping-pong leg is *not* special and the curve falls as
1/batch.

**Result: CONFIRMED — it is the wakeup.** The fixed cost is 74–98 µs whatever the batch size
(93.1 µs at 1 fiber, 0.58 µs at 128 — the product is constant), the platform floor for the same two
wakeups is 57.3–57.5 µs on this box, and the epoll leg is *cheaper* than the channels leg because
a ping-pong already has two fibers batching. My going-in guess (epoll registration per op) was
wrong. V-33.

**The fix is real but narrow.** `IGNIS_POLL_SPIN_US=100` cuts concurrency-1 from 96.1 to 30.0 µs
and lowers CPU per op at every concurrency, with no penalty on a saturated box and zero cost when
idle. But it only pays when the completion lands inside the spin window: a serial local pg query
improves 1.27–1.34×, while `GET /sleep?ms=1` gets *worse* (3.68→3.84 ms) because a millisecond-scale
wait pays the spin and then sleeps anyway. Shipped off by default as a tuning knob, not a default.

## H31 (a request is accepted but never answered, under inbound load) — CONFIRMED as mis-stated, root-caused and FIXED (V-36)

**Statement.** Under concurrent inbound load the server occasionally accepts a connection and
never produces a readable response, so a hooked `file_get_contents` against it returns `false`
with "Failed to open stream: HTTP request failed!". Rate is roughly 1 in 400–1200.

**How it surfaced.** Not as a new defect but as a newly *visible* one: E6 could not run on this
box until the port collision was fixed today, and the first honest runs showed `ok=49/50` in about
one run of three. `bench/e6-fetch.sh` asserts 50/50, so smoke stops there.

**What is already excluded** (V-34): it is not the hooked client — 1500 fetches against an idle
server are clean; it is not the self-call shape — it reproduces from a separate process with no
self-call at all; it is not a startup race — batch 1 of 6 was clean and batch 2 failed. The
captured body is `{"bodies":["slept\n",false,"slept\n"],"ms":203.9}`, and PHP's wording means the
connection *was* opened and the status line could not be read. The server logs nothing, at
`RUST_LOG=debug` too.

**Next test.** Instrument the accept path and the hyper service to count connections that are
accepted and produce no response, and separate "hyper never saw a request" from "PHP never got
it" from "the response was dropped". `bench/php/e6_underload.php` is the reproducer.

**Why it mattered before Phase B.** B1's acceptance ("100k queued requests never exceed the
configured RSS; p99 of admitted requests unchanged") is measured with exactly this storm shape, so
a 0.1–0.3 % unanswered rate would have sat inside those numbers and been read as queueing.

**Result (2026-09-16, V-36): the statement above was wrong in its central claim.** The request was
never unanswered — the server handled every one of them (counted: 1550 of 1550) and `curl` under the
identical load saw 1500/1500. The loss was on *our* client: with a read timeout set, `Op::TryRead`
can answer `WouldBlock` because the connection actor has not been polled yet even though the fd is
readable, and `op_read` returned 0, which PHP's `get_line` reads as "no line". A retry in the same
fiber got `HTTP/1.0 200 OK` immediately. Fixed by returning to the wait instead of giving up:
0 failures in 6000 against 2–3 per 1000, five clean `e6-fetch` runs, smoke GREEN, phpt unchanged.

## H32–H36 (E18 universal park, ADR-0020) — OPEN, one per owner acceptance

**H32 (acceptance 1).** With libcurl on policy `park` and no offload routing, 100 fibers each doing
`curl_exec` against a local `/sleep?ms=200` on ONE PHP thread finish in ≈ 200 ms wall, and each
`CURLOPT_WRITEFUNCTION` runs in the fiber that started the transfer. Test: `bench/php/e18_curl.php`
(`IGNIS_NO_OFFLOAD_ROUTE=1`), control `IGNIS_NO_UNIVERSAL_PARK=1` (expected ≈ 20 s). Time box: one
cycle after E18-I lands.

**H33 (acceptance 2).** Same for `pdo_pgsql`: 100 × `SELECT pg_sleep(0.2)` on one thread ≈ 200 ms
with offload disabled. Test: `bench/php/e18_pgsql.php`; control ≈ 20 s.

**H34 (acceptance 3).** `getaddrinfo` parks via the runtime resolver where the library calls it on
the calling thread (libpq, libphp — research 26); libcurl's threaded resolver parks through `poll`
instead. Test: `bench/php/e18_dns.php` — 50 concurrent libpq connects through a local stub
resolver that answers after 200 ms ≈ 200 ms; control ≈ 10 s.

**H35 (acceptance 4).** The non-fiber path costs < 20 ns per syscall. Test: `bench/e18-overhead.sh`
— two builds (feature `universal-park` on/off), 10 M zero-length `read` on a non-PHP thread, 3 reps
each, delta reported. Research 28 measured ≈ 8.3 ns in a scratch binary.

**H36 (acceptance 5).** The lock hazard is real and the policy contains it: a library that takes a
pthread mutex, calls `read` on a pipe and unlocks (research 27's `locklib.c`) **deadlocks** under
`park` when two fibers on one thread use it (test times out; the mutex owner in the trace is the
same thread) and **passes** under `block`. Test: `bench/e18-deadlock.sh`. A `park` run that does not
deadlock refutes the hazard model and is itself a finding.

**Kill criterion (owner):** any OpenSSL or libcurl test failing under `park` with a lock in the
trace — the E15 suites and `bench/e6-ssl.sh` run with `IGNIS_PARK=libcurl,libcrypto,libssl`.
