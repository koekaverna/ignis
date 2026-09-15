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
| H5 (E2') | With a warm fiber pool, the second round of 10k `Ignis\sleep(0)` jobs costs < 5 µs per job (vs 22 µs cold, V-3), and E1 (10k × 1000 ms) wall < 1.05 s on the warm round. | `bench/php/e1_sleep_10k.php` with `ROUNDS=2`, `bench/php/e2_all.php` reports warm per-job µs | < 5 µs; < 1050 ms | 60 min | see V-4 |
| H6 | `Ignis\serve()` over hyper: a hello-world handler answers `wrk -t2 -c64 -d10s` on 1 PHP thread with ≥ 20k req/s and 0 errors; `/sleep?ms=1000` with `-c 1000` completes 1000 concurrent requests in < 1.1 s on 1 thread. | `bench/wrk-hello.sh`, `bench/wrk-sleep.sh` | ≥ 20k rps; < 1.1 s | 120 min | see V-5 |
| H7 (E4, 1 thread) | Ignis hello-world rps ≥ FrankenPHP worker mode (num_threads 1) and ≥ php-fpm (pm.max_children 1) behind nginx, p99 lower, same box, same libphp build. | `bench/compare.sh` results table | Ignis ≥ both | 60 min | see V-6 |
