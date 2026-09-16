# Research 14 — runtime-owned PostgreSQL pool (E14)

Date: 2026-09-16T02:25Z (Cycle 16). Sources: `tokio-postgres 0.7` docs and source in the cargo registry (`Client`, `Connection`, `Statement::params()`, `Row`, `types::Type`, `ToSql`/`FromSql`), the pain map (RoadRunner 8, "no connection pool; pconnect leaks transactions"; Swoole 1, "state discipline pushed to the developer"; FrankenPHP 3), V-12 (PDO sqlite refuted for stream hooks: libsqlite3/libpq do their own I/O inside the calling thread), ADR-0013's `Op::Custom` seam.

## Why a native driver and not a hook

- `pdo_pgsql`/`pgsql` call libpq, which owns its socket and blocks in `poll()` inside the PHP thread; no stream layer to intercept (same finding as sqlite in V-12). libpq has an async API (`PQsendQuery`/`PQisBusy`/`PQsocket`) that a C shim could drive from the reactor, but that means re-implementing PDO's statement layer on top of it.
- A tokio-postgres client on the runtime side is the shape every other item in the pain map asks for: connections belong to the runtime, the PHP thread never blocks, the fiber parks on one op per query (ADR-0007), cancellation and deadlines apply (ADR-0009), and the pool survives a thread restart (ADR-0012, "pools survive").

## Semantics the brief fixes (E14)

| rule | mechanism |
|---|---|
| pool owned by the runtime | `static POOLS` in Rust; `ignis_pg_open(dsn, max)` returns a pool id; connections are created lazily up to `max` and kept idle |
| lease per fiber | `Ignis\Pg\Pool::acquire()` records the lease in the fiber's `Ignis\Scope`; `Pool::query()` is acquire → query → release |
| a transaction pins the lease | `Pool::transaction(fn)` (or `begin()`) holds the lease until `commit()`/`rollback()`; every query inside the closure runs on the same connection |
| session reset on return | the release op runs `DISCARD ALL` on the tokio side before the connection goes back to idle; a connection whose reset fails is closed, not reused |
| a second acquire from the same pool inside one fiber is an error, not a wait | `Ignis\Pg\LeaseError` thrown in PHP before any op is submitted (the only way to deadlock a fiber against itself is to wait on a lease it already holds) |
| fiber ends abnormally while holding a lease | the request fiber's `finally` (dispatchRequest) releases every lease recorded in its Scope; a lease released with `reset = true` while a transaction is open gets `ROLLBACK` from `DISCARD ALL`'s preceding `ROLLBACK`-on-abort semantics: we send `ROLLBACK; DISCARD ALL` explicitly |

## Wire shape

- `ignis_pg_open(string $dsn, int $max): int` — sync, no I/O.
- `ignis_pg_acquire(int $pool): int` — op; payload `{"lease": id}`. Waits (a tokio semaphore permit) when all `max` connections are leased; connects when idle is empty and the count is below `max`.
- `ignis_pg_query(int $lease, string $sql, string $paramsJson): int` — op; payload `{"rows": [{col: value}], "affected": n}`; errors as `['kind' => 'error', 'message' => 'pg: …']`.
- `ignis_pg_release(int $lease, bool $reset): int` — op; payload `1` when the connection is back in the pool.
- Values: parameters arrive as a JSON array; the prepared statement's `params()` types decide the Rust binding (int2/4/8, float4/8, numeric-as-text, bool, text/varchar/name/bpchar/unknown, json/jsonb as raw JSON, uuid/timestamps/dates as text through an explicit `::text` cast hint documented for users). Row values map the same set back to JSON; `bytea` is base64; other types come back as an error naming the column and OID so nothing is silently stringified.

## Expectation for V-21 (H23)

- 200 concurrent fibers each running `SELECT pg_sleep(0.1)` through a pool of 20 complete in ≈ 1.0 s on one PHP thread (10 rounds of 20), not 20 s; the same with a pool of 200 in ≈ 0.1 s.
- A `Pool::transaction()` that runs two statements sees both on the same backend pid (`SELECT pg_backend_pid()`); a second `acquire()` inside it throws `LeaseError` in < 1 µs (no op submitted).
- A `SET search_path` inside a lease is not visible to the next lease of the same connection (`DISCARD ALL` works).
- Per-query cost: `SELECT 1` round trip through the pool vs `pdo_pgsql` on the same box (needs libpq in the PHP build: `--with-pdo-pgsql`, rebuilt after the E15 suites finish since they run against the current libphp).
