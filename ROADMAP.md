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
| A1 | **DONE (C22)** — all three defects the triage classified as 'ours' are fixed: `stream_set_timeout()` honoured (it was accepted and ignored, so a `fread()` with no data parked for ever), port literals above 65535 now wrap like `atoi` into an unsigned short, and connect failures fill `$errstr`/`$errno` including stock's NUL-host wording. Fiber streams **122/17 → 124/15**, main 130-131/8-9, zero new failures. The stated target (≥128/138) is not comparable: it was set against the old box's 120/138 while this box runs 160 tests. The remaining 15 are the harness artefacts and CLI-only semantics the triage classified separately.  | V-26 addendum | fiber streams ≥ 128/138; baseline raised with a V-n |
| A2 | ~~Real-timer path: one `tokio::spawn` per sleep/watch → a timer wheel (`DelayQueue`) inside the reactor task~~ **DEMOTED (C22)** — both acceptance numbers were already met before any work: warm per-fiber via a real `sleep(1)` is **3.82–3.94 µs** (target < 5 µs) and E1 overhead **144–146 ms** (target < 150 ms), measured quiet and re-run by main. The gap to the `sleep(0)` inline path is only **~0.3 µs and the ranges overlap**, so a timer wheel can win at most 7.5 % of the round trip. The original rationale compared V-28's 5.5–6.4 µs — which was the *`sleep(0)`* path **before** the H28 inline fix — against 4.5 µs, i.e. two different things; `sleep(1)` had never been measured directly. Revisit only if a profile shows the per-op `tokio::spawn` dominating under a load shape not tested here. | C22 baseline (JOURNAL 2026-09-16T08:15:50Z); new bench `bench/php/e2_sleep1.php` | — (acceptance already met) |
| A3 | **DONE (2026-09-16)** — re-run by main after the owner restated the criterion: **10,266,805 requests**, RSS past 5M oscillating in a 62.5–65.8 MB band with no trend, 0 restarts, 0 stalled, 0 non-2xx (V-35). The pgsql leg still cannot run here (no PostgreSQL server).  | V-10 is 1 thread and predates E6/E13/E14/E16 | **no monotonic trend past 5M** (owner decision 2026-09-16, replacing "RSS within ±2 % between 1M and 10M", which asked two single points to agree within ±2 % while the quantity's own band between adjacent checkpoints is ±10–12 % — unsatisfiable on healthy memory); 0 errors; watchdog silent |
| A4 | **DONE (C22)** — ADR-0018 + V-29. Nine blocking `ext/sockets` functions park the fiber (20 concurrent 200 ms reads on one thread: 249-266 ms; control stalls), `unix://` added to the hooked factory (10 concurrent: 206 ms). Fiber `ext/sockets` phpt **85/7 → 86/6**. Overhead kill criterion breached and re-proposed, see the ADR addendum. `socket_connect`, `socket_addrinfo_*`, `udp`/`udg` explicitly out of scope with reasons.  | research 15 ranking: `Swoole\Coroutine\Socket` is the largest blocked group; 5 fiber-mode `ext/sockets` failures | Swoole shim ≥ 70/153; fiber sockets 80/80 |
| A5 | **DONE (C22)** — `-r` and `--` (script on stdin) in the embed; exit codes match the stock CLI on all six cases; `PHP_BINARY -r` and `PHP_BINARY --` re-exec verified. `-S` deliberately not implemented. The Symfony acceptance test named here cannot run on this box (no composer vendor tree).  | porter notes in research 19/20: the single Symfony failure and 21 worked-around ones | `CacheWarmerAggregateTest` passes; the two fixture servers no longer pre-started |
| A6 | **REPRODUCED, DIAGNOSED, NOT FIXED (C22)** — research 23 + `bench/php/a6_tls_select.php`. Needs three conditions at once to show (body larger than one `op_read`, php_stream's own buffer drained first, peer holding the connection open), which is why it looks like it works otherwise. The obvious fix (draining rustls into `Sock.pending`) was tried and reverted: `hooked_select` answers via the ORIGINAL select, which cannot see that buffer. The sound fix is an eventfd handed out by `op_cast` — larger than a Phase A item.  | V-26 `has_buffered` covers plain TCP only | a `select` on an `ssl://` stream with buffered plaintext returns at once (test in `bench/php/e6_ssl.php`) |
| A7 | **DONE (C22)** — and the wording here was wrong: `run-tests.php` kills the `sh -c` one level above the wrapper, so neither a process group nor a bare `exec` prevents the orphan, and an EXIT trap cannot run under SIGKILL. The binary is given a hard ceiling on its own life instead (`exec timeout -s KILL`). Validated: 2 orphans immediately after, 0 after the ceiling.  | V-28 (bug70198) | `scripts/ignis-php` puts the child in its own process group and kills it on exit |

## Phase B — the structural pain-map items (2–4 weeks, main; ADRs first)

| # | item | pain map | done when |
|---|---|---|---|
| B1 | **DONE for admission, NOT for the RSS bound (2026-09-16, ADR-0019, V-37)** — `IGNIS_FIBER_BUDGET` caps admitted request fibers, waiting requests are held as data in an O(1) FIFO, `IGNIS_QUEUE_DEPTH` sheds with 503 + `retry-after`, `IGNIS_BUDGET_EXEMPT` keeps health endpoints answerable on a saturated server. p99 unchanged (1.79/1.82/1.90 ms against 1.85/1.89/1.78). **The RSS half of the acceptance is not met and cannot be by this change**: a held request costs 47.7 kB with a fiber and 33.0 kB queued, so the budget removes the 14.7 kB fiber and not the ~33 kB connection — 30 % less RSS, not a bound. **Follow-up needed: cap concurrent connections at the listener.** | V-5; V-37 | ~~100k queued requests never exceed the configured RSS~~ (needs the connection cap); p99 of admitted requests unchanged — **met** |
| B2 | Per-endpoint budget + circuit breaker on the connection pool and hooked transports | PHP-FPM 2 (NOT STARTED) | one slow dependency at 100% failure leaves other endpoints at ≥ 95% of their throughput (extend `bench/e11-cancel.sh`) |
| B3 | In-process Table (shared memory across threads, atomic ops, TTL) | RoadRunner 4 (NOT STARTED) | 4 threads × 1M ops/s on a 100k-row table; survives a thread respawn (ADR-0012 test) |
| B4 | MySQL and Redis as native drivers with the E14 lease shape; the offload router stays the fallback | Swoole 5, ADR-0015/0016 | E14-style bench: 200 fibers over 20 connections, LeaseError 0, reset verified |
| B5 | Allocator-level leak detector for dev (mimalloc stats per request + Zend heap delta) | RoadRunner 2 | a deliberately leaking handler is reported with a stack within 100 requests |
| B6 | Single static artifact (glibc, `-static-pie` where libphp allows) and the Docker image from `php-image.yml` | RoadRunner 7 | `ldd ignis` shows no PHP/openssl/pq dynamic deps; smoke passes on a scratch container |
| B7 | **A6: TLS read-ahead invisible to `stream_select()`** — give the stream a readiness fd it owns (eventfd or self-pipe) and hand it out from `op_cast`, with the reactor signalling it whenever rustls has buffered plaintext | research 23; V-26's `has_buffered` covers plain TCP only | a `select` on an `ssl://` stream with buffered plaintext returns at once (`bench/php/a6_tls_select.php` verdict PASS), and E6''/V-26 still holds — `op_cast` changes what it hands out, so that is the regression to watch |
| B8 | **Cap concurrent connections at the listener** — the missing half of B1's acceptance. A held connection costs ~33 kB (hyper buffers + kernel socket) whatever the fiber budget does, so RSS is bounded by connections, not by fibers. Refuse or delay `accept` past the cap rather than admitting a connection we cannot afford. | V-37; pain map "pool exhaustion at low CPU" | RSS stays under a configured ceiling while 100k clients hold connections, and p99 of accepted requests is unchanged — the B1 acceptance that a fiber budget could not meet |

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
