# ADR-0035 — Security posture: unprivileged by default, with per-item build status

Status: proposed. Numbers per V-n where measured; every other line explicitly unbuilt.

## Context

M2 produced the only measured security-relevant claim so far: the runtime image runs unprivileged
(V-39: `whoami` → `ignis` inside the container). Nothing else in this list — header/body size
limits, slowloris timeouts, per-IP caps, an `io_uring` kill-switch, an `unsafe` audit, signed
releases — has a VALIDATION.md entry, a `config.rs` key, or a code path found by grepping the tree
for the relevant terms. This ADR makes that gap explicit rather than implied.

## Options considered

1. Treat hardening as implicit in "the architecture is sound" — rejected: fiber isolation, respawn
   and cancellation address availability/correctness under load, not resource-exhaustion caps,
   privilege drop or supply-chain signing; conflating the two misstates what is measured.
2. Build everything now, ungated — rejected as out of scope: CLAUDE.md's sweep instruction is
   "record decisions, implement nothing."
3. **Inventory each item, mark implemented (cite) or unbuilt, defer to M5 — chosen.** Matches the
   owner's instruction; ROADMAP's M5 already names "unsafe audit and signed releases" as M5 items.

## Decision

Per item, as of this sweep:

1. **Unprivileged by default — implemented** (V-39, and the addendum: confirmed on the published
   image by `image.yml` on `574b231`).
2. **Header/body size limits — unbuilt.** No config key, no code path found; any limit is hyper's own
   implicit internal buffering, undocumented and unmeasured here.
3. **Slowloris timeouts — unbuilt.** No read/write/idle timeout for inbound connections; V-33's spin
   knob and V-37's fiber budget address latency and load-shedding, not a slow-client hold-open.
4. **Per-IP caps — unbuilt.** ADR-0019's fiber budget (V-37) and BACKLOG M4-3's planned connection
   cap (B8) are per-listener, not per-source-address.
5. **`io_uring` off unless enabled — unbuilt as a named toggle**, moot today: the reactor is
   tokio-based with no `io_uring` dependency anywhere in the stack; relevant only if one is added,
   at which point it should default off under Docker seccomp.
6. **`unsafe` audit — unbuilt**, named an M5 item here per the owner's spec. Partially covered in
   spirit by per-block `unsafe` documentation discipline (CLAUDE.md) and `guard-ffi.sh` restricting
   who may edit FFI files — neither is a formal audit deliverable.
7. **Signed releases — unbuilt.** `release.yml` (M5-1) builds, tags and attaches artifacts; no
   signing step (cosign/sigstore/GPG) exists.

## Consequences

- Better: an honest, citable line per item instead of an implied "secure" claim — item 1 has a V-n,
  everything else is correctly flagged absent.
- Worse: until items 2–5 land, the runtime is exposed to the attack classes they name, beyond what
  ADR-0019's budget and BACKLOG M4-3's connection cap already mitigate in aggregate.
- Affects M5's acceptance (items 6, 7 folded into its existing scope) and M4 (items 2–5 sit next to
  M4-3/B8 conceptually).

## Kill criterion / Trigger

Not a kill criterion — an inventory. Trigger: each unbuilt item becomes its own BACKLOG entry with an
acceptance test and V-n; this ADR is amended when any item's status changes.

## Status

proposed
