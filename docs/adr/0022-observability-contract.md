# ADR-0022 — Observability contract

Status: **accepted** (main agent, owner ADR sweep 2026-09-17) as the contract; each line marked
built or unbuilt. Affects pain-map Swoole 4 (a stalled fiber's trace), RoadRunner 2, and the
owner's question of 2026-09-16 ("will we see in the logs if someone is holding?").

## Context

Until 2026-09-16 the process printed nothing below `error`: a worker could die and respawn with the
operator seeing nothing (JOURNAL 17:05Z). Raising the floor to `warn` broke every phpt test until
the harness set its own level (fibers main 108 → 72, V-38 method note). Health is answered by the
runtime (`/_ignis/health`, V-38); pg lease age is visible and logged (V-44); a stalled thread is
counted by the watchdog after 1 s (V-17) — and the clock it uses started at the wrong end until
V-38 fixed it. Nothing else is observable without reading `/stats` from an example script.

## Options considered

- Logs only (rejected: a held lease is a gauge, not an event); metrics only (rejected: the
  respawn story needs the line with the slot and the status); PHP-side observability (rejected:
  a wedged PHP thread cannot report itself — the runtime must).

## Decision — the contract

| line | status |
|---|---|
| Default log floor `warn`; `RUST_LOG` overrides; the phpt harness sets `error` for itself | **built** (V-38) |
| A worker respawn, a stalled thread and a clean `exit()` are distinguishable in the log | **built** (H-10: exit → debug, fatal → warn with status) |
| Health answered by the runtime, never by PHP: 200 while a worker is alive and not stalled, 503 otherwise | **built** (V-38) |
| Hold-time on every lease: gauge while held, warn line at release past a threshold | **built for pg** (V-44); offload jobs, stream waits, gRPC calls — **unbuilt** (BACKLOG M4-6 inventories them) |
| Every park is a span: fd, library, PHP function, duration | **unbuilt** — `IGNIS_PARK_TRACE=1` prints a line per decision (V-45), not a span |
| Loop lag per thread as a first-class metric (time between `poll` returning with work and the next `poll`) | **unbuilt** — only the 1 s stall counter exists (V-17); the V-38 clock fix is its foundation |
| `ignis dump`: fibers with traces, ops in flight, leases, the admission queue | **unbuilt** |
| The watchdog names the request, not only the thread | **unbuilt** (BACKLOG M4-7) |
| `/_ignis/stats` from the runtime, `/_ignis/metrics` in Prometheus format | **unbuilt** (M3-7, M4-4); today `/stats` is per example and queues behind the load unless exempted (ADR-0019 §5) |

## Consequences

Better: the operator's first three questions — is it up, is something held, did a worker die —
have runtime answers that do not depend on PHP. Worse: until spans exist, "where is this request
waiting" is answered by `IGNIS_PARK_TRACE` and reading, not by a tool. Affects M4, M5-3.

## Kill criterion

An incident where the runtime was the wedged component and reported itself healthy — that
reverses "health from the runtime" toward an external prober, and is why `/_ignis/health` reads
the thread registry rather than a static 200.
