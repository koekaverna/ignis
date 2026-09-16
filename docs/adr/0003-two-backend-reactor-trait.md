# ADR-0003 — Reactor/scheduler behind a trait with two backends

Status: accepted (Cycle 2, 2026-09-16; accepted by V-7 (fork builds, 61/61; the ABI has no I/O path) and V-8 (E1 on engine coroutines in 1165–1177 ms through the 14-line idle hook))

## Context

Owner direction: keep mainline PHP 8.5 as the production path and prepare for
the async scheduler ABI (php-src PR #22561, true-async fork). Research 02
established that the ABI is a provider slot table consulted only by Zend core,
with no I/O integration.

## Options

1. **Trait `Backend` with two implementations**: `Mainline85` (today's code:
   userland `Ignis\Loop`, `zend_observer_fiber_switch` for state, stream hooks
   for I/O) and `AsyncCore` (Rust implements `zend_async_scheduler_api_t`,
   engine coroutines replace userland Fibers, `poll()` at the idle point).
   Shared: the tokio `Reactor` (unchanged), HTTP front door, module entry.
2. Only mainline: ignore the ABI until merged — loses the early signal on
   whether the engine ABI is enough for Ignis.
3. Only the fork: bet on unmerged engine code — kills every confirmed number.

## Decision

Option 1. Concretely:
- `crates/ignis/src/backend/mod.rs`: `trait Backend { fn poll_point(&self, r: &Reactor) ... }` is
  *not* introduced as an abstract trait up front — the two backends share the
  Reactor and differ in *who owns the fibers*. The seam is the PHP-facing
  primitive set (`ignis_submit_*`, `ignis_poll`, `ignis_respond`), which both
  backends keep. Under (b) `ignis_poll` is called by the Rust scheduler loop
  instead of by PHP.
- `ignis-sys` gets a second bindgen target selected by `PHP_CONFIG`
  (`/opt/php86-async-zts/bin/php-config`) with `cfg(php_async_abi)` when
  `zend_async_API.h` is present.
- The provider slot table lives in `crates/ignis/src/backend/async_core.rs`,
  compiled only under `cfg(php_async_abi)`.
- E6 is pursued on (a) via stream transport hooks (Cycle 4); (b) reuses them.

## Pain-map items affected

- Engine-level 1 (GC destructors switching context): (b) delegates to the
  engine's GC coroutine; (a) must keep `Fiber::suspend()` out of destructors —
  Ignis\Loop never resumes from inside a destructor, and a `FiberError` from a
  blocked switch is logged as a scheduler bug.
- Swoole 1 (state on switch), FrankenPHP 2 (aborted connections), PHP-FPM 3/4
  (phantom work, deadlines): (b)'s `cancel` and `switch_handler` slots map
  directly; (a) needs the observer hook and a cancel event from hyper.
- Made worse by (b): reproducibility — the fork is unmerged; every number on
  (b) must be labelled with the commit (`14af3cb`).

## Kill criteria

- Drop (b) if the fork does not build as a ZTS embed within one cycle, or if
  registering a provider from an embed module is impossible without patching
  the fork (e.g. registration only honoured for MINIT of a shared extension).
- Drop the trait seam if the two backends end up sharing < 50% of the module
  code (then they are two products, not two backends).
- Promote (b) to production only when PR #22561 is merged into php-src.
