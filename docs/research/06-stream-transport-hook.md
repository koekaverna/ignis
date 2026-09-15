# Research 06 — Replacing the `tcp://` transport so unmodified stream I/O suspends the fiber

Date: 2026-09-16 (Cycle 6). Sources: `main/streams/transports.c` (`php_stream_xport_register`:28,
`_php_stream_xport_create`:52-100, `php_stream_xport_connect`), `main/streams/php_stream_transport.h`
(factory typedef, `php_stream_xport_param`), `main/streams/xp_socket.c` (socket ops, :532-560, :913),
`main/php_streams.h` (`php_stream_ops`, `php_stream` fields, option return codes),
`ext/standard/http_fopen_wrapper.c:490-975`, `Zend/zend_fibers.c` (`Fiber::suspend`, `zend_fiber_suspend`,
`zend_fiber_resume`), `main/main.c:2321-2353` (startup order), Swoole `ext-src/swoole_runtime.cc` (research 00).

## Facts

- A transport is a factory `php_stream *(proto, protolen, resourcename, len, persistent_id, options, flags, timeout, context)`
  registered per protocol name in a **process-wide** hash (`php_stream_xport_register("tcp", f)`,
  `php_stream_xport_get_hash()`). `php_init_stream_wrappers` registers the stock `tcp` factory at
  `main.c:2321`, *before* `zend_register_internal_module(additional_module)` at :2341, so our MINIT
  can read the original pointer from the hash and replace it. This is exactly Swoole's approach.
- The factory returns a `php_stream` (`_php_stream_alloc(ops, abstract, persistent_id, mode)`); the
  caller then does `php_stream_xport_connect()` which calls `ops->set_option(stream,
  PHP_STREAM_OPTION_XPORT_API, 0, &xparam)` with `xparam.op == STREAM_XPORT_OP_CONNECT`, expecting
  `PHP_STREAM_OPTION_RETURN_OK` and `xparam.outputs.returncode` (0 ok / -1 fail, `error_text` optional).
- `php_stream_ops` mandatory members: `write`, `read`, `close`, `flush`, `label`; optional `seek`,
  `cast`, `stat`, `set_option`. `read` returning 0 with `stream->eof = 1` means EOF; `-1` is an error.
  Unknown options may return `PHP_STREAM_OPTION_RETURN_NOTIMPL` (-2).
- The http wrapper (`file_get_contents('http://…')`) only uses `php_stream_xport_create`,
  `php_stream_set_option(READ_TIMEOUT)`, `php_stream_write`, `php_stream_get_line`, `php_stream_read`,
  `php_stream_eof`. No `select`, no `cast` — a transport with no file descriptor is enough for it.
- Suspending from C: `ZEND_API void zend_fiber_suspend(zend_fiber *fiber, zval *value, zval *retval)`
  is what `Fiber::suspend()` calls after checking `EG(active_fiber) != NULL`, the fiber is not
  force-closed, and `!zend_fiber_switch_blocked()` (no switching inside GC/destructors). A stream op
  can do the same checks and suspend the running fiber mid-`fread`.
- Resuming from C: `zend_fiber_resume(fiber, value, retval)` may be called from any internal function
  on the thread (V-2 research). Calling it from inside `ignis_poll()` means the userland loop's
  single wait point stays the only wait point; the fiber runs until its next suspension and control
  returns into `ignis_poll`.
- Destruction while parked: `zend_fiber_object_destroy` resumes a suspended fiber with a graceful
  exit; the C op's `zend_fiber_suspend` returns with `EG(exception)` set (unwind_exit) — the op must
  return -1 and not touch PHP state.
- PDO sqlite: no socket; `sqlite3` does synchronous `read()`/`pread()` on a file. No transport hook
  applies — "PDO (sqlite first)" in E6 cannot be met by hooks. It needs either a thread-pool offload
  of the blocking call or the pgsql native driver path (E14/tokio-postgres).

## Design (minimal, no copying beyond one buffer per read)

- Reactor gains connection ops: `Connect{host,port}`, `Read{conn,max}`, `Write{conn,bytes}`,
  `Close{conn}`; tokio side runs one actor task per connection owning the `TcpStream`.
- Rust `IgnisSocket { conn, rx: VecDeque<u8>, eof }` is the stream's `abstract`.
- Every op: submit → register `(op id → EG(active_fiber))` in a thread-local table → `zend_fiber_suspend`
  → on return, take the outcome from a thread-local result table. `ignis_poll` resumes C-parked fibers
  itself and hands the remaining events to the userland loop as before.
- Outside a fiber (`EG(active_fiber) == NULL`, i.e. `{main}` before `Ignis\serve`): delegate to the
  original factory so plain scripts keep working blocking.

## Surprises / rules out

- Nothing in the http wrapper needs an fd — simpler than expected. `stream_select()` users would need
  `cast`; out of scope.
- E6's sqlite half is structurally impossible via hooks (see above): recorded as REFUTED for the hook
  approach, not for the goal.
