# ADR-0006 — Superglobals are swapped per fiber by a fiber-switch observer; the container is a userland WeakMap

Status: accepted (Cycle 5, 2026-09-16; accepted by V-11 — 0 mismatches in-process and over 200 concurrent requests at +100 ns per switch; V-11 addendum records the amortised cost that motivated the E13' lazy swap)

## Context
E13: two interleaved requests must never observe each other's `$_SERVER`/`$_POST`;
a fiber-scoped container must exist. Research 05.

## Options
1. **Observer swap of the four symbol-table entries** (chosen): engine-level, no PHP
   patch, ~8 hash ops per switch, inherit-on-first-entry semantics.
2. Patch Zend to make `EG(symbol_table)` fiber-local: not "unmodified PHP".
3. Never expose superglobals (ADR-0002 status quo): blocks E8 (Symfony's
   `Request::createFromGlobals()`), keeps the "value-only" limitation.
Container: a `WeakMap` keyed by `Fiber::getCurrent()` in userland (`Ignis\Scope`)
needs no engine hook at all; entries die with the fiber.

## Decision
Option 1 + userland `Ignis\Scope`. On backend (b) the same logic attaches to the
ABI's per-coroutine `switch_handler` (ADR-0003) — not implemented this cycle.

## Consequences
- `PG(http_globals)` is not swapped (phar, `filter_input` see thread state) —
  documented; fix = swap those four zvals too (cheap, later).
- Every fiber switch pays the swap, including ones that never touch
  superglobals; measured in V-11.
- Pooled fibers must have their globals set at dispatch (done in `Loop::dispatchRequest`).

## Pain-map items affected
Swoole 1 (statics/superglobals change on switch): ADDRESSED for superglobals by
this ADR, userland statics remain the app's problem (leak detector later).
RoadRunner 5 (PSR-7 bridges): `$_SERVER` now exists per request, so
`Request::createFromGlobals()` works unchanged.

## Kill criterion
If the swap costs > 1 µs per switch or breaks `Fiber` semantics observable by
`ext/test_scheduler`-style tests (exceptions across resume, destructors), fall
back to a lazy design: swap only when a fiber has ever called
`ignis_set_superglobals` (flag per context).
