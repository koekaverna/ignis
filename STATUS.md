# STATUS — Ignis (updated 2026-09-15T23:00Z, end of Cycle 1 validation)

**Thesis holds.** One Rust process embeds PHP 8.5.10 (ZTS), runs many PHP requests per OS thread on native Fibers, and every wait is a tokio timer/socket. Every number below links to VALIDATION.md.

## CONFIRMED (numbers)

| claim | number | entry |
|---|---|---|
| PHP 8.5.10 ZTS + embed + opcache + {json, fibers, sockets, pdo_sqlite, mbstring} builds here | `PHP_ZTS=1`, 7/7 extensions | V-0 |
| Rust host links libphp via bindgen, registers an internal module, runs a script | 16 ms startup+script | V-1 |
| **E1** 10,000 fibers × `Ignis\sleep(1000)` on one PHP thread | 1168–1178 ms cold (idle box); **1037–1041 ms warm pool** | V-2, V-4 |
| **E2** `Ignis\all()` of 3 × 200 ms | 201–202 ms | V-3 |
| **E2** per-fiber overhead | 22 µs cold → **4.4–4.6 µs warm pool** | V-3, V-4 |
| HTTP hello-world, 1 PHP thread + 2 tokio threads, `wrk -t2 -c64` | **122k–134k req/s, p99 1.07–1.28 ms, 0 errors** | V-5 |
| 1000 concurrent HTTP requests each sleeping 1000 ms, 1 thread | p50 1.00 s, p99 1.01 s | V-5 |
| 10,000 concurrent HTTP connections, 1 thread | p50 1.01–1.03 s, p99 1.21–1.42 s (see refuted) | V-5 |

## REFUTED / INCONCLUSIVE and why

- **E1 under CPU contention**: 1302 ms with two builds running on the other cores (V-2 addendum). The cold-fiber margin was 2–3%; the fiber pool (V-4) is the fix, not "run on an idle box".
- **E1' over HTTP at 10k connections, p99 < 1.1 s**: p99 1.21 s warm / 1.42 s cold with 86 timeouts on the cold run. The load generator (wrk, 2 threads) shares the 4 vCPUs with 2 tokio threads and the PHP thread; INCONCLUSIVE until re-run with an external load box (V-5).
- **E4 vs FrankenPHP / php-fpm**: baselines are built (FrankenPHP v2.11.4 against the same libphp; php-fpm 8.5.10 NTS + nginx 1.24) but the first comparison run failed: FrankenPHP refuses a libphp built with Zend signals; rebuild with `--disable-zend-signals` in progress. Ignis alone measured 134k req/s in that run (bench/results/compare.md).

## Key finding of the night so far

Zend allocates and frees a fresh mmap'd C stack per fiber; on a multi-threaded process that costs page faults + munmap + cross-CPU TLB shootdowns = **~50% of PHP-thread CPU** at 10k fibers (V-2 perf profile). The userland scheduler and the Rust side are noise (< 1%). Keeping fibers alive in a pool removes it (V-4). `fiber.stack_size` does not matter (64K–2M within 1%).

## Run everything (5 commands)

```
scripts/build-php.sh                                   # PHP 8.5.10 ZTS embed → /opt/php85-zts (idempotent, ~6 min)
cargo build --release -p ignis && cargo nextest run     # binary + 8 unit tests (miri: cargo +nightly miri test -p ignis -- php::zval php::module)
scripts/smoke.sh                                       # hello, examples/app.php, E1/E2 thresholds
./target/release/ignis examples/hello_server.php &  bench/wrk-hello.sh   # HTTP hello on :8080
bench/compare.sh                                       # Ignis vs FrankenPHP worker vs php-fpm+nginx → bench/results/compare.md
```

## Architecture (current best)

```
            tokio runtime (2 workers)                         PHP OS thread (1 today, N in Cycle 2)
 ┌──────────────────────────────────────┐   crossbeam channel  ┌──────────────────────────────────────────┐
 │ hyper auto (h1/h2) ── service_fn ────┼─► Completion{id,    │ ignis_poll() ──► Ignis\Loop (userland)    │
 │   per connection      oneshot<Resp> ◄┼── Request/Slept}    │   ├─ fiber pool: parked Fibers reused      │
 │ timers: sleep_until ─────────────────┼─►                   │   ├─ Future / all() / async()              │
 │                          mpsc<Op>   ◄┼── ignis_submit_*()  │   └─ dispatch: Request → Fiber → Response  │
 └──────────────────────────────────────┘                     │ ignis_respond(id, status, headers, body) │
                                                              │ libphp.so (ZTS, embed SAPI, module ignis)│
                                                              └──────────────────────────────────────────┘
 Rules: no Zend pointer ever crosses to tokio; PHP never awaits a tokio future; one wait point (poll).
```

## Name collision check (owner addendum)

- crates.io: **taken** — `ignis` 0.1.0 exists (unrelated). Packagist: free (no vendor `ignis`). GitHub: `ignis-sh/ignis` (Python widget framework), `Nystik-gh/ignis` (Obsidian web app), `DavidVollmers/Ignis` (Blazor) — none in the PHP/Rust runtime space.
- Proposed alternatives (nothing renamed): **`ignis-rt`** (crate `ignis-rt`, Packagist `ignis-rt/runtime`) or **`fyra`** (free on crates.io index at check time). Decision left to the owner.

## Blocked downloads (network allowlist)

`www.php.net`, `ppa.launchpadcontent.net` (ondrej PPA), `github.com` over plain HTTPS (git protocol works), `crates.io` web (sparse index works). Mirrors used: git clone for php-src, `index.crates.io` for crates, Ubuntu archive for tools.

## Ranked recommendation for the next 3 cycles

1. **Cycle 2 — N PHP threads (E5) + fair E4.** `ts_resource(0)` per worker thread, one reactor handle per thread, hyper distributes requests. Then compare Ignis@4 threads vs FrankenPHP@4 workers vs fpm@4 children. Without this E4 is one-thread-only.
2. **Cycle 3 — E13 state isolation via `zend_observer_fiber_switch`**: swap `$_SERVER`/`$_GET`/`$_POST` and a fiber-scoped container on every switch; this unblocks E8 (Symfony RequestStack) and removes the "value-only" limitation of ADR-0002.
3. **Cycle 4 — E6 stream hooks (Swoole route)**: replace the `tcp://` transport factory so unmodified `file_get_contents('http://…')`/PDO suspend the fiber; E11 cancellation rides on the same reactor (hyper drop → cancel event).
