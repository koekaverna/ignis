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
