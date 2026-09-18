# STATUS — Ignis (updated 2026-09-18, branch `main`, CI green on all ten jobs of a `ci.yml` run — mission is now the product, ROADMAP.md M1–M5; the runtime numbers below are what it stands on)

Read top down: what the product can do, what is open, what to do next, how to run it. The evidence is underneath — every number links a VALIDATION.md entry, and anything without one says "not measured".

**Thesis holds.** One Rust process embeds PHP 8.5.10 (ZTS), runs many PHP requests per OS thread on native Fibers, and every wait is a tokio timer or a tokio fd-readiness wait (TLS included: since V-49 the records are ext/openssl's, parked on that readiness). Every number below links to a VALIDATION.md entry; anything without one says "not measured".

## Product (ROADMAP.md M1–M5)

| milestone | status |
|---|---|
| M1 Run — `ignis serve`, `ignis.toml`, `/_ignis/health` | **DONE** (V-38) |
| M2 Install — image, serving in <2 min, no PHP build | **DONE** (V-39 + addendum; `image.yml` green on `574b231`) |
| M3 Symfony — untouched skeleton through `ignis/runtime` | **DONE** (V-40 + addendum; V-41 for the package-route bench, dev-mode 404 — not V-16's prod 200, that caveat is the point of V-41) |
| M3 Laravel | re-scoped (research 25): M3-5a classic mode with `budget.fibers = 1` (Octane's own one-request-per-worker model, guaranteed by ADR-0019), M3-5b fiber-scoped `Container::$instance`/Facade caches (needs an ADR) — both open |
| M4 Operate — `/_ignis/metrics`, graceful drain, bulkhead, connection cap | **partly done**: metrics (M4-4, V-55: 22 metric families then, **19 now** — the PostgreSQL ones went with the pool, V-87 — promtool clean, 1.9–5.5 ms under `wrk -c200`), lease hold-time (M4-1, V-44), and V-56's front-door limits + two-phase `SIGTERM`/`SIGINT` drain (M4-5's drain half). **Open**: delayed-accept connection cap (M4-3). The bulkhead + breaker (M4-2) was built and removed the same day with the pool it guarded (ADR-0015 closed, V-87); `SIGHUP` reload landed with development reload (V-90) |
| M5 Ship — release + nightly workflows | **release exercised by a real tag** (V-57): `v0.1.0-rc.1` published — image 62 MB at the time + 29,795,977 B tarball, both verified by pulling and running `--version` — **the published image is 84,030,879 B today (V-83)**, and the 64 MB every other page still quotes is that stale figure; the first tag failed on the tarball step and was fixed. The **nightly schedule dispatch has still not run** (M5-4, no V-n) |

## Still open

**M4 Operate:** the delayed-accept connection cap (M4-3/B8/`S1-CAP` — the RSS half of B1's acceptance a fiber budget alone cannot meet, V-37; `IGNIS_MAX_CONNECTIONS` exists and is measured, V-56, but it accepts-then-refuses rather than delaying accept), offload cancellation on disconnect (M4-9), `IGNIS_LOOP_GC` measured (M4-10), the watchdog naming the fiber (M4-7), the held-resource audit (M4-6).

**M3:** Laravel (M3-5a/M3-5b), Packagist publication (M3-6), `/stats` moved into the runtime (M3-7), the prod-mode Symfony bench leg (M3-8). **M5:** the nightly schedule dispatch is still unrun (M5-4), the static binary is research only (M5-5/B6).

**Found by the 2026-09-18 audit and open** (`BACKLOG.md` has each with its acceptance): `R-HELP` (the binary has no `--help`), `S0-DOCS-UNVERIFIED`, `S0-RESPOND-START` — now half-closed, the dead registration went with V-91 — `S0-FRANK` (a frankenphp test regressed and is still unnamed), `R-DNS`, `R-PDO-SQLITE`, `R-FOREIGN-FIBER`, `R-SESS`, `R-REVIEW-CHORES`, `S-DBAL-DIRECT`, `S-POOL-LEASE-AGE`, `S-EXCLUSIVE`, `S-REQUEST-FORMATS`, `S-SERVE-SECOND-ADDRESS`, `S4-MIXED`, and `R-REVOLT-FLAKE` (the compat gate reads 79 or 80 on identical code). `BACKLOG.md` holds 48 open items; the closed ones are indexed there and kept in full in `BACKLOG-CLOSED.md`.

**Closed recently:** the per-dependency bulkhead + breaker (M4-2/B2 — built for the PostgreSQL pool and removed with it on 2026-09-18, ADR-0015), the `[limits]` table (`R-LIMITS-CONFIG`), `SIGHUP` reload with development reload (M4-5, V-90), `S0-FIBER` (V-89), `S0-E9`, and `main` green again (V-91 stage 0). **Deferred R&D** (DECISIONS.md): in-process Table (B3), native MySQL/Redis (B4), allocator-level leak detector (B5), static binary (M5-5/B6). B7 is retired — V-49 deleted the mechanism it was designed for.

## Ranked recommendation

1. **A quiet box** — it is now the binding constraint on three separate items, not a nicety. This box measures ±6.7 % run to run on hello throughput, which is larger than every remaining performance question: the residual code-side drop H-12 left open (4–5 %, so its bisect is refused, V-82), the lock-free `Registry::pick` that is the unlanded half of S4-ANSWER-MAP, and E4/E10 before/after for anything in the request path. Startup RSS, by contrast, has a 0.6 % floor — which is why the RSS bisect succeeded and the throughput one could not.
2. **S1-CAP (cap connections at the listener, M4-3/B8)** — the largest single item still open (it is not the only one: see "Still open"). Closes the RSS half of B1's acceptance a fiber budget alone cannot meet (V-37: ~33 kB per held connection), and the fix pain-map PHP-FPM 6 / FrankenPHP 3 still need. `IGNIS_MAX_CONNECTIONS` exists and is measured (V-56) but accepts-then-refuses rather than delaying accept.
3. **S4-MIXED (boundary validation)** — the level itself is **already 9** (`php/phpstan.neon`, no baseline), so the name is misleading: what is left is real validation of what crosses the boundary, with new throws on malformed input. Step 1 measured and landed (the completion union with a literal `kind` discriminator: 349 → 307 at zero runtime cost), step 2 measured and refused. What is left is real boundary validation with new throws on malformed input, which is a behaviour change roughly the size of the whole 2026-09-17 PHP effort — a cycle of its own, not a config bump.
4. **M3-5a (Laravel, classic mode, `budget.fibers = 1`)** — a second real framework on the existing ADR-0019 mechanism, no FFI change, `agent` lane.

## Run everything

```
cargo fmt --all --check; cargo clippy --workspace --all-targets -- -D warnings; cargo deny check   # Rust half of the gate (ADR-0041)
cd php && composer check                               # php -l, PHPStan level 9, php-cs-fixer, PHPUnit; scripts/test-php.sh --coverage for V-79's number
scripts/build-php.sh                                   # PHP 8.5.10 ZTS embed (session, iconv, openssl, curl, pdo_pgsql) → /opt/php85-zts (idempotent, ~7 min)
cargo build --release -p ignis && cargo nextest run     # binary + unit tests (miri: cargo +nightly miri test -p ignis -- php::zval php::module)
scripts/smoke.sh                                       # hello, app.php, E1/E2, 4 threads, E13, E6, E7, E11, E12
ignis serve examples/hello_server.php & bench/wrk-hello.sh   # or: docker run -p 8080:8080 ghcr.io/koekaverna/ignis (V-38, V-39) — HTTP hello on :8080
bench/compare.sh [wrk_threads conns dur]               # Ignis vs FrankenPHP worker vs php-fpm+nginx → bench/results/compare.md (URL_PATH=/cpu, IGNIS_THREADS_LIST="1 4")
# per expectation: bench/e6-fetch.sh e6-ssl.sh e7-revolt.sh e8-symfony.sh e11-cancel.sh e12-isolation.sh e12-inflight.sh e13-http.sh e16-offload.sh e23-stream.sh e25-reload.sh rss-1m.sh soak-threads.sh   (e14-pg.sh went with the pool, V-87)
# E9 (needs /opt/gobin/temporal): cargo build --release -p ignis --features temporal && bench/e9-temporal.sh; probe bench/e9-probe.sh — E10: bench/e10-grpc.sh (grpcurl+ghz), bench/e10-compare.sh (vs pure tonic/RoadRunner in /tmp/cmp)
# E15: bench/e15-phpt.sh, bench/e15-revolt.sh, bench/e15-swoole.sh --all, bench/e15-frankenphp.sh (all in CI); chaos: IGNIS_CHAOS=1 ./target/release/ignis <script>
# backend (b): scripts/build-php-async.sh; PHP_CONFIG=/opt/php86-async-zts/bin/php-config CARGO_TARGET_DIR=target-async cargo build --release -p ignis; then N=10000 IGNIS_PHP_INI=bench/php/async-core.ini ./target-async/release/ignis bench/php/e1_async_core.php
```

## CONFIRMED (numbers)

| expectation | number | entry |
|---|---|---|
| base: PHP 8.5.10 ZTS+embed+opcache builds here; Rust host links libphp, registers a module, runs a script | `PHP_ZTS=1`, 7/7 extensions; 16 ms startup+script | V-0, V-1 |
| **E1** 10,000 fibers × `Ignis\sleep(1000)`, one PHP thread | 1168–1178 ms cold; **1037–1041 ms warm pool** (idle box) | V-2, V-4 |
| **E2** `Ignis\all()` of 3 × 200 ms; per-fiber overhead | 201–202 ms; 21.6–24.2 µs cold → **4.4–4.6 µs warm** (observer off) | V-3, V-4 |
| **E3** RSS in worker mode | **the flatness claim is what E3 gates and it reproduced**: 44.4 → 43.9 MB over 1.15M hello (−1.2 %), PHP heap flat to the byte across seven samples. **The absolutes in the original entry are stale and the throughput figure in it must not be quoted** — V-82 decomposes the growth as ~10 MB outside this repository (the engine rebuild with the toolchain extensions, measured by rebuilding V-10's own commit against today's engine) and 4.7 MB across 281 of our commits, of which one carries 2.08 MB | V-10 + addendum, V-82 |
| **E4** hello vs FrankenPHP worker vs php-fpm+nginx, same libphp, 1 thread | **128k vs 27.6k vs 9.9k req/s**; p99 1.11 / 6.42 / 8.68 ms | V-6 |
| **E4'** raised: 4 PHP threads vs FrankenPHP@4 workers, superglobals+streams+cancel active | **112.5k vs 16.6k req/s**, p99 2.41 ms | V-15 |
| **E5** CPU-bound scaling, 4 threads (box has 4 vCPU → target 3.25×) | **3.7–3.98×** in-process, 3.49× over HTTP (`/cpu` 9.3k vs 2.7k req/s) | V-9 |
| **E5'** raised: least-inflight dispatch on `/cpu`@4 | p99 **12.4–12.8 ms** at 9.1–9.3k req/s (FrankenPHP@4: 15.6 ms) | V-15 |
| **E6** unmodified `file_get_contents('http://…')` suspends the fiber | 3 × 200 ms in **202.5–202.9 ms** on one thread (server calling itself); 100/100 concurrent; hook-off control stalls | V-12 |
| **E6'** raised: `ssl://`/`tls://`/`https://` + STARTTLS through the hook (rustls in the reactor — **the mechanism this was measured on has since been deleted**, V-49; the behaviour is now ext/openssl over park) | **210–231 ms hooked vs 613–623 ms unhooked** for 3 × 200 ms; 5/5 verification cases behave like ext/openssl | V-25 |
| **E7** Revolt driver, AMPHP examples unchanged | 7/8 byte-identical (8th a timing race in the example); timer benchmarks ≤ 1× of StreamSelectDriver | V-13 |
| **E8** symfony/skeleton in worker mode, fiber-scoped RequestStack, sessions on | **0/100** mismatches across suspensions; **7.2k req/s (1 thread) / 25.2k (4 threads)** through the kernel | V-16 + addendum |
| **E9** Temporal: PHP workflow (2 activities + 500 ms timer) as a suspended fiber; deterministic replay | live run **COMPLETED in 1224 ms**, 5 activations; replay of the 22-event history **OK**; mutated workflow **FAILS** (TMPRL1100) | V-18, V-19 |
| **E10** gRPC unary + server-streaming in PHP on the shared listener; runtime-owned client parks the fiber | **16.7k req/s, p99 7.8 ms** (1 thread) vs pure-tonic ceiling 21.1k/6.3 ms vs RoadRunner 5.1k–11.4k/12.6–17.8 ms; 100 × 200 ms calls **215 ms** vs RR 5.03 s; client: 217 ms | V-20 |
| **E11** disconnect cancels the request fiber + children; `Ignis\deadline()` | **0.78 ms** worst cancel latency, 20/20 `finally` ran, no phantom work; 504 at 102 ms for a 100 ms deadline. Phase A found and fixed a defect where a disconnect could kill the whole worker thread (6 dead threads in 10.08M requests under burst load, without `--supervise` the process itself died); after the fix, 12 burst rounds of `wrk -t4 -c200 -d5s` leave the server alive with 0 fatals / 0 restarts, and cancellation got *faster*: 279 µs worst latency, 20/20 cancelled, 40/40 `finally` blocks | V-14, V-30 |
| **E12** fatal / CPU spin in one thread; supervisor respawn | fatal killed 1 of 4 workers, hello uninterrupted at 134k req/s, respawn inside the 50 ms tick, no opcache reset; spin stalled 1 thread (others 90k req/s, p99 2.67 ms); recovery **95.7%** | V-17 |
| **E13** fiber-scoped `$_SERVER`/`$_GET`/`$_POST`/`$_COOKIE` + `Ignis\Scope` | **0 mismatches** (300 in-process checks, 200 concurrent HTTP); **+100 ns** per fiber switch | V-11 |
| **E14** connections: a pool with a lease per fiber, a transaction pinning it, a reset on return — **the runtime-owned pool that proved it is deleted** (owner decision 2026-09-18, ADR-0015 closed): V-86 measured it against parked `pdo_pgsql` and the speed case did not survive. The expectation is answered in userland now, by `ignis/doctrine`'s pool | historic: 200 fibers over 20 connections in **1046 ms** warm, LeaseError in 38 µs, **112 µs/query** | V-21, then V-85, V-86, **V-87** |
| **E15** compat suites — **DONE**; the counts differ by machine and both are recorded | Measured on **this box** 2026-09-18 (`bench/results/e15-phpt/summary.md`, two of my own full runs agreeing): stock **108/110 fibers, 91/118 sockets, 140/160 streams**; main **108, 91, 134**; fiber **77, 85, 126**. The same commit in **CI** (run 35326670050) gives fiber **77**, sockets **92 main / 87 fiber** — same suite sizes (118, 26 skipped), so the sockets delta is the machine, not the subset, and the proof is that **stock mode differs too** (91 here, 92 there) with no Ignis in the picture at all. **Fibers is the one that is not environmental:** this box and CI both say **77** against a baseline of 78 committed on 2026-09-16, and the test that lost its PASS is `gh9916-009.phpt`, which passes in stock and main mode and fails only in fiber mode — **explained and closed** (V-89: it asserts the script shutdown sequence, which fiber mode does not have; the baseline is 77 with the reason attached). Swoole shim **55/153**; FrankenPHP **29 pass**; Revolt `DriverTest` and the Symfony/Doctrine chaos numbers unchanged | V-22, V-23 + addendum, V-26 addendum, V-27 for the originals; the 2026-09-18 phpt counts are my own runs plus CI run 35326670050, not yet a V-n |
| **E16** offload pool (own TSRM context), `Ignis\offload()`, auto-routing with no code changes — **`curl_*` and `PDO` have since left the default table** (park is faster: `pdo_pgsql` 303 ms parked against 2,753 ms on 8 workers, V-59 addendum), so the default routes `SQLite3` only | 100 × 200 ms blocking calls: **2608 ms on 8 workers, 243 ms on 100** (fiber thread kept ticking); auto-routed `new PDO`+query 100 × 200 ms: 3454 ms on 8; `curl_exec` + `CURLOPT_WRITEFUNCTION` on the caller; **13–67 µs** copy, 17–45 µs per routed call | V-24 + addendum |
| **E18** universal park (ADR-0020/0037) — **default build since cycle 1**; `sleep.rs` (cycle 1), `sockets.rs` + `accept.rs` (cycle 2) deleted and the stream transport factory + rustls path (cycle 3) deleted — **−1,436 Rust lines, −42 `unsafe {`, binary 48.7 → 37.2 MB**; three mechanisms left (park, offload, context); every creating test green through park, A6/B7 closed by disappearance | `curl_exec` 100 × 200 ms **279 ms**, `pdo_pgsql` **296–333 ms** with no PHP hook and no offload (controls 20.3 / 20.6 s); through the project bench after the E18-I1 fix **301–308 ms**; gate ≈ 8 ns/call; E1/E2/E4/E5 on vs off indistinguishable from noise (E4 quiet band pending); phpt gate ≥ baseline; policy table `IGNIS_PARK` = `lib[:symbol]` rows; the seed has grown with each cycle and is `SEED` in `park.rs` (today: sleep/usleep/nanosleep/select/accept/poll/recv/send/recvfrom/sendto/recvmsg/sendmsg/connect/read/write/flock on libphp, plus libcurl, libpq, libssl, libcrypto) | V-45, V-46, V-47, V-48, V-49 |
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

## Architecture (current best)

```
            tokio runtime (2 workers)                         PHP fiber threads (--threads N; one reactor each, least-inflight dispatch)
 ┌──────────────────────────────────────┐   crossbeam channel  ┌──────────────────────────────────────────┐
 │ hyper auto (h1/h2) ── service_fn ────┼─► Completion{id,    │ ignis_poll() ──► Ignis\Loop (userland)    │
 │   per connection      oneshot<Resp> ◄┼── Request/Slept}    │   ├─ fiber pool: parked Fibers reused      │
 │ tonic Grpc (same listener, RawCodec) │                     │   ├─ Future / all() / async() / deadline   │
 │ timers, fd readiness (Op::Watch)     │                     │   └─ dispatch: Request → Fiber → Response  │
 │ file watcher (IGNIS_WATCH, dev only) │                     │ ignis_respond / ignis_grpc_send|end        │
 │            mpsc<Op> Sleep|Watch|…   ◄┼── ignis_submit_sleep │ libphp.so (ZTS, embed SAPI, module ignis)  │
 └──────────────────────────────────────┘                     └──────────────────────────────────────────┘
 offload pool (--offload M): synchronous PHP threads, own TSRM context; serialized args in, result/RemoteException out; callbacks run back on the calling fiber (ADR-0016, V-24).
 Per fiber: $_SERVER/$_GET/$_POST/$_COOKIE swapped by the zend_observer fiber-switch hook (reserved slot per context, lazy since E13').
 Streams: no transport factory and no rustls any more (ADR-0037 cycle 3, V-49) — a stream op inside a fiber blocks in libc, the interposed call parks the fiber (zend_fiber_suspend) and submits Op::Watch on the fd, and ignis_poll() resumes it when tokio says the fd is ready. TLS is PHP's own ext/openssl on top of that, so the handshake and the records never leave the PHP thread. Outside fibers every call forwards to libc unchanged.
 gRPC: content-type application/grpc on the same listener → tonic framing → PHP handler fiber; Ignis\Grpc\Client calls are Op::Custom futures on a lazy h2 channel.
 PostgreSQL: nothing of ours. `pdo_pgsql` parks like any other syscall; pooling, if an application wants it, is `ignis/doctrine`'s (V-85). The runtime-owned tokio-postgres pool this line used to describe was deleted on 2026-09-18 (V-86, V-87).
 Rules: no Zend pointer ever crosses to tokio; PHP never awaits a tokio future; one wait point (poll) per thread.
```

## Quality gate (ADR-0041, accepted — its own §7 gate is green on `main`)

Until this cycle there was **no linter of any kind in CI, on either side, and no coverage number** (ADR-0041 §1). `cargo fmt`, `clippy -D warnings`, `cargo check --no-default-features`, `cargo deny`, `php -l`, PHPStan (level 6 when the gate landed, level 9 since `0ec13ba`), php-cs-fixer @PER-CS and PHPUnit now all block a merge.

| | before | after | entry |
|---|---|---|---|
| Rust tests / line coverage | 9 / **7.67 %** | **56** / **29.95 %** — 60 when V-78 measured it, 51 after the PostgreSQL pool went (V-87), 56 after this cycle's regression tests (V-91) | V-78, V-87, V-91 |
| PHP tests / line coverage | 42 / **5.61 %** | **307 / 720 assertions** (209 when V-79 measured the coverage) / **36.60 %**, floored at 31.6 % in CI | V-79 + addenda 1–2 |
| undocumented `unsafe` blocks | **101** | **0**, and now under `--all-features` | V-79 addendum 3 |
| PHPStan errors | 123 at level 6 | **0 at level 9** (`php/phpstan.neon`, no baseline) | V-79 addendum, S3-STAN8 |

What those percentages do not cover, from the entries themselves: `csrc/park.c` is **not instrumented at all** (`cc` reads `CFLAGS`, not `RUSTFLAGS`, and every line needs a live engine, V-78); `crates/ignis/src/php/**` is ~2 % because every line needs `php_embed_init` on the calling thread, so the 29.95 % is carried by the tokio side at 55.8 % (V-78); `packages/revolt/src` has no line coverage because its suite only runs inside the ignis binary (V-79). Those paths are held by pass/fail suites (E1/E2/E5/E6/E7/E11/E13, E15, E18), not by line counters. Rust coverage is **reported, not gated**; the PHP side gets a fixed floor of `achieved − 5` (ADR-0041 §6).

## CI / blocked downloads

**`main` is green** on all **ten jobs of a `ci.yml` run**, plus `image` and `docs`. Ten and seven are both right and the file used to say both without saying why: `ci.yml` defines **seven** jobs, one of which (`e15`) is a four-leg matrix — `phpt`, `revolt`, `swoole`, `frankenphp` — so a run reports ten. Written out here because reading it as a contradiction is what the 2026-09-18 audit did first. It went red again on 2026-09-18 for three runs in a row — `cargo deny` on `notify`'s CC0-1.0 licence, twice, the second push made without reading the first verdict, and then `phpstan` crashing a parallel worker on the container's 128M — and is green again since `d9bb336`, which also gave the repository the local gate (`scripts/gate.sh`) it did not have (V-91's stage 0). All three failing gates are fixed, and **all three turned out to be gates that could not fail rather than code that was broken**: `E15 revolt` had pointed at `php/packages/revolt/test/`, renamed to `tests/` by the package split (V-62), so every run exited 2 and reported `IGNIS_PASSED=0`; the `E9 temporal` negative control printed `REPLAY_OK` while the same log showed sdk-core evicting the mutated history (`TMPRL1100`, `NONDETERMINISM`); `E15 frankenphp` was a real regression from the stream/multipart work and is back at baseline. A fourth was found by the pre-merge gate: the `--all-features` clippy step had never linted a line, because its job took `rust-toolchain@stable`, which ships without the component — and behind it `backend/temporal.rs` carried 9 undocumented `unsafe` blocks (V-79 addendum 3). The `image` workflow then caught a fifth thing, this one post-merge by design: the runtime image's explicit library list had not moved when the toolchain extensions made libphp link libxml2, so the pushed image died on `libxml2.so.2` before answering `/_ignis/health`.

`ci.yml` has seven jobs (`lint`, `unit` with nextest+miri and coverage, `smoke`, `php-lint`, `php-unit`, `e9-temporal` which also runs the `--all-features` clippy and nextest, `e15` gated against `bench/results/e15-baseline.txt`); `php-image.yml` (builder image) and `image.yml` (runtime image, V-39 addendum) run today; `release.yml` has now run against a real tag (V-57), `nightly.yml`'s schedule dispatch has not. Network allowlist blocks `www.php.net`, `pecl.php.net` (403 — grpc C-core built from the git clone instead), `ppa.launchpadcontent.net`, `github.com` over plain HTTPS (git protocol works), `crates.io` web (sparse index works), composer dist downloads (`--prefer-source` used). Name collision (owner addendum): crates.io taken (unrelated `ignis` 0.1.0), Packagist free, GitHub three unrelated — nothing renamed.

## Phase A (closed, C22 — full narrative in ROADMAP.md's R&D backlog)

| item | status | V-n |
|---|---|---|
| A1 — 3 stream defects (`stream_set_timeout`, port-literal wrap, connect `$errstr`/`$errno`) | DONE | V-31 |
| A2 — real-timer path (timer wheel) | DEMOTED — both acceptance numbers already met | — |
| A3 — RSS soak, 1M→10M requests | RUN, criterion restated to "no monotonic trend past 5M" | **V-35** (V-30, cited here and in DECISIONS.md until 2026-09-18, is the client-disconnect entry) |
| A4 — `ext/sockets` parks the fiber | DONE | V-29 |
| A5 — php-cli parity (`-r`, `--`) | DONE | V-32 |
| A6 — TLS read-ahead invisible to `stream_select` | **CLOSED by disappearance** — the rustls path it lived in is deleted and PHP's own `ext/openssl` handles buffered plaintext; V-49 records the case PASS in 22.9 µs. B7, the eventfd design for it, is retired with it | V-49 |
| A7 — `run-tests.php` orphans `ignis` children | DONE | V-28 |

Owner sign-off on Phase A: **nothing outstanding**. All five questions were answered on 2026-09-16 and the section that said otherwise is now an index of where each was closed (DECISIONS.md). A6/B7 went further and is moot — V-49 deleted the mechanism and records the case as PASS in 22.9 µs.
