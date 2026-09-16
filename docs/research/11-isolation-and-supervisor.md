# Research 11 — Thread isolation, fatal errors and a supervisor

Date: 2026-09-16 (Cycle 11). Sources: `main/main.c` (`php_execute_script` → `zend_try`, returns false on bailout),
`Zend/zend.c` (`zend_error` E_ERROR/E_CORE_ERROR → `zend_bailout`), `TSRM/TSRM.c` (`ts_free_thread`, a
later `ts_resource(0)` on a *new* thread allocates a fresh resource block), `ext/opcache/ZendAccelerator.c`
(SHM is process-wide; `accel_activate` per request, no per-thread state that a thread's death invalidates),
`crates/ignis/src/main.rs` (thread spawn), V-15 (a dead thread must leave the dispatch registry).

## Facts
- A fatal error (`E_ERROR`, `E_USER_ERROR`, uncaught bailout) inside a worker's script longjmps to the
  `zend_try` in `php_execute_script`, which returns `false`. Our `WorkerThread::run_file` then returns;
  `Drop` does `php_request_shutdown` + `ts_free_thread`. The OS thread ends. Nothing else in the process
  is touched: other threads' TSRM blocks, the opcache SHM, the tokio runtime, the listener.
- A new OS thread can call `ts_resource(0)` at any time after `php_module_startup`; it gets a fresh
  resource block with all ctors run (research 03). Compiled scripts stay in opcache SHM: the respawned
  thread does not recompile, and no `opcache_reset()` happens (FrankenPHP pain-map item 6).
- A fiber that loops on CPU never reaches a suspension point; only its thread stalls. Least-inflight
  dispatch (V-15) stops routing new requests there once its pending count exceeds the others'.
  Detection: every `ignis_poll` call stamps a per-thread "last active" time; a tokio task compares.
- In-flight requests on the dead thread are lost: their responders die with the reactor
  (hyper answers 500 "no response from php" via the dropped oneshot).
- Not covered: a crash in C (segfault) kills the process — that is a Zend/extension bug class the
  pain map lists under "remains true for Ignis too".

## Design
- `main.rs`: the supervisor loop joins worker threads; when one exits (script ended by fatal or `exit()`),
  it spawns a replacement with the same index, bounded to N restarts per minute, logging the reason.
- Watchdog: `Reactor::touch()` in `ignis_poll` (µs timestamp, atomic); a tokio task every 250 ms logs
  threads idle-in-PHP for > 1 s (`stalled_threads` exposed via a new `ignis_stats()` internal function).
