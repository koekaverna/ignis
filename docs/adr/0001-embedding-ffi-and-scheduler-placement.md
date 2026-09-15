# ADR-0001 — Embedding via raw bindgen, reactor in Rust, scheduler in PHP userland

Status: accepted (Cycle 0, 2026-09-15)

## Context

Ignis must run many PHP requests per OS thread on native Fibers with I/O waits
owned by tokio. Cycle 0 must choose (a) how Rust talks to Zend, (b) which
thread runs PHP and how it talks to tokio, (c) where the fiber scheduler
lives. See docs/research/00-environment-and-fiber-api.md for verified facts.

## Options considered

### (a) FFI layer
1. **Raw bindgen over `sapi/embed/php_embed.h`** (ZTS headers from our own
   PHP 8.5.10 build). Macros reimplemented by hand as small `unsafe` helpers.
2. ext-php-rs 0.15.15 — extension-oriented; does not own SAPI startup, ZTS
   status unverified for 8.5.
3. phper 0.17.3 — same shape as 2.

### (b) Thread / runtime model
1. **tokio multi-thread runtime for I/O + dedicated PHP OS threads**, each
   with its own `ts_resource(0)` context; threads talk only via channels
   (crossbeam/std mpsc for PHP→tokio submits, a completion channel back).
2. Run PHP on a tokio worker thread with `block_in_place` — rejected: PHP
   holds the thread for the whole request loop and would starve the runtime;
   also violates "PHP callbacks never touch tokio".
3. tokio-uring — rejected for now: single-threaded runtime per core and a
   different I/O model; epoll via tokio is enough to test the thesis. Revisit
   if E4 shows syscall-bound behaviour.

### (c) Scheduler placement
1. **PHP userland scheduler** (`Ignis\Loop`): Rust exposes
   `ignis_submit(op, ...) -> int id` and `ignis_poll(timeout_ms) -> array`,
   PHP owns the `Fiber` objects and resumes them.
2. Rust-side scheduler calling `zend_fiber_resume()` directly from an
   internal `Ignis\run()` function.

## Decision

- (a) **Raw bindgen.** Only it lets us own `php_embed_module.startup`, thread
  startup (`ts_resource`) and the SAPI callbacks. The unsafe surface stays
  explicit and reviewable; miri can run on the pure-Rust helpers.
- (b) **Dedicated PHP threads + tokio runtime, channels only.** One
  `Reactor` handle per PHP thread: `submit()` pushes an op onto an unbounded
  channel consumed by a tokio task which spawns the I/O future; completions
  are pushed to a per-thread completion channel that `ignis_poll()` blocks on
  with a timeout. No Zend pointer ever crosses to a tokio thread; only plain
  data (ids, bytes, ints).
- (c) **Userland scheduler first.** Reason: Revolt's `AbstractDriver` needs
  exactly `dispatch(bool $blocking)` and friends — i.e. a submit/poll reactor —
  and already owns fiber bookkeeping. A userland `Ignis\Loop` and a Revolt
  driver are then the same object with different method names (E7 becomes a
  thin adapter, not a rewrite). A Rust-side resume loop would be a second
  scheduler competing with Revolt.

## Consequences

- We must hand-write helpers for `ZVAL_*`, argument access and array
  building. Kept in one module (`ignis-sys/src/zval.rs`) with tests.
- Per-fiber wakeup crosses PHP→C once (`ignis_poll`) per batch, not per
  fiber; per fiber cost is one `Fiber::resume()` in userland.
- The SAPI `ub_write` must be redirected to a per-request buffer once HTTP
  arrives (Cycle 1); for Cycle 0 stdout is fine.

## Kill criterion

Reverse (c) to a Rust-side `zend_fiber_resume` loop if either:
- E1 (10k fibers sleeping 1000 ms on one thread) takes ≥ 1.2 s wall and the
  profile shows ≥ 30% of the time in userland scheduler code, or
- E2 per-fiber overhead measured from userland exceeds 100 µs.

Reverse (b) to tokio-uring if a hello-world benchmark (E4) is dominated by
epoll/syscall time in `perf` (≥ 40% of cycles in kernel).
