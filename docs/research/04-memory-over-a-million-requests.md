# Research 04 — Where a worker-mode process can leak, and how to measure it

Date: 2026-09-16 (Cycle 4). Sources: php/ignis.php, crates/ignis/src/{reactor,http}.rs,
main/main.c `php_request_shutdown` (not called per HTTP request in worker mode),
V-4/V-5 RSS notes.

## Candidate leaks per request (worker mode = one PHP request for the process lifetime)

| where | what could grow | mitigation in place |
|---|---|---|
| Rust `Reactor.responders` HashMap | a oneshot per request | removed in `respond()`; if PHP never answers (handler crash without catch) the entry stays → the loop answers 500 for every throwable, so only a Zend bailout can leak one |
| Rust `Reactor.inflight` counter | drift if poll and deliver disagree | decremented per completion drained; would only stall, not leak memory |
| hyper connections / tokio tasks | one task per connection, one future per request | dropped on connection close; keep-alive connections are bounded by wrk's `-c` |
| PHP `Loop::$waiting` / `$ready` / `$idle` | fibers parked forever if an op never completes | timers always complete; requests always get a response |
| PHP `Future` objects | one per request (`dispatchRequest` ignores the returned Future) | no references remain after the pool fiber settles it → freed by refcount, unless a cycle exists (Future ↔ waiters ↔ Fiber). No cycle: dispatch never awaits |
| PHP `Request`/`Response` objects, event arrays from `ignis_poll` | per request | refcounted, dropped at end of handler |
| Zend interned strings, opcache | first-touch only | fixed after warmup |
| Fiber pool | grows to peak concurrency, never shrinks | bounded by `-c` |
| zend_mm heap fragmentation | chunk-level growth | `memory_get_usage(true)` shows it |

Nothing runs `gc_collect_cycles()`; PHP's GC runs on its own root-buffer threshold (10k roots) — if cycles exist they are collected, if not GC never runs. Both are fine for E3 as long as RSS is flat.

## Measurement

- `/stats` returns `rss_kb` (VmRSS from `/proc/self/status`), `mem` (`memory_get_usage()`), `mem_real` (`memory_get_usage(true)`), `resumes`, `fibers`.
- Sample after a 5 s warm-up (pool + opcache + interned strings settled), then after each 3 s wrk burst (~350k requests at V-5 rates) until ≥ 1M requests. Flat = last/first RSS within ±2%.
- Second workload: `/sleep?ms=1` with 500 connections (timers + pool of 500 fibers + 10k-entry churn in `Loop::$waiting`) for 200k+ requests; third: `/cpu` (md5 + string churn) for 5 s.
