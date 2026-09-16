# Research 15 — What Swoole's `swoole_runtime` hook tests need, and what Ignis has (E15c / H22c)

Date: 2026-09-16 (E15c). Sources: `/home/user/cmp/swoole-src` (shallow clone, `tests/swoole_runtime/`:
46 top-level `.phpt` + 107 in 9 subdirectories; `tests/include/{bootstrap,config,functions}.php`,
`tests/include/lib/src/Assert.php`; `ext-src/php_swoole_coroutine.h:120-144` for the `HOOK_*` bit values),
`php/ignis.php` (Loop/Future/Scope, `Ignis\sleep/async/all`), `crates/ignis/src/php/stream.rs` (the `tcp`
transport replacement), `docs/research/06-stream-transport-hook.md`. Empirical probes ran the release
binary on `/tmp/swoole-e15/probe{1,2,3}.php`; the bench is `bench/e15-swoole.sh`, the shim `php/swoole/shim.php`.

## 0. What Ignis actually hooks today (from `stream.rs`)

- `install()` replaces the process-wide `tcp` transport factory (`php_stream_xport_register("tcp", ignis_tcp_factory)`).
  Inside a fiber (`EG(active_fiber) != NULL`, no persistent id) the stream is an `ignis_tcp` stream whose
  `connect`, `read`, `write` submit reactor ops and park the fiber with `zend_fiber_suspend`; `ignis_poll` resumes it.
- Only `STREAM_XPORT_OP_CONNECT`/`CONNECT_ASYNC`/`SHUTDOWN` are implemented; `BIND`, `LISTEN`, `ACCEPT`,
  `SEND`/`RECV` with flags, `GET_NAME` return `NOTIMPL` (`op_set_option`). `cast` returns `FAILURE`, so
  `stream_select()` on a hooked stream fails; `stat` is `None`. Probe: `stream_socket_server("tcp://127.0.0.1:0")`
  inside a fiber fails with `Unable to connect to tcp://127.0.0.1:0 (Unknown error)`; the same call in `{main}` works
  (stock factory), and `stream_socket_accept()` on that stock stream inside a fiber blocks the whole PHP thread.
- Nothing else is hooked: `udp://`, `unix://`, `udg://`, `ssl://`/`tls://`, files, `sleep()`/`usleep()`
  (probe: `usleep(50000)` in a fiber took 50 ms wall, `sleep(1)` 1000 ms), `stream_select`, `proc_open`, curl,
  ext/sockets, `gethostbyname`, PDO — all block the thread. `IGNIS_NO_STREAM_HOOK=1` disables even the tcp hook.
- `Ignis\Loop::run()` returns as soon as no *userland* op is awaited, even while a fiber is parked in a C stream op
  (probe 2: `inflight=1`, future not done). The shim keeps a ticker fiber sleeping so the loop keeps polling.
- Under the embed SAPI `STDIN`/`STDOUT`/`STDERR` are not defined (probe 3), and an exception thrown in an
  un-awaited `Ignis\async` fiber is silently stored in its Future.

## 1. Hook flags exercised by the tests vs Ignis

Bit values from `php_swoole_coroutine.h` (`SWOOLE_HOOK_ALL = 0x7fffffff & ~NATIVE_CURL & ~MONGODB`).
"Top-level" = the 46 files in `tests/swoole_runtime/*.phpt`; subdirectory counts are listed separately.

