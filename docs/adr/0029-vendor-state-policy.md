# ADR-0029 — Vendor state policy: classifying and isolating statics a framework did not fiber-scope

Status: proposed (accepted-as-policy is the main agent's call). Rests on ADR-0006 (superglobal swap
model), research 25 (`illuminate/container` v12.0.0 `Container::$instance`), pain-map Swoole 1
(statics change on switch) and FrankenPHP 1 (worker mode needs a leak-free app).

## Context

ADR-0006 fiber-scopes the four superglobals via a `zend_observer` switch hook (V-11: 0 mismatches,
+100 ns/switch). Research 25 found the same hazard one layer up, unsolved: Laravel's
`Container::$instance` is a class-static shared by every fiber on a thread — Octane's own model
never interleaves two requests for exactly this reason. Nothing today detects this before it
corrupts a response; the only existing tools are chaos mode (V-27: 0 new failures in 10,849 tests,
but it exercises timing, not static-property identity) and `Ignis\Scope` (V-11), which is opt-in app
code, not a scanner.

## Options considered

1. Do nothing, document the hazard — rejected: pain-map already marks "userland statics remain the
   app's problem" NOT STARTED, contradicting M3's "real apps unchanged".
2. Fiber-scope every static universally via the observer — rejected: unbounded cost per switch, and
   no way to separate an immutable cache from request state without classifying first.
3. **Scanner + classification + a graduated isolation ladder, opt-in per finding — chosen.** Reuses
   the scanner design, chaos mode (V-27) and the scope leak check (V-11) as three detection legs, and
   pays an isolation cost only where a static is actually found unsafe.

## Decision

1. **Find**: a composer plugin `ignis audit` snapshot-diffs every class-static/constant per request,
   runs the target under chaos mode (V-27) and the `Ignis\Scope` leak check (V-11).
2. **Classify**: immutable cache (safe) / unbounded cache (memory hazard) / request state (unsafe
   under interleaving — `Container::$instance` is this class).
3. **Isolate ladder**, cheapest first, for statics classified "request state": (a) observer static
   slots — the ADR-0006 mechanism generalised to a named (getter, setter) pair per static; (b)
   container proxy — a fiber-aware accessor with no engine hook; (c) offload quarantine (ADR-0016) —
   pin the object to one worker, one call at a time; (d) library mutex — serialise access, last
   resort; (e) composer-patches/Rector when upstream must be patched.
4. **Deliverable**: the `ignis audit` plugin, and a compat allowlist with statuses `safe` /
   `needs-slot` / `needs-offload` per package.

## Consequences

- Better: fix cost matches hazard — an immutable cache pays nothing, a `Container::$instance`-class
  static gets ADR-0006 treatment, only a genuinely unscopable library pays offload/mutex cost.
- Worse: scanner overhead per audited request is unmeasured (no V-n); it is a dev/audit tool, not
  hot-path code, so not gated on that cost here.
- Worse: the allowlist is a maintenance surface — a library regressing from `safe` needs chaos mode
  in CI per dependency bump to catch it, not designed here.
- Affects M3-5b (fiber-scoped `Container::$instance`, `main`, needs its own ADR citing research 25's
  kill criterion) and M3-5a (Laravel classic mode, sidesteps this ADR by serialising).

## Kill criterion

If `ignis audit` on `symfony/skeleton` (the one framework with a worker-mode V-n, V-16) flags
materially more statics as "request state" than research 25's manual read found for Laravel, the
scanner isn't saving work — classification reverts to a research note per framework.

## Status

proposed
