# ADR-0019 — Fiber budget: admission control with a queue and a 503 past it

Status: accepted (Phase B item B1, 2026-09-16; V-37). Affects pain-map item "pool exhaustion at low
CPU" and PHP-FPM 2. Depends on ADR-0002 (fiber pool), ADR-0009 (cancellation).

## Context

Nothing bounded the number of request fibers. A burst created one Fiber per request and the process
grew until the allocator or the OOM killer stopped it — `bench/php/e14_pg.php` at N=10000 reaches
`Allowed memory size of 134217728 bytes exhausted` inside `Fiber->start()`, so the ceiling is hit by
an ordinary bench, not by a synthetic one.

## Decision

1. `Ignis\Loop` admits at most `IGNIS_FIBER_BUDGET` **request** fibers at a time. 0 (the default)
   means unlimited, which is the pre-B1 behaviour.
2. A request that cannot be admitted waits as **data** — the raw array the reactor delivered — in a
   FIFO read by index, never as a Fiber. This is the whole point: a Fiber is expensive, a queued
   request is a few hundred bytes of PHP array.
3. Past `IGNIS_QUEUE_DEPTH` the server answers **503 with `retry-after: 1`** immediately rather than
   queueing without limit. 0 means an unbounded queue.
4. A slot is released in the request fiber's outermost `finally`, which then admits the next waiting
   request. No new wait point and no new `Op`: the whole mechanism is in the userland scheduler.
5. `IGNIS_BUDGET_EXEMPT` is a comma-separated list of path prefixes admitted regardless of the
   budget. A saturated server is exactly when its metrics matter, so `/stats`-style endpoints must
   not queue behind the load they exist to report.
6. A client that disconnects while its request is still queued is dropped from the queue and never
   admitted; there is no fiber to throw into (ADR-0009 handles the admitted case).

## Kill criterion

Reversed if any of the following holds:
1. The p99 of admitted requests degrades measurably when the offered load fits inside the budget.
   Measured: 1.79 / 1.82 / 1.90 ms without a budget against 1.85 / 1.89 / 1.78 ms with one — fully
   overlapping ranges, so the criterion is satisfied (V-37).
2. Queueing costs as much as admitting. **Partly breached, and the honest number is in V-37**: the
   marginal cost of a held request is 47.7 kB with a fiber and 33.0 kB queued, so the budget removes
   the 14.7 kB fiber and not the ~33 kB the *connection* costs. RSS for the same offered load falls
   ~30 %, not by the order of magnitude the fiber figure alone suggests.
3. The budget changes observable behaviour other than shedding — a request that would have been
   answered is lost rather than queued or refused. Measured: budget 2 with 10 × 200 ms serialises to
   1028 ms with 10/10 answered; budget 2 + depth 3 with 12 concurrent gives exactly 5 × 200 and
   7 × 503.

## Consequences

- The fiber count is bounded and the shed path is explicit, so a burst is refused rather than
  swallowed.
- **It does not bound RSS on its own.** A held connection costs ~33 kB whatever the budget, so an
  RSS bound needs a limit on concurrent *connections* at the listener as well — that is a separate
  item and is recorded as such in ROADMAP B1, not quietly folded in here.
- Children spawned by an admitted request are not counted against the budget; one admitted request
  can still fan out. Bounding that needs a per-request child budget, which is not in this ADR.
