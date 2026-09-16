# ADR-0010 — Least-inflight dispatch (push model), shared queue deferred

Status: accepted (Cycle 9, 2026-09-16; accepted by V-15 — `/cpu`@4 p99 12.4–12.8 ms vs FrankenPHP's 15.6 ms at 9.1–9.3k req/s, so the kill criterion for the pull queue was not met). Context: V-9 p99 gap on CPU-bound work. Decision: pick the
PHP thread with the fewest unanswered requests (`Reactor::pending_requests()`), rotating ties. Rejected
for now: a shared pull queue where idle PHP threads take requests (FrankenPHP model) — it needs
`ignis_poll` to consume from two sources and is only justified if V-15 still shows a tail gap.
Pain-map: ADR-0004's "made worse" item (busy thread still receives 1/N) is removed if V-15 holds.
Kill criterion: p99 on `/cpu` at 4 threads not within 10% of FrankenPHP's → implement the pull queue.
