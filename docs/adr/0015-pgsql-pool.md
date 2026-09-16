# ADR-0015 — Runtime-owned PostgreSQL pool with per-fiber leases

Status: accepted (Cycle 16, 2026-09-16; V-21). Amendment: the reset is the documented expansion of `DISCARD ALL` minus `DEALLOCATE ALL`/`DISCARD PLANS`, in one round trip, so the per-connection prepared-statement cache survives; `ignis_pg_acquire` returns the lease without a reactor hop when a connection is idle. Affects pain-map items: RoadRunner 8 (no pool, pconnect leaks transactions), Swoole 1 (state discipline pushed to the developer), Swoole 7 / FrankenPHP 6 ("pools survive" a thread restart), Swoole 5 (native drivers instead of hooks). Depends on ADR-0007 (parking), ADR-0009 (cancellation), ADR-0013 (`Op::Custom`).

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
