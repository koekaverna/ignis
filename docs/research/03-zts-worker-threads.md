# Research 03 — N PHP worker threads in one ZTS embed process

Date: 2026-09-16 (Cycle 3). Sources: `TSRM/TSRM.c` (ts_resource_ex:417,
allocate_new_resource:378, in_main_thread:115), `main/main.c`
`php_request_startup`, `Zend/zend.c:1022-1025` (per-thread globals ctors),
`frankenphp.c:1495-1540` (thread loop), V-2/V-4 profiles.

## Facts (verified in source)

- `php_tsrm_startup()` (inside `php_embed_init`) marks the calling thread as
  the TSRM main thread. Any other thread becomes a PHP thread by calling
  `ts_resource(0)`: `allocate_new_resource` mallocs the thread's resource
  block and runs **every registered ctor** — compiler globals (copies the
  global function/class tables), executor globals, scanner globals, core
  globals, SAPI globals, module globals. Nothing else is required.
- `php_request_startup()` has no main-thread assumption: it activates output,
  `zend_activate()`, `sapi_activate()`, sets the timeout
  (`max_execution_time=0` in the embed hardcoded ini → no timer), hashes the
  environment and activates modules — all on the calling thread's globals.
  FrankenPHP calls it from every PHP thread.
- Teardown per thread: `php_request_shutdown(NULL)` then `ts_free_thread()`.
  `tsrm_shutdown()` only from the main thread after all others are gone.
- Opcache: in ZTS the shared memory segment is per process and threads share
  compiled scripts; the first thread to compile a file populates it.
- `php_embed_module` (SAPI struct) is process-global; `SG(...)` is per thread.
  `ub_write` writes to stdout from any thread (interleaving is possible; we
  only `echo` in benches).
- `zend_signal` is compiled out (`--disable-zend-signals`), so no per-thread
  signal bookkeeping applies.

## Design implications for Ignis

- **One `Reactor` per PHP thread** (own completion channel), all on the same
  tokio runtime. `Reactor` is already `Send + Sync` and created per handle.
- The module's `REACTOR` global becomes a `thread_local!` set by the worker
  before running the script; `zif_*` read the thread-local. Backend (b)'s
  idle hook uses the same accessor.
- HTTP: bind once (first `ignis_serve` call wins; later calls from other
  threads register their reactor). Dispatch = round-robin over registered
  reactors (atomic counter). Least-inflight is a later refinement.
- Each worker thread runs the **same script** (worker mode), so a script
  that calls `Ignis\serve()` naturally becomes N independent loops.

## What E5 can and cannot measure here

The box has 4 vCPUs; wrk (2 threads) + tokio (2 workers) + N PHP threads
compete. The in-process test (no HTTP: each thread runs a fixed CPU workload
and reports its own time) isolates ZTS scaling; the HTTP `/cpu` test shows
what a user would see on this box. Both are recorded.

## Risks

- Interned strings: `zend_interned_strings_activate()` per request/thread is
  standard; nothing to do.
- A fatal error in one thread's script ends that thread's loop only if we
  catch it: `php_execute_script` returns `false` on bailout, the thread then
  shuts its request down and exits; the others keep serving (E12 groundwork,
  not validated this cycle).
