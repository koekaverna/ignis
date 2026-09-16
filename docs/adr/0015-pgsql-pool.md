# ADR-0015 — Runtime-owned PostgreSQL pool with per-fiber leases

Status: accepted (Cycle 16, 2026-09-16; V-21). Amended 2026-09-16 (V-42/V-43/V-44): see the end. Amendment: the reset is the documented expansion of `DISCARD ALL` minus `DEALLOCATE ALL`/`DISCARD PLANS`, in one round trip, so the per-connection prepared-statement cache survives; `ignis_pg_acquire` returns the lease without a reactor hop when a connection is idle. Affects pain-map items: RoadRunner 8 (no pool, pconnect leaks transactions), Swoole 1 (state discipline pushed to the developer), Swoole 7 / FrankenPHP 6 ("pools survive" a thread restart), Swoole 5 (native drivers instead of hooks). Depends on ADR-0007 (parking), ADR-0009 (cancellation), ADR-0013 (`Op::Custom`).

## Decision

1. PostgreSQL connections are owned by the Rust runtime (`tokio-postgres`), never by PHP. A pool is process-wide (shared by all PHP threads), created with `ignis_pg_open(dsn, max)`.
2. A **lease** is the unit PHP holds: one connection, one fiber, recorded in `Ignis\Scope`. `Pool::query()` leases for a single statement; `Pool::transaction(fn)` leases for the closure. Acquiring a second lease from the same pool in a fiber that already holds one throws `Ignis\Pg\LeaseError` immediately.
3. Returning a lease always runs `ROLLBACK; DISCARD ALL` on the runtime side before the connection becomes idle; a failed reset closes the connection.
4. Parameters and rows cross the boundary as JSON; bindings are chosen from the prepared statement's parameter types; unsupported types are errors, not strings.
5. Not in scope: prepared-statement caching across leases, `COPY`, `LISTEN/NOTIFY`, TLS to the server, MySQL/Redis (same shape, separate ADRs).

## Consequences

- No blocking call in the PHP thread for database work; a slow query costs a parked fiber, not a thread.
- `pconnect`-style leaks are impossible by construction: the request fiber's `finally` releases its leases, and the reset wipes session state.
- The PHP API is not PDO. Framework adapters (Doctrine DBAL driver) are a follow-up; the prototype exposes `query/exec/transaction`.

## Amendment (2026-09-16, M4-1 / M4-11 / M4-12)

1. **"Process-wide" was true of the Rust object and false of the deployment** (V-43): every worker
   thread runs the script, every `ignis_pg_open` minted a new pool, so an application got
   `threads × max` connections. `open` now dedupes by DSN; the first opener's `max` applies and a
   differing later `max` is logged at warn.
2. **A thread dying with a lease leaked its permit for the life of the process** (V-42). A lease now
   records the acquiring reactor; on thread unregister every lease it held is released with the
   reset on the runtime side, so the dying thread blocks nothing. Consequence for §2 ("a lease is
   the unit PHP holds"): the runtime holds a second reference for exactly this case.
3. **Hold time is visible** (V-44): `oldest_lease_ms` and `leases_over_warn` in `ignis_pg_stats()`,
   and a warn line at release past `IGNIS_PG_LEASE_WARN_MS` (default 5000).
