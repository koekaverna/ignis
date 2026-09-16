# Ignis — roadmap after night 1

Written 2026-09-16T05:15Z at the owner's request ("stop the loop, build a roadmap"). Every number here is from VALIDATION.md; every open item names the entry it would extend. Ordering inside a phase is the recommended order; phases can overlap.

## Where night 1 ends

- **38 hypothesis rows (H0–H28), none OPEN**: 36 CONFIRMED, 2 REFUTED by design (H9a: the true-async fork does not suspend unmodified blocking calls; H14b: sqlite cannot be made async through stream hooks — hence E16 offload), 1 CONFIRMED-with-caveat (H21/E10: gRPC works, the absolute p99 target is inconclusive on this box: 7.8 ms vs tonic's 6.3 ms).
- **E1–E16 all have a V-n**; E15 and E2' closed tonight (V-26 addendum, V-27, V-28 addendum). Brief-level targets still not met in absolute terms: none. Raised targets still open: E3' (10M requests at 4 threads with streams/state/offload on — the RSS claim V-10 predates E6/E13/E14/E16), E7', E8', E9', E10', E11'.
- **Compat**: phpt 108/108 fibers, 80/80 sockets, 132/138 streams in main mode; 78 / 75 / 120 in fiber mode (12 fiber-only stream failures: 4 harness, 8 ours — listed in the V-26 addendum); Revolt DriverTest = StreamSelectDriver; Swoole shim 44/153; FrankenPHP testdata 29/4/33; Symfony + Doctrine 10 849 tests per mode with 0 new failures under chaos (V-27).
- **CI**: nextest + miri, smoke, E9, and the four E15 gates run on every push to `night-1` against `bench/results/e15-baseline.txt`.
- **Pain map**: 27 of 31 items ADDRESSED; NOT STARTED: per-endpoint budget + circuit breaker (PHP-FPM 2), in-process Table (RoadRunner 4), static glibc artifact (RoadRunner 7), allocator-level leak detector (RoadRunner 2, dev side).

## Phase A — harden what exists (1–2 weeks, main + porter)

| # | item | why / evidence | done when |
|---|---|---|---|
| A1 | The 8 "ours" fiber-mode phpt stream failures: unix-socket `stream_socket_get_name`, invalid-port / NUL-host error texts, `timed_out` meta after a read timeout (needs `stream_set_timeout` honoured on hooked streams), `fclose(STDIN)` warning | V-26 addendum | fiber streams ≥ 128/138; baseline raised with a V-n |
| A2 | ~~Real-timer path: one `tokio::spawn` per sleep/watch → a timer wheel (`DelayQueue`) inside the reactor task~~ **DEMOTED (C22)** — both acceptance numbers were already met before any work: warm per-fiber via a real `sleep(1)` is **3.82–3.94 µs** (target < 5 µs) and E1 overhead **144–146 ms** (target < 150 ms), measured quiet and re-run by main. The gap to the `sleep(0)` inline path is only **~0.3 µs and the ranges overlap**, so a timer wheel can win at most 7.5 % of the round trip. The original rationale compared V-28's 5.5–6.4 µs — which was the *`sleep(0)`* path **before** the H28 inline fix — against 4.5 µs, i.e. two different things; `sleep(1)` had never been measured directly. Revisit only if a profile shows the per-op `tokio::spawn` dominating under a load shape not tested here. | C22 baseline (JOURNAL 2026-09-16T08:15:50Z); new bench `bench/php/e2_sleep1.php` | — (acceptance already met) |
| A3 | E3' soak: 10M requests, 4 threads, streams + superglobals + pgsql pool + offload on | V-10 is 1 thread and predates E6/E13/E14/E16 | RSS within ±2% between 1M and 10M; 0 errors; watchdog silent |
| A4 | UDP and unix transports in the stream hook; `ext/sockets` (`socket_*`) parking via the same reactor watch | research 15 ranking: `Swoole\Coroutine\Socket` is the largest blocked group; 5 fiber-mode `ext/sockets` failures | Swoole shim ≥ 70/153; fiber sockets 80/80 |
| A5 | php-cli parity in the embed: `--` (script on stdin), `-r`, `-S` for tests that re-exec `PHP_BINARY` | porter notes in research 19/20: the single Symfony failure and 21 worked-around ones | `CacheWarmerAggregateTest` passes; the two fixture servers no longer pre-started |
| A6 | TLS read-ahead visible to `stream_select` (rustls buffers plaintext the fd does not show) | V-26 `has_buffered` covers plain TCP only | a `select` on an `ssl://` stream with buffered plaintext returns at once (test in `bench/php/e6_ssl.php`) |
| A7 | phpt fiber-mode harness cleanup: run-tests' timeout kills only the wrapper; spinning children survived twice tonight | V-28 (bug70198) | `scripts/ignis-php` puts the child in its own process group and kills it on exit |

## Phase B — the structural pain-map items (2–4 weeks, main; ADRs first)

| # | item | pain map | done when |
|---|---|---|---|
| B1 | Fiber budget: pool cap per thread with request queueing and a 503 past the queue | V-5 memory note (34 KB RSS per parked fiber) | 100k queued requests never exceed the configured RSS; p99 of admitted requests unchanged |
| B2 | Per-endpoint budget + circuit breaker on the connection pool and hooked transports | PHP-FPM 2 (NOT STARTED) | one slow dependency at 100% failure leaves other endpoints at ≥ 95% of their throughput (extend `bench/e11-cancel.sh`) |
| B3 | In-process Table (shared memory across threads, atomic ops, TTL) | RoadRunner 4 (NOT STARTED) | 4 threads × 1M ops/s on a 100k-row table; survives a thread respawn (ADR-0012 test) |
| B4 | MySQL and Redis as native drivers with the E14 lease shape; the offload router stays the fallback | Swoole 5, ADR-0015/0016 | E14-style bench: 200 fibers over 20 connections, LeaseError 0, reset verified |
| B5 | Allocator-level leak detector for dev (mimalloc stats per request + Zend heap delta) | RoadRunner 2 | a deliberately leaking handler is reported with a stack within 100 requests |
| B6 | Single static artifact (glibc, `-static-pie` where libphp allows) and the Docker image from `php-image.yml` | RoadRunner 7 | `ldd ignis` shows no PHP/openssl/pq dynamic deps; smoke passes on a scratch container |

## Phase C — protocol and framework depth (2–3 weeks, main + porter)

- **E9'** Temporal signals, queries, cancellation, child workflows; activity heartbeats (extends V-19).
- **E10'** gRPC client-streaming and bidi, TLS on the listener, deadline propagation to `Ignis\deadline()` (extends V-20).
- **E8'** multi-value `Set-Cookie`, streamed responses, Laravel through `symfony/runtime` (extends V-16).
- **E7'** AMPHP HTTP client/server on the hooked transports (not only timers), signals (extends V-13).
- **E11'** cancellation of offload jobs (E16) and pgsql queries (E14) on client disconnect (extends V-14/V-21/V-24).

## Phase D — productization (parallel to B/C, scribe + bencher)

- Config file (threads, offload pool, fiber budget, routing tables) replacing the env flags; `ignis serve` / `ignis run` CLI.
- `/metrics` (Prometheus) from the reactor and pools: in-flight, parked fibers, pool leases, offload queue depth, watchdog stalls, restarts.
- Graceful reload (drain + respawn per thread, opcache untouched — ADR-0012 already has the mechanism).
- Nightly perf job in CI with thresholds (E1, E2', E4 hello, E14, E16) so regressions surface without a human reading numbers.
- Docs: `examples/app.php` stays the API spec; add an operator guide (sizing = threads × fiber budget), and a migration guide from php-fpm / FrankenPHP / RoadRunner using the pain map as the index.

## Known unknowns worth a research note before committing to them

1. **PHP true-async RFC** (H8/H9b): the fork builds and a Rust scheduler provider works, but unmodified blocking calls do not suspend there either. Track the RFC; the stream/transport hook approach (ADR-0007) is the bet for 8.5/8.6.
2. **Opcache JIT with fibers under ZTS**: all numbers tonight are with opcache on and JIT off; JIT + fibers + ZTS has upstream history.
3. **Scaling past 4 threads**: E5 is 3.7–3.98× at 4; TSRM and the allocator have not been measured at 16–64 threads.
4. **Memory per parked fiber**: 34 KB (16 KiB of it Zend's fixed VM stack). B1 sets the budget; lowering the per-fiber cost needs a Zend-side change.
5. **The phpt fiber-mode "not applicable" bucket** hides the embed's differences from php-cli (stack-trace frames, `open_basedir`); A5/A7 shrink it, they do not remove it.
