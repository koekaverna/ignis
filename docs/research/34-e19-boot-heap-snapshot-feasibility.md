# 34 — E19 boot-heap snapshot: what the write barrier costs, before any of it is designed

2026-09-17, main agent. The owner's E19 note specifies the mechanism (a per-thread boot arena,
`mprotect` after boot, a `SIGSEGV` write barrier copying dirty pages to an undo log, restore at
request end) **and** a budget: *cost per request < 100 µs at < 50 dirty pages*. That budget is a
falsifiable claim about the mechanism, so it is measured here first — an ADR written against an
unreachable number would design the wrong thing.

Programs: `bench/e19/barrier.c` (the proposal, alone) and `bench/e19/compare.c` (three ways to undo
a request's writes, same workload). Built `cc -O2`, this box, quiet. Each arm runs the same
"request": write one byte into N distinct pages of the arena, then put the arena back.

## The three ways, 2 MiB arena, 50 dirty pages per request, 1000 requests

| | barrier | restore | **total per request** | scaling |
|---|---:|---:|---:|---|
| **A. `mprotect` + `SIGSEGV`** (the proposal) | 354.3 µs | 3.1 µs | **357.4 µs** | O(dirty), **7,087 ns per fault** |
| **B. no barrier; memcpy the whole arena back** | 0 | 33.8 µs | **33.8 µs** | O(arena size) |
| **C. soft-dirty bits** (`/proc/self/clear_refs` + `pagemap`) | 0 | clear 50.0 + scan 17.2 + restore 2.9 | **70.1 µs** | O(arena) to scan, O(dirty) to restore |

**A misses its own budget by 3.5×**, and the term that misses it is not the copying — it is signal
delivery: ~7 µs for every first write to a protected page, paid 50 times. At 200 dirty pages it is
1,064 µs.

## B is cheapest only while the boot heap is small

| boot arena | B, whole-arena restore |
|---:|---:|
| 2 MiB | 37.9 µs |
| 8 MiB | 241.7 µs |
| 16 MiB | 677.3 µs |
| 32 MiB | 1,487.8 µs |
| 64 MiB | 2,920.8 µs |

B fits the budget up to roughly **3 MiB** of boot heap and nothing beyond. C's scan is also O(arena)
(~17 µs per 2 MiB of pagemap), so at 32 MiB C is ≈ 320 µs. **At a framework-sized boot heap none of
the three meets 100 µs.**

## What this means for the design

1. The mechanism the note specifies is the most expensive of the three measured, by an order of
   magnitude, and the cost is inherent to signal-based barriers. `userfaultfd` write-protect
   (`UFFD_WP`, Linux ≥ 5.7) replaces the signal with a handler thread; it is worth measuring, but a
   fault still costs a context switch (~2–4 µs), so at 50 pages it lands at 100–200 µs — at best
   borderline.
2. C is disqualified for a different reason regardless of cost: `/proc/self/clear_refs` clears
   soft-dirty for the **whole process**, so with N PHP threads each doing it per request, the
   threads erase each other's tracking. It would need one PHP thread per process.
3. **The number nobody has measured is the one the design depends on**: how large is a real
   framework's boot heap, and how many of its pages does a request actually write? The owner's "< 50
   dirty pages" is an assumption, not an observation. If a Laravel request dirties 500 boot pages,
   A costs 3.5 ms and the epic is dead; if the boot heap is 2 MiB, B is 34 µs and needs no barrier
   at all.

## What to measure next, before an ADR

- A real Laravel boot: `memory_get_usage(true)` after bootstrap, and the same after N requests — the
  arena size B and C scale with. Laravel is not installed on this box (BACKLOG M3-5a is open), so
  this is blocked on that.
- The dirty-page count of one request against that boot heap, using arm A purely as an instrument
  (the fault count is exactly the dirty-page count) rather than as the mechanism.
- `UFFD_WP` per-fault cost on this kernel, as A's replacement if the dirty set turns out small.

## Not measured here, and deliberately

Everything about correctness: what lives outside the arena (libcurl handles, OpenSSL contexts, libpq
connections, file descriptors, extension `static` C state), whether skipping destructors is
acceptable, and whether restoring a page can resurrect a pointer into a discarded request arena.
The owner's kill criterion already names the first of these. None of it matters until the cost
question has an answer, because a mechanism that costs 350 µs per request will not ship whatever
its correctness.

---

## Addendum (2026-09-17) — the dirty count is measured, and it changes the answer

The section above concluded that the proposed mechanism misses its budget by 3.5×. That conclusion
was computed at the **assumed** 50 dirty pages from the owner's note. The owner pointed out that a
real framework is already on this box — `../symfony-ignis` — so the assumption was measurable all
along. It is now measured, and **it was conservative by a factor of four and a half**.

`bench/e19/dirty-probe.php` boots the Symfony kernel under stock `php` (same libphp, no runtime
noise), clears the kernel's soft-dirty marks, handles one request, and counts the process's
anonymous writable pages that came back marked. No barrier, so the probe does not disturb what it
measures.

| | |
|---|---|
| boot heap, `memory_get_usage(true)` | **2.0 MiB → 6.0 MiB** after boot + first request |
| process RSS | 23.8 MiB → 27.6 MiB |
| anonymous writable pages in the process | 930 (3.6 MiB), 18 regions |
| **dirty pages per request** | **20** on the first, then **11–12**, ten requests running |

11 pages, not 50. Re-run of the same three mechanisms at the measured numbers (6 MiB arena):

| | 11 dirty pages | 20 dirty pages |
|---|---:|---:|
| **A. `mprotect` + `SIGSEGV`** (the proposal) | **71.0 µs** | 118.3 µs |
| B. whole-arena memcpy | 119.7 µs | 121.5 µs |
| C. soft-dirty | 141.0 µs | 141.5 µs |

**The owner's mechanism fits its own budget: 71 µs against 100.** It is also the *only* one of the
three that does, at this arena size — B and C are both O(arena) and a 6 MiB boot heap already puts
them over. The earlier verdict is withdrawn: it was arithmetic on an assumed input, and the input
was wrong.

Three caveats, so the number is not read as more than it is:

1. This is one Symfony route returning JSON. Doctrine, sessions, Twig and a fuller service graph
   will dirty more pages; the budget is met with 29 µs of headroom, which is ~4 more pages.
2. The count is the whole process's anonymous pages, which over-counts the boot heap — so 11 is an
   upper bound, and the real boot-heap figure can only be smaller.
3. Nothing here says the mechanism is *correct*, only that it is affordable. Everything under "Not
   measured here" still stands: extension C-state outside the arena, file descriptors opened during
   a request, and the skipped destructors.

E19-R2 is answered; the ADR is unblocked, and the mechanism it should specify is the one the owner
specified.
