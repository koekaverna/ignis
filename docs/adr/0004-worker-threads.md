# ADR-0004 — N PHP worker OS threads, one reactor each, round-robin HTTP dispatch

Status: accepted (Cycle 3, 2026-09-16)

## Context
E5 needs multi-thread; E4' needs a fair 4-thread comparison. Research 03.

## Options
1. **Thread-per-core PHP workers, one reactor each, HTTP dispatch round-robin** (chosen).
2. One PHP thread + work-stealing between fibers: impossible, Zend globals are per thread.
3. Process-per-core (fpm style): loses the shared opcache and in-process Table story.

## Decision
Option 1. `ignis --threads N script.php` (env `IGNIS_THREADS`). Thread 0 is the
main thread (already initialised by `php_embed_init`); threads 1..N call
`ts_resource(0)` + `php_request_startup()` and run the same script. The
listener is bound once; each request goes to reactor `counter % N`.

## Pain-map items affected
- FrankenPHP 3 (sizing formula): threads = cores, fibers = concurrency — now literal.
- Swoole 6/7, E12 (one blocking call / fatal affects one thread): groundwork; a
  crashed thread is not restarted yet (supervisor is a later item).
- Made worse: request dispatch ignores per-thread load (a thread stuck in CPU
  work still receives 1/N of new requests). Least-inflight dispatch is the fix.

## Kill criterion
If the in-process CPU test shows < 2.5× throughput at 4 threads (ZTS lock
contention inside libphp, e.g. on the allocator or interned strings), the
thread model needs profiling before any HTTP work continues.
