# ADR-0036 — Naming: keep "ignis" despite the crates.io collision

Status: proposed (the decision is the owner's).

## Context

BRIEF.md's Cycle 0 instruction: check name collisions for "ignis" on crates.io, Packagist and GitHub,
propose two alternatives if taken, rename nothing. Recorded at STATUS.md line 110 and JOURNAL.md's
C1 entry: crates.io taken (unrelated `ignis` 0.1.0), Packagist free, GitHub three unrelated repos —
nothing renamed. Since that check the name has been built on further: the GHCR image is published as
`ghcr.io/koekaverna/ignis` (V-39, V-39 addendum, CI-green on `574b231`) and the composer package is
`ignis/runtime` (V-40, installed end to end by a Symfony skeleton). Rename cost is no longer zero.

## Options considered

1. **Keep `ignis`, accept the collision.** The binary/image are not published to crates.io under
   that name today (distributed via the GHCR image, V-39), so the collision blocks a `cargo publish`
   that has never been attempted, not anything shipped. GitHub and Packagist — the two names actually
   load-bearing for M2/M3's shipped artifacts — are clear or unrelated.
2. **Rename to `ignis-rt`** (BRIEF's first alternative). Clears the crates.io collision if a crate is
   ever published. Cost: the GHCR image tag (V-39), the composer package name (V-40, already
   documented in README per BACKLOG M3-1), and every doc/bench/script reference to the binary name.
3. **Rename to `fyra`** (the second alternative). Same cost profile as option 2, no offsetting
   benefit over it.

## Decision

**Not decided here** — naming is the owner's call. The two BRIEF-mandated alternatives (options 2, 3)
are on record for the owner to pick from; this ADR lays out the cost growth since Cycle 0 without
recommending among them.

## Consequences

- Rename cost has grown monotonically since Cycle 0: nothing was published then; today it means
  updating and re-validating the GHCR image (V-39, built/pushed/smoke-tested on every push to main)
  and the composer package (V-40, target of BACKLOG M3-6's still-open Packagist publication).
- The collision has no measured product impact: no V-n or BACKLOG item depends on publishing a crate
  named `ignis`; STATUS.md line 110 also notes crates.io's web UI is network-blocked here (sparse
  index path works), so confirming the collision's current state needs non-routine tooling.
- If a rename is chosen, it should happen before BACKLOG M3-6 (Packagist publication) and before
  M5-1's first real release tag — both fix the name into a harder-to-change public surface.

## Kill criterion / Trigger

Trigger: the owner attempts an actual crates.io publish under `ignis` (the collision becomes a real
blocker), or explicitly picks option 2 or 3. Absent either, this stays `proposed` and the name stays
`ignis`.

## Status

proposed