| Hook flag | Top-level tests (46) | Subdir tests (107) | Ignis status | Reason (from the Ignis code / probes) |
|---|---|---|---|---|
| `TCP` (client: `stream_socket_client("tcp://")`, `file_get_contents("http://")`, Redis/PDO/FTP over tcp) | 23: accept, base, bindto, bindto2, block, bug_5104, destruct, enable_crypto, ftp_fopen_wrapper, out_of_coroutine, pdo, persistent, redis_connect, redis_pconnect, sento, stream_context, stream_context_pass_null, stream_get_meta_data, stream_socket_accept_peername, stream_socket_partial_write, stream_socket_shutdown, tcp-c10k, tcp | sockets/tcp_client, stream_select/* (16) | **HAVE** (client side only) | `ignis_tcp_factory` + `op_set_option(CONNECT)`; `op_read`/`op_write` park the fiber. Context options (`bindto`, `so_reuseaddr`) and `stream_set_timeout` are accepted silently and ignored (`READ_TIMEOUT`/`BLOCKING` return OK without effect). |
| `TCP` server side (`stream_socket_server`, `stream_socket_accept`, `stream_socket_get_name`) | 8: accept, accept_timeout, bug_5104, stream_copy_to_stream_socket, stream_socket_accept_peername, stream_socket_partial_write, stream_socket_shutdown, tcp-c10k | sockets/tcp_server | **MISSING** (worse than stock) | The hooked factory takes every in-fiber `tcp://` stream, but `BIND`/`LISTEN`/`ACCEPT`/`GET_NAME` return `PHP_STREAM_OPTION_RETURN_NOTIMPL`, so `stream_socket_server()` fails inside a fiber; outside a fiber the stock blocking stream is used and `accept` stalls the thread. |
| `UDP` (`udp://`) | 2: udp, udp-c10k | sockets/udp, sockets/basic/*udp* (3) | **MISSING** | Only the `tcp` transport is registered (`install()`); `udp://` goes to the stock factory and blocks. |
| `UNIX` (`unix://`, `stream_socket_pair`) | 3: stream_socket_pair, unix, stream_copy_to_stream_socket | sockets/socketpair, sockets/basic/unixloop, socket_create_pair | **MISSING** | No `unix` transport replacement; `stream_socket_pair` is `socketpair(2)` + stock socket ops → `fread` blocks the thread (stream_socket_pair.phpt hangs after the first line). |
| `UDG` (`udg://`, `async.udg://`) | 2: async_protocol, udg | — | **MISSING** | No `udg` transport; the server half also needs `Swoole\Coroutine\Socket`. |
| `SSL` / `TLS` (`stream_socket_enable_crypto`, `https://`, `:443`) | 4: enable_crypto, stream_context_pass_null, bug_5104, stream_copy_to_stream_socket | ssl/* (6) | **MISSING** | The PHP build has no `openssl` module (`php -m`), and the `ignis_tcp` stream has no crypto ops (`enable_crypto` needs `php_stream_xport_crypto_setup` on an `xp_socket` stream). |
| `STREAM_SELECT` / `STREAM_FUNCTION` (`stream_select`, `stream_set_blocking`, `stream_get_meta_data`, `stream_socket_shutdown`, `stream_socket_sendto`, `stream_copy_to_stream`, `socket_import_stream`) | 12: base, bug_5104, nonblock, sento, stream_context, stream_copy_to_stream_socket, stream_get_meta_data, stream_socket_accept_peername, stream_socket_partial_write, stream_socket_shutdown, udp, udp-c10k, unix | stream_select/* (16), sockets/nonblock | **PARTIAL** | `stream_socket_shutdown` returns OK (no-op) and `stream_set_blocking` is accepted; but `op_cast` returns `FAILURE`, so `stream_select()`/`socket_import_stream()` on a hooked stream fail, `stream_get_meta_data` has no `timed_out`, and non-blocking mode has no effect (reads always park). |
| `FILE` (`fopen(__FILE__)`, `SWOOLE_HOOK_FILE`) | 1: base | file_hook/* (16), file_lock/* (4) | **MISSING** | No file wrapper replacement; `fread`/`flock`/`scandir` are synchronous syscalls (research 06: "no transport hook applies"). |
| `SLEEP` (`sleep`, `usleep`, `time_nanosleep`, `time_sleep_until`) | 13: base, block, cancel_sleep, destruct, pdo, persistent, set_hook_flags, sleep, sleep_yield, stdin, stream_socket_pair, time_nanosleep_invalid_nsec, unix | 7 | **MISSING** | Only `Ignis\sleep()` (userland, `ignis_submit_sleep`) is non-blocking; the internal functions are untouched (probe: `usleep` 50 ms wall inside a fiber). `sleep.phpt` still "passes" because with one coroutine the blocking order equals the expected order. |
| `STDIO` (`fread(STDIN)`) | 1: stdin | — | **MISSING** | `STDIN` is not even defined under the embed SAPI (probe 3); also needs `Swoole\Process`. |
| `PROC` (`proc_open`, `Swoole\Process`, `ProcessManager` fork) | 4: bindto, bindto2, stdin, stream_get_meta_data | proc/* (7), exec/exec | **MISSING** | No `proc_open` pipe hook; no `pcntl` module in this PHP build (`ProcessManager` uses `pcntl_fork`/`Swoole\Process`). |
| `CURL` / `NATIVE_CURL` | 1: hook_native_curl_to_curl | — | **MISSING** | No `curl` module in this PHP build (`Call to undefined function curl_init`); no `Swoole\Curl\Handler`. |
| `SOCKETS` (ext/sockets `socket_create` → `Swoole\Coroutine\Socket`) | 2: bug_4657, stream_socket_partial_write | sockets/basic/* (46), sockets/* (9) | **MISSING** | ext/sockets is loaded but untouched: `socket_create()` returns `Socket`, and every `socket_*` call blocks. |
| `PDO_*` (`PDO("mysql:…")`) | 2: block, pdo | — | **MISSING** | pdo_mysql is not built (only pdo_sqlite) and there is no MySQL; research 06 shows sqlite cannot be hooked at all. |
| `NET_FUNCTION` / `BLOCKING_FUNCTION` (`gethostbyname`, `gethostbynamel`) | 1: gethostbyname | remote_object/dns | **MISSING** | No DNS offload; `gethostbyname` is a blocking libc call. (`Op::Connect` resolves inside tokio, so hooked `tcp://host:port` connects do not block on DNS.) |
| `Swoole\Timer` (not a hook flag, but loop infrastructure) | 3: destruct, persistent, sleep_yield | — | **HAVE** via shim | `Timer::tick/after/clear` are coroutines looping on `Ignis\sleep`. |
| Coroutine cancellation (`Coroutine::cancel` interrupting `System::sleep`) | 1: cancel_sleep | — | **PARTIAL** | Ignis has `Loop::throwInto` (ADR-0009) but only for request fibers (`cancelRequest`); it is private, so the shim can only flag a coroutine and `System::sleep(2000)` is not interrupted → the test hangs. |

Subdirectory tests by directory (`find -mindepth 2`): sockets 55 (SOCKETS hook, `Swoole\Coroutine\Socket`),
file_hook 16 + file_lock 4 (FILE), stream_select 16 (STREAM_SELECT on tcp streams), proc 7 + exec 1 (PROC),
ssl 6 (SSL, needs `tests/include/ssl_certs`), remote_object 1 (DNS/NET_FUNCTION), unsafe 1 (`pcntl_fork`).

## 2. Swoole APIs called by the tests (number of test files)

| API | Top-level (46) | Subdirs (107) | Shim (`php/swoole/shim.php`) |
|---|---|---|---|
| `Swoole\Runtime::enableCoroutine()` | 34 | 55 | yes — records flags only |
| `Assert::*` (`SwooleTest\Assert`, `$throwException=false`) | 33 | 53 | loaded from `tests/include/lib/src/Assert.php` by the bench bootstrap |
| `go()` / `Swoole\Coroutine\go()` | 27 | 47 | yes — `Ignis\Loop::spawn` wrapper with cid |
| `Co\run()` / `Swoole\Coroutine\run()` | 21 | 72 | yes — spawn + drive the loop until no coroutine is live |
| `Swoole\Event::wait()` | 19 | 32 | yes; also run implicitly at shutdown (Swoole semantics; 7 top-level tests rely on it: destruct, get_hook_flags, library, out_of_coroutine, persistent, tcp-c10k, udp-c10k) |
| `Swoole\Coroutine\Socket` (`bind/recvfrom/sendto/accept/recv/send`) | 6 | 10 | **no** (needs udp/unix/udg/sockets hooks) |
| `Co::sleep` / `Coroutine::sleep` / `System::sleep` | 5 | 7 | yes — `Ignis\sleep` |
| `Swoole\Runtime::getHookFlags()` | 5 | 0 | yes |
| `Swoole\Runtime::setHookFlags()` | 3 | 48 | yes |
| `Swoole\Timer::tick/clear` | 3 | 0 | yes |
| `SwooleTest\ProcessManager` (fork, `getFreePort`, `wakeup`, `kill`) | 3 | 1 | **no** (needs `Swoole\Process` + pcntl) |
| `Swoole\Server` (`SWOOLE_BASE`, `on("Receive")`) | 3 (bindto, bindto2, stream_get_meta_data) | 0 | **no** |
| `Swoole\Coroutine\Http\Server` | 2 | 0 | **no** |
| `Swoole\Process` | 2 | 0 | **no** |
| `Co::set()` (`hook_flags`, `socket_timeout`) | 2 | 1 | yes (`hook_flags` only) |
| `Coroutine::getCid()` | 1 | 0 | yes (`Ignis\Scope`) |
| `Coroutine::cancel()` | 1 | 0 | best effort (flag) |
| `Co::create()` | 1 | 1 | yes |
| `Coroutine::join()` | 0 | 1 | yes |
| `Swoole\Coroutine\Channel` | 1 | 0 | yes (bounded, fiber parking via `Loop::markReady`) |
| `Swoole\Coroutine\WaitGroup` | 0 | 0 | yes (kept: cheapest primitive on top of the same parking trait) |
| `SwooleTest\CoServer::createTcpGreeting()` | 1 | 0 | **no** (built on `Swoole\Coroutine\Socket`) |
| `Swoole\Curl\Handler` | 1 | 0 | **no** |
| `swoole_async_set()` (bootstrap) | bootstrap | bootstrap | stub |
| `swoole_cpu_num()` (config.php) | config | config | yes (`nproc`) |
| `SWOOLE_USE_SHORTNAME`, `ini_get_all('swoole')` | 1 (library) | 0 | constant yes; the ini check cannot be satisfied without the extension (N/A) |
| `defer()` | 0 (CoServer) | 0 | yes |
| `Coroutine\System::*` other than sleep, `swoole_async_*`, `Swoole\Client`, `Swoole\Atomic` | 0 | 0 | — |

## 3. Helper infrastructure the tests need

- `tests/include/bootstrap.php` requires `tools/bootstrap.php`, `swoole_async_set()`, `swoole_library_set_option()`,
  `swoole_init_default_remote_object_server()` (starts a **remote object server with 2 worker processes**), `Co::set()`,
  and `tests/include/lib/vendor/autoload.php` — **absent in the clone** (needs `composer install`). The bench therefore
  writes its own `/tmp/swoole-e15/include/bootstrap.php`: shim + `config.php` (+ `functions.php`) + `lib/src/Assert.php`
  + `class Assert extends SwooleTest\Assert { $throwException = false }` (failures print "Assert failed: …" and continue).
- `config.php` loads without Swoole once `swoole_cpu_num()`, `SWOOLE_BASE`, `SWOOLE_PROCESS` exist (shim); it defines
  the fixture endpoints below. `functions.php` only defines helpers (`get_one_free_port`, `tcp_pack/tcp_length`,
  `time_approximate`, `ms_random`, `phpt_var_dump`, `var_dump_return`, `switch_process`, …); the ones used here work.
- Servers started by the test itself: `stream_socket_server("tcp://…")` inside `go()` (8 tests), `Swoole\Coroutine\Socket`
  UDP/UNIX/UDG servers (5), `Swoole\Server` / `Swoole\Coroutine\Http\Server` in a forked child via `ProcessManager` (3),
  `SwooleTest\CoServer` (1), `Swoole\Process` with a pipe (1). Fixed ports used: 8000, 9100, 9601, 7000 (`bindto`).
- External hosts (never contacted from this VM; the bench SKIPs them): www.baidu.com:80/:443 (tcp, sento, nonblock,
  out_of_coroutine, stream_context, stream_context_pass_null, enable_crypto), www.gov.cn:80 (stream_copy_to_stream_socket),
  example.com:80 (bug_5104), www.tsinghua.edu.cn + www.taobao.com DNS (gethostbyname), httpbin.org (config only).
  `stream_get_meta_data.phpt` only puts "baidu" in a Host header but needs `Swoole\Server` + fork, so it is skipped too.
- Fixtures not available here: Redis on 127.0.0.1:6379 + ext/redis (destruct, persistent, redis_connect, redis_pconnect),
  MySQL 127.0.0.1:3306 + pdo_mysql (block, pdo), FTP 127.0.0.1:21 (ftp_fopen_wrapper), OpenSSL + `tests/include/ssl_certs`
  (accept's SKIPIF, enable_crypto, ssl/*), curl (hook_native_curl_to_curl), pcntl (`ProcessManager`, unsafe/pcntl_fork).
  PHP modules present: `ctype date filter hash iconv json lexbor mbstring pcre PDO pdo_sqlite random session sockets SPL
  sqlite3 standard tokenizer uri zlib OPcache` — no openssl, curl, pcntl, redis, pdo_mysql.

## 4. Bench results (`bench/e15-swoole.sh`, 20 s timeout per test, ignis release binary, 1 thread)

Run 2026-09-16, `bash bench/e15-swoole.sh --all` (log `/tmp/swoole-e15/run-all.log`, per-test `results.tsv`, outputs
`out/`). The shim is `auto_prepend_file`d; `--EXPECTF--` uses php-src's `%s/%d/%r…%r` semantics; SKIP = SKIPIF/body
names an external host or a fixture that does not exist here.

| Set | Tests | PASS | FAIL | SKIP |
|---|---|---|---|---|
| top-level `tests/swoole_runtime/*.phpt` | 46 | 7 | 20 | 19 |
| subdirectories (`--all`) | 107 | 35 | 61 | 11 |
| total | 153 | 42 | 81 | 30 |

Top-level passes: get_hook_flags, hook_default, hook_enable_coroutine, hook_set_flags, set_hook_flags (flag bookkeeping
in the shim), sleep and time_nanosleep_invalid_nsec (single coroutine: blocking sleep yields the same output order).
Most of the 35 subdirectory passes are the same effect: `sockets/basic/*` (18) and `file_hook/*` (10) are php-src
tests wrapped in `Co\run` with one coroutine, so stock blocking behaviour produces the expected output; they prove
nothing about a hook.

Top-level failures by first blocker (20):

| Blocker | Tests | Hook / API |
|---|---|---|
| `stream_socket_server`/`accept` fails inside a fiber | 4: accept_timeout, stream_socket_accept_peername, stream_socket_partial_write, stream_socket_shutdown (+ tcp-c10k, which then reports "connection refused") | TCP server side |
| `Class "Swoole\Coroutine\Socket" not found` | 4: async_protocol, udg, udp, unix (+ udp-c10k: no server, `fread` on `udp://` blocks → empty output) | UDP/UNIX/UDG + SOCKETS |
| hang (blocking call in a fiber stalls the thread) | 2: cancel_sleep (`System::sleep(2000)` not cancellable), stream_socket_pair (`fread` on an unhooked unix pair) | cancellation, UNIX |
| `SwooleTest\ProcessManager` / `Swoole\Process` / `SwooleTest\CoServer` | 4: bindto, bindto2, stdin, base | PROC (fork), Server classes |
| output order (blocking `usleep` vs `Timer::tick`) | 1: sleep_yield (prints only `"b"`: ten blocking `usleep(100000)` starve the 1 s tick) | SLEEP |
| `socket_create()` returns `Socket`, not `Swoole\Coroutine\Socket` | 1: bug_4657 | SOCKETS |
| `curl_init` undefined | 1: hook_native_curl_to_curl | CURL (module absent) |
| `ini_get_all('swoole')` is false | 1: library | N/A without the extension |

Subdirectory failures (61): sockets 31 (6 hangs on blocking `socket_accept/recv` inside `go()`, 7 output mismatches
because `socket_set_block/nonblock`/`SO_RCVTIMEO` semantics differ from Swoole's coroutine socket, 13 warnings — 5 of
them IPv6/multicast `socket_create(): Unable to create socket` = no IPv6 on this VM, environment not hook —, 2
connection-refused, 2 asserts, `checktimeout` helper missing), stream_select 13 (mismatch/asserts on `stream_select`
over hooked streams whose `cast` fails, 2 need `Swoole\Atomic`, 1 `CoServer`), file_hook 6 + file_lock 4 (5 hangs on
blocking `flock` between two coroutines, `include`, `flock()` TypeError with the pipe socket), proc 6 (3 need the
`create_sleep_script` helper, `System::wait`, `proc_open` pipes), exec 1 (`Co\go`).

Top missing hooks by tests blocked (top-level + subdirectory, counting the first blocker only):

1. **SOCKETS** (`Swoole\Coroutine\Socket`, ext/sockets in coroutines): 5 + 31 = 36
2. **TCP server side** (`stream_socket_server`/`accept` inside a fiber) + **STREAM_SELECT** on hooked streams: 6 + 13 = 19
3. **FILE** (`flock`/`fread`/`include` between coroutines): 10
4. **PROC** (`proc_open`, `Swoole\Process`, `ProcessManager` fork): 4 + 6 = 10
5. **UDP/UNIX/UDG transports** (+ **SLEEP**, which underlies 13 top-level tests and the 2 hangs): 5 + 2

## 5. What a real Swoole-compatible hook layer would need from Ignis (ordered by tests unblocked)

1. **Server-side tcp** in `stream.rs`: `BIND`/`LISTEN`/`ACCEPT`/`GET_NAME` on `Op::Listen/Accept` (tokio `TcpListener`),
   so `stream_socket_server`/`accept` inside a fiber park instead of failing — unblocks 8 top-level + 16 `stream_select`
   tests once (2) is done. Today the hook makes these *fail* where stock PHP would merely block.
2. **`cast` to a real fd** (or an `Op::Select`/readiness op) so `stream_select()`, `socket_import_stream()` and
   `stream_get_meta_data(timed_out)` work; plus honouring `stream_set_blocking(false)` / `stream_set_timeout`.
3. **SLEEP**: replace `sleep/usleep/time_nanosleep/time_sleep_until` function entries (`zend_hash` swap of
   `CG(function_table)` handlers, as Swoole does) with `Ignis\sleep` when inside a fiber — 13 top-level tests touch it.
4. **udp/unix/udg transports** (same pattern as `tcp`, tokio `UdpSocket`/`UnixStream`/`UnixDatagram`) — 7 tests, plus
   `Swoole\Coroutine\Socket` in userland on top of them (6 tests).
5. **Cancellation from userland**: expose `Loop::throwInto` (already used by E11) as `Ignis\Loop::cancel(Fiber, Throwable)`
   so `Coroutine::cancel` can interrupt a parked coroutine.
6. FILE, PROC, SOCKETS, SSL, CURL, PDO: each needs either a thread-pool offload (files, DNS, PDO) or a new transport;
   none is reachable through the stream-transport hook alone (research 06).
