# Research 09 — Request dispatch across PHP threads

Date: 2026-09-16 (Cycle 9). Sources: `crates/ignis/src/http.rs` (`Registry::pick`, round-robin), V-9
(`/cpu` at 4 threads: Ignis 9,285 req/s p99 17.5 ms vs FrankenPHP 7,278 req/s p99 15.6 ms), FrankenPHP
`worker.go:237-271` (a request is handed to *any* idle worker thread via a shared channel: work-conserving).

## Facts
- Round-robin sends 1/N of new requests to a thread that is busy in CPU-bound PHP; those requests wait
  behind it while other threads may be idle → tail latency. FrankenPHP's shared channel is
  work-conserving by construction (a busy thread does not take from the channel).
- The reactor already knows how many requests a thread has not answered yet: the `responders` map
  (one oneshot per unanswered request). That is the exact "in flight" count for dispatch; `inflight`
  (submitted ops) is not — a thread with 10k parked sleepers is idle.
- Registries have ≤ 16 entries; a linear scan under one mutex per request is ~50 ns, negligible next to
  the request itself (≥ 7 µs on the hello path, V-5).

## Design
`Registry::pick` = the reactor with the smallest `pending_requests()`; ties broken by rotating start
index so equal-load threads still alternate. No queueing in Rust (a request is still assigned
immediately); a true shared queue (pull model) is the next step if the tail is still worse than
FrankenPHP's.
