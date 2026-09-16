# STATUS — Ignis (updated 2026-09-16T22:15Z — mission is now the product, ROADMAP.md M1–M5; the runtime numbers below are what it stands on)

**Thesis holds.** One Rust process embeds PHP 8.5.10 (ZTS), runs many PHP requests per OS thread on native Fibers, and every wait is a tokio timer/socket/TLS session. Every number below links to a VALIDATION.md entry; anything without one says "not measured".

## Product (ROADMAP.md M1–M5)

| milestone | status |
|---|---|
| M1 Run — `ignis serve`, `ignis.toml`, `/_ignis/health` | **DONE** (V-38) |
| M2 Install — image, serving in <2 min, no PHP build | **DONE** (V-39 + addendum; `image.yml` green on `574b231`) |
| M3 Symfony — untouched skeleton through `ignis/runtime` | **DONE** (V-40 + addendum; V-41 for the package-route bench, dev-mode 404 — not V-16's prod 200, that caveat is the point of V-41) |
| M3 Laravel | re-scoped (research 25): M3-5a classic mode with `budget.fibers = 1` (Octane's own one-request-per-worker model, guaranteed by ADR-0019), M3-5b fiber-scoped `Container::$instance`/Facade caches (needs an ADR) — both open |
| M4 Operate — `/_ignis/metrics`, graceful reload, bulkhead, connection cap | **not started**; the only adjacent work is the log floor already at `warn` (M1) and H-10 (fixed a clean `exit()` misread as a fatal in the log) |
| M5 Ship — release + nightly workflows | **written** (M5-1, M5-4), dry-run clean; **not yet exercised by a real `v*` tag push or schedule dispatch** — the tag push is refused by the session git proxy and is the owner's action |

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
| **E11** disconnect cancels the request fiber + children; `Ignis\deadline()` | **0.78 ms** worst cancel latency, 20/20 `finally` ran, no phantom work; 504 at 102 ms for a 100 ms deadline. Phase A found and fixed a defect where a disconnect could kill the whole worker thread (6 dead threads in 10.08M requests under burst load, without `--supervise` the process itself died); after the fix, 12 burst rounds of `wrk -t4 -c200 -d5s` leave the server alive with 0 fatals / 0 restarts, and cancellation got *faster*: 279 µs worst latency, 20/20 cancelled, 40/40 `finally` blocks | V-14, V-30 |
| **E12** fatal / CPU spin in one thread; supervisor respawn | fatal killed 1 of 4 workers, hello uninterrupted at 134k req/s, respawn inside the 50 ms tick, no opcache reset; spin stalled 1 thread (others 90k req/s, p99 2.67 ms); recovery **95.7%** | V-17 |
| **E13** fiber-scoped `$_SERVER`/`$_GET`/`$_POST`/`$_COOKIE` + `Ignis\Scope` | **0 mismatches** (300 in-process checks, 200 concurrent HTTP); **+100 ns** per fiber switch | V-11 |
| **E14** runtime-owned PostgreSQL pool, lease per fiber, transaction pins, one-round-trip reset | 200 fibers over 20 connections in **1046 ms** warm (ideal 1000); LeaseError in 38 µs with 0 ops; SET/temp do not leak; **112 µs/query**, 7.3k q/s on one thread | V-21 |
| **E15** compat suites — **DONE**, refreshed post-Phase-A on a different (24-core) box | phpt main mode **108/110 fibers, 89/118 sockets, 133/160 streams**; fiber mode **78/110 fibers, 83/118 sockets, 125/160 streams** (suite sizes differ from the 4 vCPU box's 108/80/138 because this box runs more of each suite — an environment/kernel difference, not an Ignis effect, per research 21); Swoole shim **55/153** (up from 44 — A4's `ext/sockets` hooks moved it); FrankenPHP testdata **29 pass** (fail/skip not reported by this CI run); Revolt `DriverTest` and the Symfony/Doctrine chaos numbers unchanged from V-23 addendum / V-27 | V-22, V-23 + addendum, V-26 addendum, V-27 for the original numbers; refreshed phpt/Swoole/FrankenPHP counts are from **CI run 35086872504 on `3112a96`**, not yet a V-n |
| **E16** offload pool (own TSRM context), `Ignis\offload()`, auto-routing of `curl_*`/`PDO`/`SQLite3` with no code changes | 100 × 200 ms blocking calls: **2608 ms on 8 workers, 243 ms on 100** (fiber thread kept ticking); auto-routed `new PDO`+query 100 × 200 ms: 3454 ms on 8; `curl_exec` + `CURLOPT_WRITEFUNCTION` on the caller; **13–67 µs** copy, 17–45 µs per routed call | V-24 + addendum |
| **E18** universal park (ADR-0020/0037) — **default build since cycle 1**; `sleep.rs` (cycle 1), `sockets.rs` + `accept.rs` (cycle 2) deleted and the stream transport factory + rustls path (cycle 3) deleted — **−1,436 Rust lines, −42 `unsafe {`, binary 48.7 → 37.2 MB**; three mechanisms left (park, offload, context); every creating test green through park, A6/B7 closed by disappearance | `curl_exec` 100 × 200 ms **279 ms**, `pdo_pgsql` **296–333 ms** with no PHP hook and no offload (controls 20.3 / 20.6 s); through the project bench after the E18-I1 fix **301–308 ms**; gate ≈ 8 ns/call; E1/E2/E4/E5 on vs off indistinguishable from noise (E4 quiet band pending); phpt gate ≥ baseline; policy table `IGNIS_PARK` = `lib[:symbol]` rows, seed `libphp:sleep,libphp:usleep,libphp:nanosleep,libcurl,libpq,libssl,libcrypto` | V-45, V-46, V-47, V-48, V-49 |
| backend (b): true-async fork builds (61/61 reference-scheduler tests) and runs E1 on engine coroutines via a 14-line idle hook | 1165–1177 ms for 10k × 1000 ms | V-7, V-8 |

## REFUTED / INCONCLUSIVE / not measured, and why

- **E1 under CPU contention**: 1302 ms with two builds on the other cores (V-2 addendum); the 2–3% cold margin does not survive a loaded box. The warm pool (V-4) is the fix. Quiet-box re-run after all of tonight's changes (V-28): **1175–1195 ms** (3 reps), still < 1200 ms; 1294 ms with two stray processes on the box.
- **E1' (10k HTTP connections, p99 < 1.1 s)**: p99 1.21 s warm / 1.42 s cold with 86 timeouts cold — INCONCLUSIVE: wrk's 2 threads share the 4 vCPUs with 2 tokio threads and the PHP thread. Needs an external load box (V-5).
- **E2' (< 5 µs per warm job)**: **met** — **4.5–4.7 µs** warm with the observer on (3/3 runs), 4.4–4.7 off (V-28 addendum, quiet box) after two changes tonight: the E13' lazy swap removed the observer's 3.5–6.9 µs (V-11 addendum → ≤ 0.7 µs, V-28) and a zero-sleep now completes inline in the reactor (H28). Honest scope: this is one loop round trip via `sleep(0)`; a fiber parked on a real timer/socket pays 5.5–6.4 µs. Cold per-fiber 17–21 µs** on a quiet box (no V-n).
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

## Phase A (closed, C22 — full narrative in ROADMAP.md's R&D backlog)

| item | status | V-n |
|---|---|---|
| A1 — 3 stream defects (`stream_set_timeout`, port-literal wrap, connect `$errstr`/`$errno`) | DONE | V-31 |
| A2 — real-timer path (timer wheel) | DEMOTED — both acceptance numbers already met | — |
| A3 — RSS soak, 1M→10M requests | RUN, criterion restated to "no monotonic trend past 5M" | V-30 |
| A4 — `ext/sockets` parks the fiber | DONE | V-29 |
| A5 — php-cli parity (`-r`, `--`) | DONE | V-32 |
| A6 — TLS read-ahead invisible to `stream_select` | HANDED ON to Phase B (B7) | — |
| A7 — `run-tests.php` orphans `ignis` children | DONE | V-28 |

Owner sign-off still needed on four points from Phase A — DECISIONS.md "Owner decisions outstanding".

## Run everything (5 commands)

```
scripts/build-php.sh                                   # PHP 8.5.10 ZTS embed (session, iconv, openssl, curl, pdo_pgsql) → /opt/php85-zts (idempotent, ~7 min)
cargo build --release -p ignis && cargo nextest run     # binary + unit tests (miri: cargo +nightly miri test -p ignis -- php::zval php::module)
scripts/smoke.sh                                       # hello, app.php, E1/E2, 4 threads, E13, E6, E7, E11, E12
ignis serve examples/hello_server.php & bench/wrk-hello.sh   # or: docker run -p 8080:8080 ghcr.io/koekaverna/ignis (V-38, V-39) — HTTP hello on :8080
bench/compare.sh [wrk_threads conns dur]               # Ignis vs FrankenPHP worker vs php-fpm+nginx → bench/results/compare.md (URL_PATH=/cpu, IGNIS_THREADS_LIST="1 4")
# per expectation: bench/e6-fetch.sh e6-ssl.sh e7-revolt.sh e8-symfony.sh e11-cancel.sh e12-isolation.sh e12-inflight.sh e13-http.sh e14-pg.sh e16-offload.sh rss-1m.sh soak-threads.sh
# E9 (needs /opt/gobin/temporal): cargo build --release -p ignis --features temporal && bench/e9-temporal.sh; probe bench/e9-probe.sh — E10: bench/e10-grpc.sh (grpcurl+ghz), bench/e10-compare.sh (vs pure tonic/RoadRunner in /tmp/cmp)
# E15: bench/e15-phpt.sh, bench/e15-revolt.sh, bench/e15-swoole.sh --all, bench/e15-frankenphp.sh (all in CI); chaos: IGNIS_CHAOS=1 ./target/release/ignis <script>
# backend (b): scripts/build-php-async.sh; PHP_CONFIG=/opt/php86-async-zts/bin/php-config CARGO_TARGET_DIR=target-async cargo build --release -p ignis; then N=10000 IGNIS_PHP_INI=bench/php/async-core.ini ./target-async/release/ignis bench/php/e1_async_core.php
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
 offload pool (--offload M): synchronous PHP threads, own TSRM context; serialized args in, result/RemoteException out; callbacks run back on the calling fiber (ADR-0016, V-24).
 Per fiber: $_SERVER/$_GET/$_POST/$_COOKIE swapped by the zend_observer fiber-switch hook (reserved slot per context, lazy since E13').
 Streams: tcp/ssl/tls/tlsv1.2/tlsv1.3 factories replaced at MINIT; inside a fiber a stream op parks it (zend_fiber_suspend), the tokio actor does the socket I/O and, for TLS, the rustls handshake/records (STARTTLS = Op::Upgrade); ignis_poll() resumes. Outside fibers, server sockets and unhooked protocols stay on the stock/openssl transports.
 gRPC: content-type application/grpc on the same listener → tonic framing → PHP handler fiber; Ignis\Grpc\Client calls are Op::Custom futures on a lazy h2 channel.
 PostgreSQL: pool owned by the runtime (tokio-postgres); a fiber holds a lease (Ignis\Scope), a transaction pins it, return = one-round-trip session reset.
 Rules: no Zend pointer ever crosses to tokio; PHP never awaits a tokio future; one wait point (poll) per thread.
```

## CI / blocked downloads

`ci.yml` (nextest+miri, `smoke.sh`, E9, the E15 matrix gated against `bench/results/e15-baseline.txt`), `php-image.yml` (builder image) and `image.yml` (runtime image, V-39 addendum) run today; `release.yml`/`nightly.yml` exist (M5-1, M5-4) but have not run against a real tag/schedule. Network allowlist blocks `www.php.net`, `pecl.php.net` (403 — grpc C-core built from the git clone instead), `ppa.launchpadcontent.net`, `github.com` over plain HTTPS (git protocol works), `crates.io` web (sparse index works), composer dist downloads (`--prefer-source` used). Name collision (owner addendum): crates.io taken (unrelated `ignis` 0.1.0), Packagist free, GitHub three unrelated — nothing renamed.

## Still open

M4 Operate: metrics (M4-4), graceful reload (M4-5), hold-time on leases (M4-1), per-dependency bulkhead + breaker (M4-2/B2 — pain-map PHP-FPM 2, still NOT STARTED), connection cap at the listener (M4-3/B8 — the RSS half of B1's acceptance a fiber budget alone cannot meet, V-37). M5's real tag push and nightly dispatch are the owner's. M3: Laravel (M3-5a/M3-5b), Packagist publication (M3-6), the prod-mode Symfony bench leg (M3-8). R&D backlog deferred from the product (DECISIONS.md): in-process Table (B3), native MySQL/Redis (B4), allocator-level leak detector (B5), static binary (M5-5/B6), TLS read-ahead via `op_cast`-owned eventfd (B7).

## Ranked recommendation

1. **M4-3 (cap connections at the listener, B8)** — closes the RSS half of B1's acceptance a fiber budget alone cannot meet (V-37: ~33 kB per held connection), and the fix pain-map PHP-FPM 6 / FrankenPHP 3 still need; then **M4-2 (per-dependency bulkhead + breaker, B2)** — pain-map PHP-FPM 2, still NOT STARTED, `pg::acquire` waits unboundedly today.
2. **M3-5a (Laravel, classic mode, `budget.fibers = 1`)** — a second real framework on the existing ADR-0019 mechanism, no FFI change, `agent` lane.
3. **M5's real tag push** — the release and nightly workflows (M5-1, M5-4) are written and dry-run clean; only a `v*` tag push and a schedule dispatch are missing to close M5.
