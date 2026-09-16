# ADR-0008 — Revolt driver = `AbstractDriver` subclass over `ignis_poll`; fd readiness via tokio AsyncFd

Status: accepted (Cycle 7, 2026-09-16; accepted by V-13 — 7/8 examples byte-identical, benchmarks ≤ 1× of StreamSelectDriver — and by V-23 addendum: Revolt's `DriverTest` 81 tests / 222 assertions / 0 failures after the readiness fast path and the cancel op that this ADR predicted)

Context: E7. Research 07. Decision: implement `Ignis\Revolt\IgnisDriver` (activate/dispatch/deactivate/now)
selected with `REVOLT_DRIVER`; add `Op::Watch` + `ignis_watch()` for readiness on real fds; run AMPHP with
the `tcp://` hook off. Alternatives rejected: (1) making the hooked transport expose an fd (would need a
pipe per stream just to signal readiness — Swoole-style hybrid, later if AMPHP-on-hooked-streams is wanted);
(2) porting AMPHP to `Ignis\Loop` (not "unchanged").
Consequences: one reactor per thread is shared by whichever loop runs; Revolt programs are single-loop
by construction. Watches are one-shot; a deactivated watch's dup'd fd lives until it fires or the
process exits (bounded by the number of streams; fine for R&D, a cancel op is the fix).
Pain-map: Swoole 8 (ecosystem fork): AMPHP runs on Ignis via Revolt — ADDRESSED if V-13 holds.
Kill criterion: if Revolt's own examples cannot run within 2× their StreamSelectDriver time, the
one-shot watch design is the suspect (re-arm cost per dispatch).
