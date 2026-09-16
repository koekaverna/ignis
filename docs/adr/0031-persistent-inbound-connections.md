# ADR-0031 — Persistent inbound connections: WebSocket/SSE as one fiber per connection, respawn drops it

Status: proposed. Rests on ADR-0002 (one respond per request), ADR-0012 (respawn), V-17.

## Context

Every inbound-connection model today (ADR-0002) is request/response: one `Ignis\serve` fiber per
request, one `ignis_respond` when it returns (V-5, V-6). A WebSocket/SSE connection is long-lived and
the server writes to it repeatedly with no natural "response" moment — no V-n exists for this today.
ADR-0012's supervisor already establishes what happens to in-flight work when a thread dies: V-17
measured a fatal killing one of four worker threads with hello uninterrupted (134k req/s during),
and the requests in flight on the dying thread lost (closed connection, http 000) rather than
answered. A persistent connection is in-flight for its whole lifetime, so it inherits that gap for
as long as it stays open.

## Options considered

1. A dedicated tokio actor per connection, PHP notified by callback — rejected: reintroduces a
   second wait point, forbidden by the reactor's "exactly one wait point per thread" invariant.
2. **Fiber per connection, using the existing `Op` completion channel for inbound and outbound —
   chosen.** The connection's fiber never returns until close; reads and writes go through the same
   one-wait-point mechanism (`ignis_poll`) every other op uses.
3. Unbounded per-connection outbound buffer — rejected: the same unbounded-queue hazard ADR-0019
   exists to prevent for requests, applied per connection instead.

## Decision

1. A WebSocket/SSE connection is one fiber, opened at the handshake, running until close; no new
   wait point — frames and send-completions arrive on the existing reactor completion channel.
2. Ping/pong and backpressure live in Rust: a bounded per-connection channel on the tokio side holds
   outbound frames; a slow reader gets backpressure (writes stop past the bound) rather than
   unbounded growth — the ADR-0019 shape, per connection.
3. **Connections on a respawned thread are dropped.** ADR-0012's respawn (V-17: in-flight requests
   lost, not migrated) applies unchanged — there is no mechanism to hand a live connection's fiber
   state to a different OS thread (TSRM is thread-local, the same constraint ADR-0033 cites against
   fiber migration). Clients must reconnect; documented as the reason in the close code and the
   operator guide, not hidden as a generic disconnect.

## Consequences

- Better: no new wait point, no change to the reactor's one-channel invariant — a persistent
  connection is architecturally the same shape as a slow HTTP response, just longer.
- Better: backpressure/keepalive live in Rust, reusing the ADR-0019 bounded-queue shape.
- Worse: a thread respawn (fatal, watchdog stall, future `SIGHUP` reload) closes every persistent
  connection on that thread — a reconnect storm for a chat/notification app, unmeasured (no V-n).
- Worse: composes with BACKLOG M4-3 (connection cap, B8) once that ships; a held connection costs
  ~33 kB indefinitely (V-37) rather than for one request, so M4-3's ceiling matters more here.

## Kill criterion / Trigger

Trigger for revisiting "drop on respawn": a user need for connection migration across a respawn
surfaces — revisit alongside ADR-0033, since both hinge on the same TSRM thread-locality constraint.

## Status

proposed
