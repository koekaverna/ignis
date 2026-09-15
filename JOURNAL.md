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
