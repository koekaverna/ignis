# Research 01 — HTTP transport into a PHP thread, and pooling fibers

Date: 2026-09-15 (Cycle 1). Sources on disk: `~/.cargo/registry/src/*/hyper-1.11.1`,
`hyper-util-0.1.20/src/server/conn/auto/mod.rs`, `hyper-util/src/rt/tokio.rs`,
`/home/user/frankenphp/{frankenphp.c,worker.go,threadworker.go}`,
`/home/user/php-src/Zend/zend_fibers.c`, V-2 perf profile.

## 1. What the V-2 profile says the pool must remove

Per fiber lifetime Zend does: `mmap` (C stack, 2 MiB virtual) + `mprotect`
(guard page) + emalloc 16 KiB VM stack + first-touch page faults on both, then
`munmap` at destruction. In a multi-threaded process every `munmap` broadcasts
TLB shootdown IPIs to the other running threads (tokio workers). Measured:
~50% of PHP-thread CPU (V-2). None of it is proportional to `fiber.stack_size`
(tested 64K..2M, < 1% change).

A fiber that never terminates pays this once. Zend has no fiber-stack cache
(`zend_fiber_stack_allocate`/`_free` are plain mmap/munmap, zend_fibers.c:249/286),
so the reuse must be done in userland: the pool fiber body is
`while (true) { $job = Fiber::suspend(); run($job); }`.

Zend constraints checked for long-lived fibers:
- `Fiber::resume()` requires status SUSPENDED and `caller == NULL`; a parked
  pool fiber satisfies both (zend_fibers.c:715).
- A fiber object is only destroyed when its refcount drops; the pool array keeps
  it alive. On request shutdown, `zend_fiber_object_destroy` unwinds
  non-terminated fibers with a graceful exit — so the pool needs no explicit
  teardown (zend_fibers.c: `zend_fiber_object_destroy`).
- Exceptions thrown by a job must be caught inside the pool fiber, otherwise
  the fiber dies and the pool shrinks; the loop counts that as a bug.

## 2. hyper 1.x server shape (verified in hyper-util 0.1.20)

- `hyper_util::server::conn::auto::Builder::new(TokioExecutor::new())`
  `.serve_connection(TokioIo::new(tcp_stream), service)` — one future per
  connection; HTTP/1.1 and h2 (prior knowledge / ALPN) auto-detected
  (`auto/mod.rs:213`).
- `service` is `hyper::service::service_fn(|req: Request<Incoming>| async {...})`
  returning `Result<Response<B>, E>`; body type `http_body_util::Full<Bytes>`
  is enough for responses; request bodies are collected with
  `http_body_util::BodyExt::collect`.
- Nothing in hyper needs to know about PHP: the service future sends a plain
  struct over a channel and awaits a `oneshot::Receiver` for the response.
  That keeps ADR-0001's "no Zend pointer on tokio threads" intact.

## 3. FrankenPHP's request boundary (for comparison and for E4 fairness)

- `frankenphp_handle_request($cb)` (frankenphp.c:844) blocks the PHP thread
  on a Go channel (`threadworker.go:230`), then runs a *partial*
  `php_request_startup` copy (`frankenphp_worker_request_startup`, adapted
  from php_request_startup, frankenphp.c:565) to reset `$_SERVER`, output,
  headers per request, then `zend_call_function($cb)`. One request per thread
  at a time; concurrency = threads.
- Consequence for Ignis: PHP's per-request state (`SG(request_info)`,
  `$_SERVER`, `header()`, output buffers `OG(...)`) is **per thread**, not per
  fiber. With N interleaved requests per thread we cannot reset it per
  request. Cycle 1 therefore models a request as values: `Request` object in,
  `Response` object out, both plain PHP. `echo` inside a handler goes to
  stdout (documented limitation; per-fiber output capture via the
  `zend_observer_fiber_switch` hook is a later item, see GOALS).
- FrankenPHP worker mode is the right baseline: same libphp, same box.

## 4. Data crossing the boundary per request

PHP → Rust: `ignis_respond(int $id, int $status, array<string,string> $headers, string $body)`.
Rust → PHP: an event array from `ignis_poll()`:
`[$id => ['kind' => 'request', 'method' => ..., 'uri' => ..., 'headers' => [...], 'body' => ...]]`.
Strings are created with `add_assoc_stringl_ex`/`add_next_index_stringl`
(ZEND_API; they allocate the `zend_string` themselves, so no hand-written
string layout is needed). Reading the PHP array in `ignis_respond` uses
`zend_hash_get_current_*` iteration via `ZEND_HASH_FOREACH`, which is a macro:
reimplemented with `zend_hash_internal_pointer_reset_ex` /
`zend_hash_get_current_key_ex` / `zend_hash_get_current_data_ex` /
`zend_hash_move_forward_ex` (all ZEND_API).

## 5. What this rules out / surprises

- No way to get per-fiber `$_SERVER`/`header()` without patching PHP; so the
  API is value-based. Symfony/Laravel runtimes already return Response objects
  (E8 is unaffected); legacy `echo` scripts are out of scope for now.
- Surprise: FrankenPHP disables the execution timer while blocked in
  `frankenphp_handle_request` (`zend_unset_timeout`); Ignis must do the same
  around `ignis_poll` or `max_execution_time` will kill the worker loop.
  Cycle 1 sets `max_execution_time=0` for the worker script; a proper
  per-job timer is a later item.
