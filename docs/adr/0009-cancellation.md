# ADR-0009 — Client disconnect is a cancel event; one wall-clock deadline per request; both throw at the suspension point

Status: accepted (Cycle 8, 2026-09-16; accepted by V-14 — 20/20 disconnects cancelled including children at 0.78 ms worst case, `finally` ran, 504 at 102 ms for a 100 ms deadline)

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

## Addendum (owner ADR sweep, 2026-09-17) — deadlines and cancellation: the contract, and the gaps

**Status: accepted** (main agent).

**Context.** A client disconnect cancels the request fiber and its children (V-14: 0.78 ms worst
latency, 20/20 `finally` blocks ran, no phantom work); `Ignis\deadline()` gives one wall-clock
deadline per request, inherited by children (V-14: 504 at 102 ms for a 100 ms deadline). A
disconnect that used to kill a whole worker thread was fixed by absorbing the injected exception
at every throw site including the C-parked path (V-30). Watches and sleeps are cancellable ops
(`Op::CancelWatch`, E15b); an `Op::Read` is not, which is why timed reads park on a watch first
(A1, V-31) and why H31 happened.

**Decision.**
1. One wall-clock deadline per request, inherited by child fibers **and by reactor ops**. *Built
   for fibers (V-14); for ops, built where the op is cancellable (watch, sleep) and not for an
   in-flight `Op::Read`/pg query/offload job — BACKLOG M4-9 (E11').*
2. Cancellation inside an opaque C region is delivered as a **fake syscall error, `ECANCELED`**,
   never a longjmp through library frames. *Unbuilt: today a fiber parked in one of our own C hooks
   is resumed with a pending exception (`zend_fiber_resume_exception`, V-30), which is sound because
   the C frame is ours; under universal park (ADR-0020) a cancelled call falls back to blocking
   (V-45) until stage 2 delivers `ECANCELED`.*
3. `finally` runs on cancellation. *Built, V-14, V-30.*
4. `spawn()` tasks own their deadline and outlive the request; `all()` children die with it.
   *Unbuilt: `spawn` today attributes the child to the request and it is cancelled with it (E11
   semantics). A detached spawn is a new API, not a flag.*
5. An unawaited rejected Future is surfaced **at once** through a loop error handler. *Gap: today it
   is surfaced when the loop stops (V-22: rethrown instead of swallowed).*

## Absorbing the injected exception

`Fiber::throw()` on a fiber that has no handler for the exception re-throws it straight back out at
the caller. Unguarded user code is the normal case, so without absorption the cancellation walked
back up through `Loop::cancelRequest()` into the event loop and killed the whole worker thread's
script — **one disconnected client took out a quarter of the server's capacity**. Found by the A3
soak: **6 dead threads in 10,081,952 requests** (V-30), and without `--supervise` the process
itself died.

`Loop::throwAndAbsorb()` therefore swallows exactly the exception it injected and nothing else:
anything *other* than that object — a `finally` that throws while unwinding, say — is a genuine
user error and is kept for the unobserved-error report rather than swallowed.

The `ignis_cancel_parked_any()` path is guarded the same way and for a sharper reason: that is the
path that actually killed the worker threads. `zend_fiber_resume_exception` leaves the throwable
pending in C when the fiber has no handler, so it surfaces on return into PHP with no `throwInto`
frame in the trace — which is why guarding `Fiber::throw()` alone did not help.

**Options rejected.** Signals into Zend (`zend_signal`) for cancellation — a signal cannot switch
a fiber and libraries are not signal-safe; killing the thread (the pre-V-30 behaviour, by
accident) — one client took a quarter of the server.

**Consequences.** Better: cancellation is an ordinary exception for PHP code and an ordinary
error code for C code — both already have handling paths. Worse: until (2), a cancelled fiber
inside a parked C call still finishes the call. Affects E11, E11', E18.

**Kill criterion.** A library that cannot be made to return on `ECANCELED` without corrupting its
state — then that library's policy is `block` (ADR-0020) and cancellation waits for the call.
