# ADR-0015 — Runtime-owned PostgreSQL pool with per-fiber leases

Status: **closed and removed, 2026-09-18** (owner decision; the code is deleted, this record is what
is left of it). Accepted Cycle 16, 2026-09-16 (V-21), amended 2026-09-16 (V-42/V-43/V-44). Affected
pain-map items: RoadRunner 8 (no pool, pconnect leaks transactions), Swoole 1 (state discipline
pushed to the developer), Swoole 7 / FrankenPHP 6 ("pools survive" a thread restart), Swoole 5
(native drivers instead of hooks). Depended on ADR-0007 (parking), ADR-0009 (cancellation), ADR-0013
(`Op::Custom`).

## Why it was removed

The decision below was right about the problem and wrong about where to solve it. Three things
happened between it and 2026-09-18:

1. **Universal park made the ordinary driver asynchronous** (V-45, V-59): `pdo_pgsql` suspends the
   fiber on its own socket with no runtime client involved — 100 concurrent 200 ms queries on one
   thread in 303 ms.
2. **The pool moved to userland where the framework already is** (V-85 addenda 2–4): `ignis/doctrine`
   gives every fiber its own connection and, optionally, a per-thread pool with a warm start, a
   bounded wait and the same `DISCARD ALL` expansion this ADR introduced. Doctrine speaks to it
   without an adapter, because it is a DBAL driver middleware rather than a second API.
3. **The speed argument was measured and lost** (V-86): parked `pdo_pgsql` prepared is 264 µs per
   query against this pool's 349 µs on a held lease, and 74–86 µs against 97–100 µs at twenty
   concurrent fibers. The native client was paying for the crossing — zif, op, tokio, completion
   channel — where the parked driver simply waits on its socket.

What this design still had that the userland pool does not: one pool for the whole process rather
than one per thread (`threads × size` connections), and lease age, an acquire timeout and a circuit
breaker (`S-POOL-LEASE-AGE` carries those forward). They were not enough to keep 697 lines of Rust,
27 registered functions and a PHP package alive for a path no framework used. Removing it took
5.6 MB off the binary (39.1 MB → 33.5 MB) with `tokio-postgres` and its dependencies.

The decision as it stood follows, unedited, because the shape it describes — the runtime owning a
resource and PHP holding a lease — is the one thing here worth remembering.

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

## Addendum 2 (owner ADR sweep, 2026-09-17) — pool rules, built or not

**Status: accepted** (main agent) for the rules; each is marked.

| rule | status |
|---|---|
| A nested `acquire` from the same fiber **inside a transaction** returns the pinned connection (transaction-aware pool) | **unbuilt** — today a second acquire in a fiber is `LeaseError` in every case (V-21: 38 µs, 0 ops). This rule replaces the error *for that case only*; a genuinely second lease outside a transaction stays an error |
| `idle_timeout` strictly below the upstream's and the load balancer's idle timeout | **unbuilt** — no idle timeout on pooled connections today |
| Jittered `max_lifetime` | **unbuilt** |
| DNS re-resolved on reconnect | **by construction, unmeasured** — tokio-postgres connects from the DSN each time a connection is created, so a reconnect resolves again; no test pins it |
| Transparent retry only outside transactions | **unbuilt** — no retry today; a failed reset closes the connection (§3) |
| Drain on shutdown with a deadline | **unbuilt** — BACKLOG M4-5 |
| For HTTP pools: a connection returns to the pool when the response object is dropped, not only when read to EOF | **not applicable yet** — there is no runtime-owned HTTP client pool; hooked streams are per call (ADR-0007) |
| Process-wide pool per DSN | **built** (V-43 addendum, M4-12) |
| Leases survive a thread death | **built** (V-42 addendum, M4-11) |
| Hold-time visible and logged | **built** (V-44, M4-1) |
| Lease per fiber, transaction pins it, reset on return | **built** (V-21) |

**Options rejected.** PHP-owned connections with `pconnect` semantics (pain-map RoadRunner 8:
leaked transactions); a pool per thread (V-43 showed what that costs: `threads × max`).

**Consequences.** Better: the transaction-aware acquire removes the most common `LeaseError`
users will hit (a repository called inside a transaction). Worse: until `idle_timeout` and
`max_lifetime` exist, a connection can outlive the server's or balancer's idle window and fail on
first use after a quiet period — a known gap, not a surprise. Affects E14, B4, M4-2.

**Trigger for the unbuilt rows.** The first application deployed behind a load balancer with an
idle timeout, or the first `LeaseError` report from inside a transaction — whichever comes first.
