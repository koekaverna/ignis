# Research 02 — PHP async scheduler ABI (PR #22561) and the true-async fork

Date: 2026-09-15/16 (Cycle 2). Owner direction: read the RFC, PR #22561 and the
`true-async/php-src` `async-core` branch; decide on a two-backend reactor.

## Sources actually read

| source | how | note |
|---|---|---|
| wiki.php.net/rfc/async_scheduler_abi | **blocked** by the network allowlist (HTTP 000 on the one allowed attempt) | logged in STATUS.md; the PR head carries the same design in `Zend/zend_async_API.h` comments |
| php/php-src PR #22561 | `git fetch origin pull/22561/head` into `/home/user/php-src` (GitHub API is out of scope for this session) | head = `14af3cb` 2026-07-16 "async: coroutine engine core, the reference scheduler and the coroutine context", author Edmond |
| true-async/php-src `async-core` | `git clone --depth 1 --branch async-core` → `/home/user/php-src-async` | **same commit `14af3cb`** as the PR head; version `8.6.0-dev` |
| php/php-src master (2026-09-15, `38956a3f`) | `git fetch --depth 1 origin master` | has `main/poll/` + `main/php_poll.h` (poll backend abstraction landed); has **no** `Zend/zend_async_API.*` and no `ext/test_scheduler` — the ABI is unmerged |

## What the ABI is (from `Zend/zend_async_API.h`, 698 lines, and `Zend/zend_async_API.c`, 744 lines)

- A **scheduler provider** registers a slot table once per process:
  `zend_async_scheduler_register(const char *module, const zend_async_scheduler_api_t *api)`.
  Slots: `new_coroutine`, `gc_new_coroutine`, `enqueue_coroutine`, `suspend`,
  `cancel`, `launch`, `shutdown`, `get_class_ce`, `call_on_main_stack`,
  `defer` (microtasks), `coroutine_from_object`, `intercept_fiber`,
  `coroutine_execute_data`, add/remove `switch_handler`, `finish_handler`,
  `awaiting_info`, `await`. Each slot is a `ZEND_API extern` function pointer
  with a default; macros `ZEND_ASYNC_SUSPEND()`, `ZEND_ASYNC_ENQUEUE_COROUTINE()`
  etc. call through them.
- `zend_coroutine_t`: status bits, optional PHP object (container_of), fcall or
  C entry, result zval, exception, spawn location, per-coroutine
  `internal_context` HashTable (C extensions, numeric keys allocated per
  process by name) and a lazy `Async\Context` object. This is the engine-level
  answer to "fiber-scoped state" (E13) — for coroutines, not for `$_SERVER`.
- Who calls into the ABI: **only Zend core** — `zend_fibers.c` (Fiber::start →
  `ZEND_ASYNC_INTERCEPT_FIBER` + enqueue; Fiber::suspend/resume → `ZEND_ASYNC_SUSPEND`
  / enqueue when a scheduler is on), `zend_gc.c` (GC runs destructors in a GC
  coroutine via `defer` microtasks; `gc_new_coroutine` slot), `zend_execute_API.c`
  (main-flow suspend, `ZEND_ASYNC_RUN_SCHEDULER_AFTER_MAIN`), `zend_objects_API.c`.
  `grep -rl ZEND_ASYNC_ON main/ ext/` (minus `ext/test_scheduler`) returns **nothing**.
- Reference scheduler `ext/test_scheduler` (1964 lines, 61 .phpt tests): a
  FIFO run queue on its own `zend_fiber_context`; `suspend` switches to the
  scheduler context; `launch` adopts the main flow as a coroutine; **when the
  queue is empty and coroutines are still parked it raises `DeadlockError`**
  ("this scheduler has no reactor to deliver it", test_scheduler.c:1150). A real
  provider plugs its reactor exactly there: block in `poll()` instead of
  declaring a deadlock.

## What this means for Ignis

1. **E6 does not fall out of the ABI.** Streams, sockets, PDO and `sleep()` do
   not consult `ZEND_ASYNC_*` in this PR; a blocking `fread()` still blocks the
   thread. Stream-level hooks (Swoole route, research 01) are needed on **both**
   backends. This rules out "prototype E6 on (b) first" as a shortcut: it would
   be the same hook work on a moving fork. Recorded as a refutation in
   HYPOTHESES.md (H9a).
2. **What (b) does give:** engine-owned coroutines (no userland `Fiber` objects
   in the loop), a per-coroutine context, GC/destructor integration (the
   owner's pain-map item: GC destructors run in a dedicated GC coroutine via
   `defer`, so `Fiber::suspend()` inside a destructor is scheduler-mediated
   instead of forbidden), cancellation as a first-class slot (E11), and
   `switch_handler`s per coroutine (E13). Ignis as a **provider** = implement
   the slot table in Rust and put `reactor.poll()` where the reference
   scheduler raises `DeadlockError`.
3. **Cost of (b):** ~2k lines of C in the reference provider to mirror; the ABI
   is unmerged (targets 8.6, not 8.5) and its RFC is not readable from here;
   `size` field in the slot table hints the struct will grow.
4. **(a) stays the production path**: mainline 8.5.10, userland `Ignis\Loop`,
   `zend_observer_fiber_switch` for E13, stream hooks for E6. Confirmed
   numbers (V-2..V-6) are all on (a).

## Surprises

- The fork branch is literally the PR head, not ahead of it.
- Mainline master already merged the `main/poll` backend abstraction (epoll/
  kqueue/eventport/wsapoll) that the fork's I/O work builds on — that is the
  piece a stream hook on (a) should target for 8.6.
- GC in async mode gets its own coroutine and microtask queue
  (`zend_gc.c:2053-2088`): the engine's answer to "destructors may switch
  context" is to move destructor runs off the current coroutine.

## Ruled out

- Reading the RFC text from this VM (blocked host, logged).
- E6-on-(b)-first (no I/O integration to build on).
