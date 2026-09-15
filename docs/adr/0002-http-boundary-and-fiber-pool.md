# ADR-0002 — Value-based HTTP request boundary and a userland fiber pool

Status: accepted (Cycle 1, 2026-09-15)

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
