# ADR-0034 — GC and destructors: loop-scheduled collection, plus an unresolved fiber-suspend-in-destructor policy

Status: proposed. Rests on pain-map "Engine-level 1", ADR-0003/V-7's note (the true-async fork's ABI
does not cover I/O — H9a REFUTED by inspection), BACKLOG M4-10 and R-7.

## Context

Pain-map "Engine-level 1" names the hazard: a `__destruct` inside `gc_collect_cycles()` could call
`Fiber::suspend()`. Zend already calls `zend_fiber_switch_block()` around GC and dead-fiber
destructors, but Ignis must never resume a fiber from inside a destructor and must treat a
blocked-switch `FiberError` as a scheduler bug, not a user error. Marked **DESIGNED, not validated**:
on backend (b) the engine runs GC destructors on a dedicated GC coroutine via `defer` microtasks
(`zend_gc.c:2053-2088`); on backend (a) `Ignis\Loop` simply never resumes from inside a destructor.
V-7 only confirms backend (b)'s ABI doesn't cover I/O at all (no code path consults `ZEND_ASYNC_*`
for stream I/O) — it validates nothing about GC-destructor ordering on either backend. Separately,
V-11's addendum found `zend_fiber_object_gc` at 2.9% of profiled samples when the cycle collector's
root buffer filled mid-request and walked every pooled fiber — the finding behind `IGNIS_LOOP_GC`
(Cycle 19): GC at the loop's idle point, off the request path. Implemented, but with no V-n of its
own (BACKLOG M4-10, open).

## Options considered

GC scheduling: inside the request path (today's implicit default without the flag) vs. at the loop's
idle point (`IGNIS_LOOP_GC=1`, preferred by V-11's addendum and pain-map RoadRunner-2) — unmeasured
either way, so no result is asserted here.

Destructor suspension, left open deliberately:
1. **Forbid.** Treat `Fiber::suspend()` from inside a GC-triggered destructor as a scheduler bug —
   catch the `FiberError` (or the condition before it), surface an internal error, never a silent
   hang. Matches the pain-map wording verbatim.
2. **Run destructors on a dedicated fiber**, mirroring backend (b)'s `defer` microtask design: a
   suspend inside one is a normal suspend of that fiber, not a re-entrant suspend of the interrupted
   one. Costs a new scheduler concept not in `php/packages/runtime/src/ignis.php` today.

## Decision

1. **GC scheduling**: loop-scheduled GC (Cycle 19) is the shipped mechanism and stays; measuring it
   is BACKLOG M4-10, open — no V-n exists, none manufactured here.
2. **Destructor suspension**: recorded, not decided. Neither option is recommended. The choice is
   deferred to whichever agent implements R-7's test (a destructor inside GC calling
   `Fiber::suspend()`, checking the loop survives) — that test is a precondition for picking an
   option, not a follow-up to it.

## Consequences

- Better (once M4-10 lands): request-path latency stops paying for a GC pass mid-request — the fix
  V-11's addendum called for, magnitude unmeasured.
- Worse: the destructor-suspension hazard is exactly as unresolved as the pain-map already says —
  this ADR adds no new safety, only names the two options precisely enough for R-7 to decide.
- Affects E13/E13' (V-11) and any long-running worker-mode deployment where GC pressure accumulates
  (V-10's 4.6M-request soak did not specifically probe destructor-triggered suspension).
- Not measured: `IGNIS_LOOP_GC`'s p99/RSS effect (M4-10); the destructor-ordering guarantee (R-7).
  Pain-map "Engine-level 1" stays NOT VALIDATED after this ADR.

## Kill criterion

Not applicable to decision 1 (shipped; M4-10 measures it). For decision 2: no result exists to kill
until R-7's test names which option was chosen and whether it held.

## Status

proposed
