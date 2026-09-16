# ADR-0002 — Value-based HTTP request boundary and a userland fiber pool

Status: accepted (Cycle 1, 2026-09-15; accepted by V-4 (warm pool: overhead 37–41 ms, 4.4–4.6 µs per job), V-5 (122–130k req/s over hyper) and V-6 (128k vs FrankenPHP 27.6k))

## Context

Cycle 0 confirmed E1/E2 but showed ~50% of PHP-thread CPU in fiber stack
mmap/munmap (V-2) and E1 refuted under load (V-2 addendum). Cycle 1 adds the
HTTP transport (E4 needs it) and must remove the per-fiber lifecycle cost.
PHP request globals are per thread, so N interleaved requests per thread
cannot each get their own `$_SERVER`/`header()` (research 01 §3).

## Options

1. **Value-based boundary**: `Ignis\serve(fn(Request): Response)`; Rust hands
   PHP a plain array per request, PHP calls `ignis_respond()` with status,
   headers, body. `echo` goes to stdout.
2. FrankenPHP-style per-request `php_request_startup` emulation: correct
   superglobals, but forces one request at a time per thread — kills the thesis.
3. Patch Zend to make SG/OG fiber-local: not "unmodified PHP"; out of scope.

Fiber reuse:
1. **Userland pool** in `Ignis\Loop`: parked fibers resumed with a job.
2. Rust-side pool via `zend_fiber_resume`: same Zend cost, more unsafe.

Transport:
1. **hyper-util `auto` builder** (h1 + h2) on the tokio runtime, one service
   future per connection, `mpsc` to the PHP thread, `oneshot` back.
2. Custom h1 parser: rejected, hyper is the shared stack E10 (tonic) needs.

## Decision

Options 1 / 1 / 1. Request ids are the reactor op ids so the same `poll`
path delivers timers and requests. The PHP thread never awaits a tokio future.

## Consequences

- Handler code must return `Response`; `header()`/`echo` are not captured.
  Documented in examples/app.php.
- The pool never shrinks in Cycle 1 (bounded by peak concurrency); an idle
  trim is a later item.
- Unanswered requests (handler throws) are answered 500 by the loop, never
  left hanging.

## Kill criterion

- If E4 hello-world p99 with the value-based boundary is worse than
  FrankenPHP worker mode by > 2× on 1 thread, the boundary (array building
  per request) is the suspect: measure `ignis_poll` array cost with
  criterion and consider a lazy `Request` object backed by a Rust handle.
- If the pool does not cut per-item overhead below 5 µs (E2'), the 16 KiB VM
  stack first-touch is the residual and the pool decision stands anyway
  (it is still the cheapest option), but E2' is refuted.

## Addendum (owner ADR sweep, 2026-09-17) — the network path never waits for PHP

**Status: accepted** (main agent). What this ADR decided in Cycle 1 and what has been built on it
since, stated as one rule: *nothing on the network path ever enters Zend.*

**Context.** hyper 1.x (auto h1/h2) and tonic share one listener on the tokio runtime (V-6, V-20);
requests are delivered to a PHP thread as plain data over the reactor's completion channel
(`deliver_request_with_id`) and answered through a oneshot (`ignis_respond`), so a PHP thread has
exactly one wait point (ADR-0001). Admission control (ADR-0019, V-37), health (`/_ignis/health`,
V-38) and, once built, metrics (BACKLOG M4-4) are answered on the tokio side. Dispatch is
least-inflight and skips a thread that has stopped polling (ADR-0010, V-15, V-17: one stalled
thread, the other three at 90k req/s).

**Options considered.** (a) hyper inside the PHP threads — rejected: a stalled PHP thread would
stall its accept loop and its in-flight parsing, and TLS/h2 framing would compete with Zend for
the same core. (b) One tokio runtime per PHP thread — rejected for the same reason plus N copies
of every pool. (c) This: accept, TLS, HTTP/1-2 and gRPC framing, parsing, the admission queue,
shedding, health and metrics on tokio; PHP consumes a queue of parsed requests and returns
responses through it.

**Decision.** (c). The queue between the two worlds is bounded (ADR-0019: `budget.fibers` per
thread, `budget.queue`, 503 + `retry-after` past it); the dispatcher never hands a request to a
thread that is not polling; bodies are collected on tokio before handoff today (`body.collect()` in
`http.rs`) and will stream from tokio after handoff when streamed responses land (Phase C, E8').

**Consequences.** Better: a wedged PHP thread costs one thread's capacity, never the listener
(V-17); the runtime can say "unhealthy" when PHP cannot (V-38); TLS termination on the listener
(ADR-0032, deferred) slots in without touching PHP. Worse: every request pays one channel
crossing in and one out — the wakeup pair measured in V-33 (93 µs at one request in flight,
0.58 µs at 128); ADR-0033 records the per-thread-reactor answer and its trigger. Affects E4, E5',
E11, E12, B1/M4-3.

**Follow-up.** Opcode-boundary preemption of a CPU-bound fiber (ADR-0030, proposed) is the
escalation for a thread that is polling too rarely rather than not at all.

**Kill criterion.** A measured request path where the tokio→PHP→tokio crossing exceeds 5 % of
service time on a real application (the ADR-0033 trigger) — that reverses "one queue crossing
each way" but not "the network path never enters Zend".
