# Research 08 — Cancellation on client disconnect and per-request deadlines

Date: 2026-09-16 (Cycle 8). Sources: `crates/ignis/src/http.rs` (service future awaits a oneshot),
hyper 1.11 `proto/h1/dispatch.rs` (the in-flight service future is dropped when the connection
task ends), `Zend/zend_fibers.c` (`zend_fiber_resume_exception`, ZEND_API in 8.5.10 — research 00),
`Fiber::throw()` userland, php/ignis.php (`Loop::$waiting`, dispatch).

## Facts
- hyper drops the request-handling future (our `handle()`) together with the connection when the
  client goes away; anything awaiting inside it (the oneshot receiver) is dropped. A guard struct
  with `Drop` is the standard way to observe that. Verified empirically in V-14.
- A fiber parked in userland (`Fiber::suspend()` inside `Ignis\sleep`/`await`) is resumed with an
  exception by `$fiber->throw($e)`; one parked in a C stream op (ADR-0007) by
  `zend_fiber_resume_exception(fiber, exception)`. In both cases the exception is thrown at the
  suspension point and unwinds the handler normally (`finally` blocks run).
- Children: `Ignis\async()` inside a request fiber can be attributed to the request by reading a
  fiber-scoped request id (`Ignis\Scope`) at spawn time.

## Design (from the pre-cycle notes)

Detection (Rust, hyper): when the client disconnects, hyper drops the connection task and with it our
`handle()` future while it awaits the oneshot receiver. Drop of the future = drop of `rx`. Wrap the
await in a guard struct whose Drop (when the response was not yet received) calls
`reactor.cancel_request(id)` -> pushes Completion{id, Outcome::Cancelled}. The responder Sender stays
in the map until PHP answers (then `respond()` returns false because the receiver is gone) or until
the cancel path removes it.

Delivery (PHP loop): ignis_poll returns `[$id => ['kind' => 'cancel']]` (a special array payload) ->
Loop::cancelRequest($id): find the request fiber (Loop keeps `$requestFibers[$id] = $fiber` from
dispatch) and its pending op ids; if the fiber is parked in userland ($waiting[$opId] === $fiber) ->
unset and `$fiber->throw(new CancelledException)`; if parked in C (stream op) -> Rust
`ignis_cancel_parked($opId)` calls zend_fiber_resume_exception(fiber, CancelledException). The
exception unwinds the handler (finally blocks run), dispatch catches Throwable -> respond() (no-op,
receiver gone). Children: Loop::spawn records `$parent = current request id` (Scope); cancel walks
children first. "Within 10 ms": the cancel completion arrives on the next poll; the loop is always in
poll when idle, so latency = one poll wake (~µs) + the time until the fiber's next suspension point.
Deadline: Ignis\deadline(int $ms): submit a sleep op tagged as deadline for the current request; when
it fires -> same cancel path with DeadlineExceededException. Child fibers inherit the request id, so
the deadline covers them.

Test: /slow handler sleeps 5s; client (curl -m 0.2) disconnects; /stats shows cancelled=1 within
10 ms (measure: record hrtime at cancel arrival vs hyper drop time via a Rust-side timestamp in the
completion). Second test: handler with Ignis\deadline(100) and sleep(1000) -> 504 in ~100 ms.
