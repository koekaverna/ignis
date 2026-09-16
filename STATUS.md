# STATUS — Ignis (updated 2026-09-16T03:58Z, Cycle 19 running; Cycles 0–18 closed)

**Thesis holds.** One Rust process embeds PHP 8.5.10 (ZTS), runs many PHP requests per OS thread on native Fibers, and every wait is a tokio timer/socket/TLS session. Every number below links to a VALIDATION.md entry; anything without one says "not measured".

## CONFIRMED (numbers)

| expectation | number | entry |
|---|---|---|
| base: PHP 8.5.10 ZTS+embed+opcache builds here; Rust host links libphp, registers a module, runs a script | `PHP_ZTS=1`, 7/7 extensions; 16 ms startup+script | V-0, V-1 |
| **E1** 10,000 fibers × `Ignis\sleep(1000)`, one PHP thread | 1168–1178 ms cold; **1037–1041 ms warm pool** (idle box) | V-2, V-4 |
| **E2** `Ignis\all()` of 3 × 200 ms; per-fiber overhead | 201–202 ms; 21.6–24.2 µs cold → **4.4–4.6 µs warm** (observer off) | V-3, V-4 |
| **E3** RSS in worker mode | 26.9 → **26.2 MB over 1.5M** hello (4.6M total), PHP heap flat to the byte; `/sleep?ms=1` at 500 conns = 131k req/s | V-10 |
| **E4** hello vs FrankenPHP worker vs php-fpm+nginx, same libphp, 1 thread | **128k vs 27.6k vs 9.9k req/s**; p99 1.11 / 6.42 / 8.68 ms | V-6 |
| **E4'** raised: 4 PHP threads vs FrankenPHP@4 workers, superglobals+streams+cancel active | **112.5k vs 16.6k req/s**, p99 2.41 ms | V-15 |
| **E5** CPU-bound scaling, 4 threads (box has 4 vCPU → target 3.25×) | **3.7–3.98×** in-process, 3.49× over HTTP (`/cpu` 9.3k vs 2.7k req/s) | V-9 |
| **E5'** raised: least-inflight dispatch on `/cpu`@4 | p99 **12.4–12.8 ms** at 9.1–9.3k req/s (FrankenPHP@4: 15.6 ms) | V-15 |
| **E6** unmodified `file_get_contents('http://…')` suspends the fiber | 3 × 200 ms in **202.5–202.9 ms** on one thread (server calling itself); 100/100 concurrent; hook-off control stalls | V-12 |
| **E6'** raised: `ssl://`/`tls://`/`https://` + STARTTLS through the hook (rustls in the reactor) | **210–231 ms hooked vs 613–623 ms unhooked** for 3 × 200 ms; 5/5 verification cases behave like ext/openssl | V-25 |
| **E7** Revolt driver, AMPHP examples unchanged | 7/8 byte-identical (8th a timing race in the example); timer benchmarks ≤ 1× of StreamSelectDriver | V-13 |
| **E8** symfony/skeleton in worker mode, fiber-scoped RequestStack, sessions on | **0/100** mismatches across suspensions; **7.2k req/s (1 thread) / 25.2k (4 threads)** through the kernel | V-16 + addendum |
| **E9** Temporal: PHP workflow (2 activities + 500 ms timer) as a suspended fiber; deterministic replay | live run **COMPLETED in 1224 ms**, 5 activations; replay of the 22-event history **OK**; mutated workflow **FAILS** (TMPRL1100) | V-18, V-19 |
| **E10** gRPC unary + server-streaming in PHP on the shared listener; runtime-owned client parks the fiber | **16.7k req/s, p99 7.8 ms** (1 thread) vs pure-tonic ceiling 21.1k/6.3 ms vs RoadRunner 5.1k–11.4k/12.6–17.8 ms; 100 × 200 ms calls **215 ms** vs RR 5.03 s; client: 217 ms | V-20 |
| **E11** disconnect cancels the request fiber + children; `Ignis\deadline()` | **0.78 ms** worst cancel latency, 20/20 `finally` ran, no phantom work; 504 at 102 ms for a 100 ms deadline | V-14 |
| **E12** fatal / CPU spin in one thread; supervisor respawn | fatal killed 1 of 4 workers, hello uninterrupted at 134k req/s, respawn inside the 50 ms tick, no opcache reset; spin stalled 1 thread (others 90k req/s, p99 2.67 ms); recovery **95.7%** | V-17 |
| **E13** fiber-scoped `$_SERVER`/`$_GET`/`$_POST`/`$_COOKIE` + `Ignis\Scope` | **0 mismatches** (300 in-process checks, 200 concurrent HTTP); **+100 ns** per fiber switch | V-11 |
| **E14** runtime-owned PostgreSQL pool, lease per fiber, transaction pins, one-round-trip reset | 200 fibers over 20 connections in **1046 ms** warm (ideal 1000); LeaseError in 38 µs with 0 ops; SET/temp do not leak; **112 µs/query**, 7.3k q/s on one thread | V-21 |
| **E15** compat suites — **DONE** | phpt main mode **108/108 fibers, 80/80 sockets, 132/138 streams** of stock (fiber mode 78/75/120), 0 upstream failures; Revolt `DriverTest` **81 tests / 222 assertions / 0 failures** = StreamSelectDriver; Swoole shim **44/153** with the missing-hook ranking; FrankenPHP testdata **29 pass / 4 fail / 33 skip**; Symfony + Doctrine (10 849 tests per mode) under chaos scheduling **0 new failures**, 1 ignis-only failure (php-cli stdin script, not applicable) | V-22, V-23 + addendum, V-26 addendum, V-27 |
| **E16** offload pool (own TSRM context), `Ignis\offload()`, auto-routing of `curl_*`/`PDO`/`SQLite3` with no code changes | 100 × 200 ms blocking calls: **2608 ms on 8 workers, 243 ms on 100** (fiber thread kept ticking); auto-routed `new PDO`+query 100 × 200 ms: 3454 ms on 8; `curl_exec` + `CURLOPT_WRITEFUNCTION` on the caller; **13–67 µs** copy, 17–45 µs per routed call | V-24 + addendum |
| backend (b): true-async fork builds (61/61 reference-scheduler tests) and runs E1 on engine coroutines via a 14-line idle hook | 1165–1177 ms for 10k × 1000 ms | V-7, V-8 |

