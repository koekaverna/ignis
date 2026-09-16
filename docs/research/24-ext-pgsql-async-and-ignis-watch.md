# 24 — ext/pgsql in async mode parked on `ignis_watch`

Asked 2026-09-16 by the owner ("рассмотри путь ext-pgsql async + ignis_watch"). Probe:
`bench/php/pg_async_probe.php`. Nothing in Rust changed: the whole path is userland PHP over
the `ignis_watch` op that already exists for the Revolt driver (ADR-0008).

## The claim this falsifies

`docs/pain-map.md` item 1 says in-process C I/O that never touches php_stream — "sqlite, libpq,
curl" — is answered **not by hooks** but by the runtime-owned pool (V-21) and the offload router
(V-24). That is true for sqlite and curl. It is **false for libpq**: `ext/pgsql` exposes both the
non-blocking libpq API and the connection's own socket, so a fiber can park on the existing single
wait point with no new `Op`, no new module function and no `unsafe`.

The API is all present in our build (`--with-pgsql` is already in `scripts/build-php.sh`):
`pg_connect($dsn, PGSQL_CONNECT_ASYNC)`, `pg_connect_poll`, `pg_socket`, `pg_send_query_params`,
`pg_flush`, `pg_connection_busy`, `pg_get_result`, `pg_cancel_query`.

## Shape

```php
function pg_park($c, int $mode): void { Loop::awaitOp(\ignis_watch(\pg_socket($c), $mode)); }
// send -> flush until 1 -> drain results, parking only while the connection is busy
while (\pg_connection_busy($c)) { pg_park($c, 1); }   // <- the guard, see "correctness" below
$r = \pg_get_result($c);
```

`pg_connection_busy()` is `PQconsumeInput` + `PQisBusy`, so once it is false `PQgetResult` cannot
block. Checking it **before** arming the watch is what keeps us from parking on an fd that already
delivered everything and will never fire again — the same rule A4 needed for `ext/sockets`
(`can_block()`): *delegating is always semantically correct; parking wrongly is a hang.*

## Numbers

Machine: this box (4 vCPU), quiet. PHP 8.5.10 ZTS. Server: `postgres:17-alpine` in docker,
reached over a bind-mounted unix socket (`-v /tmp/ignis-pgsock:/var/run/postgresql`, DSN
`host=/tmp/ignis-pgsock`). TCP was not usable: this docker daemon accepted `-p` into
`HostConfig.PortBindings` but left `NetworkSettings.Ports` empty, so no published port and no
bridge IP was reachable from the host shell; a sync `pg_connect` to it hangs rather than refuses.

**Parking works** — 20 fibers, one connection each, `SELECT pg_sleep(0.2)`, one PHP thread:

```
PG_DSN="host=/tmp/ignis-pgsock user=ignis password=ignis dbname=ignis" \
  ./target/release/ignis bench/php/pg_async_probe.php concurrency
concurrency: 20 fibers x 200 ms  async=203.7 ms  sync-control=4031.4 ms  speedup=19.8x
```

The control is the stock blocking `pg_query_params()` over the same connections in the same
process: 4031.4 ms, i.e. fully serialized. Ideal for the async leg is 200 ms; we pay 3.7 ms.

**Throughput against the runtime-owned pool (ADR-0015 / V-21)**, same box, same server, same
shape — 20 backend connections, 2000 `SELECT 1`, one PHP thread, 3 reps each:

| path | q/s (3 reps) | µs/query |
|---|---|---|
| `ext/pgsql` async + `ignis_watch` | 21 570 / 22 032 / 21 298 | 45.4–47.0 |
| tokio pool, `Pool::query()` (V-21 path) | 13 386 / 12 538 / 11 468 | 74.7–87.2 |

Both legs return decoded PHP arrays (`pg_fetch_all(PGSQL_ASSOC)` against the pool's JSON hop), so
this is like for like. A first pass that fetched the result handle and discarded the rows measured
19 215–20 033 q/s — *slower*, i.e. inside the noise: decoding one integer column is free, and the
earlier run was simply the worse sample.

**1.6–1.9× in favour of the userland path**, with zero Rust. The pool's extra cost is structural:
ADR-0015 §4 marshals parameters and rows as JSON across the boundary; the libpq path decodes in
place.

**Serial latency** (one connection, no concurrency, 2000 × `SELECT 1`):

| path | µs/query |
|---|---|
| stock blocking `ext/pgsql` | 116.6 |
| async + `ignis_watch` | 237.0 |
| tokio pool, sequential on one lease | 271.9 |

Exactly **1 park per query**; caching the `pg_socket()` stream instead of creating one per park
changes nothing (236.4 vs 231.2 µs — noise). A bare `ignis_watch` on an **already ready** fd costs
**14.4 µs**, so the ~120 µs serial gap is the real cross-thread reactor round trip (submit →
reactor task → completion channel → `ignis_poll` wakeup). It is latency, not work: at 20 fibers it
amortizes to 50 µs/query. A single-fiber workload is the worst case for this path.

## What it buys

- Everything ADR-0015 §5 lists as out of scope comes for free, because it is libpq: `COPY`,
  `LISTEN`/`NOTIFY`, TLS to the server, arrays/hstore/numeric decoded by libpq rather than JSON,
  prepared statements, cursors.
- The real prize is not the API but the hook: `pg_query()` and friends can be replaced at MINIT
  with the A4 **park-then-delegate** pattern (send → park → `pg_get_result`), which makes
  **unmodified** pgsql application code async. The runtime-owned pool can never do that — it
  requires the application to be rewritten against `Ignis\Pg`.

## What it costs, and what is still unknown

1. **Per-thread, not process-wide.** A `PgSql\Connection` is a PHP resource in one thread's TSRM
   context, so a pool built this way is per-thread: N threads × M connections against the server's
   `max_connections`. ADR-0015's pool is shared by every thread. This is the one real architectural
   regression.
2. **Cancellation is unwritten.** On fiber cancellation the in-flight query must be cancelled
   (`pg_cancel_query`) or the connection is left mid-result and is poisoned. ADR-0009 shape applies;
   not implemented, not measured.
3. **TLS to the server is untested** — libpq drives the handshake inside `PQconnectPoll`, which
   should be fine, but readability of the socket is not the same as a complete TLS record being
   available. This is the A6 failure mode; it needs its own test before any claim.
4. **`pg_socket()` lifetime**: 2000 queries each creating a fresh stream from the same fd did not
   break the connection, so the stream does not close libpq's fd. Empirical, not from the source.

## Recommendation

Worth an ADR, not a rewrite. The two paths are complementary, not competing: keep the
runtime-owned pool as the cross-thread, JSON-typed, framework-facing API, and add the libpq path
for (a) raw `ext/pgsql` compatibility and (b) the hooked `pg_query()` that needs no application
change. Proposed kill criterion for that ADR: **under concurrency at equal connection count the
hooked path stays within 1.2× of stock blocking `ext/pgsql`, and a hook-off control serializes**
— the second half is not optional, it is what makes the first half mean anything.

These numbers are research, not a V-n: they become one when an ADR gives them an expectation.
