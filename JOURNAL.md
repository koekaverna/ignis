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
