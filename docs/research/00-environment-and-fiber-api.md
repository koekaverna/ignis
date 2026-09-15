# Research 00 — Environment, PHP 8.5 fiber API, embedding, prior art

Date: 2026-09-15 (Cycle 0). Everything below was verified against files on disk
or command output on this machine, not memory.

## Machine

| item | value |
|---|---|
| CPU | Intel Xeon @ 2.80GHz, 4 vCPU (`nproc` = 4) |
| RAM | 15 GiB, no swap |
| Kernel | Linux 6.18.44-fc-v33, Ubuntu 24.04.4 |
| `vm.max_map_count` | 65530 (matters: each fiber C stack is an mmap + guard page) |
| `ulimit -n` | 20000 (matters for 10k-connection HTTP tests; raise with `ulimit -n 65535`) |
| perf_event_paranoid | 2 (no perf for non-root; we are root) |

## Toolchain versions (verified)

| tool | version | how verified |
|---|---|---|
| rustc / cargo | 1.94.1 stable, edition 2024 available | `rustc --version` |
| tokio | 1.53.1 | `cargo info tokio` |
| hyper / hyper-util | 1.11.1 / 0.1.20 | `cargo info` |
| bytes | 1.12.1 | `cargo info` |
| bindgen | 0.73.2 | `cargo info` |
| mimalloc | 0.1.52 | `cargo info` |
| tracing / tracing-opentelemetry / opentelemetry-otlp | 0.1.44 / 0.33.0 / 0.32.0 | `cargo info` |
| criterion / loom / proptest | 0.8.2 / 0.7.2 / 1.11.0 | `cargo info` |
| ext-php-rs / phper | 0.15.15 / 0.17.3 | `cargo info` |
| cargo-nextest | 0.9.144 (installing from source, crates.io) | `cargo info` |
| PHP | 8.5.10 = newest tag in php-src (`git ls-remote --tags`), built from source | `main/php_version.h` |
| libclang | 18.1.3 (for bindgen) | dpkg |
| wrk / ab | 4.1.0 / 2.4.58 | apt |

Network policy: GitHub over git works (git proxy), raw HTTPS to github.com,
php.net, crates.io web UI and the ondrej PPA are denied (403). Crate downloads
via index.crates.io work. Go module proxy is allowed (relevant for building
FrankenPHP later).

PHP configure line actually used:

```
./configure --prefix=/opt/php85-zts --enable-zts --enable-embed=shared \
  --enable-opcache --disable-all --enable-mbstring --enable-sockets \
  --enable-pdo --with-pdo-sqlite --with-sqlite3 --enable-fibers --with-zlib \
  --enable-cli --disable-cgi --disable-phpdbg
```

Note: `--with-pdo-sqlite` requires `--enable-pdo` explicitly once
`--disable-all` is given (first configure failed on that).

## Zend fibers (Zend/zend_fibers.h, zend_fibers.c) — what matters for us

- A fiber is a `zend_fiber` object embedding a `zend_fiber_context` (C stack +
  boost.context `fcontext`). `zend_fiber_switch_context()` swaps C stacks with
  `jump_fcontext` and saves/restores the VM state (`zend_fiber_capture_vm_state`).
- Public, exported C API (ZEND_API):
  - `zend_fiber_start(zend_fiber*, zval *retval)`
  - `zend_fiber_resume(zend_fiber*, zval *value, zval *retval)`
  - `zend_fiber_resume_exception(zend_fiber*, zval *exception, zval *retval)` (new in 8.5? present in 8.5.10)
  - `zend_fiber_suspend(zend_fiber*, zval *value, zval *retval)`
  - `zend_fiber_init_context / destroy_context / switch_context` for custom fiber kinds
  - `zend_fiber_switch_block/unblock/blocked` (used by GC and destructors)
- Default C stack: `ZEND_FIBER_DEFAULT_C_STACK_SIZE` = 4096*512 = 2 MiB (64-bit),
  mmap'd lazily, plus 1 guard page (`ZEND_FIBER_GUARD_PAGES`). VM stack
  page per fiber: `ZEND_FIBER_VM_STACK_SIZE` = 1024*sizeof(zval) = 16 KiB
  (emalloc). Configurable via ini `fiber.stack_size`.
- `zend_fiber_resume` asserts `status == SUSPENDED && caller == NULL`, sets
  `stack_bottom->prev_execute_data = EG(current_execute_data)`, then switches.
  It is callable from any internal function running on the thread that owns
  the EG. There is nothing in it that requires the caller to be PHP userland.
- Fiber switch observers: `zend_observer_fiber_init_register`,
  `zend_observer_fiber_switch_register(from, to)`,
  `zend_observer_fiber_destroy_register` (Zend/zend_observer.h:162-172). This is
  the hook for propagating tracing spans across fiber switches.
- Exceptions crossing a resume: `zend_fiber_delegate_transfer_result` rethrows
  via `zend_throw_exception_internal` in the resumer. A C caller of
  `zend_fiber_resume` must check `EG(exception)` afterwards.

