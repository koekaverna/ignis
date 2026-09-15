# HYPOTHESES

Each entry is falsifiable with a number and names the exact test that decides
it. Status is one of OPEN / CONFIRMED / REFUTED / INCONCLUSIVE and links to the
entry in VALIDATION.md.

| id | statement | decided by | expected | time box | status |
|---|---|---|---|---|---|
| H0 | PHP 8.5.10 builds here as ZTS + embed with the minimal extension set and `php -r 'echo PHP_ZTS;'` prints 1. | `scripts/build-php.sh`, `/opt/php85-zts/bin/php -r 'var_dump(PHP_ZTS, extension_loaded("pdo_sqlite"));'` | ZTS=1, all listed extensions loaded | 30 min | CONFIRMED (V-0) |
| H1 | A Rust binary linking libphp (ZTS, bindgen) can register an internal module and run a script that calls a Rust-implemented function; startup+script < 50 ms. | `cargo run -p ignis -- examples/hello.php` | prints "hello from rust: 42" | 90 min | CONFIRMED (V-1) |
| H2 (E1) | 10,000 fibers on ONE PHP thread each calling `Ignis\sleep(1000)` all complete in < 1.2 s wall, with sleeps owned by tokio timers on other threads. | `cargo run --release -p ignis -- bench/php/e1_sleep_10k.php` prints wall ms | < 1200 ms | 60 min | see V-2 |
| H3 (E2) | `Ignis\all([sleep(200), sleep(200), sleep(200)])` returns in < 230 ms; per-fiber overhead (create+submit+poll+resume) measured over 10k fibers with 0 ms sleep is < 100 µs. | `bench/php/e2_all.php` | < 230 ms; < 100 µs | 30 min | see V-3 |
| H4 | The userland `Ignis\Loop` is not the bottleneck: with 10k fibers, time spent between `ignis_poll` returning and all fibers resumed is < 100 ms. | timing inside `e1_sleep_10k.php` (resume phase) | < 100 ms | inside H2 | see V-2 |