## REFUTED / INCONCLUSIVE / not measured, and why

- **E1 under CPU contention**: 1302 ms with two builds on the other cores (V-2 addendum); the 2–3% cold margin does not survive a loaded box. The warm pool (V-4) is the fix. Quiet-box re-run after all of tonight's changes (V-28): **1175–1195 ms** (3 reps), still < 1200 ms; 1294 ms with two stray processes on the box.
- **E1' (10k HTTP connections, p99 < 1.1 s)**: p99 1.21 s warm / 1.42 s cold with 86 timeouts cold — INCONCLUSIVE: wrk's 2 threads share the 4 vCPUs with 2 tokio threads and the PHP thread. Needs an external load box (V-5).
- **E2' (< 5 µs per warm job)**: **not met** — 5.5–6.4 µs warm with the observer on, 4.9–5.7 µs off (V-28, quiet box). The observer's 3.5–6.9 µs (V-11 addendum) is gone after the E13' lazy swap; what remains is the pool/loop path. Cold per-fiber 16–19 µs, the 3× GC variance is gone** on a quiet box (no V-n).
- **E13' / E12' / E3' / E7' / E8' (multi-cookie) / E9' / E11'**: raised targets with code landed or not started and **no measurement yet**. E13' lazy swap + `IGNIS_LOOP_GC` and E12' fail-fast (a dying thread answers in-flight requests with 500) are pushed but unvalidated — no V-n.
- **E15e chaos mode**: chaos only reorders where a fiber awaits an Ignis op; dbal/orm/http-kernel never enter the loop (1–4 yields), so their "0 new failures" is an embed-environment statement. The scheduling claim rests on httpcache (222 539 yields) and http-foundation (V-27).
- **E6 via the async scheduler ABI (PR #22561)**: REFUTED by inspection (V-7) — the ABI is consulted only by Zend core (fibers, GC, execute API, objects); no stream/socket/sleep path calls `ZEND_ASYNC_SUSPEND`, so I/O is stream-hook work on both backends (ADR-0003). The ABI does give engine-owned cancellation, per-coroutine context and GC destructors on a dedicated coroutine; mainline stays the production path.
- **E6 for PDO sqlite via hooks**: REFUTED (V-12) — libsqlite3 does its own `read`/`pread`/`fsync` in the calling thread, there is no stream layer. Answered instead by offload auto-routing (V-24) and the native pgsql pool (V-21).
- **Round-robin dispatch `/cpu` p99** (17.5 ms vs FrankenPHP 15.6 ms at +28% throughput, V-9): superseded by least-inflight (V-15).
- **E10 absolute p99 < 5 ms at c=64**: INCONCLUSIVE on this box — the Rust-only tonic ceiling is p99 6.3 ms under the same ghz load (V-20). Ignis is 1.23× the ceiling.
- **ext-grpc as a comparison server**: it **does** build and load on 8.5.10 ZTS (`grpc 1.85.0dev`; C-core 77 min at `nice -j2`, `grpc.so` 47.6 MiB, three linker workarounds, `pecl.php.net` blocked) but has **no supported server runtime or PHP server codegen**, so it cannot serve E10's handlers (V-20 + corrections).
- **Full Rust scheduler provider for backend (b)**: deferred, not refuted — the reference provider's coroutine entry uses `zend_first_try` (setjmp) and needs a C shim; the idle hook proved the architectural claim (V-8).

## Key finding of the night

Zend allocates and frees a fresh mmap'd C stack per fiber; in a multi-threaded process that costs page faults + `munmap` + cross-CPU TLB shootdowns = **~50% of PHP-thread CPU** at 10k fibers (V-2 perf profile), while the userland scheduler and the Rust side are < 1%. Keeping fibers alive in a pool removes it (V-4: overhead 153 → 41 ms, per-job 16 → 4.5 µs); `fiber.stack_size` is irrelevant (64K–2M within 1%). Everything built afterwards — HTTP, streams, TLS, gRPC, pool leases, offload — is a hop onto that one pooled-fiber/one-poll-point loop.

## Run everything (5 commands)

```
scripts/build-php.sh                                   # PHP 8.5.10 ZTS embed (session, iconv, openssl, curl, pdo_pgsql) → /opt/php85-zts (idempotent, ~7 min)
cargo build --release -p ignis && cargo nextest run     # binary + unit tests (miri: cargo +nightly miri test -p ignis -- php::zval php::module)
scripts/smoke.sh                                       # hello, app.php, E1/E2, 4 threads, E13, E6, E7, E11, E12
./target/release/ignis --threads 4 examples/hello_server.php &  bench/wrk-hello.sh   # HTTP hello on :8080
bench/compare.sh [wrk_threads conns dur]               # Ignis vs FrankenPHP worker vs php-fpm+nginx → bench/results/compare.md (URL_PATH=/cpu, IGNIS_THREADS_LIST="1 4")
# per expectation: bench/e6-fetch.sh e6-ssl.sh e7-revolt.sh e8-symfony.sh e11-cancel.sh e12-isolation.sh e12-inflight.sh e13-http.sh e14-pg.sh e16-offload.sh rss-1m.sh soak-threads.sh
# E9: cargo build --release -p ignis --features temporal && bench/e9-temporal.sh (needs /opt/gobin/temporal); probe: bench/e9-probe.sh
# E10: bench/e10-grpc.sh (grpcurl + ghz); bench/e10-compare.sh (Ignis vs pure tonic vs RoadRunner in /tmp/cmp)
# E15: bench/e15-phpt.sh, bench/e15-revolt.sh, bench/e15-swoole.sh --all, bench/e15-frankenphp.sh (all in CI); chaos: IGNIS_CHAOS=1 ./target/release/ignis <script>
# backend (b): scripts/build-php-async.sh; PHP_CONFIG=/opt/php86-async-zts/bin/php-config CARGO_TARGET_DIR=target-async cargo build --release -p ignis
#              N=10000 IGNIS_PHP_INI=bench/php/async-core.ini ./target-async/release/ignis bench/php/e1_async_core.php
```

## Architecture (current best)

```
            tokio runtime (2 workers)                         PHP fiber threads (--threads N; one reactor each, least-inflight dispatch)
 ┌──────────────────────────────────────┐   crossbeam channel  ┌──────────────────────────────────────────┐
 │ hyper auto (h1/h2) ── service_fn ────┼─► Completion{id,    │ ignis_poll() ──► Ignis\Loop (userland)    │
 │   per connection      oneshot<Resp> ◄┼── Request/Slept}    │   ├─ fiber pool: parked Fibers reused      │
 │ tonic Grpc (same listener, RawCodec) │                     │   ├─ Future / all() / async() / deadline   │
 │ timers, TcpStream + rustls actors    │                     │   └─ dispatch: Request → Fiber → Response  │
 │ tokio-postgres pool (process-wide)   │                     │ ignis_respond / ignis_grpc_send|end        │
 │                          mpsc<Op>   ◄┼── ignis_submit_*()  │ libphp.so (ZTS, embed SAPI, module ignis)  │
 └──────────────────────────────────────┘                     └──────────────────────────────────────────┘
                                                              offload pool (--offload M): synchronous PHP
                                                              threads, own TSRM context; serialized args in,
                                                              result/RemoteException out; callbacks run back
                                                              on the calling fiber (ADR-0016, V-24)
 Per fiber: $_SERVER/$_GET/$_POST/$_COOKIE swapped by the zend_observer fiber-switch hook (reserved slot per context, lazy since E13').
 Streams: tcp/ssl/tls/tlsv1.2/tlsv1.3 factories replaced at MINIT; inside a fiber a stream op parks it (zend_fiber_suspend), the tokio
          actor does the socket I/O and, for TLS, the rustls handshake/records (STARTTLS = Op::Upgrade); ignis_poll() resumes. Outside
          fibers, server sockets and unhooked protocols stay on the stock/openssl transports.
 gRPC: content-type application/grpc on the same listener → tonic framing → PHP handler fiber; Ignis\Grpc\Client calls are Op::Custom futures on a lazy h2 channel.
 PostgreSQL: pool owned by the runtime (tokio-postgres); a fiber holds a lease (Ignis\Scope), a transaction pins it, return = one-round-trip session reset.
 Rules: no Zend pointer ever crosses to tokio; PHP never awaits a tokio future; one wait point (poll) per thread.
```

## CI

`.github/workflows/ci.yml` — nextest + miri, `smoke.sh` with a Postgres service, E9 with the Temporal dev server, and the E15 matrix (phpt / revolt / swoole / frankenphp) gated by `scripts/ci-gate.sh` against `bench/results/e15-baseline.txt`; timeouts everywhere, failed-step logs as artifacts. `php-image.yml` builds `ghcr.io/koekaverna/ignis-php:8.5.10-zts` from `scripts/build-php.sh` when its inputs change. Both run on `night-1` only. Rule: compat/correctness suites run in CI; local runs produce the VALIDATION numbers and perf.

## Blocked downloads (network allowlist)

`www.php.net`, `pecl.php.net` (403 — grpc C-core built from the git clone instead), `ppa.launchpadcontent.net` (ondrej PPA), `github.com` over plain HTTPS (git protocol works), `crates.io` web (sparse index works), composer dist downloads (`--prefer-source` used). Mirrors used: git clone for php-src, `index.crates.io` for crates, Ubuntu archive for tools.

## Name collision check (owner addendum)

crates.io: **taken** (`ignis` 0.1.0, unrelated); Packagist: free; GitHub: three unrelated `ignis` projects (Python widgets, Obsidian app, Blazor). Alternatives proposed, nothing renamed: **`ignis-rt`** or **`fyra`**.

## Still open

E2' below 5 µs warm (pool/loop path; the observer is no longer the cost, V-28); E3' (10M requests, 4 threads, streams + state enabled); fiber-budget pool cap with request queueing (V-5 memory note); per-endpoint budget + circuit breaker (pain map PHP-FPM 2); in-process Table (RoadRunner 4); MySQL/Redis drivers; allocator-level leak detector; E9' (signals/queries/cancellation), E10' (client-streaming/bidi, TLS on the listener), E8' (multi-value `Set-Cookie`), E7' (AMPHP on hooked transports, signals); the 12 fiber-only phpt stream failures classified as ours in the V-26 addendum (unix-socket names, error texts, `timed_out` meta, select usec validation, `fclose(STDIN)`).

## Ranked recommendation for the next 3 cycles

1. **E2' under 5 µs.** Measured (V-28): the observer is out of the picture; the next 1–1.5 µs are in the pool/loop path (`Loop::runUntil` bookkeeping, `ignis_poll` array building). Profile before touching — every per-fiber figure before V-28 was taken under load.
2. **Validate what was pushed unmeasured**: E12' (in-flight requests on a dying thread must get 500, `bench/e12-inflight.sh`), the fatal → 500 mapping, then E3' at 4 threads with streams/state/offload on for 10M requests — the RSS claim (V-10) predates E6, E13, E14 and E16.
3. **Take the pain map's remaining structural items**: pool cap + request queueing (fiber budget), per-endpoint budget/circuit breaker for slow dependencies, in-process Table, then MySQL/Redis as native drivers with the E14 lease shape (ADR-0015) and the offload router as the fallback (ADR-0016).
