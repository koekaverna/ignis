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

## Addendum (owner ADR sweep, 2026-09-17) — fiber-scoped services: the model, and what of it exists

**Status: accepted** as the model (main agent). The superglobal swap is built and measured; the
rest of the model is recorded here so it is built the same way.

**Context.** Superglobals are swapped on the fiber-switch observer (V-11: 0 mismatches over 300
in-process checks and 200 concurrent HTTP requests, +100 ns per switch; E13' lazy swap ≤ 0.7 µs,
V-28). `Ignis\Scope` is a per-fiber container (WeakMap) and the leak check for it is the only
dev-mode detector (V-11). Symfony's `RequestStack` is fiber-scoped through it (V-16, 0/100
mismatches, sessions on). Children are attributed to their request through `Scope::get('ignis.request')`
(E11, V-14). Research 25 showed why the model must extend to statics: Laravel's
`Container::$instance` is process-global.

**Options considered.** (a) Isolate by *thread* (one request per thread at a time) — that is
FrankenPHP/Octane's model and it forfeits the fiber concurrency the project exists for
(research 25: recommended only as the Laravel stopgap, ADR-0028). (b) Copy state on every switch
(eager) — rejected by measurement: V-11's addendum showed the eager observer at 3.5–6.9 µs per
switch; the lazy swap replaced it. (c) Bind scope to the Fiber object — rejected because pooled
fibers are reused across requests (V-4, the single biggest win); scope must be bound to the
**dispatch**, not the Fiber.

**Decision (the model).**
1. Scope is bound to dispatch: created when a request fiber is admitted, destroyed in its outermost
   `finally`, and a pooled fiber carries nothing across requests. *Built for the request id and the
   superglobals; the general container is keyed by fiber today — partial.*
2. Child fibers of `all()` inherit the parent's scope **by reference**. *Built for the request id
   only; the scope container itself is per fiber — unbuilt.*
3. `spawn()` starts with an empty scope or an explicit snapshot, never a reference. *Unbuilt:
   `spawn` today attributes the child to the request (cancellation semantics of E11).*
4. Access resolves at call time through a lazy proxy (a service handle looks up the scope of the
   fiber that calls it). *Unbuilt.*
5. Observer slots: the four superglobals today; later, listed static properties (research 25's
   `Container::$instance`, Facade caches) — ADR-0028's M3-5b. *Unbuilt beyond superglobals.*
6. Leak detector = a scoped object still alive after its scope died. *Built for `Ignis\Scope`
   entries (V-11); a static-property scanner is ADR-0029.*
7. The Symfony list to scope: `RequestStack` (built, V-16), `TokenStorage`, `EntityManager`,
   `Session` (sessions *work* in V-16's addendum; scoping of the session object is unmeasured),
   request locale. *All but the first unbuilt.*

**Consequences.** Better: the only fiber-safe definition of "request state" that survives fiber
pooling. Worse: every framework brings its own list of statics (ADR-0029's audit is the tool).
Affects E13, E13', E8, M3-5b.

**Kill criterion.** A framework whose request state cannot be enumerated as scope entries or
static slots — then the answer for it is ADR-0028's one-request-per-thread mode, not a wider
observer.
