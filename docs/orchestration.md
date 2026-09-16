# Orchestration — how the backlog gets executed

Owner's brief, 2026-09-16: "оркестратор + Sonnet-агенты, большой подробный бэклог". The main
agent orchestrates; Sonnet agents execute; nothing an agent measures is believed until the
orchestrator has re-run it. `BACKLOG.md` is the queue; this file is the loop.

## The loop

1. **Pick.** From `BACKLOG.md`, take every `open` item whose lane is `agent` or `research`, whose
   blockers are `done`, and whose files do not overlap with an item already `in progress`. Batch
   size: as many as are independent — they run concurrently. `main` items are the orchestrator's
   own queue and run between batches, never beside a benchmark (DECISIONS: one measurement at a
   time, never beside a build).
2. **Brief.** One agent per item, model `sonnet`, subagent type by kind: `bencher` for a
   measurement, `porter` for a suite, `scribe` for documents, `general-purpose` otherwise. The
   brief is the BACKLOG entry verbatim plus: the repo path, the box's constraints (below), the
   acceptance command, and "report the exact commands you ran and their output; do not commit".
   Mark the item `in progress (name)`.
3. **Validate.** When an agent reports: read its diff (`git diff --stat`, then the files), run its
   acceptance command yourself, and — if it produced a number — run the measurement once more on a
   quiet box. A number that does not reproduce within the item's stated tolerance is not a result;
   send the agent back with the two outputs side by side.
4. **Gate.** `cargo nextest run --workspace`; `scripts/smoke.sh` (with `IGNIS_LISTEN` set on this
   box); `bench/e15-phpt.sh` if `php/ignis.php`, `crates/**` or `scripts/ignis-php` changed. A
   regression against `bench/results/e15-baseline.txt` blocks the item.
5. **Record.** VALIDATION entry (the orchestrator's numbers, with "agent's run: X, re-run: Y"),
   JOURNAL line, BACKLOG status → `done (V-n)`, README/ROADMAP if user-visible. Commit per item
   with a conventional message that says what was measured. Push.
6. **Reassess** after each batch: what the batch found that is not in the backlog gets added as an
   item, with evidence, before the next pick.

## Rules the agents get verbatim

- Never edit `crates/ignis/src/php/**`, `crates/ignis-sys/**`, `crates/ignis/src/backend/**` or a
  file containing `unsafe`. If the item needs it, stop, write what the FFI change must be, and
  hand it back.
- Never run two benchmarks at once and never a benchmark beside a build. Never touch
  `target/release/ignis` while a suite is using it.
- `:8080` on this box belongs to another project. Every server you start takes `IGNIS_LISTEN`;
  readiness is the expected body, never "something answered".
- `pkill -f` matches your own shell. Kill by PID. A bare `wait` waits for the server you started.
- No PostgreSQL on this box; the builder image has `psql`; run `postgres:17-alpine` over a
  bind-mounted unix socket (research 24) because port publishing is broken on this docker daemon.
- No composer, no php-cli on this box: use `ghcr.io/koekaverna/ignis-php:8.5.10-zts` with the repo
  bind-mounted (V-40 shows the exact invocation).
- `RUST_LOG` unset means the binary prints only `warn` and above; set `RUST_LOG=info` when you
  need to see a hook install. The phpt harness sets `RUST_LOG=error` itself.
- Numbers or it didn't happen. Report every command and its output. Do not commit. Do not edit
  JOURNAL.md or VALIDATION.md — the orchestrator writes those after re-running.
- If a number contradicts a recorded one, say so; do not average it away.

## Model routing

Sonnet for every `agent` and `research` item — the owner's instruction, and the validation step is
what makes it safe. The orchestrator escalates to itself, not to a larger agent, when an item
needs an FFI change or when two agent runs disagree.

## Commit and CI cadence

CI used to cancel the in-flight run on every push; three pushes in ten minutes on 2026-09-16 meant
no run of the full gate completed. Since H-4, runs on `main` are never cancelled — they queue. So:
push per batch, not per item, or the queue grows by one full run per push. Never leave more than
30 minutes of work uncommitted (CLAUDE.md) — commit locally as you go; the *push* is what batches.
