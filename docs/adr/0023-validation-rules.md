# ADR-0023 — Validation rules

Status: **accepted** (main agent, owner ADR sweep 2026-09-17). These are the rules the numbers in
VALIDATION.md are admitted under; DECISIONS.md holds their history.

## Context

The project's claims are numbers. Three kinds of number have gone wrong so far and each produced
a rule: a benchmark taken beside a build (E1 at 1,302 ms against 1,175–1,195 quiet, V-2 addendum /
V-28); a soak criterion that healthy memory could not satisfy (±2 % between two points on a
quantity whose own band is ±10–12 %, V-30 → V-35); a subagent's number that did not carry the
machine state the claim needed (DECISIONS C15). Three boundary bugs were found by benches rather
than tests: the timed-read race (H31, V-36), the exception left pending in C on cancellation (V-30),
and the 1-based `zval::arg` in the sockets hook (A4, JOURNAL 2026-09-16).

## Options considered

Benches in CI (rejected: runners are shared, the numbers are not comparable — the nightly job
records and gates only against 50 % of its own last value, M5-4 — and on 2026-09-22 the job itself was deleted for the same reason, V-119); trusting agents' numbers with a
tolerance (rejected: the tolerance would have to be chosen per quantity and would hide the
machine-state problem); endpoint-pair soak criteria (rejected by V-35).

## Decision

1. **Correctness suites run in CI with baseline gates** — php-src phpt in three modes, Revolt
   DriverTest, the Swoole shim, FrankenPHP testdata (V-23, V-26, V-27); pass counts may never
   drop below `bench/results/e15-baseline.txt`; a baseline is raised only from the **minimum of
   at least two CI samples** (2026-09-16: streams fiber read 125 then 124; swoole 55, 55, 54).
2. **Benches are local**, one at a time, never beside a build, on a quiet box; the machine state
   is part of the entry. A number from a loaded box is recorded as such and is not a result.
3. **Soak criterion:** no monotonic trend past 5M requests, 0 errors, watchdog silent (V-35) —
   replacing "±2 % between the 1M and 10M checkpoints".
4. **A subagent's number enters VALIDATION only after main re-ran it once** (C15); the entry shows
   both columns (V-41, V-45).
5. **Chaos mode is the standard for framework suites**: a random fiber switch at every await point,
   zero new failures against stock PHP (V-27, 10,849 tests per mode).
6. **Every hook claim runs against its off switch** (`IGNIS_NO_*`, `IGNIS_NO_UNIVERSAL_PARK`).
7. **Three tiers, by what a change is** (owner, 2026-09-16) — running everything on every change was
   duplicating CI on the dev box and made deletions slow:
   - **per change** (seconds, always, before any commit): `cargo build`, `cargo nextest`, and the
     probe scripts for what actually changed (e.g. `bench/php/e18_timeo.php` for a timeout path).
   - **per deletion** (ADR-0037 §6's rule, unchanged): the suite *that created the deleted code* —
     nothing more. Deleting the `sleep()` hook gates on the phpt fibers suite (V-22); deleting
     `ext/sockets` hooks on phpt sockets + the Swoole shim (V-29); deleting the transport factory
     on phpt streams + Revolt + chaos (V-12, V-25, V-26). The other suites run in CI on the push.
   - **per epic** (a cycle of ADR-0037, a milestone, before an ADR moves to *accepted*): the full
     E15 set, chaos, soak, `scripts/smoke.sh`, and the performance set (E1, E2, E4, E5, and the
     on/off comparison when a mechanism changed), one at a time on a quiet box.
   CI still runs every suite on every push — that is the safety net that makes the narrow local
   gate sufficient; a local full run before pushing is for when the answer is needed *before* the
   push, not as a habit.
8. **FFI fuzzing is planned** (unbuilt): proptest over `zval` argument decoding and the stream op
   state machine, because the three boundary bugs above were found by benches, not by tests.

## Consequences

Better: a reader can tell a measurement from an estimate, and a CI green from a bench green; a
deletion cycle costs one narrow gate locally instead of the whole set. Worse: throughput
regressions are caught nightly, not per push (M5-4), and a defect the epic-level suites would have
caught now surfaces in CI after the push rather than before it — accepted, because CI runs the same
suites and the branch of record is protected by them. Affects every V-n.

## Kill criterion

A CI gate that flaps on healthy code more than once a week — then its baseline moves to the
minimum of a larger sample, never to a tolerance.
