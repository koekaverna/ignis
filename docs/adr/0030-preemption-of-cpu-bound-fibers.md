# ADR-0030 — Preemption of CPU-bound fibers: a watchdog-escalated, opt-in interrupt at an opcode boundary

Status: proposed. Rests on V-17 (the watchdog and the spin test), pain-map Swoole 4 (CPU-heavy work
starves others).

## Context

V-17's `/spin?s=5` test showed today's answer: a CPU-bound fiber stalls one thread of N
(least-inflight dispatch routes new work elsewhere) and the watchdog reports it stalled after 1 s
(`stalled=1`, cleared after). That does not make the stalled thread's *other* fibers progress — a
cooperative scheduler cannot resume a sibling until the running one yields, and a fiber that never
awaits an op never yields. Pain-map Swoole 4 names this directly.

## Options considered

1. Nothing beyond V-17's dispatch mitigation — viable today but leaves the stalled thread's own
   in-flight fibers starved indefinitely if the CPU-bound fiber never yields.
2. Signals into Zend (`SIGALRM` + handler) — rejected: PHP's own signal contract defers to the next
   safe point anyway (same mechanism as option 4, slower path), and conflicts with
   `--disable-zend-signals` (used for the true-async fork, ADR-0003).
3. Preempt mid-opcode — rejected: unsound. Engine-internal state (operand stack, in-progress COW, a
   lock held by an internal function) is not guaranteed consistent between arbitrary machine
   instructions inside an opcode handler, only between opcodes.
4. **Timer → `EG(vm_interrupt)` → `zend_fiber_suspend` at an opcode boundary, watchdog-escalated,
   opt-in per route — chosen.** `EG(vm_interrupt)` is the engine's existing interrupt flag (already
   used for `pcntl` dispatch and `max_execution_time`), checked at every opcode boundary — a
   guaranteed-safe suspension point, reused rather than a new unsafe one invented.

## Decision

A route opts in (`ignis.toml` per-route or a handler wrapper). When the existing watchdog (ADR-0012,
V-17) flags a thread stalled past its threshold (≥ 1 s, configurable), it sets `EG(vm_interrupt)` for
that fiber instead of only logging. The next opcode boundary suspends it via `zend_fiber_suspend`;
the scheduler resumes a waiting fiber; the preempted one is re-queued like any suspended fiber.
Never the default — a route that does not opt in behaves exactly as today.

## Consequences

- Better: an opted-in CPU-bound fiber stops starving siblings past the watchdog threshold, closing
  the gap V-17's dispatch-level mitigation leaves open.
- Better: reuses an engine-provided, opcode-boundary-safe mechanism instead of an unsafe mid-opcode
  switch or a slower signal path.
- Worse: this does not change PHP-level atomicity — a handler assuming exclusivity between two
  points in its own code can be broken by preemption the same way a voluntary yield already can.
- Worse: fires rarely (past-threshold, opted-in only), so it is hard to exercise in ordinary testing
  — the kill criterion below has to carry that weight.
- Affects E12 (watchdog, V-17) and any E15 framework suite (V-23, V-27) run with preemption enabled
  on its routes.

## Kill criterion

Any E15-family suite failure (V-23, V-27) when re-run with preemption enabled on its routes, where
the failure traces to a mid-request switch (a mutation observed half-applied, a resource left
locked). Never shipped as a default regardless, by decision 1.

## Status

proposed
