# STATUS — Ignis (updated 2026-09-16T08:50Z, Cycle 13 in progress)

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
| **E4** hello-world vs FrankenPHP worker vs php-fpm+nginx, same libphp build, 1 thread | **128k vs 27.6k vs 9.9k req/s**; p99 1.1 vs 6.4 vs 8.7 ms | V-6 |
| **E5** CPU-bound scaling, 4 PHP threads (box has 4 vCPU) | **3.7–3.98×** in-process, 3.49× over HTTP (`/cpu` 9.3k vs 2.7k req/s); FrankenPHP@4 workers 7.3k | V-9 |
| true-async fork (PR #22561 head) builds as ZTS embed; reference scheduler tests | 61/61 pass with Ignis's 14-line idle-hook patch | V-7 |
| E1 on backend (b): engine coroutines driven by the tokio reactor | 1165–1177 ms for 10k × 1000 ms | V-8 |
| **E3** RSS over 4.6M requests, worker mode, 1 thread | RSS 26.9 → 26.2 MB over 1.5M hello; PHP heap flat to the byte; `/sleep?ms=1` at 500 conns = 131k req/s | V-10 |
| **E13** fiber-scoped `$_SERVER`/`$_GET`/`$_POST`/`$_COOKIE` + `Ignis\Scope` | 0 mismatches (300 in-process checks, 200 concurrent HTTP); +100 ns per fiber switch | V-11 |
| **E6 (tcp)** unmodified `file_get_contents('http://…')` suspends the fiber | 3 × 200 ms fetches in **203 ms on one thread** (server calling itself); 100/100 concurrent; hook-disabled control deadlocks | V-12 |
| **E7** Revolt driver: Revolt + amphp/amp + amphp/socket examples unchanged | 7/8 byte-identical (1 timing race in the example), timer benchmarks ≤ 1× of StreamSelectDriver | V-13 |
| **E11** client disconnect cancels request fiber + children; `Ignis\deadline()` | **0.78 ms** worst-case cancel latency, `finally` runs, no phantom work; 504 at 102 ms for a 100 ms deadline | V-14 |
| **E5'** least-inflight dispatch, `/cpu` at 4 threads | p99 **12.4–12.8 ms** (FrankenPHP@4: 15.6 ms) at 9.1–9.3k req/s; two multi-thread bugs found and fixed (bind race, forged refcount flag on immutable arrays) | V-15 |
| **E8** symfony/skeleton in worker mode via a `symfony/runtime` class, fiber-scoped RequestStack, sessions on | 0/100 mismatches across suspensions; **7.2k req/s (1 thread) / 25.2k req/s (4 threads)** through the full kernel | V-16 |
| **E12** fatal in one worker thread; CPU loop in one thread; supervisor respawn | fatal killed 1 of 4 workers, hello uninterrupted at 134k req/s, respawn within 50 ms, no opcache reset; spin stalled 1 thread, others 90k req/s p99 2.7 ms; recovery 96% | V-17 |
| **E9 step 1** temporal-sdk-core (git) in a Rust binary against a locally built dev server | first activation polled and completed; run COMPLETED with the probe's payload | V-18 |

## REFUTED / INCONCLUSIVE and why

- **E1 under CPU contention**: 1302 ms with two builds running on the other cores (V-2 addendum). The cold-fiber margin was 2–3%; the fiber pool (V-4) is the fix, not "run on an idle box".
- **E1' over HTTP at 10k connections, p99 < 1.1 s**: p99 1.21 s warm / 1.42 s cold with 86 timeouts on the cold run. The load generator (wrk, 2 threads) shares the 4 vCPUs with 2 tokio threads and the PHP thread; INCONCLUSIVE until re-run with an external load box (V-5).
- **E6 via the async scheduler ABI (owner's "prototype E6 on (b) first")**: REFUTED by inspection (V-7). PR #22561 is consulted only by Zend core (fibers, GC, execute API, objects); no stream, socket or sleep path calls `ZEND_ASYNC_SUSPEND`. E6 is stream-hook work on both backends (ADR-0003).
- **/cpu p99 at 4 threads**: FrankenPHP 15.6 ms vs Ignis 17.5 ms while Ignis has +28% throughput (V-9): round-robin dispatch feeds busy threads. Least-inflight dispatch is the fix (E5').
- **E6 for PDO sqlite via hooks**: REFUTED (V-12). libsqlite3 reads the database file itself inside the calling thread; there is no stream layer to intercept. Needs a blocking-call offload or the native pgsql path (E14).
- **Full Rust scheduler provider for backend (b)**: deferred, not refuted. The reference provider's coroutine entry relies on `zend_first_try` (setjmp); a Rust provider needs a C shim for that frame. The idle-hook prototype validated the architectural claim (reactor at the idle point) without it.

## What the async scheduler ABI (php-src PR #22561 / true-async fork) changes

- The ABI is a provider slot table (`new_coroutine`, `enqueue`, `suspend`, `cancel`, `launch`, `defer`, switch/finish handlers, per-coroutine context) — research 02. Ignis can be a provider; the reactor plugs in where the reference scheduler raises `DeadlockError` (V-8 proves it with 14 lines).
- It gives engine-owned cancellation (E11), per-coroutine context and switch handlers (E13), and GC destructors on a dedicated coroutine (owner's pain-map item). It does **not** make I/O non-blocking (E6).
- Mainline master (2026-09-15) already has `main/poll/`; the ABI itself is unmerged and targets 8.6. Production path stays mainline 8.5 (ADR-0003).

## Key finding of the night so far

Zend allocates and frees a fresh mmap'd C stack per fiber; on a multi-threaded process that costs page faults + munmap + cross-CPU TLB shootdowns = **~50% of PHP-thread CPU** at 10k fibers (V-2 perf profile). The userland scheduler and the Rust side are noise (< 1%). Keeping fibers alive in a pool removes it (V-4). `fiber.stack_size` does not matter (64K–2M within 1%).

## Run everything (5 commands)

```
scripts/build-php.sh                                   # PHP 8.5.10 ZTS embed (--disable-zend-signals) → /opt/php85-zts (idempotent, ~6 min)
cargo build --release -p ignis && cargo nextest run     # binary + 8 unit tests (miri: cargo +nightly miri test -p ignis -- php::zval php::module)
scripts/smoke.sh                                       # hello, app.php, E1/E2, 4 threads, E13, E6, E7 (if amphp vendor present), E11, E12
./target/release/ignis --threads 4 examples/hello_server.php &  bench/wrk-hello.sh   # HTTP hello on :8080
bench/compare.sh [wrk_threads conns dur]               # Ignis vs FrankenPHP worker vs php-fpm+nginx → bench/results/compare.md (URL_PATH=/cpu, IGNIS_THREADS_LIST="1 4")
# backend (b): scripts/build-php-async.sh; PHP_CONFIG=/opt/php86-async-zts/bin/php-config CARGO_TARGET_DIR=target-async cargo build --release -p ignis
#              N=10000 IGNIS_PHP_INI=bench/php/async-core.ini ./target-async/release/ignis bench/php/e1_async_core.php
```

## Architecture (current best)

```
            tokio runtime (2 workers)                         PHP OS threads (--threads N; one reactor each, requests round-robin)
 ┌──────────────────────────────────────┐   crossbeam channel  ┌──────────────────────────────────────────┐
 │ hyper auto (h1/h2) ── service_fn ────┼─► Completion{id,    │ ignis_poll() ──► Ignis\Loop (userland)    │
 │   per connection      oneshot<Resp> ◄┼── Request/Slept}    │   ├─ fiber pool: parked Fibers reused      │
 │ timers: sleep_until ─────────────────┼─►                   │   ├─ Future / all() / async()              │
 │                          mpsc<Op>   ◄┼── ignis_submit_*()  │   └─ dispatch: Request → Fiber → Response  │
 └──────────────────────────────────────┘                     │ ignis_respond(id, status, headers, body) │
                                                              │ libphp.so (ZTS, embed SAPI, module ignis)│
                                                              └──────────────────────────────────────────┘
 Per fiber: $_SERVER/$_GET/$_POST/$_COOKIE swapped by the zend_observer fiber-switch hook (reserved slot per context).
 Streams: tcp:// transport factory replaced at MINIT; a stream op inside a fiber parks it (zend_fiber_suspend),
          the tokio actor does the socket I/O, ignis_poll() resumes the fiber. Outside fibers: stock blocking transport.
 Rules: no Zend pointer ever crosses to tokio; PHP never awaits a tokio future; one wait point (poll).
```

## Name collision check (owner addendum)

- crates.io: **taken** — `ignis` 0.1.0 exists (unrelated). Packagist: free (no vendor `ignis`). GitHub: `ignis-sh/ignis` (Python widget framework), `Nystik-gh/ignis` (Obsidian web app), `DavidVollmers/Ignis` (Blazor) — none in the PHP/Rust runtime space.
- Proposed alternatives (nothing renamed): **`ignis-rt`** (crate `ignis-rt`, Packagist `ignis-rt/runtime`) or **`fyra`** (free on crates.io index at check time). Decision left to the owner.

## Blocked downloads (network allowlist)

`www.php.net`, `ppa.launchpadcontent.net` (ondrej PPA), `github.com` over plain HTTPS (git protocol works), `crates.io` web (sparse index works). Mirrors used: git clone for php-src, `index.crates.io` for crates, Ubuntu archive for tools.

## Still open

E9 step 2 (workflows on fibers + replay: runtime written, `--features temporal` build in progress, see H20b), E10 (tonic gRPC), E14 (runtime-owned connection pool). E10/E14 not started: each is a multi-hour build with a new dependency tree (tonic, tokio-postgres) and E14 needs a PostgreSQL on the box; the reactor's `Op::Custom` seam (added for Temporal) is the integration point for both.

## Ranked recommendation for the next 3 cycles

1. **E14 runtime-owned pgsql pool** via tokio-postgres: `Op::PgQuery` + a `PDO`-shaped PHP client; lease per fiber, transaction pins the lease, `DISCARD ALL` on return. Also the honest answer to the sqlite half of E6. Needs a PostgreSQL on the box (apt has it).
2. **E10 tonic gRPC** on the shared hyper/h2 stack: unary first, then server-streaming (the first fiber that yields several responses through `ignis_respond`-style chunks); compare with RoadRunner's grpc plugin and ext-grpc.
3. **E9 Temporal sdk-core**: research-first as the brief says (how sdk-python bridges core ↔ asyncio: the same "poll the core from the loop, complete activations from fibers" shape as ADR-0007); needs a Temporal dev server. Then E6' `ssl://`, E12' (500 for requests on a dying thread), E13' (lazy swap).