Surprise: `zend_fiber_resume_exception` exists as ZEND_API in 8.5.10; I had
assumed only the userland `Fiber::throw()` path.

## Embedding (sapi/embed/php_embed.c, TSRM)

- `php_embed_init(argc, argv)` does: `php_tsrm_startup()` (ZTS),
  `zend_signal_startup()`, `sapi_startup(&php_embed_module)`, sets hardcoded
  ini, `php_embed_module.startup()` (= `php_module_startup(sapi, NULL)`), then
  `php_request_startup()` for the main thread.
- `php_embed_module` is an exported global `sapi_module_struct`; its function
  pointers (`startup`, `ub_write`, `flush`, `register_server_variables`, ...) can
  be replaced before `php_embed_init`. Replacing `startup` with a function that
  calls `php_module_startup(sapi, &ignis_module)` is the supported way to add
  an internal module (functions/classes) to an embed build. FrankenPHP does
  exactly this (`frankenphp.c:1134`).
- `php_embed_init` unconditionally overwrites `additional_functions`, so that
  field cannot be used to inject functions.
- Worker threads in ZTS: call `ts_resource(0)` once per thread (allocates all
  per-thread globals and sets the static TLS cache, TSRM.c:373-374), then
  `php_request_startup()` / `php_execute_script()` / `php_request_shutdown()`
  per request, and `ts_free_thread()` at exit. FrankenPHP's `php_thread`
  loop (`frankenphp.c:1495-1540`) is the reference.
- The build defines `ZEND_ENABLE_STATIC_TSRMLS_CACHE=1`; the `_tsrm_ls_cache`
  `__thread` variable is defined once inside libphp.so and updated by
  `ts_resource_ex`, so Rust code never needs to touch it as long as every
  Zend call happens on a thread that called `ts_resource(0)`.
- Scripts: `php_execute_script(zend_file_handle*)` (main/php_main.h:61) with
  `zend_stream_init_filename`. Eval: `zend_eval_string`.

## Rust FFI options

| option | fits embedding? | PHP 8.5 ZTS? | notes |
|---|---|---|---|
| raw bindgen over `php_embed.h` | yes | yes (headers on disk) | macros (`EG()`, `ZVAL_*`, `ZEND_CALL_ARG`) must be reimplemented as small inline helpers; all ZEND_API functions available |
| ext-php-rs 0.15.15 | no: designed for building `.so` extensions loaded by php; ZTS support historically partial | not verified for 8.5 | would fight us on thread startup and SAPI ownership |
| phper 0.17.3 | same as ext-php-rs: extension-oriented | not verified | same |

## Prior art

- FrankenPHP (php/frankenphp, cloned): one OS thread per PHP instance, Go side
  owns HTTP; worker mode = a PHP script loops on `frankenphp_handle_request()`
  which blocks the thread until Go hands it a request. No fibers; concurrency
  = threads. Everything crosses cgo per request.
- Swoole (ext-src/swoole_runtime.cc, cloned): "runtime hooks" replace the
  stream transport factories (`php_stream_xport_register("tcp", ...)`) and
  copy/patch `php_stream_stdio_ops`, so `file_get_contents('http://...')`,
  PDO/mysqli sockets etc. go through Swoole coroutine sockets. This is the
  proven route for E6: swap transport factories per process, keep the original
  factory pointers to fall back.
- Revolt (revoltphp/event-loop, cloned): a driver extends
  `Internal\AbstractDriver` and implements only four methods:
  `activate(array $callbacks)`, `dispatch(bool $blocking)`,
  `deactivate(DriverCallback)`, `now(): float`
  (`src/EventLoop/Internal/AbstractDriver.php:395-440`). AbstractDriver owns
  the fibers (loop fiber, callback fiber, Suspension objects). So a Revolt
  driver over Ignis only needs a reactor that can register timers /
  readable / writable / signal interest and block-poll for completions with a
  timeout. This fixes the shape of the Rust-side primitive: **submit + poll**.

## What this rules out

- ext-php-rs / phper for the host binary: rejected, they do not own the SAPI.
- Injecting functions via `php_embed_module.additional_functions`: impossible,
  overwritten by `php_embed_init`.
- A Rust-side scheduler that calls `zend_fiber_resume` itself is *possible*
  (API is public and thread-affine only), but it duplicates what Revolt's
  AbstractDriver already does in userland and would make E7 a second
  scheduler. It stays as the fallback if the userland loop is measurably too
  slow (see ADR-0001 kill criterion).

## Open risks noted for later cycles

- 10k fibers = 10k mmaps + 10k guard pages; `vm.max_map_count` 65530 caps
  ~30k concurrent fibers per process unless raised.
- `ZEND_MAX_EXECUTION_TIMERS` is compiled in: each `php_request_startup`
  creates a POSIX timer. Fine for now; watch it in E3 (RSS/leak) tests.
