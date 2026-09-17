# ADR-0039 — Boot-heap snapshot for classic mode (E19)

Status: **rejected** (owner, 2026-09-17). Not rejected for cost — the mechanism was measured and it
fits its own budget. Rejected because the constraint it imposes removes the reason this runtime
exists. Depends on ADR-0019 (fiber budget), ADR-0024 (non-goals), ADR-0028 (Laravel compatibility
model); numbers in `docs/research/34-e19-boot-heap-snapshot-feasibility.md`.

## Context

The owner proposed E19: boot allocations in a dedicated per-thread Zend MM arena; `mprotect` after
boot; a `SIGSEGV` write barrier copying dirty pages into an undo log; at request end, restore the
dirty pages and discard the request arena without running destructors. Acceptance: a Laravel app
that leaks a static-held object per request survives 1M requests with RSS flat and identical
response bytes, at under 100 µs per request with fewer than 50 dirty pages. Kill criterion: any
extension whose C state lives outside the arena and diverges after restore.

The problem it addresses is real and unsolved here: an application that is not worker-safe — state
in statics, a container that accumulates, a leak per request — cannot run in a long-lived worker.
That is the gap behind ADR-0028 (Laravel) and half of BACKLOG R-GLOBALS.

## What was measured before deciding

The acceptance names a number, so the number was measured first (research 34), both for the
mechanism and for the input it assumes:

| | |
|---|---|
| cost of the barrier | **7.1 µs per fault** — signal delivery, not copying |
| a real Symfony request | boot heap **2.0 → 6.0 MiB**; **11–12 dirty pages** (20 on the first), not the assumed 50 |
| the proposal at the measured dirty count | **71 µs** against a 100 µs budget |
| whole-arena memcpy instead | 119.7 µs — O(arena), already over at 6 MiB |
| soft-dirty bits instead | 141.0 µs, and `clear_refs` is process-wide, so unusable from several PHP threads |

So the proposed mechanism is affordable, and it is the only one of the three that does not grow with
the boot heap. An earlier verdict in this project said it missed its budget by 3.5×; that was
arithmetic on the assumed 50 pages and is withdrawn.

## Why it is rejected anyway

**The undo log and the arena are per thread, so a restore is only safe when exactly one request is
in flight on that thread.** Two fibers mid-request share the arena: if fiber A dirties a boot page
and fiber B writes the same page, restoring at A's request end clobbers what B is using. Strict
per-request isolation by page rollback therefore requires `budget.fibers = 1` — one request per
thread, concurrency equal to the thread count.

That is php-fpm's concurrency model with a faster bootstrap. It keeps the worker-mode win (the
framework boots once, not per request) and gives up the one this runtime is built on: while a
request waits on a database or an HTTP call, the thread cannot serve another. Universal park,
the fiber pool and the reactor all exist to make that wait free. E19 would switch them off for
exactly the applications that most need help.

**The variant that keeps concurrency does not meet the acceptance.** Restoring when the thread goes
idle — zero requests in flight — preserves fibers and still bounds RSS, so "1M requests with RSS
flat" holds. But "identical response bytes" does not: a leak from one request stays visible to the
others in the same batch. That is a different product — *bounding* accumulated state rather than
*isolating* a request — and it is not what the note asked for.

## Options considered

- **E19 as specified, `budget.fibers = 1`.** Affordable, correct in principle, and it makes an
  unrunnable application runnable. Rejected: it trades away in-thread concurrency, which is the
  runtime's whole proposition, for the applications where the proposition matters most.
- **E19 with restore at thread-idle.** Keeps fibers, bounds RSS, fails the isolation half of the
  acceptance. Not pursued: half the guarantee for all of the complexity.
- **Fix the applications instead** — the worker-safety rules every worker runtime already documents
  (`require_once`, no state in statics, a socket-backed session). This is what README, the
  compatibility table and the runbook already say, and what V-54 measured for the classic worker
  loop.
- **Do nothing.** Chosen, for now.

## Consequences

Better: the runtime keeps one concurrency model, and the FFI boundary does not grow a signal
handler, a second allocator arena and a page-level undo log — all of which would have to be correct
under ZTS, with extension C state, file descriptors opened during a request, and destructors that
no longer run. Worse: an application that is not worker-safe still cannot run here unchanged, and
the answer stays "make it worker-safe", which is a real limit and is documented as one.

The measurements survive the rejection and are the point of writing this down: the barrier costs
7.1 µs per fault, a Symfony request dirties 11–12 boot pages, and the boot heap is 6 MiB. Anyone
reopening this starts from those, not from a guess.

## Revisit if

- A target application's dirty-page count is measured **and** its requests are CPU-bound, so
  `budget.fibers = 1` costs nothing that park would have recovered.
- Or a mechanism appears that isolates per request **without** thread exclusivity — a per-fiber
  allocator arena with copy-on-write boot pages resolved per fiber rather than per thread. That is
  research, not a variant of this proposal.
