# ADR-0026 — Timer contract

Status: **accepted** (main agent, owner ADR sweep 2026-09-17). Affects E1, E2, E2', A2.

## Context

Every timer is a tokio timer on the runtime (`Op::Sleep`), completing through the reactor's
completion channel. `Ignis\sleep(0)` completes inline in the dispatcher with no timer task (H28,
V-28 addendum: 4.5–4.7 µs per warm fiber round trip with the superglobals observer on). A real
`sleep(1)` costs 3.82–3.94 µs per warm fiber (C22 baseline, ROADMAP A2), within 0.3 µs of the
inline path — which is why A2 (a timer wheel inside the reactor) was demoted by measurement, not
by argument. 10,000 fibers × 1,000 ms finish in 1,144–1,176 ms on this box (V-2, V-28, V-45
addendum). Each completion carries `late_us`, the timer's lateness, in `Outcome::Slept`.

## Options considered

A timer wheel of our own in the reactor (A2 — demoted: the gain is bounded at ~7.5 % of a round
trip and the ranges overlap); busy-wait timers for sub-millisecond sleeps (rejected: a PHP
thread has one wait point, and spinning it starves every other fiber); `timerfd` per fiber
(rejected: an fd per sleep at 10k fibers, for no measured gain over tokio's wheel).

## Decision

1. Timers are tokio's: a 1 ms wheel, one task per real sleep, cancellable like a watch
   (`Op::CancelWatch`, E6'').
2. `sleep(0)` is a yield to the loop and never touches the wheel (H28).
3. **Precision statement for users:** not for sub-millisecond scheduling. A `sleep(n)` completes
   at the wheel's next tick at or after `n`; `late_us` is available for anyone who needs to know
   by how much. The spin knob (`IGNIS_POLL_SPIN_US`, V-33) shortens the *wakeup*, not the timer.
4. Rate: 131k timer completions per second on one PHP thread were sustained in V-10
   (`/sleep?ms=1` at 500 connections); this is not the bottleneck.

## Consequences

Better: no timer code of our own to keep correct; cancellation and timers share one mechanism.
Worse: a caller who needs 100 µs precision cannot get it here — say so rather than approximate.
Affects E1, E2, E7 (Revolt timers ride the same wheel, V-13).

## Kill criterion

A workload where the 1 ms granularity, not the wakeup, dominates request latency at p99 — then
A2 returns with that workload as its benchmark.
