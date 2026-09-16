# ADR-0025 — Memory model under ZTS

Status: **accepted** (main agent, owner ADR sweep 2026-09-17). Affects pain-map PHP-FPM 6,
FrankenPHP 3, RoadRunner 2; E3, E3', E12, B1/M4-3.

## Context

Under ZTS each PHP thread owns its Zend allocator heap, and `memory_limit` bounds that heap.
Every fiber on a thread shares it. Measured costs: ~34 kB RSS per parked fiber with 16 KiB of it
Zend's fixed VM stack (V-5, 10k parked fibers); the *marginal* cost of a held request measured
later as 47.7 kB with a fiber and 33.0 kB queued as data (V-37, 2,000 → 4,000 held) — the two
figures are different quantities on different workloads and are both kept. RSS over 10.27M mixed
requests at 4 threads shows no trend past 5M (V-35). `bench/php/e14_pg.php` at N=10,000 reaches
`Allowed memory size of 134217728 bytes exhausted` inside `Fiber->start()` (JOURNAL 2026-09-16):
the ceiling is reached by an ordinary bench.

## Options considered

Per-fiber memory accounting (rejected: Zend's allocator is per thread; a per-fiber limit would
need a second allocator); a fiber pool cap as the only bound (rejected by V-37: the queue is
cheaper than a fiber but a held *connection* still costs 33 kB, so the bound is connections);
memory-limit restarts per request as php-fpm does (rejected: pain-map RoadRunner 2, and V-10
shows a flat heap without them).

## Decision

1. **`memory_limit` is per thread; one fiber's OOM ends the thread's script and therefore its
   siblings** — a recorded limit of E12's isolation (V-17 isolates a *fatal* to one thread; an
   OOM is a fatal). Documented, not hidden.
2. Admission is by the fiber budget (ADR-0019, V-37) plus **memory headroom shedding** — refuse
   admission when the thread's heap is within a configured margin of `memory_limit`. *Unbuilt.*
3. The fiber pool has a cap. *Unbuilt: the pool grows to peak concurrency and stays (V-10).*
4. The RSS bound is the **listener connection cap** (BACKLOG M4-3), because 33 kB of the marginal
   cost is the connection, not the fiber (V-37). *Unbuilt.*
5. The unbounded item on the application side is a per-request `EntityManager` (owner statement,
   unmeasured); ADR-0006's addendum scopes it, ADR-0029's audit finds it.

## R&D

Huge pages for fiber stacks (the 16 KiB VM stack per fiber is Zend's, V-5) — deferred; trigger:
a measured page-fault share in the fiber-start profile above what V-2's profile showed (the
munmap/TLB cost that the pool removed, V-4).

## Consequences

Better: sizing is three numbers — threads, `budget.fibers`, connections — and docs/operate.md
says so with V-37's costs. Worse: a memory-hungry request on a busy thread takes its neighbours
with it until (2) exists. Affects E3', E12', B1, M4-3.

## Kill criterion

A measured per-fiber cost below 8 kB on a real application — then the fiber, not the
connection, stops being the interesting term and (4) is re-ranked.
