# ADR-0033 — Per-thread reactor for in-request I/O: deferred, triggered by ready/wakeup share of request time

Status: deferred, with trigger. Rests on H30/V-33 (the wakeup-pair decomposition), `IGNIS_POLL_SPIN_US`
as the current mitigation (V-33).

## Context

The architecture's budget is exactly one wait point per PHP thread (`ignis_poll` on the shared
completion channel). H30/V-33 measured its cost: a reactor round trip is dominated by a fixed
cross-thread wakeup pair, **74–98 µs regardless of batch size** (93.1 µs at 1 fiber in flight, 0.58
µs at 128 — the product is constant), against a **57.3–57.5 µs** platform floor for the same two
wakeups (a bare two-thread `std::mpsc` ping-pong in Rust, same box). Over half the fixed cost is
unavoidable OS scheduling latency here; the rest is tokio's wake path plus the dispatcher.
`IGNIS_POLL_SPIN_US` (V-33) is the shipped mitigation — cuts concurrency-1 latency 96.1→30.0 µs, but
only pays when the completion lands inside the spin window: it made `GET /sleep?ms=1` *worse*
(3.68→3.84 ms), which is why it ships off by default.

## Options considered

1. `IGNIS_POLL_SPIN_US` as the permanent answer — already shipped (V-33); insufficient alone, a
   workload-dependent-sign knob, not a fix for the wakeup-pair cost (BACKLOG R-14 still open).
2. Fiber migration across threads — rejected: TSRM thread-local state (`ts_resource`, the same
   constraint ADR-0012 and ADR-0031 cite) pins a fiber's engine context to its creating thread.
3. `seccomp-notify`/eBPF — rejected: neither switches a userspace fiber; they operate below the level
   where a Zend context switch happens, so neither touches the actual bottleneck H30 found.
4. **A reactor owned by each PHP thread for in-request I/O — the change under consideration,
   deferred.** Removes the cross-thread wakeup pair for I/O that starts and completes on one thread,
   but is a second reactor architecture beside the shared one — a scope change, not a tuning knob.

## Decision

Deferred. `IGNIS_POLL_SPIN_US` stays the shipped mitigation (real per V-33, narrow per its own
finding, not promoted to default). A per-thread reactor is deferred until the trigger fires — not
built speculatively against a cost that V-33's amortization curve shows is already small at the
concurrency most benches exercise (0.58 µs/op at 128 fibers).

**Trigger:** (a) profiling a real-service workload shows >5% of request time in the ready/wakeup
phase H30/V-33 isolated, or (b) a benchmark the project cares about is measurably lost to it and
cannot be recovered by tuning `IGNIS_POLL_SPIN_US` per V-33's documented limits.

## Consequences

- Better (if triggered): removes the 74–98 µs wakeup-pair cost for I/O that never needs to leave its
  originating thread — the dominant term at low concurrency per V-33.
- Worse: a second reactor per thread is a second thing to keep consistent with the shared reactor's
  Op semantics, cancellation and offload boundary — not designed here, only triggered here.
- Affects: low-concurrency, sub-100 µs completions (research 24's local pg path improved 1.27–1.34×
  under the spin knob); does not affect millisecond-scale I/O.
- Options 2/3 are ruled out permanently under today's TSRM/fiber model, not merely deferred.

## Kill criterion

Not applicable to the deferred state; see Trigger. If built, its kill criterion is a regression in
any H30 decomposition leg or in the shared-reactor invariants (no Zend pointer crosses to tokio, PHP
never awaits a tokio future, an Op is plain data) — a future ADR's job once option 4 is designed.

## Status

deferred, trigger: >5% of real-service request time in ready/wakeup, or a benchmark lost to it
