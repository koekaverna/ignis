# ADR-0009 — Client disconnect is a cancel event; one wall-clock deadline per request; both throw at the suspension point

Status: accepted (Cycle 8, 2026-09-16)

Decision: a `Drop` guard around the pending response in hyper's service future emits
`Outcome::Cancelled` for the request id (stamped with the drop instant). The loop maps the id to the
request fiber and its `Ignis\async` children (attributed via `Ignis\Scope('request')` at spawn),
and throws `Ignis\CancelledException` into each at its suspension point (`Fiber::throw` for userland
parks, `zend_fiber_resume_exception` for C stream parks via `ignis_cancel_parked`). `Ignis\deadline(ms)`
registers a timer op tagged for the request; on expiry the same path throws
`Ignis\DeadlineExceededException` and the loop answers 504 if the handler has not responded.
Rejected: polling `connection_status()` (needs cooperation), killing the fiber without unwinding
(leaks resources, breaks `finally`). Pain-map: PHP-FPM 3/4, FrankenPHP 2, Swoole 3 → ADDRESSED if
V-14 holds. Kill criterion: cancel latency > 10 ms at p99 under load, or an exception thrown into a
fiber parked in a C op corrupting the stream state (checked by running E6 after E11).
