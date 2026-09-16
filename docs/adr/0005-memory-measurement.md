# ADR-0005 — RSS is the E3 metric; measured from inside the process

Status: accepted (Cycle 4, 2026-09-16; accepted by V-10 — `/stats` RSS 26.9 → 26.2 MB over 1.5M requests with the PHP heap flat to the byte; the kill criterion (> 2% growth) was not triggered)

Context: E3 says "RSS flat (±2%) over 1,000,000 requests". Options: (1) sample
`/proc/<pid>/status` from the bench script; (2) expose it via `/stats` from PHP
reading `/proc/self/status`. Decision: (2), plus `memory_get_usage()` so a PHP-heap
leak is distinguishable from allocator retention. Consequence: the metric is
one HTTP call, reproducible by anyone with curl. Pain-map: RoadRunner 2
(leaks handled by restarts) — this is the detection half; the supervisor half
is E12. Kill criterion: if RSS grows > 2% between the 500k and 1M samples the
loop has a per-request leak and Cycle 4 becomes a leak hunt before anything else.
