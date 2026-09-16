# JOURNAL

Timestamped log of every stage transition. UTC. Newest at the bottom.

- 2026-09-15T22:18:36Z C0 START — repo empty, branch claude/ignis-php-fibers-tokio-644209, no commits.
- 2026-09-15T22:18:36Z C0 RESEARCH — toolchain verified: rustc 1.94.1, cargo 1.94.1, PHP 8.5.10 (latest tag) cloned from php-src, system php is 8.4.19 NTS (unusable). ondrej PPA blocked by proxy; building PHP from source. re2c/wrk/ab installed via apt.
- 2026-09-15T22:18:36Z C0 RESEARCH — read Zend/zend_fibers.{h,c}, zend_observer.h fiber hooks, sapi/embed/php_embed.c, TSRM.c ts_resource, FrankenPHP frankenphp.c thread loop, Revolt AbstractDriver, Swoole runtime hooks. Writing docs/research/00-environment-and-fiber-api.md.
- 2026-09-15T22:18:36Z C0 RESEARCH done — docs/research/00-environment-and-fiber-api.md written.
- 2026-09-15T22:19:36Z C0 DECIDE — writing ADR-0001 (FFI + process/thread model + scheduler placement).
- 2026-09-15T22:19:36Z C0 DECIDE done — ADR-0001 accepted.
- 2026-09-15T22:19:36Z C0 HYPOTHESIZE — writing HYPOTHESES.md H1..H4 for E1/E2 and the embed FFI.
- 2026-09-15T22:19:36Z C0 HYPOTHESIZE done — H0..H4 recorded.
- 2026-09-15T22:21:37Z C0 IMPLEMENT — scaffolding cargo workspace: crates/ignis-sys (bindgen over php_embed.h, ZTS) + crates/ignis (bin, tokio reactor, internal module).
- 2026-09-15T22:26:50Z C0 IMPLEMENT/VALIDATE — H0 CONFIRMED (V-0), H1 CONFIRMED (V-1): ignis binary embeds libphp ZTS, internal module registered, hello runs in 16ms; nextest 7/7 green.
- 2026-09-15T22:31:12Z C0 VALIDATE — H2/E1 CONFIRMED marginal: 1168-1178ms for 10k fibers (V-2). H4 CONFIRMED: resume phase 87-90ms. perf: ~50% of PHP-thread CPU is kernel mmap/munmap/page-fault of fiber stacks; scheduler code is noise.
- 2026-09-15T22:31:12Z C0 VALIDATE — H3/E2 CONFIRMED: all() 201-202ms, 21.6-24.2us per fiber (V-3).
- 2026-09-15T22:33:43Z C0 REASSESS — E1/E2 confirmed; raising E1; next cycle question chosen (HTTP worker transport + fiber pool). miri (nightly) green on zval/module helpers after fixing a test-only provenance bug it found.
- 2026-09-15T22:35:07Z C0 VALIDATE addendum — smoke.sh E1 = 1302ms with background builds running (REFUTED under load). Margin too thin; fiber pool moves to top of Cycle 1.
- 2026-09-15T22:37:11Z C1 START — question: cheapest sound HTTP transport (hyper→channel→PHP thread) + fiber pool; E4 baselines: php-fpm (NTS build) behind nginx, FrankenPHP v2.11.4 built against the same libphp ZTS.
- 2026-09-15T22:37:11Z C1 RESEARCH — hyper 1.11.1 / hyper-util 0.1.20 auto server API read from registry sources; FrankenPHP frankenphp_handle_request + threadworker.go read; writing docs/research/01-http-transport-and-fiber-pool.md.
- 2026-09-15T22:37:11Z C1 DECIDE — ADR-0002 (value-based request boundary, hyper auto builder, userland fiber pool).
- 2026-09-15T22:37:11Z C1 HYPOTHESIZE — H5 (pool), H6 (HTTP hello via hyper), H7 (E4 vs baselines).
- 2026-09-15T22:38:44Z C1 IMPLEMENT — reactor: HTTP requests as poll events (hyper auto, oneshot responders); module: ignis_serve/ignis_respond; loop: fiber pool + Ignis\serve.
- 2026-09-15T22:44:50Z C1 VALIDATE — H5 CONFIRMED (V-4): warm pool 37-41ms overhead for 10k fibers, 4.4-4.6us per job. H6 CONFIRMED (V-5): 122-130k rps hello on 1 PHP thread, p99 1.01s for 1000 concurrent sleeps; 10k-connection p99 1.21s INCONCLUSIVE (load generator shares the box).
- 2026-09-15T22:49:35Z C1 IMPLEMENT — owner addendum applied (pain map, E9-E14, CLAUDE.md, push-after-commit, night-1). Name check: ignis taken on crates.io (v0.1.0 unrelated), free on Packagist, unrelated GitHub repos. FrankenPHP needs --disable-zend-signals: rebuilding libphp ZTS with it (both servers will use the same build). nextest 8/8.
- 2026-09-15T22:56:31Z C1 VALIDATE — H7/E4 CONFIRMED (V-6): ignis 128k rps p99 1.1ms vs FrankenPHP worker 27.6k p99 6.4ms vs php-fpm+nginx 9.9k p99 8.7ms, same libphp build, 1 PHP thread.
- 2026-09-15T22:56:31Z C1 REASSESS — E4 raised to E4' (4 threads, $_SERVER populated). Owner message: Cycle 2 = async scheduler ABI RFC + PR #22561 + true-async fork; ADR with two backends; E6 prototype on the fork if it builds. GC/destructor suspend item added to pain map. E5 moves to Cycle 3.
- 2026-09-15T22:56:46Z C2 START — question: can the reactor/scheduler sit behind a trait with a mainline-8.5 backend and an async-core (true-async fork) backend, and does E6 fall out of the engine ABI?
- 2026-09-15T22:56:46Z C2 RESEARCH — fetching RFC async_scheduler_abi (one try), php-src PR #22561, cloning true-async/php-src async-core.
- 2026-09-15T23:00:30Z C2 RESEARCH done — docs/research/02: PR #22561 == fork async-core head 14af3cb (8.6.0-dev); ABI = provider slot table used only by Zend core; NO stream/sleep integration → E6 does not fall out of (b). Mainline master already has main/poll. RFC page blocked (logged).
- 2026-09-15T23:00:30Z C2 DECIDE — ADR-0003: two backends (mainline85 production, async-core provider prototype), shared reactor; E6 via stream hooks on (a).
- 2026-09-15T23:00:30Z C2 HYPOTHESIZE — H8 (fork builds + test_scheduler passes), H9a (E6 on (b): expected REFUTED by inspection), H9b (Rust provider runs E1 on engine coroutines).
- 2026-09-15T23:04:22Z C2 IMPLEMENT — backend (b) prototype: idle-hook patch for ext/test_scheduler, ignis_await_op (Rust) parks the engine coroutine on a reactor op, cfg(php_async_abi) wiring; mainline build unaffected.
- 2026-09-15T23:08:54Z C2 VALIDATE — H8 CONFIRMED (V-7: fork builds, 61/61 tests), H9a REFUTED by inspection (no I/O path consults the ABI), H9b CONFIRMED (V-8: E1 on engine coroutines 1165-1177ms via idle hook).
- 2026-09-15T23:08:54Z C2 REASSESS — E6 stays stream-hook work on both backends; full Rust provider deferred (needs C shim for zend_first_try); Cycle 3 = N PHP threads (E5) + E4' at 4 threads.
- 2026-09-15T23:10:52Z C3 START — question: N PHP OS threads (ZTS) with one reactor each; does CPU-bound work scale (E5) and does E4' hold at 4 threads?
- 2026-09-15T23:10:52Z C3 RESEARCH/DECIDE/HYPOTHESIZE — research 03 (TSRM per-thread ctors, php_request_startup has no main-thread assumption), ADR-0004, H10/H11.
- 2026-09-15T23:11:50Z C3 IMPLEMENT — per-thread reactors, worker thread lifecycle (ts_resource + php_request_startup), round-robin dispatch, --threads.
- 2026-09-15T23:20:53Z C3 VALIDATE — H10 CONFIRMED (V-9: 3.7-3.98x in-process at 4 threads), H11 CONFIRMED (3.49x /cpu over HTTP; hello@4 105k vs FrankenPHP@4 16.6k; /cpu p99 17.5 vs FrankenPHP 15.6 ms noted).
- 2026-09-15T23:20:53Z C3 REASSESS — E5 confirmed (scaled to 4 vCPU), E5' = least-inflight dispatch; Cycle 4 question = E3 RSS flat over 1M requests.
- 2026-09-15T23:22:37Z C4 START — question: is RSS flat (±2%) over 1,000,000 requests in worker mode (E3), and does PHP heap usage stay flat too?
- 2026-09-15T23:22:37Z C4 RESEARCH/DECIDE/HYPOTHESIZE — research 04 (leak candidates), ADR-0005 (RSS via /stats), H12; bench/rss-1m.sh.
- 2026-09-15T23:24:13Z C4 VALIDATE — H12/E3 CONFIRMED (V-10): RSS 26.9→26.2 MB over 1.5M hello requests, PHP heap flat to the byte; 4.6M requests total; /sleep?ms=1 at 500 conns sustained 131k rps.
- 2026-09-15T23:24:13Z C4 REASSESS — E3 raised to E3' (with E6/E13, 4 threads, 10M). Cycle 5 = E13 fiber-switch state swap.
- 2026-09-15T23:25:58Z C5 START — question: can zend_observer fiber-switch hooks give each fiber its own $_SERVER/$_GET/$_POST/$_COOKIE at < 1 us per switch (E13)?
- 2026-09-15T23:25:58Z C5 RESEARCH/DECIDE/HYPOTHESIZE — research 05, ADR-0006, H13.
- 2026-09-15T23:27:23Z C5 IMPLEMENT — superglobals.rs (fiber-switch/destroy observers, ignis_set_superglobals), loop populates $_SERVER/$_GET/$_POST/$_COOKIE at dispatch, Ignis\Scope WeakMap, E13 tests.
- 2026-09-15T23:41:57Z C5 VALIDATE — H13/E13 CONFIRMED (V-11): 0 mismatches in-process and over 200 concurrent HTTP requests; +100 ns per switch (reserved-slot storage; HashMap version was 2x worse and replaced).
- 2026-09-15T23:41:57Z C5 REASSESS — E13' = lazy swap + PG(http_globals); Cycle 6 = E6 stream hooks (tcp:// factory), sqlite part not hookable.
- 2026-09-15T23:42:28Z C6 START — question: can a replacement tcp:// transport factory make unmodified file_get_contents('http://…') suspend the fiber (E6)?
- 2026-09-15T23:44:56Z C6 RESEARCH/DECIDE/HYPOTHESIZE — research 06 (transport factory swap, http wrapper needs no fd, suspend from C via zend_fiber_suspend), ADR-0007, H14/H14b.
- 2026-09-15T23:47:15Z C6 IMPLEMENT — reactor connection ops (tokio actor per TcpStream), stream.rs tcp:// factory + ops suspending via zend_fiber_suspend, ignis_poll resumes C-parked fibers, /fetch route.
- 2026-09-15T23:49:31Z C6 VALIDATE — H14/E6 tcp CONFIRMED (V-12): 3x200ms unmodified file_get_contents in 203ms on one thread, 100/100 concurrent, fallback ok, hook-disabled control stalls. H14b sqlite REFUTED for hooks (no stream layer).
- 2026-09-15T23:49:31Z C6 REASSESS — E6' = ssl + native pgsql; Cycle 7 = E7 Revolt driver.
- 2026-09-15T23:52:55Z C7 START — question: can a Revolt Driver on ignis_poll run Revolt/AMPHP examples unchanged (E7)?
- 2026-09-15T23:57:49Z C7 IMPLEMENT — IgnisDriver (Revolt AbstractDriver: activate/dispatch/deactivate/now), ignis_watch + Op::Watch (tokio AsyncFd, regular files always ready), amphp installed from source via composer --prefer-source; 6/7 examples identical, fiber-local-manual is a timing race, amp-socket needs ext-filter (rebuilding libphp with filter/ctype/tokenizer).
- 2026-09-16T00:03:34Z C7 VALIDATE — H15/E7 CONFIRMED (V-13): 7/8 Revolt+AMPHP examples byte-identical on IgnisDriver (1 timing race), amphp/socket TCP client works, timer benchmarks <= 1x of StreamSelectDriver.
- 2026-09-16T00:03:34Z C7 REASSESS — E7' = AMPHP on hooked transport + signals; Cycle 8 = E11 cancellation + deadline.
- 2026-09-16T00:05:15Z C8 START — question: does a client disconnect cancel the request fiber and its children within 10 ms; does a per-request wall-clock deadline work (E11)?
- 2026-09-16T00:05:15Z C8 RESEARCH/DECIDE/HYPOTHESIZE — research 08, ADR-0009, H16.
- 2026-09-16T00:11:56Z C8 VALIDATE — H16/E11 CONFIRMED (V-14): 20/20 disconnects cancelled incl. children, finally ran, worst latency 0.78 ms; deadline 504 at 102 ms; E6 still green.
- 2026-09-16T00:11:56Z C8 REASSESS — E11' = under load; Cycle 9 = E5' least-inflight dispatch (small), then E8 Symfony attempt.
- 2026-09-16T00:12:46Z C9 START — question: does least-inflight dispatch close the /cpu p99 gap vs FrankenPHP at 4 threads (E5')?
- 2026-09-16T00:12:46Z C9 IMPLEMENT — Registry::pick = least pending_requests() with rotating ties.
- 2026-09-16T00:19:13Z C9 VALIDATE — H17/E5' CONFIRMED (V-15): /cpu@4 p99 12.4-12.8ms (FrankenPHP 15.6), 9.1-9.3k rps, hello 112k. Found+fixed: ignis_serve bind race; forged IS_ARRAY_EX refcount on immutable [] corrupting the heap under 4 threads.
- 2026-09-16T00:19:13Z C9 REASSESS — soak rule added (DECISIONS); Cycle 10 = E8 Symfony (skeleton installing from source in background).
- 2026-09-16T00:21:17Z C10 START — question: does symfony/skeleton boot once in worker mode behind a symfony/runtime adapter over Ignis\serve, with a fiber-scoped RequestStack (E8)?
- 2026-09-16T00:25:52Z C10 VALIDATE — H18/E8 CONFIRMED (V-16): skeleton boots once under Ignis\Symfony\IgnisRuntime, 0/100 RequestStack mismatches across suspensions, 7.8k rps hello through the kernel; sessions disabled until ext-session rebuild.
- 2026-09-16T00:26:30Z C10 REASSESS — E8' (sessions, multi-cookie, 4 threads); remaining open: E9, E10, E12, E14. Cycle 11 = E12 isolation + supervisor.
- 2026-09-16T00:28:03Z C11 START — question: does a fatal or a long CPU loop in one thread leave the others serving; can the supervisor respawn that thread without an opcache reset (E12)?
- 2026-09-16T00:29:40Z C11 VALIDATE — H19/E12 CONFIRMED (V-17): fatal kills one worker, respawned (restarts=1) with hello at 134k rps throughout; CPU spin stalls one thread (watchdog stalled=1), others 90k rps p99 2.7ms; recovery 95.7%.
- 2026-09-16T00:31:07Z C11 REASSESS — E8 addendum with sessions on (7.2k/25.2k rps); open: E9/E10/E14 (research-first next); STATUS refreshed.
- 2026-09-16T01:03:55Z C11 — scripts/smoke.sh GREEN end to end (build, 9 unit tests, app.php routes incl. E6 self-call and E11 deadline, E1/E2, E5 threads, E13, E6, E7, E11, E12). E2' not met with observer on (V-11 addendum).
- 2026-09-16T01:07:03Z C12 START — question (E9, research-first per the brief): how does the Python SDK bridge temporal sdk-core to asyncio, and what is the smallest in-process Ignis equivalent on Fibers?
- 2026-09-16T01:07:03Z C12 RESEARCH/DECIDE/HYPOTHESIZE — research 12 (sdk-python bridge = poll/complete bytes over pyo3 futures; determinism is userland), ADR-0013 (proposed), H20/H20b. Building sdk-core (git) probe + temporal CLI in background.
- 2026-09-16T01:13:56Z C12 IMPLEMENT/VALIDATE — H20 CONFIRMED (V-18): sdk-core (git) worker in a Rust binary completed a CLI-started workflow on the locally built dev server. Probe source kept at examples/rust/temporal-probe.
- 2026-09-16T01:22:06Z C13 START/IMPLEMENT — H20b: temporal feature in the ignis crate (Op::Custom escape hatch, JSON boundary via serde_serialize), PHP runtime php/temporal (workflows as fibers, commands recorded on await, activities as Ignis fibers), replay via init_replay_worker.
- 2026-09-16T01:37:40Z C13 VALIDATE — H20b/E9 CONFIRMED (V-19): Demo workflow (greet → 500 ms timer → shout) on PHP fibers COMPLETED in 1224 ms on the dev server, 5 activations with the fiber kept alive (sticky cache); replay from the gRPC-fetched history OK; the same history against the workflow without the timer FAILS replay with TMPRL1100 (negative control).
- 2026-09-16T01:40:00Z C13 REASSESS — three refuted sub-hypotheses on the way (raw proto JSON from PHP, protojson history round-trip, loop payload routing) fixed and logged in V-19. E9 done in prototype scope. Open: E10 (tonic gRPC), E14 (pgsql pool), E2'/E13' (observer cost), E6' (ssl), E12'. Cycle 14 = E10.
- 2026-09-16T01:42:37Z C13 — owner note: target-async/ (backend (b) cargo dir, 709 files) was tracked by mistake; untracked and ignored (target-*/). History not rewritten (CLAUDE.md), so the blobs stay in past commits.
- 2026-09-16T01:43:13Z C14 START — question (E10): can Ignis serve gRPC unary + server-streaming handlers written in PHP on the existing hyper h2 stack, and can a PHP gRPC client call suspend the fiber; how does that compare with RoadRunner's grpc plugin and ext-grpc on latency and build complexity?
- 2026-09-16T01:48:11Z C14 — GOALS table repaired (E7/E8 were still marked OPEN although V-13/V-16 confirmed them; E11–E14 rows were missing); E15 added.
- 2026-09-16T01:55:46Z C14 IMPLEMENT/VALIDATE (first numbers) — gRPC unary + server-streaming in PHP on the shared listener: grpcurl round-trips both; ghz unary c=64: 18.5k rps, p99 6.9 ms (1 thread; 4 threads 18.1k, p99 8.2 ms → the bottleneck is not the PHP thread); streaming 3 msgs 10 ms apart avg 34.6 ms; H21b: 100 Proxy calls each awaiting a 200 ms upstream done in 217 ms on one thread. Next: pure-tonic baseline on the same box, RR + ext-grpc comparison.
- 2026-09-16T02:02:22Z C14 VALIDATE — H21/H21b/H21c (V-20): Ignis gRPC 16.7k req/s p99 7.8 ms (1 PHP thread) vs pure tonic 21.1k / 6.3 ms vs RoadRunner 5.1k–11.4k / 12.6–17.8 ms on the same box; 100 concurrent 200 ms calls: 215 ms (Ignis) vs 5.03 s (RR 4 workers); client call parks the fiber (217 ms for 100 Proxy calls). Absolute p99 < 5 ms unmeasurable here (ceiling 6.3 ms) → INCONCLUSIVE, everything else CONFIRMED. ext-grpc C-core still building in the background (pecl.php.net blocked).
- 2026-09-16T02:03:26Z C14 REASSESS — pain-map: RoadRunner 3/5 and Swoole 5 amended with V-20 numbers. E10 done (unary + server-streaming; client-streaming/bidi/TLS out of scope). Cycle 15 = E15 compat, starting with the Revolt DriverTest (harness exists) and an `ignis run-tests` wrapper for php-src suites; needs `$argv` under embed.
- 2026-09-16T02:04:42Z C15 START — question (E15a): what fraction of Zend/tests/fibers, ext/standard/tests/streams and ext/sockets/tests passes when each test runs inside the ignis binary (a) in {main} and (b) inside an Ignis fiber with the stream hook active, and what is every failure?
- 2026-09-16T02:08:11Z C15 — owner note applied at the C14→C15 boundary: history-rewrite inventory recorded in DECISIONS (only `target-async/` ever bloated the history), `.claude/settings.json` + agents porter/bencher/scribe on Opus 5 with the guard-ffi hook, delegation rule and `model=` suffix adopted. Two subagents (Swoole hook inventory, FrankenPHP testdata port) were already running from before the note. model=main
- 2026-09-16T02:10:07Z C15 — one-time history rewrite, owner-authorized, 2026-09-16: `git filter-repo --invert-paths --path target-async/` (0.26 s). Objects 1323 loose / 113.8 MiB → one pack of 295 KiB; full bare clone 88 MB → 500 KB; shallow clone 400 KB; commits 51 → 51 (authors/dates/messages intact); 0 target-async objects left; origin re-added, night-1 and claude/ignis-php-fibers-tokio-644209 force-pushed; .gitignore extended (target-*/, vendor-php/, php-src*/, **/vendor/, *.so, *.a). The no-rewrite rule is back in force. model=main
- 2026-09-16T02:12:59Z C15 IMPLEMENT — `$argv`/`$argc`/`$_SERVER['argv']` now set under embed on the main and worker threads (SG(request_info).argc/argv before php_request_startup; `ignis script.php args...` like php-cli), needed by phpunit-based E15 suites. E15a/E15c/E15d delegated (porter on Opus: php-src suites; two pre-note agents: Swoole inventory, FrankenPHP testdata); ext-grpc build-complexity numbers delegated to bencher. model=main
- 2026-09-16T02:13:02Z C16 START — question (E14): can a runtime-owned PostgreSQL pool (tokio-postgres) hand a fiber a leased connection whose queries park the fiber, pin the lease for a transaction, reset the session on return, and refuse a second acquire in the same fiber — and what does it cost vs pdo_pgsql per query? model=main
- 2026-09-16T02:14:27Z C16 RESEARCH/DECIDE/HYPOTHESIZE — research 14 (native tokio-postgres driver; hooks cannot intercept libpq), ADR-0015 (pool owned by the runtime, lease per fiber, transaction pins, ROLLBACK; DISCARD ALL on return, second acquire = LeaseError), H23. model=main
- 2026-09-16T02:24:48Z C16 IMPLEMENT/VALIDATE — H23/E14 CONFIRMED (V-21): tokio-postgres pool in the runtime; 200 fibers × pg_sleep(0.1) over 20 connections in 1046 ms warm; transaction pins one backend; LeaseError in 38 µs with 0 ops; SET/temp table do not leak; 112 µs per query with the statement cache, 7.3k q/s on one thread under load 8.9. Two refuted sub-steps fixed on the way: `ROLLBACK; DISCARD ALL` in one string fails inside the implicit transaction block (every connection was dropped: 200 connects); unobserved exceptions in un-awaited fibers hid the second failure (bytea binding). model=main
- 2026-09-16T02:24:48Z C16 REASSESS — pain-map RoadRunner 8 and Swoole 1 ADDRESSED for PostgreSQL. Swoole agent (E15c, pre-note, model=main-spawned) reported: 153 swoole_runtime tests → PASS 42 / FAIL 81 / SKIP 30 through the shim, and four runtime defects worth fixing now: (1) `stream_socket_server` inside a fiber is broken by the tcp hook (BIND/LISTEN → NOTIMPL) — a regression vs stock; (2) `Loop::run()` returns while a fiber is parked in a C stream op; (3) `sleep`/`usleep` block the thread inside fibers; (4) exceptions in un-awaited `Ignis\async` fibers are swallowed (also bit V-21). Cycle 17 = those four, then re-run the Swoole bench for the delta. model=main
- 2026-09-16T02:25:10Z C17 START — question: do the four defects from the Swoole port (server sockets broken inside fibers, Loop::run exiting with C-parked fibers, blocking sleep/usleep in fibers, swallowed exceptions in un-awaited fibers) each have a small fix, and how many more swoole_runtime tests pass afterwards? model=main
- 2026-09-16T02:32:04Z C17 — owner added E16 Offload (sync PHP worker threads with own TSRM context, Ignis\offload with copy-in/copy-out, config-driven auto-routing of curl_exec/PDO pgsql/SQLite3/Redis, reverse callbacks for CURLOPT_WRITEFUNCTION); recorded in BRIEF/GOALS/STATUS; scheduled as Cycle 18 (needs libphp rebuilt with pdo_pgsql/curl/sqlite3 once the porter's suites stop using the current libphp). FrankenPHP agent (E15d, pre-note, model=main-spawned) reported 29 pass / 4 fail / 33 skip; the four fails are runtime gaps: no peer address in the request payload, single Cookie header + no name mangling, putenv persists across requests, no multipart/$_FILES. model=main
- 2026-09-16T02:53:52Z C17 IMPLEMENT — fixes from the E15 ports: (1) server sockets (STREAM_XPORT_SERVER=1, verified against php_stream_transport.h; the porter caught my first value 2, which would have silently unhooked client connects) stay on the stock transport; (2) Loop::runUntil keeps polling while ops are in flight (C-parked fibers); (3) sleep()/usleep() handlers swapped at MINIT: inside a fiber they park on a reactor timer (10 fibers × usleep(200 ms) = 201 ms, 3 × sleep(1) = 1001 ms), outside they run the originals; (4) a rejected Future nobody awaits is rethrown when the loop stops; (5) STDIN/STDOUT/STDERR defined at RINIT on every thread; (6) PHP_BINARY via sapi executable_location (not yet effective: still empty, investigating). Swoole suite re-run by main after (1)–(4) under load 25: PASS 44 / FAIL 79 / SKIP 30 (agent: 42/81/30). phpt suites re-running in the background. model=main
- 2026-09-16T02:58:21Z C17 — owner CI note applied: docker/php.Dockerfile + .github/workflows/php-image.yml (ghcr.io/koekaverna/ignis-php:8.5.10-zts from scripts/build-php.sh on change) and ci.yml (nextest + miri; smoke.sh with Postgres service; E9 with the Temporal CLI dev server; E15 matrix phpt/revolt/swoole/frankenphp with scripts/ci-gate.sh against bench/results/e15-baseline.txt; timeout-minutes everywhere; failed-step logs as artifacts). smoke.sh: every step under timeout (120 s runtime, 900 s build/tests). Rule adopted: compat/correctness suites run in CI; local runs are for VALIDATION numbers and perf. No gh CLI here: runs are read through the GitHub MCP actions tools. model=main
- 2026-09-16T03:03:44Z C17 — PHP_BINARY fixed (php_embed_init overwrites executable_location with argv[0]; the constant is now re-registered at MINIT from current_exe(), PG(php_binary) strdup'd so core_globals_dtor can free it). CI triggers restricted per the owner note; pushes go to night-1 only. model=main
- 2026-09-16T03:06:57Z C18 START — question (E16): can a pool of synchronous PHP threads (own TSRM context) run named functions with copied-in args and copied-out results while the calling fiber parks, with reverse callbacks to the caller thread, and what does a call cost? model=main
- 2026-09-16T03:20:33Z C18 VALIDATE — E15b re-run by main: after the `ignis_watch` readiness fast path and `ignis_cancel` (fd leak +110 → +0), IgnisDriver passes Revolt's DriverTest identically to StreamSelectDriver (81/222, 0 failures, 3/3 runs). E16 part 1 CONFIRMED (V-24): 100 × 200 ms through 8 workers = 2608 ms, 100 workers = 243 ms, 13–27 µs copy cost, callbacks + RemoteException work. CI: first real run on the image found the ported scripts' hardcoded /home/user paths and a missing libprotobuf-dev in the image; fixed. model=main
- 2026-09-16T03:35:12Z C18 VALIDATE — H24/E16 CONFIRMED (V-24 + addendum): auto-routing via handler trampolines + create_object hook; `new PDO`/`new SQLite3`/`curl_init` in fibers become worker-pinned proxies; 100 × 200 ms pdo_pgsql through 8 workers = 3454 ms (64: 2019), curl WRITEFUNCTION on the caller, 17–45 µs per routed call. libphp now has pdo_pgsql/pgsql/curl/openssl (build-php.sh; php-image rebuilds). Bencher's E10 build table landed with V-20 corrections (ext-grpc builds in 77 min + 3 linker workarounds, 47.6 MB .so; baseline cold 56.9 s; binary 27.7 MiB). Disk was at 99%%: comparison trees and Go caches removed (9 GB free). model=main
- 2026-09-16T03:35:12Z C18 REASSESS — pain-map Swoole 5/6 amended. Open: E15e chaos mode (needs IGNIS_CHAOS in the loop, then porter), E15c quiet re-run, E6' ssl:// (openssl is now in the build), E2'/E13' observer cost, E12'. Cycle 19 = chaos mode switch + E6' ssl. model=main
- 2026-09-16T03:36:23Z C19 START — owner disk rule in CLAUDE.md; cleanup done (disk 99% → see df below). CI run #11: unit+miri, smoke, phpt, revolt, swoole, frankenphp green; E9 failed only on the pre-libprotobuf image. Question for C19: does a chaos switch (random switch order + extra yields at every I/O point) leave Symfony/Doctrine suites at zero new failures vs stock PHP (E15e), and can ssl:// go through the hook now that openssl is built (E6')? model=main
- 2026-09-16T03:50:09Z C19 VALIDATE — H25/E6' CONFIRMED (V-25): hooked ssl://+https:// 210–231 ms for 3 × 200 ms vs 613–623 ms unhooked; verification per context options; STARTTLS in place. Fixed on the way: Ready not routed to C-parked fibers, missing TLS flush, Future::await() returning null from an idle loop. Chaos mode landed (IGNIS_CHAOS); porter running E15e; Swoole quiet re-run in background. model=main
- 2026-09-16T03:54:34Z C19 — E13' lazy swap + loop-scheduled GC landed; E2' per-fiber numbers under load are noise (warm 4.2–9.8 µs across combos) → re-measure on a quiet box before recording. E12' next: pending requests on a dying thread must get a 500 now, not hang. model=main
- 2026-09-16T03:56:02Z C19 — Swoole quiet re-run identical (44/79/30 at load 3.4): stable. E12' fail-fast for in-flight requests on a dying thread pushed unbuilt (CI builds it); local rebuild + smoke + quiet E1/E2 numbers wait for the E15e porter to finish (its phpunit runs share the 4 vCPUs; E1 measured 1278–1305 ms under that load vs the 1200 ms bar). model=main
- 2026-09-16T04:06:15Z C20 IMPLEMENT — E6'': `stream_socket_accept()` inside a fiber parks on the listener's readiness and the accepted socket is adopted by the reactor; `stream_select()` inside a fiber parks on all fds + timeout (park-on-any) then runs the original with a zero timeout; hooked streams expose a dup'd fd for select/socket_import_stream, answer `stream_socket_get_name()` and report `stream_type=tcp_socket`. Test (side build, target-c20): 3 clients served by an accept loop in a fiber on one thread in 305 ms (3 × 100 ms sequential sleeps), select=1 on every client, peer/local names ok. Found and fixed a cast-protocol bug (fd cast writes an int, not a pointer — the first version smashed the stack). Scribe reconciled STATUS/GOALS/pain-map/HYPOTHESES/ADRs (model=scribe); V-24 second run raw output added. model=main
- 2026-09-16T04:08:50Z C20 — CI gate caught a real regression from the E13' lazy swap (FrankenPHP 29 → 27: a child Fiber started inside a request saw the base world's empty $_GET). Fix: the observer tracks the owner of the installed view; children of an isolated fiber inherit it, {main} gets the base world back, a dying owner releases the view. Local: FrankenPHP 29/4/33 again, isolation 0/0, 200 concurrent HTTP 0 mismatches. E12' in-flight test on the side build: the sleeping request got its 500 0.30 s after the fatal, respawned thread answers, 823 ms wall (a hang would be ≥ 3 s). model=main
- 2026-09-16T04:58:40Z C20 IMPLEMENT — phpt fiber-mode streams regression root-caused to three hook gaps, not one: (1) `stream_select` parked before PHP's buffered-data check (fixed earlier: probe-first + CAST_INTERNAL); (2) a losing `stream_select` timeout kept the userland loop alive until it lapsed (bug64438: 60 s) → `Op::Sleep` is now cancellable like a watch and the select/accept hooks cancel the loser; (3) `stream_socket_accept` ignored its timeout and non-finite check (non_finite_values hung forever) → the hook computes the timeout like PHP (null → FG(default_socket_timeout) via TSRM id storage, < 0 → forever, non-finite → original throws), parks on watch+timer, and on expiry calls the original with a zero timeout for the stock warning; (4) adopted sockets ignored `stream_set_blocking(false)` (gh8472: second fread parked forever) → Sock.blocking, `Op::TryRead` (one poll with a no-op waker → Data or WouldBlock), META_DATA_API fills blocked/timed_out/eof. All 8 previously regressed tests PASS in fiber mode on target-c20; full matrix + gate running. nextest 10/10. model=main
- 2026-09-16T05:01:43Z C20 VALIDATE — V-26 addendum: full phpt matrix on the fixed build, fiber-mode streams **120** (gate 116; 111 before), main streams 132, fiber fibers 78, sockets unchanged; gate rc=0 locally; baseline raised. The 12 remaining fiber-only stream failures classified (4 harness, 8 ours, listed). model=main
- 2026-09-16T05:01:43Z C20 VALIDATE — H22e/E15e CONFIRMED (V-27): porter's full pass (5 suites × 4 modes, 10 849 tests per mode) shows zero new failures from chaos and one ignis-only failure (php-cli `--` stdin script: not applicable); main re-ran http-foundation + dbal once, numbers identical (chaos seed 1: 10 756 yields / 21 624 noise ticks, 0 failures). E15 is now DONE in GOALS/STATUS. Porter's runtime notes taken: `--` (script on stdin) and `-S` are the php-cli features the embed lacks. model=main (port: porter)
- 2026-09-16T05:01:43Z C20 REASSESS — open after this: rebuild the main binary from HEAD (the porter is done with target/), smoke, quiet E1/E2' + E12' numbers, STATUS refresh; then the "ours" phpt leftovers (select usec ValueError, timed_out meta, unix-socket names, error texts). CI run #26 pending on the hook fix. model=main
- 2026-09-16T05:07:47Z C20 VALIDATE — V-28: main binary rebuilt from HEAD, quiet box. E1 1175–1195 ms (3 reps). E2' matrix: observer on 5.5–6.4 µs warm, off 4.9–5.7 µs, loop GC on/off no difference — the E13' lazy swap removed the observer cost (was +3.5–6.9 µs), E2' itself still not met (< 5 µs). E12' on main 0.30 s (same as V-26). smoke: E1 step failed at 1294 ms while two bug70198 harness processes spun at 97 % CPU each → root cause fixed (`feof()` liveness check on hooked streams: poll + MSG_PEEK) and stream_select's null-usec ValueError delegated to the original; both retested in fiber mode. model=main
- 2026-09-16T05:10:48Z C21 HYPOTHESIZE/IMPLEMENT/VALIDATE — H28: phase breakdown of the warm round trip (ready 2.3–2.8, resume 1.7, poll 0.1–1.0, async 0.8 µs) pointed at the reactor's per-op timer task; a zero sleep now completes inline. E2' **met**: 4.51/4.67/4.54 µs warm with the observer on (4.37–4.71 off), cold 17.6–20.8, `all()` 202 ms, E1 1193 ms, chaos 8.8–9.2 µs by construction (V-28 addendum). Real sleeps unchanged (5.5–6.4 µs). model=main
- 2026-09-16T05:12:43Z C21 STOP — owner: "are all hypotheses checked? stop the loop and build a roadmap". Answer: 38 hypothesis rows H0–H28, none OPEN (36 CONFIRMED, H9a/H14b REFUTED by design, H21's absolute p99 inconclusive). Loop stopped; check-in trigger deleted; ROADMAP.md written (phases A–D with acceptance numbers, known unknowns). CI run #28 (liveness fix) in progress at the time of writing. model=main

- 2026-09-16T05:26:16Z CI #30 green on 91f1afa: nextest+miri, smoke, E9, E15 phpt/revolt/swoole/frankenphp all success (all 7 jobs). model=main
- 2026-09-16T05:31:27Z BRANCHES — owner: assemble `main` without the extras, keep history. Done as a fast-forward of `night-1` plus one commit that drops the php-src `.diff/.out` copies (gitignored), retargets the workflows (ci: main + night-*, php-image: main), updates CLAUDE.md; scratch-branch deletion and the `night-1-done` tag push were refused by the git proxy — left to the owner (commands in DECISIONS.md). model=main

- 2026-09-16T05:44:05Z CI #35 green on main (8ffd758): all 7 jobs success. model=main

- 2026-09-16T07:25:57Z C22 RESEARCH — Phase A opened on a new box (24 cores / 30 GB vs the recorded 4 vCPU / 15 GB; PHP 8.5.10 ZTS rebuilt here, cargo 36.9 s, nextest-equivalent 10/10 via cargo test, E1 1197.4 ms cold, E2 202.03 ms / 3.86 µs warm, HTTP 200s). Two subagents launched in parallel on **sonnet** (owner: economise tokens, keep validation): porter → local phpt baseline with diffs + triage of the 8 fiber-mode stream and 5 fiber-mode socket failures (docs/research/21); general → ext/sockets blocking-call inventory from php-src sources + missing-transport list, A4 research (docs/research/22). Serialisation constraints recorded: benches never overlap (V-28 showed 1175 → 1294 ms with two stray processes), and the release binary is frozen while the porter runs the suite against it. model=main
- 2026-09-16T07:31:37Z C22 DECIDE — ADR-0018 (ext/sockets parking + missing transports, A4). Research 22 delivered by the sonnet subagent and **verified by main against php-src**: `socket_addrinfo_connect` creates the socket (sockets.c:2922) and connects (:2935) in one call → no wrapper can park it; `socket_addrinfo_lookup` blocks in `getaddrinfo()` (:2829) with no fd → offload-only, the research-06 sqlite shape; `php_read` derives non-blocking from `fcntl(F_GETFL)` and falls back to `php_socket.blocking` only when that fails → the struct field is not authoritative; 0 EINTR retries in ext/sockets; stock registers tcp/udp/unix/udg (streams.c:1920-1927). Decision: hook 9 functions park-then-delegate + `socket_select`; `unix://` added to the hooked factory; `socket_connect`, `socket_addrinfo_*`, `udp`/`udg` explicitly out of scope with reasons. Three thread-blocking paths stay and are named (hostname DNS before connect/sendto, addrinfo lookup, MSG_WAITALL). Kill criterion: parity drop vs the C22 local baseline, > 0.45 µs non-parking overhead, or no Swoole sockets-group movement. model=main (research: general/sonnet)
- 2026-09-16T07:39:58Z C22 IMPLEMENT — A7 done, and the roadmap's wording for it is **wrong**: measured, `run-tests.php` enforces `--set-timeout` by killing the `sh -c` it spawned, one level above `scripts/ignis-php`, so neither "put the child in its own process group and kill it on exit" nor a bare `exec` in the wrapper prevents the orphan, and an EXIT trap cannot help because the kill may be SIGKILL. Fix instead bounds the binary's own life: `exec timeout -s KILL \${IGNIS_PHPT_TIMEOUT:-20}`, with `bench/e15-phpt.sh` exporting `TEST_TIMEOUT + 5` so the ceiling is always above run-tests' timeout and only ever catches orphans. Validated on the test that hung the porter's run (stream_get_meta_data_socket_variation2, fiber mode): 2 orphans immediately after, 0 after the ceiling lapsed. `exec` kept (one less level) and the ini cleanup moved to a per-run `rm -rf` in e15-phpt.sh, since an exec'd wrapper cannot run its own trap. model=main (triage: porter/sonnet)
- 2026-09-16T08:15:50Z C22 VALIDATE — **A2's premise is falsified on this box; both halves of its acceptance were already met before any work.** Bencher (sonnet) numbers re-run by main, quiet box (load 0.42-0.66), binary unchanged: warm per-fiber via the `sleep(0)` inline fast path 3.83/3.62/3.36 µs, via a REAL `sleep(1)` timer 3.82/3.94/3.92 µs — a ~0.3 µs gap whose ranges OVERLAP, against A2's target of "< 5 µs warm"; E1 overhead 144.0/146.2/145.5 ms against A2's "< 150 ms". So the timer wheel (DelayQueue) that A2 prescribes can win at most ~0.3 µs/fiber (7.5 %), inside run-to-run variance. Method check by main before accepting: the new `bench/php/e2_sleep1.php` is a faithful copy of `e2_all.php` with only the inner op changed, and with 10k fibers sleeping 1 ms concurrently at ~3.9 µs each the 1 ms is fully amortised (0.1 µs of the average), so the comparison is sound. Also corrected: V-28's "5.5-6.4 µs" was the *sleep(0)* path BEFORE the H28 inline fix, never a measured `sleep(1)` — the roadmap's A2 rationale rested on comparing two different things. Phase A re-ordered: A2 demoted, A4 and A1 promoted. Not reproduced: the bencher's first-invocation E1 of 1215.8 ms (3 further runs by main gave 1144-1146 ms); it showed a 198.7 ms start phase vs 128-137 ms and reads as cold-start, left as an open note rather than a claim. model=main (measurement: bencher/sonnet, re-run by main)
- 2026-09-16T09:14:12Z C22 IMPLEMENT/VALIDATE — **A4 landed** (H29, V-29): `crates/ignis/src/php/sockets.rs` hooks the nine blocking `ext/sockets` read/write functions with accept.rs's park-then-delegate pattern; `wrapper.h` gains `ext/sockets/php_sockets.h` and `socket_ce` is allowlisted. 20 concurrent `socket_read` of 200 ms on one thread: **249-266 ms**, 20/20 ok; hook-off control stalls. Two bugs of mine found by measurement, both recorded: (1) `zval::arg` is **1-based** and I passed 0-based indices, so the hook read the frame header instead of the Socket — caught by tracing after the first probe hung; (2) parking on poll readiness hung six php-src tests, because readiness is not "the call would succeed" — a data op on a listening socket, or on an unconnected/unbound one, errors at once while POLLIN never fires, and the original validates arguments before any syscall. `can_block()` fixes it under the rule "delegating is always semantically correct; parking wrongly is a hang". Parity now 86/6 against the C22 baseline 85/7: zero new failures, one recovered. **Kill criterion 2 (overhead) is breached and the criterion was mis-specified** — the bar was 10 % of a fiber round trip, but the hook adds a syscall to a syscall-bound call; a replacement is proposed in the ADR addendum for the owner, not applied unilaterally. Three bench artefacts recorded in V-29 so they are not repeated. model=main
- 2026-09-16T09:19:55Z C22 IMPLEMENT — **A1 part 1**: `stream_set_timeout()` is now honoured on hooked streams. It was accepted and ignored (`PHP_STREAM_OPTION_READ_TIMEOUT => OK`) with `timed_out` hard-coded false, so a `fread()` with no data parked the fiber for ever — the defect that hung the porter's whole suite run. `Sock` gains `read_timeout_us`/`timed_out`; with a deadline set, `op_read` waits on cancellable `Op::Watch` + `Op::Sleep` and then takes the bytes with `Op::TryRead`, because an `Op::Read` cannot be cancelled and a winning timer would strand it and lose its bytes. Untimed reads keep the old single-hop path. `stream_get_meta_data_socket_variation2` now PASSes in 0.123 s. Full-suite A/B in main mode: **130/9 without the patch, 131/8 with it** (`bug60106-001` recovered). Method note that cost three wrong conclusions: these tests are **not reproducible in isolation** (`bug46024`/`bug70362` fail alone, pass in a suite) and the suite has a few tests of run-to-run variance, so single-test and cross-run comparisons are invalid — only full-suite A/B on the same box counts. Known gap left: on a TLS stream the timeout path watches the raw fd, which is not the same as plaintext availability (that is roadmap A6). model=main
- 2026-09-16T09:23:43Z C22 IMPLEMENT/VALIDATE — **A1 complete for the three real defects** the triage found. (2) `bug69521`: `parse_host_port` parsed straight into `u16`, so a port literal above 65535 was rejected and the stream failed with errno 0 inside a fiber; PHP uses `atoi` into an unsigned short and WRAPS (74321 → 8785), so the parse now goes through `i64` and truncates like the C cast. (3) `ghsa-3cr5-j632-f35r`: an unparseable address set `returncode = -1` with no `error_text`, leaving `$errstr`/`$errno` empty, and a NUL in the host reached the resolver instead of being rejected up front — both now fill `error_text`, the NUL case with stock's exact wording ("The hostname must not contain null bytes"). Both PASS. Full-suite fiber streams **122/17 → 124/15**, main unchanged at 131/8, zero new failures. Roadmap A1's "fiber streams ≥ 128/138" is NOT met as a number and cannot be compared anyway: it was set against the old box's 120/138, while this box runs 160 streams tests and starts from 122. The three defects classified as "ours" are fixed; the remaining 15 are the harness artefacts and CLI-only semantics the triage classified separately. model=main
- 2026-09-16T09:31:56Z C22 VALIDATE — **A6 reproduced and diagnosed, not fixed** (research 23). Three conditions must coincide, which is why my first three attempts all passed and nearly produced a wrong "already works" verdict: a body larger than one `op_read`, the client first draining what php_stream buffered (a one-byte read hides the bug entirely, because the original select DOES see php_stream's buffer), and the peer holding the connection open (with `Connection: close` the fd is readable through EOF and select answers ready for the wrong reason). A fourth trap: the test originally ended with `stream_get_contents()`, which blocks for ever on a held-open connection and made both arms of the A/B look identical. With all four handled: `select_ready=0` while plaintext waits. The fix I tried — draining rustls into `Sock.pending` after each TLS read — **does not work and was reverted**: `hooked_select` answers by running the ORIGINAL select, which filters on php_stream's buffer and the fd and cannot see `Sock.pending`, so the data merely moved somewhere else invisible, at the cost of one reactor round trip per TLS read. Two candidate designs recorded; the right one (an eventfd the stream controls, returned by `op_cast`) is larger than a Phase A item. Reproducer committed. model=main
- 2026-09-16T09:37:50Z C22 IMPLEMENT/VALIDATE — **A5**: the embed SAPI now has php-cli's `-r <code>` and `--` (script on stdin), via a new `Engine::eval`. Exit codes match the stock CLI on all six cases checked (`exit(0)`/`exit(7)`/`exit(255)`/clean/parse error/uncaught throw). Two things the first version got wrong and measurement caught: `zend_eval_stringl` leaves the error pending and prints nothing, so a parse error exited 255 in silence where php-cli reports it — the `_ex` form with `handle_exceptions=true` is required; and with that form an `exit()` also returns FAILURE, so the return code alone cannot separate `exit(0)` from a parse error, which a sentinel written into `EG(exit_status)` before the call now does. The real target works: a script running under ignis can re-exec `PHP_BINARY -r ...` and `PHP_BINARY --` with a script on stdin (both verified). `-S` is deliberately NOT implemented: Ignis has its own HTTP front door, and re-creating php-cli's development server is not justified by any test here. `scripts/ignis-php` keeps delegating `-r` bookkeeping probes to the stock CLI — identical answers, faster, and the Symfony acceptance test named in the roadmap (`CacheWarmerAggregateTest`) cannot be run on this box, which has no composer vendor tree. Suites after the change: streams 130/9 main, 124/15 fiber; sockets 91/1 main, 86/6 fiber; nextest-equivalent 10/10. model=main
- 2026-09-16T09:41:42Z C22 IMPLEMENT/VALIDATE — **A4 completed with the `unix://` transport** (ADR-0018 decision 7). `Op::ConnectUnix { path }` in the reactor; everything after the connect — actor, reads, writes, close — is the tcp path unchanged, because `UnixStream` is just another `AsyncRead + AsyncWrite`. The factory now recognises `unix`, addresses by path instead of `host:port`, and keeps the stock factory for server sockets and out-of-fiber use (`IGNIS_NO_UNIX_HOOK=1` disables it). 10 concurrent `unix://` clients each waiting 200 ms on one thread: **205.9 / 206.1 ms**, 10/10 ok; control with the hook off stalls. `udp`/`udg` stay unregistered on purpose: connectionless and addressed per packet, they do not fit one-actor-per-connection and need `RecvFrom`/`SendTo` ops with an actor per socket — their own ADR. Suites unchanged: streams 130/9 main, 124/15 fiber, zero new failures; tests 10/10. model=main
- 2026-09-16T10:47:24Z C22 VALIDATE — **A3 ran; its criterion is the thing that failed, and its real yield was a defect** (V-30). Bencher's soak (10,081,952 requests, 4 threads + 4 offload workers, streams/superglobals/offload exercised; pgsql leg not run — no PostgreSQL on this box): RSS +50.1 % between the 1M and 10M checkpoints against a ±2 % criterion, watchdog literally silent (0 lines), 829 errors in 10.08M (0.0082 %). But the curve is front-loaded — growth stops trending at ~5M, after which readings oscillate in an 87–110 MB band, i.e. **±10–12 % between adjacent checkpoints, wider than the ±2 % the criterion demands of two single points**, and the criterion anchors on 1M where growth is still in progress. So A3's acceptance as written is unsatisfiable even with healthy memory; the honest restatement is "no monotonic trend past 5M". Second run stopped at 9.17M on my instruction: an identical repeat re-measures a quantity that does not vary between runs; it should have been a different condition (no `--supervise`), which would have found the defect below by design instead of by luck. **The defect: a client disconnect could kill an entire worker thread.** Reproduced and fixed by main, see V-30. Two mistakes of mine on the way, both from acting before reading: guarding the two `Fiber::throw()` calls was not enough because the killer path is `ignis_cancel_parked_any` leaving the throwable pending in C — the stack trace said so (no `throwInto` frame) and I fixed first, read after; and my own probe harness used a bare `wait`, which waits for the server too, so it reported "server died" twice when nothing had died. After the fix: 12 burst rounds, server alive, 0 fatals, 0 restarts; cancellation intact — 20/20 cancelled, 279 µs worst latency, 40/40 `finally` blocks, deadline 504 in 103.7 ms. model=main (soak: bencher/sonnet)

- 2026-09-16T11:40Z — **research 24: ext/pgsql async + `ignis_watch`** (owner question). Probe
  `bench/php/pg_async_probe.php`, no Rust change. Parking proved: 20 fibers × `pg_sleep(0.2)` on
  one PHP thread = **203.7 ms** vs a stock-blocking control of **4031.4 ms** (19.8×). Same box,
  same server, 20 backend connections × 2000 `SELECT 1`, 3 reps: libpq path **21 570 / 22 032 /
  21 298 q/s** (rows decoded both sides) vs the ADR-0015 tokio pool **13 386 / 12 538 / 11 468
  q/s** — the userland path is 1.6–1.9× faster, because ADR-0015 §4 marshals params and rows as JSON. Serial (single fiber) it
  is the worse one: 237.0 µs/query vs 116.6 stock-blocking, all of it the cross-thread round trip
  (a bare `ignis_watch` on an already-ready fd is 14.4 µs; exactly 1 park per query). Corrects
  pain-map item 1, which says libpq cannot be answered by hooks. Costs recorded: per-thread rather
  than process-wide pool, cancellation unwritten, TLS untested.
- 2026-09-16T11:40Z — **E15 baseline raised** from two CI runs of `3112a96` (run 35086872504 and
  its E15 phpt rerun): sockets main 80→89, sockets fiber 75→83, streams main 132→133, streams fiber
  120→**124** (the two samples read 125 then 124 — the gate takes the minimum), swoole 42→55 (one
  sample; the A4 `ext/sockets` hooks moved it). Also: the **revolt gate was dead** — `ci-gate.sh`
  greps `IGNIS_PASSED=<n>` and `bench/e15-revolt.sh` never printed it, so it reported "no baseline
  yet" and protected nothing. The script now emits it; its baseline gets set from the next CI run
  rather than from a number typed here.
- 2026-09-16T12:20Z — **H30 / V-33: the reactor round trip is a wakeup pair, not work.** Chased the
  ~120 µs from research 24. My going-in guess — per-op epoll registration in `watch_fd` — was
  **wrong**: the real-`Op::Watch` leg (55.3 µs) is cheaper than the pure-channel leg (110–125 µs),
  because a ping-pong already batches two fibers. The cost is one futex wakeup per `poll()`, shared
  by the batch it drains: 93.1 µs at 1 fiber, 0.58 µs at 128, product constant at 74–98 µs. A bare
  two-thread `std::mpsc` ping-pong on this box is 57.4 µs, so over half of it is the platform.
  Added `IGNIS_POLL_SPIN_US` (bounded `try_recv` spin before sleeping, guarded on `inflight() > 0`,
  **off by default**): concurrency 1 goes 96.1 → 30.0 µs, CPU per op falls at every concurrency,
  no penalty on a saturated box, 0 CPU when idle. But it only pays for sub-100 µs completions — a
  serial local pg query improves 1.27–1.34×, while `GET /sleep?ms=1` gets slightly *worse*. Knob,
  not a default.
- 2026-09-16T12:20Z — **`bench/e15-phpt.sh` was silently passing locally.** It hardcoded
  `/home/user/php-src`, and with no tree there it still ran every suite to completion and printed
  `?` with zero passes — exit 0. Only CI caught it, and only because 0 < baseline. Now resolves
  `PHPSRC` → `/home/user/php-src` → `$HOME/php-src` and hard-fails when the tree is missing. This
  is part of owner decision (d); the other four scripts still carry the hardcode.
- 2026-09-16T12:20Z — `cargo-nextest` was missing from this box (the documented runner). Installed
  from the upstream prebuilt binary at the owner's suggestion; a fallback I had added to
  `scripts/smoke.sh` was reverted in favour of the real tool.
- 2026-09-16T13:05Z — **the smoke gate was measuring someone else's server.** `:8080` on this box is
  held permanently by the neighbouring `../symfony-ignis` project. Every bench hardcoded that port,
  started its own server (which exits 255 with `bind: Address already in use` — the runtime fails
  loudly, the scripts sent it to `/dev/null`), and then accepted *any* answer on `:8080` as
  readiness. So the suite curled the stranger and reported its replies as ours: app.php printed
  Symfony 404s, `e13_http` read **200 mismatches out of 200**, `e6-fetch` read **ok=0**. Fixed by
  giving each script and each example one address (`IGNIS_LISTEN`, default unchanged at
  `127.0.0.1:8080`) plus a `kill -0` liveness check after the readiness loop:
  `bench/{e13-http,e6-fetch,e11-cancel,e12-isolation,e7-revolt}.sh`, `scripts/smoke.sh`,
  `examples/{app,hello_server}.php`. The examples' **self-calls** were the subtle half — `/fetch`
  and `/upstream` fetched `http://127.0.0.1:8080/...` regardless of where they were listening.
  After the fix: `e13_http` mismatches **200 → 0**, E6 **ok=0 → 49-50/50** at 206-257 ms for
  3 × 200 ms, app.php answers its own routes.
- 2026-09-16T13:05Z — newly visible once E6 actually ran: **one request in 50 fails in ~1 of 3 bench
  runs** (`ok=49`), and `bench/e6-fetch.sh` ends with `[ "$ok" = "$N" ]`, so smoke stops there. Not a
  regression — the test was not executing on this box before today. **Correction (13:40Z): the
  startup-race reading was wrong.** Six consecutive batches against one server put the failure in
  batch 2, not batch 1, and it reproduces with no self-call at all from a separate process — see
  H31 / V-34. Left as an assertion that fails rather than a tolerance that hides it.
- 2026-09-16T13:05Z — `wrk` was missing from this box (all of V-6/V-15/E4 were measured with it).
  Rebuilt 4.2.0 from source into `~/.cargo/bin`; the build tree under `/tmp/cmp` was deleted per the
  disk rule. B1 needs it.
- 2026-09-16T13:40Z — **H31 / V-34: a request is accepted and never answered, ~1 in 400–1200 under
  inbound load.** Chased the E6 flake to a characterised defect. It is not the hooked client (1500
  fetches against an idle server are clean), not the self-call shape (reproduces from a separate
  process), and not a startup race (batch 2 of 6 failed, batch 1 did not). PHP's
  "Failed to open stream: HTTP request failed!" means the connection opened and no status line
  could be read; the server logs nothing even at `RUST_LOG=debug` — which itself emitted only the
  two startup lines, so hyper's logs are filtered out at our subscriber level and that wants fixing
  before the next attempt. Reproducer committed as `bench/php/e6_underload.php`. Left OPEN: this is
  accept-path/hyper territory and bigger than the harness work it came out of.
- 2026-09-16T13:40Z — remaining `/home/user` hardcodes closed (owner item d): `e7-revolt.sh` had
  `IGNIS_PHP_INI` pointing at another machine, so the ini silently did not apply anywhere else;
  `e15-chaos.sh` generated a `run.php` requiring an absolute `php/ignis.php` from that box.
  `e15-swoole.sh` and `e15-frankenphp.sh` now fall back to `$HOME`. `bench/frankenphp/Caddyfile`
  and `bench/fpm/nginx.conf` left alone — neither comparison server is installed here, so a change
  would be unverifiable; `bench/results/e10-build-complexity.md` is a recorded measurement.
- 2026-09-16T14:25Z — **A3 closed against the restated criterion (V-35).** Owner accepted "no
  monotonic trend past 5M" and the re-run by main that I made a condition of it. 10,266,805 requests
  over a `/whoami` → `/dashboard` → `/offload` mix at 4 threads + 4 offload workers: past 5M the RSS
  readings are 64,612 → 62,492 → 65,824 → 63,220 → 63,320 kB — a 62.5–65.8 MB band with no trend.
  0 restarts, 0 stalled, 0 non-2xx, server log empty. New driver committed as `bench/a3-soak.sh`,
  which prints a checkpoint per chunk rather than two endpoints — the shape the old criterion could
  not see. My curve is *not* the bencher's (+16.7 % here between the same points against their
  +50 %, 62–70 MB against 87–110): different route weights and no PG leg. They agree on what the
  criterion now asks — front-loaded growth that stops trending — and that is the point of restating
  it as a trend rather than a pair of endpoints.
- 2026-09-16T15:20Z — **H31 root-caused and fixed (V-36), and my own diagnosis of it was wrong twice.**
  It is not the accept path and not the server: the server counted all 1550 requests and `curl` under
  the same load saw 1500/1500. With a read timeout set — which PHP's `http` wrapper always sets —
  `Op::TryRead` can answer `WouldBlock` because the connection actor has not been polled yet although
  the fd is readable; `op_read` returned 0, and `php_stream_get_line` reads 0 bytes as "no line", so a
  blocking caller abandoned a response already on the wire. A retry in the same fiber got
  `HTTP/1.0 200 OK` at once with `unread_bytes` 0 → 102. **Method failure worth recording: three
  "this path never fires" conclusions came from probe runs where every `warn!` was being discarded,
  because `EnvFilter::from_default_env()` passes only `ERROR` when `RUST_LOG` is unset. The hypothesis
  those silent runs appeared to refute was the right one.** Fix: in the timed path `WouldBlock`
  returns to the wait with the remaining deadline. 0 failures in 6000 (was 2-3 per 1000), five clean
  `e6-fetch` runs, smoke GREEN, phpt unchanged at 108/78, 91/84, 133/125.
- 2026-09-16T16:10Z — **B1 landed (ADR-0019, V-37) and half of its acceptance is refused rather than
  fudged.** `IGNIS_FIBER_BUDGET` caps admitted request fibers; waiting requests are held as the raw
  array in an O(1) FIFO, never as a Fiber; `IGNIS_QUEUE_DEPTH` sheds with 503 + `retry-after`; a
  client that leaves while queued is dropped rather than admitted later. `IGNIS_BUDGET_EXEMPT` was
  added when the measurement itself exposed the need — `/stats` queued behind the load it exists to
  report, so a saturated server could not be scraped at all. Budget 2 over 10 × 200 ms serialises to
  1028 ms with 10/10 answered; budget 2 + depth 3 over 12 concurrent gives exactly 5 × 200 + 7 × 503;
  p99 1.79/1.82/1.90 ms without against 1.85/1.89/1.78 with, ranges overlapping.
  **The RSS half of the roadmap's acceptance is not met, and a fiber budget cannot meet it.** Measured
  marginal cost of a held request: 47.7 kB with a fiber, 33.0 kB queued — the budget removes the
  14.7 kB fiber, not the ~33 kB the connection costs. 30 % less RSS for the same offered load, with 4
  fibers instead of 4001, but not a bound. The follow-up is a cap on concurrent connections at the
  listener, recorded in ROADMAP B1 rather than folded quietly into the ADR.
  Method note: the first three attempts at this measurement were worthless because I set `wrk -c N`
  and reported N as "N queued". It is not — the server only queues what it has read. Reading the
  live queue depth from an exempt `/stats` is what made the numbers mean anything, and it changed
  the marginal figure from an apparent 9 B to 33 kB.
- 2026-09-16T17:05Z — **a worker could die and respawn with the operator seeing nothing.** Owner asked
  whether a held resource shows up in the logs. It does not, and the audit was worse than the
  question: nothing tracks hold time (`Lease` is `{pool, conn, _permit}`, no timestamp; `pg::stats`
  returns only idle/created/permits), nothing above `debug` mentions a held resource at all (all 19
  such lines are startup or failure), and with `RUST_LOG` unset `EnvFilter`'s default directive is
  `error`, so even the warnings we do have were invisible — including the watchdog's "php threads
  busy for > 1 s" and the supervisor's "worker script ended; respawning". Raised the default floor to
  `warn`; a deliberate fatal now prints all three lines with no configuration.
  **The risk I named in the same commit message then bit me**: phpt compares output byte for byte, so
  one warning on stderr fails a test — fibers main dropped **108 → 72**, fiber 78 → 62, streams main
  133 → 125. Caught before commit by running the suites. Fixed at the harness (`scripts/ignis-php`
  exports `RUST_LOG=error`) rather than by reverting the visibility; suites back to 108/78, 91/84,
  133/125 and smoke GREEN. Hold-time tracking and per-dependency counters stay unbuilt — they belong
  to B2.
- 2026-09-16T17:55Z — **Mission changed by the owner: R&D → product.** "Тот роадмап … абсолютно не
  отражает то, что должно быть в продукте." Defined the product from the brief's mission (an
  application server a PHP team puts in front of Symfony/Laravel in place of php-fpm/FrankenPHP/
  RoadRunner), rewrote ROADMAP.md as five user-facing milestones — M1 Run, M2 Install, M3 Real apps
  unchanged, M4 Operate, M5 Ship — with the R&D backlog kept beneath, and recorded the decision.
  What the audit found: no README at all, ~15 `IGNIS_*` env vars and no config file, no `serve`,
  no health endpoint, every default "unlimited".
- 2026-09-16T17:55Z — **M1 shipped the same day (V-38).** `crates/ignis/src/config.rs`: `ignis.toml`
  with `deny_unknown_fields`, bridged into the environment before any thread exists so the PHP
  scheduler needed no change; precedence CLI > env > file > default falls out of "set only what the
  environment lacks". `ignis serve [--config] [entry.php]` rewrites itself into the legacy argv, so
  `main` past that point is untouched. `/_ignis/health` answered in `http.rs` from the registry:
  200 while a worker is alive and not stalled, 503 otherwise — something PHP could never report
  about itself. `ignis --version`. README.md and ignis.toml.example written. Found and fixed a
  watchdog false positive on the way: the stall clock started at `poll` entry, so a thread that had
  been *waiting* looked *stuck* on its first request.
- 2026-09-16T18:45Z — **M2 "Install" built and verified locally (V-39).** The audit for it: the
  binary is 48 MB and `libphp.so` drags ~35 shared libraries through libcurl, and there is no
  `libphp.a`, so a downloadable static binary is a PHP rebuild away — but the PHP builder image was
  already on GHCR, so the first artifact is a runtime image: `docker/Dockerfile` (build stage FROM
  the builder + one rustup; runtime `ubuntu:24.04` + six apt libraries + `libphp.so` at the rpath
  path + binary + userland + examples, unprivileged user), `docker/ignis.toml` binding `0.0.0.0`
  because `127.0.0.1` is unreachable from outside a container, `.dockerignore` so the context does
  not ship `target/`, and `.github/workflows/image.yml` which pushes `ghcr.io/koekaverna/ignis` and
  then smoke-tests the pushed image itself. Local: 55.6 s build, **64 MB**, health ok on 24 threads,
  `/` answers, `ldd` 0 "not found", runs as `ignis`. The "downloaded artifact" half of the
  acceptance is CI's on this push.
- 2026-09-16T19:15Z — **M2 closed (image.yml green on `574b231`) and M3's Symfony leg confirmed
  (V-40).** The product shape for frameworks turned out to be a composer package, not a wrapper
  script: `php/composer.json` publishes `ignis/runtime`; the app sets `extra.runtime.class` and
  `ignis.toml` points at `public/index.php`. Two dead ends recorded: `APP_RUNTIME` in `.env` is read
  too late by symfony/runtime (the app ran as one CGI request and the supervisor burned its restart
  budget — exactly the failure the raised log floor made visible), and the builder image's 8.3 CLI
  needs `platform.php` pinned. Bare skeleton from the runtime image: welcome page, 20/20 concurrent,
  health ok, 0 restarts. Laravel open.
- 2026-09-16T19:50Z — **Batch 1 of the harness, first three items validated.** M3-1 (README Symfony
  section, scribe/sonnet): the five V-40 commands verbatim, both traps, path-repo caveat; agent
  correctly refused to cite V-16's throughput because it was measured through `worker.php`, not the
  package route — that number waits for M3-3. H-6 (sonnet): `PG_DSN` required in both PG probes;
  my re-run: message + `rc=2` on each. H-4 (sonnet): `cancel-in-progress` is now
  `github.ref != 'refs/heads/main'` — pushes to main queue instead of killing the run; I trimmed the
  agent's "e15 ~45–60 min" to the ~10 min actually observed. Five agents still running; push held
  until CI on `fb8d4d2` completes.
- 2026-09-16T20:05Z — **H-5 validated**: `scripts/smoke.sh --image <tag>` (sonnet) — a 55-line
  insertion, zero lines of the binary path touched; two containers probed over `docker exec` +
  `/dev/tcp` because port publishing is broken here; my re-run printed the same seven app.php routes
  with the same status codes as the binary mode, `/_ignis/health` ok on 24 threads, `smoke: GREEN`,
  0 containers left. Image mode deliberately skips the E-legs that need a host-reachable port; it
  says so in its output. The agent added the `ldd` "not found" check for parity with `image.yml`.
- 2026-09-16T20:25Z — **H-1 and M5-2/M5-3 validated.** H-1 (sonnet): the nine remaining benches and
  three examples take `IGNIS_LISTEN` with content-based readiness; my quoted grep leaves only three
  legitimate defaults (`serve()`'s signature, `e16_route.php`'s `CURL_URL` fallback, a comment);
  `wrk-hello.sh` end to end on :8117 — 24,884 req/s. Two harness slips of mine on the way: an
  unquoted `--include=*.sh` in zsh ("no matches found") and a `source <(sed …)` trick that left two
  processes behind — killed by PID. M5-2/M5-3 (scribe): `docs/migrate.md` (25 V-n citations) and
  `docs/operate.md` (11; every `IGNIS_*` in config.rs and every toml key present, defaults match);
  the agent refused four claims it could not source and said which — the right failure mode.
- 2026-09-16T20:35Z — **M3-4 validated, M3-5 re-scoped.** Research 25 (sonnet) read `laravel/octane`
  at v2.19.1 / 68a2516 with line references: all three shipped Octane servers run `Worker::handle()`
  one request at a time, `Container::$instance` is process-global, `octane:start --server=` is a
  closed `match`. So the M3-5 acceptance I wrote ("two interleaved requests never see each other's
  `request()`") cannot be met by any route today. `php/classic.php`'s own header says it *assumes*
  no suspension; a hooked fetch inside a Laravel request would break that. Re-scoped: M3-5a ships
  Laravel in classic mode with `budget.fibers = 1` per thread — ADR-0019 turns the assumption into
  a guarantee and it is Octane's own model — with a control run at budget 2 that must fail; M3-5b
  (`main`) is the ADR for a fiber-scoped container on the ADR-0006 observer. Option (b) dropped as
  the agent argued: strictly dominated.
- 2026-09-16T21:00Z — **H-3 validated** (sonnet): `examples/app.php` gains a docblock line on
  `/_ignis/health` + ADR-0019 and a `/stats` route (`budgetStats()` merged with resumes/idle/runtime);
  additive only. My re-run on :8132: the seven smoke routes answer 200,200,200,200,200,504,200 as
  before, `/stats` carries `budget`, health ok.
- 2026-09-16T21:20Z — **M3-2 validated, M3-3 accepted as a script (sonnet, one agent for both).**
  `php/symfony/worker.php` is a 17-line shim printing the migration note and exiting 2; the four
  remaining references point at the app's own `public/index.php`. `bench/e8-symfony.sh` installs the
  skeleton V-40's way inside the builder image — and the agent found three holes in that recipe
  which README had just inherited verbatim: `--no-scripts` (composer's 8.3 CLI trips
  `platform_check.php` in Flex's hooks even with `platform.php` pinned), `mkdir -p var`, and
  root-owned cleanup through the image. Fixed in README, recorded as a V-40 addendum. The agent's
  4.7k/13.4k req/s are **not recorded**: the bare skeleton answers the dev-mode 404, a different
  quantity from V-16's prod 200 — BACKLOG M3-8 adds the prod leg; my re-run waits for a quiet box.
  Batch 2 so far: H-2, H-3, H-7 validated and committed; M5-1's release workflow and
  `scripts/release.sh` dry-run by me in a worktree.
- 2026-09-16T21:30Z — **M5-1 validated** (sonnet): `.github/workflows/release.yml` on a `v*` tag —
  build + push `ghcr.io/koekaverna/ignis:<tag>`, image.yml's smoke, then the honesty check that
  `ignis --version` inside the tagged image equals the tag, then binary + `libphp.so` tarball with
  the six runtime libraries named, release notes from `git log <prev>..<tag>`. `scripts/release.sh`
  bumps the version, refreshes Cargo.lock offline, commits, and prints the tag commands it must not
  run (the session git proxy refuses tag pushes). My own worktree dry run: both files at 0.0.2-rc.1,
  commands printed, worktree removed. The first real run is the owner's tag push.
- 2026-09-16T21:45Z — **Batch 2 complete; M5-4 validated** (sonnet): `.github/workflows/nightly.yml`
  on a schedule + dispatch, never on push — E1/E2/hello/E16/B1-p99 each printed beside its
  threshold, a regression opens or updates a `nightly: regression <date>` issue. The agent caught
  its own bug before reporting: the PASS test was `value < threshold` for every metric, which would
  have passed a halved throughput — now `dir=gt` for hello. My run of the E1/E2 gate: 1144.3 ms,
  201.08 ms, 3.48 µs. CI on the baseline-54 push is green on all seven jobs (swoole read 55). Found
  on the way: a clean `exit()` now logs as a fatal at `warn` — H-10, mine.
  Batch 2 tally: H-2, H-3, H-7, M5-1, M5-4 validated; batch 1: eight of eight, M3-3's number
  pending my re-run.
- 2026-09-16T21:55Z — **M3-3 closed (V-41).** My re-run of `bench/e8-symfony.sh` on a quiet box:
  4,680.64 req/s at 1 thread, 13,629.61 at 4 — within 2 % of the agent's numbers, so they enter
  VALIDATION with both columns and the caveat that every response is the dev-mode 404 welcome page,
  not V-16's prod 200. Batch 1 is now eight of eight. H-11 added from a phpantom finding on the
  gRPC example (pre-existing `int|float`).
- 2026-09-16T22:15Z — **H-10 (main).** Since the `warn` floor every script ending in `exit()`
  printed "php_execute_script returned false (fatal error or exit)". Both paths bail out, so `ok`
  is false for either; the discriminator is `EG(exit_status)`: 255 means a fatal (php_error_cb),
  anything else is `exit(N)`. Now `warn!("script ended with a fatal error", status)` vs
  `debug!("script called exit()")`; `exit(255)` is the one case still read as a fatal, said in the
  comment. E1 prints 0 WARN lines, `trigger_error(E_USER_ERROR)` prints one with status=255,
  `exit(7)` returns 7, nextest 10/10, smoke GREEN (E12 respawn 1). Batch 3 (H-8, H-9) validated.
- 2026-09-16T22:40Z — **E18 Universal park added by the owner, ADR first.** Recorded verbatim in
  BRIEF.md's additional expectations, a GOALS row, and a BACKLOG section split by the loop: R1
  (which blocking symbols the installed libraries import — `nm`, not memory), R2 (who holds a lock
  across a blocking call, from source at the installed versions — this is what acceptance (5) and
  the kill criterion are about), R3 (does interposition from the executable bind inside libcurl on
  this toolchain — my experiment, it decides feasibility), then ADR-0020, then implementation
  behind a feature flag with a hook-off control. R1 and R2 dispatched to sonnet agents; R3 is mine.
