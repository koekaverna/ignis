# Research 07 — A Revolt driver over the Ignis reactor

Date: 2026-09-16 (Cycle 7). Sources: `revolt-event-loop/src/EventLoop/Internal/AbstractDriver.php:395-440,516`,
`Driver/StreamSelectDriver.php` (activate/dispatch/deactivate/getTimeout), `DriverFactory.php:58` (`REVOLT_DRIVER` env),
`Internal/TimerQueue.php`, `revolt-event-loop/examples/*`, `main/php_streams.h` (`PHP_STREAM_AS_FD_FOR_SELECT`).

## Facts
- A driver extends `Internal\AbstractDriver` and implements exactly `activate(array $callbacks)`,
  `dispatch(bool $blocking)`, `deactivate(DriverCallback)`, `now(): float`. The base class owns the
  loop fiber, the callback fiber, suspensions, defer/queue microtasks and error handling.
- `StreamSelectDriver::dispatch()` = select on read/write streams with `getTimeout()` derived from
  `TimerQueue::peek()`, then extract due timers and `enqueueCallback()` them. Signals via pcntl.
- `DriverFactory` honours `REVOLT_DRIVER=<class>`: any autoloadable `Driver` subclass, no code change
  in the application. That is the mechanism for "AMPHP examples unchanged".
- Readiness on real fds: `_php_stream_cast(stream, PHP_STREAM_AS_FD_FOR_SELECT, &fd, 0)` yields the fd
  of a socket/pipe stream; tokio's `AsyncFd` can wait for readable/writable on a dup of it. The Ignis
  `tcp://` transport (ADR-0007) has **no fd** — AMPHP's socket layer (`stream_socket_client` +
  `onReadable` + non-blocking `fread`) therefore runs with the transport hook **off**
  (`IGNIS_NO_STREAM_HOOK=1`), which is also the correct layering: AMPHP already owns its I/O.
- Revolt's own `examples/` cover timers (`benchmark-timers*.php`, `benchmark-ticks*.php`), defer,
  fiber-local and `onReadable(STDIN)` (`consume-stdin.php`, `generate-yes.php`). `amphp/amp` v3
  adds `delay()`, `async()`, `Future`; `amphp/socket` adds real TCP via `onReadable/onWritable`.

## Design
- Rust: `Op::Watch { fd, write }` → `AsyncFd::with_interest(dup(fd))` → `.readable()/.writable().await`
  → `Outcome::Ready`. One-shot: the driver re-arms on the next `dispatch` while the callback stays
  enabled. `ignis_watch(resource $stream, int $mode): int` (1 = read, 2 = write) casts the stream to an
  fd and submits.
- PHP `Ignis\Revolt\IgnisDriver extends AbstractDriver`: timers in Revolt's `TimerQueue`; `dispatch()`
  = `ignis_poll(blocking ? timeout : 0)` (ms, -1 = forever) → map op id → callback → `enqueueCallback`;
  then extract due timers. Signals: `UnsupportedFeatureException` (pcntl is not built).
- Both loops (Ignis\Loop and the Revolt driver) call the same `ignis_poll`; they must not be mixed in
  one thread — a Revolt program owns the thread's reactor.

## Surprises
- Nothing in AbstractDriver needs fibers from the driver: the shape predicted in ADR-0001 (c) holds
  literally — four methods.
- `dispatch()` is called with `blocking=false` when microtasks are pending, so `ignis_poll(0)` must
  return immediately even with nothing in flight (it does: V-1 test `poll_on_empty_reactor_does_not_block`).
