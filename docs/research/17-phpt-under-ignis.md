# 17 — php-src test suites under Ignis (E15a / H22a)

`bench/e15-phpt.sh` drives php-src's own `run-tests.php` against the Ignis binary through
`scripts/ignis-php`, for `Zend/tests/fibers`, `ext/standard/tests/streams` and
`ext/sockets/tests`, in three modes, and classifies every failure.

| mode | executable | what runs the test body |
|---|---|---|
| `stock` | `/opt/php85-zts/bin/php` | the CLI SAPI of the same libphp build — the baseline |
| `main` | `scripts/ignis-php` | `ignis <test.php>`: the embed SAPI, `{main}`, no fiber |
| `fiber` | `scripts/ignis-php` + `IGNIS_PHPT_MODE=fiber` | `scripts/phpt-harness.php` includes the test inside an `Ignis\async()` fiber and drives `Ignis\Loop` — the tcp:// stream hook and the fiber-switch observer are live for the test |

## How the wrapper talks to run-tests.php

php-src 8.5.10 `run-tests.php` builds exactly two shapes of command line:

* the test itself — `$php $pass_options $ini_settings -f "<test.php>" [-- args] 2>&1` (line 2426);
* bookkeeping — `-r`, `-m`, `--ri`, the `run-test-info.php` probe, and every SKIPIF/CLEAN command,
  which are the only ones carrying `-q` (lines 2133, 2489).

`scripts/ignis-php` therefore delegates everything with `-q`/`-r`/`-m`/`-v`/`-i`/`--ri`/`--version`
and the `run-test-info.php` probe to `/opt/php85-zts/bin/php` — the *same libphp build*, so the
version, extension list and skip decisions are identical to what the embed would answer — and runs
only the real test through `target/release/ignis`. The embed has no `-n`/`-c`/`-d`, so the wrapper
materialises them: `tmp.ini = (contents of the -c file unless -n) + one "key=value" line per -d`,
then `IGNIS_PHP_INI=tmp.ini ignis <test.php>`; stdin, environment and exit code pass through.
All `--INI--` values used by these three suites are plain `key=value` (`fiber.stack_size`,
`memory_limit`, `default_socket_timeout`, `allow_url_fopen`, `open_basedir`), so no ini quoting is
needed. `--ARGS--` is forwarded after `--`.

## Counts

Binary under test: `target/release/ignis` md5 `95729a80d934a941f04c8ef8b7df5094` (build 2026-09-16
02:22:46), `php/packages/runtime/src/ignis.php` md5 `126e6f076f24d37516b7e38d36e99336`, pinned into `/tmp/e15-phpt/`
because the main agent rebuilt the binary while the suites were running. php-src at tag
`php-8.5.10` (`34308a66`). `--set-timeout 15`, serial (no `-j`), `--offline`.

| suite | mode | total | passed | failed | skipped | warned | borked | wall s |
|---|---|---:|---:|---:|---:|---:|---:|---:|
| Zend/tests/fibers | stock | 110 | 108 | 0 | 2 | 0 | 0 | 9 |
| Zend/tests/fibers | main | 110 | 108 | 0 | 2 | 0 | 0 | 26 |
| Zend/tests/fibers | fiber | 110 | 77 | 31 | 2 | 0 | 0 | 35 |
| ext/sockets/tests | stock | 118 | 80 | 0 | 38 | 0 | 0 | 35 |
| ext/sockets/tests | main | 118 | 79 | 1 | 38 | 0 | 0 | 121 |
| ext/sockets/tests | fiber | 118 | 74 | 6 | 38 | 0 | 0 | 114 |
| ext/standard/tests/streams | stock | 160 | 138 | 0 | 22 | 0 | 0 | 12 |
| ext/standard/tests/streams | main | 160 | 124 | 14 | 22 | 0 | 0 | 70 |
| ext/standard/tests/streams | fiber | 160 | 102 | 36 | 22 | 0 | 0 | 161 |

Pass/fail/skip counts were identical across four repeats. Wall times were not: a
`cargo build --release` shared the 4 vCPUs for most of this run. An uncontended repeat of the same
script and binary measured stock 2 s / main 5 s / fiber 34 s for fibers, 6 s / 41 s / 46 s for
streams and 4 s / 11 s / 12 s for sockets. The stable signal is that **`main` mode costs 3-7× the
stock CLI per suite** — every test pays a fresh `php_embed_init()`/shutdown where the CLI pays a
lighter startup — and that `fiber` mode costs roughly the same as `main` (the harness adds one
`require` of `php/packages/runtime/src/ignis.php` and one fiber per test). Exactly one test in the whole matrix hit the
15 s per-test timeout: `streams/proc_open_bug60120`, whose child PHP process dies on the missing
`STDIN` constant while the parent waits for its output.

**Headline: in `main` mode Ignis is at 100% of the stock rate on `Zend/tests/fibers` (108/108),
98.8% on `ext/sockets/tests` (79/80) and 89.9% on `ext/standard/tests/streams` (124/138).**
Every streams/sockets gap in `main` mode is CLI-SAPI surface, not engine behaviour: 13 of the 15
are `STDIN`/`STDOUT`/`STDERR` or a child PHP process that needs them. H22a's "≥ 95% of the stock
rate in {main} mode" holds for fibers and sockets and misses on streams only because of those.

## Classification

73 distinct tests fail under Ignis and not under the stock CLI. The stock CLI failed **zero**
tests in all three suites, so there is no **upstream** bucket; and every `main`-mode failure also
fails in `fiber` mode, so the fiber column is a strict superset. The split is:

* **ours — real runtime defects: 16** (14 of them one bug, see below);
* **ours — harness artifacts of running the body inside a fiber: 41** (fiber mode only);
* **not applicable — CLI SAPI surface: 16**;
* **upstream: 0**.


| label | reason | tests |
|---|---|---:|
| ours (harness) | stack-trace tail: the body is an `include` inside a fiber, so harness frames replace `#0 {main}` | 24 |
| ours | tcp:// hook has no bind/listen/accept path: `stream_socket_server()` returns false inside a fiber | 14 |
| ours (harness) | the body runs in function scope, so `global $x` / top-level vars are not globals | 8 |
| not applicable | proc_open()s the php binary and the child needs CLI STDIN/STDOUT | 7 |
| ours (harness) | object ids shifted: the harness allocates Fiber/Future/closures before the test | 5 |
| not applicable | needs the CLI-only STDIN/STDOUT/STDERR constants | 4 |
| not applicable | asserts {main} is not a fiber; in fiber mode the body *is* a fiber | 3 |
| ours (harness) | GC root order differs: the harness keeps extra live objects around the test | 2 |
| not applicable | drives the CLI built-in web server / `$_SERVER["PHP_SELF"]` via sapi/cli/tests/php_cli_server.inc | 2 |
| ours (harness) | `--INI-- fiber.stack_size` also applies to the harness pool fiber, which fails to start first | 1 |
| ours (harness) | `--INI-- open_basedir` also applies to the harness file, which lives outside it | 1 |
| ours | `PHP_BINARY` is the empty string under the embed SAPI | 1 |
| ours | the embed opens the primary script through the open_basedir-checked path; php-cli exempts it | 1 |

### Per test

| test | mode(s) | label | reason | first differing line |
|---|---|---|---|---|
| `fibers/backtrace-deep-nesting.phpt` | fiber | **ours (harness)** | stack-trace tail: the body is an `include` inside a fiber, so harness frames replace `#0 {main}` | `018- #15 {main}` |
| `fibers/backtrace-nested.phpt` | fiber | **ours (harness)** | stack-trace tail: the body is an `include` inside a fiber, so harness frames replace `#0 {main}` | `006- #3 {main}` |
| `fibers/backtrace-object.phpt` | fiber | **ours (harness)** | stack-trace tail: the body is an `include` inside a fiber, so harness frames replace `#0 {main}` | `005- #2 {main}` |
| `fibers/call-to-ctor-of-terminated-fiber.phpt` | fiber | **ours (harness)** | stack-trace tail: the body is an `include` inside a fiber, so harness frames replace `#0 {main}` | `007- #1 {main}` |
| `fibers/debug-backtrace.phpt` | fiber | **ours (harness)** | stack-trace tail: the body is an `include` inside a fiber, so harness frames replace `#0 {main}` | `004+ #3 /tmp/e15-phpt/pinned/scripts/phpt-harness.php(40): include('/home/user/php-...')` |
| `fibers/destructors_003.phpt` | fiber | **ours (harness)** | the body runs in function scope, so `global $x` / top-level vars are not globals | `002- 0: End destruct` |
| `fibers/destructors_004.phpt` | fiber | **ours (harness)** | stack-trace tail: the body is an `include` inside a fiber, so harness frames replace `#0 {main}` | `013- #5 {main}` |
| `fibers/destructors_005.phpt` | fiber | **ours (harness)** | GC root order differs: the harness keeps extra live objects around the test | `001+ 1: Start destruct` |
| `fibers/destructors_006.phpt` | fiber | **ours (harness)** | the body runs in function scope, so `global $x` / top-level vars are not globals | `008- 0: End destruct` |
| `fibers/destructors_009.phpt` | fiber | **ours (harness)** | the body runs in function scope, so `global $x` / top-level vars are not globals | `001- bool(true)` |
| `fibers/destructors_010.phpt` | fiber | **ours (harness)** | the body runs in function scope, so `global $x` / top-level vars are not globals | `001- bool(true)` |
| `fibers/double-start.phpt` | fiber | **ours (harness)** | stack-trace tail: the body is an `include` inside a fiber, so harness frames replace `#0 {main}` | `004- #1 {main}` |
| `fibers/failing-fiber.phpt` | fiber | **ours (harness)** | stack-trace tail: the body is an `include` inside a fiber, so harness frames replace `#0 {main}` | `007- #2 {main}` |
| `fibers/failing-nested-fiber.phpt` | fiber | **ours (harness)** | stack-trace tail: the body is an `include` inside a fiber, so harness frames replace `#0 {main}` | `009- #4 {main}` |
| `fibers/fiber-get-current.phpt` | fiber | **not applicable** | asserts {main} is not a fiber; in fiber mode the body *is* a fiber | `001- NULL` |
| `fibers/get-return-after-bailout.phpt` | fiber | **ours (harness)** | the body runs in function scope, so `global $x` / top-level vars are not globals | `003- Fatal error: Uncaught FiberError: Cannot get fiber return value: The fiber exited with a fatal error in %` |
| `fibers/get-return-after-throwing.phpt` | fiber | **ours (harness)** | stack-trace tail: the body is an `include` inside a fiber, so harness frames replace `#0 {main}` | `006- #1 {main}` |
| `fibers/get-return-from-unstarted-fiber.phpt` | fiber | **ours (harness)** | stack-trace tail: the body is an `include` inside a fiber, so harness frames replace `#0 {main}` | `004- #1 {main}` |
| `fibers/get-return-in-unfinished-fiber.phpt` | fiber | **ours (harness)** | stack-trace tail: the body is an `include` inside a fiber, so harness frames replace `#0 {main}` | `006- #1 {main}` |
| `fibers/gh10496-001.phpt` | fiber | **ours (harness)** | GC root order differs: the harness keeps extra live objects around the test | `001+ Dtor x()` |
| `fibers/gh20483.phpt` | fiber | **ours (harness)** | `--INI-- fiber.stack_size` also applies to the harness pool fiber, which fails to start first | `001- Fiber stack size is too small, it needs to be at least %d bytes` |
| `fibers/multiple-calls-to-ctor.phpt` | fiber | **ours (harness)** | stack-trace tail: the body is an `include` inside a fiber, so harness frames replace `#0 {main}` | `004- #1 {main}` |
| `fibers/resume-non-running-fiber.phpt` | fiber | **ours (harness)** | stack-trace tail: the body is an `include` inside a fiber, so harness frames replace `#0 {main}` | `004- #1 {main}` |
| `fibers/resume-previous-fiber.phpt` | fiber | **ours (harness)** | stack-trace tail: the body is an `include` inside a fiber, so harness frames replace `#0 {main}` | `008- #5 {main}` |
| `fibers/resume-running-fiber.phpt` | fiber | **ours (harness)** | stack-trace tail: the body is an `include` inside a fiber, so harness frames replace `#0 {main}` | `006- #3 {main}` |
| `fibers/resume-terminated-fiber.phpt` | fiber | **ours (harness)** | stack-trace tail: the body is an `include` inside a fiber, so harness frames replace `#0 {main}` | `004- #1 {main}` |
| `fibers/start-arguments.phpt` | fiber | **ours (harness)** | stack-trace tail: the body is an `include` inside a fiber, so harness frames replace `#0 {main}` | `007- #2 {main}` |
| `fibers/suspend-outside-fiber.phpt` | fiber | **not applicable** | asserts {main} is not a fiber; in fiber mode the body *is* a fiber | `001- Fatal error: Uncaught FiberError: Cannot suspend outside of a fiber in %ssuspend-outside-fiber.php:%d` |
| `fibers/throw-into-non-running-fiber.phpt` | fiber | **ours (harness)** | stack-trace tail: the body is an `include` inside a fiber, so harness frames replace `#0 {main}` | `004- #1 {main}` |
| `fibers/throw.phpt` | fiber | **ours (harness)** | stack-trace tail: the body is an `include` inside a fiber, so harness frames replace `#0 {main}` | `005- #0 {main}` |
| `fibers/ticks.phpt` | fiber | **not applicable** | asserts {main} is not a fiber; in fiber mode the body *is* a fiber | `001- 1` |
| `streams/bug48309.phpt` | main+fiber | **not applicable** | needs the CLI-only STDIN/STDOUT/STDERR constants | `002- te` |
| `streams/bug51056.phpt` | main+fiber | **not applicable** | proc_open()s the php binary and the child needs CLI STDIN/STDOUT | `001- fread read 8 bytes` |
| `streams/bug60106-001.phpt` | fiber | **ours (harness)** | the body runs in function scope, so `global $x` / top-level vars are not globals | `002+` |
| `streams/bug60106-002.phpt` | fiber | **ours (harness)** | the body runs in function scope, so `global $x` / top-level vars are not globals | `002+` |
| `streams/bug64433.phpt` | main+fiber | **not applicable** | drives the CLI built-in web server / `$_SERVER["PHP_SELF"]` via sapi/cli/tests/php_cli_server.inc | `001- HELLO!` |
| `streams/bug69521.phpt` | fiber | **ours** | tcp:// hook has no bind/listen/accept path: `stream_socket_server()` returns false inside a fiber | `001- Sending bug 69521` |
| `streams/bug70198.phpt` | fiber | **ours** | tcp:// hook has no bind/listen/accept path: `stream_socket_server()` returns false inside a fiber | `001- int(0)` |
| `streams/bug70362.phpt` | fiber | **ours (harness)** | `--INI-- open_basedir` also applies to the harness file, which lives outside it | `001- bool(true)` |
| `streams/bug72075.phpt` | fiber | **ours** | tcp:// hook has no bind/listen/accept path: `stream_socket_server()` returns false inside a fiber | `001+ Warning: stream_socket_server(): Unable to connect to tcp://127.0.0.1:0 (Unknown error) in /home/user/php` |
| `streams/bug77664.phpt` | fiber | **ours (harness)** | stack-trace tail: the body is an `include` inside a fiber, so harness frames replace `#0 {main}` | `005- #2 {main}` |
| `streams/gh10031.phpt` | main+fiber | **not applicable** | drives the CLI built-in web server / `$_SERVER["PHP_SELF"]` via sapi/cli/tests/php_cli_server.inc | `001- expected filesize=1000` |
| `streams/gh11418.phpt` | main+fiber | **not applicable** | proc_open()s the php binary and the child needs CLI STDIN/STDOUT | `001- Hi Hello World` |
| `streams/gh14506.phpt` | fiber | **ours (harness)** | the body runs in function scope, so `global $x` / top-level vars are not globals | `001- Warning: fclose(): cannot close the provided stream, as it must not be manually closed in %s on line %d` |
| `streams/gh16889.phpt` | main+fiber | **ours** | `PHP_BINARY` is the empty string under the embed SAPI | `001- bool(true)` |
| `streams/gh8409.phpt` | fiber | **ours (harness)** | stack-trace tail: the body is an `include` inside a fiber, so harness frames replace `#0 {main}` | `005- #2 {main}` |
| `streams/gh8472.phpt` | fiber | **ours** | tcp:// hook has no bind/listen/accept path: `stream_socket_server()` returns false inside a fiber | `001- string(4) "0000"` |
| `streams/ghsa-3cr5-j632-f35r.phpt` | fiber | **ours** | tcp:// hook has no bind/listen/accept path: `stream_socket_server()` returns false inside a fiber | `001- Warning: stream_socket_client(): Unable to connect to tcp://localhost\0.example.com:%d (The hostname must` |
| `streams/glob-wrapper.phpt` | main+fiber | **ours** | the embed opens the primary script through the open_basedir-checked path; php-cli exempts it | `001- ** Opening %s` |
| `streams/non_finite_values.phpt` | fiber | **ours** | tcp:// hook has no bind/listen/accept path: `stream_socket_server()` returns false inside a fiber | `001- stream_socket_accept(): Argument #2 ($timeout) must be a finite value` |
| `streams/nonblocking_stdin.phpt` | main+fiber | **not applicable** | needs the CLI-only STDIN/STDOUT/STDERR constants | `001- string(0) ""` |
| `streams/proc_open_bug51800_right.phpt` | main+fiber | **not applicable** | proc_open()s the php binary and the child needs CLI STDIN/STDOUT | `003-   int(0)` |
| `streams/proc_open_bug51800_right2.phpt` | main+fiber | **not applicable** | proc_open()s the php binary and the child needs CLI STDIN/STDOUT | `003-   int(0)` |
| `streams/proc_open_bug60120.phpt` | main+fiber | **not applicable** | proc_open()s the php binary and the child needs CLI STDIN/STDOUT | `001- string(10000) "%s"` |
| `streams/proc_open_bug69900.phpt` | main+fiber | **not applicable** | proc_open()s the php binary and the child needs CLI STDIN/STDOUT | `001- hello0` |
| `streams/stream_context_tcp_nodelay_server.phpt` | main+fiber | **not applicable** | proc_open()s the php binary and the child needs CLI STDIN/STDOUT | `001- server-delay:conn-nodelay` |
| `streams/stream_get_meta_data_socket_basic.phpt` | fiber | **ours** | tcp:// hook has no bind/listen/accept path: `stream_socket_server()` returns false inside a fiber | `001- array(8) {` |
| `streams/stream_get_meta_data_socket_variation1.phpt` | fiber | **ours** | tcp:// hook has no bind/listen/accept path: `stream_socket_server()` returns false inside a fiber | `001- Write some data:` |
| `streams/stream_get_meta_data_socket_variation2.phpt` | fiber | **ours** | tcp:// hook has no bind/listen/accept path: `stream_socket_server()` returns false inside a fiber | `001- array(8) {` |
| `streams/stream_get_meta_data_socket_variation3.phpt` | fiber | **ours** | tcp:// hook has no bind/listen/accept path: `stream_socket_server()` returns false inside a fiber | `001- array(8) {` |
| `streams/stream_get_meta_data_socket_variation4.phpt` | fiber | **ours** | tcp:// hook has no bind/listen/accept path: `stream_socket_server()` returns false inside a fiber | `001- Write some data:` |
| `streams/stream_select_null_usec.phpt` | fiber | **ours (harness)** | stack-trace tail: the body is an `include` inside a fiber, so harness frames replace `#0 {main}` | `010- #1 {main}` |
| `streams/stream_set_timeout_error.phpt` | fiber | **ours** | tcp:// hook has no bind/listen/accept path: `stream_socket_server()` returns false inside a fiber | `003+ Warning: fsockopen(): Unable to connect to tcp://127.0.0.1:33907:-1 (connect 127.0.0.1:33907: Connection ` |
| `streams/stream_socket_get_name.phpt` | fiber | **ours** | tcp:// hook has no bind/listen/accept path: `stream_socket_server()` returns false inside a fiber | `001- string(15) "127.0.0.1:31855"` |
| `streams/stream_socket_recvfrom.phpt` | fiber | **ours** | tcp:// hook has no bind/listen/accept path: `stream_socket_server()` returns false inside a fiber | `001- bool(false)` |
| `streams/user_streams_consumed_bug.phpt` | main+fiber | **not applicable** | needs the CLI-only STDIN/STDOUT/STDERR constants | `001- Hello` |
| `streams/user_streams_context_001.phpt` | fiber | **ours (harness)** | stack-trace tail: the body is an `include` inside a fiber, so harness frames replace `#0 {main}` | `004- #1 {main}` |
| `sockets/socket_addrinfo_bind.phpt` | fiber | **ours (harness)** | object ids shifted: the harness allocates Fiber/Future/closures before the test | `001- object(Socket)#2 (0) {` |
| `sockets/socket_addrinfo_connect.phpt` | fiber | **ours (harness)** | object ids shifted: the harness allocates Fiber/Future/closures before the test | `001- object(Socket)#2 (0) {` |
| `sockets/socket_cmsg_rights.phpt` | main+fiber | **not applicable** | needs the CLI-only STDIN/STDOUT/STDERR constants | `008- int(11)` |
| `sockets/socket_create_listen_invalid_port.phpt` | fiber | **ours (harness)** | object ids shifted: the harness allocates Fiber/Future/closures before the test | `001- object(Socket)#1 (0) {` |
| `sockets/socket_create_pair.phpt` | fiber | **ours (harness)** | object ids shifted: the harness allocates Fiber/Future/closures before the test | `003-   object(Socket)#1 (0) {` |
| `sockets/socket_set_nonblock.phpt` | fiber | **ours (harness)** | object ids shifted: the harness allocates Fiber/Future/closures before the test | `001- object(Socket)#1 (0) {` |

## Runtime defects found, ranked by tests broken

### 1. The tcp:// hook claims server sockets it cannot serve — 14 tests

`stream_socket_server('tcp://…')` inside a fiber returns `false` with `$errno = 0` and an empty
`$errstr`, which run-tests sees as `Unable to connect to tcp://127.0.0.1:NNN (Unknown error)`.
Everything downstream (`stream_socket_accept`, `stream_get_meta_data`, `stream_socket_get_name`,
`stream_socket_recvfrom`, `stream_select`) then gets `false` and the test dies on a TypeError.

`crates/ignis/src/php/stream.rs:129` `ignis_tcp_factory()` returns an Ignis stream for *every*
tcp:// creation when `EG(active_fiber)` is set, including `STREAM_XPORT_SERVER|BIND|LISTEN`;
`op_set_option()` (same file, line 238) answers only `STREAM_XPORT_OP_CONNECT`,
`CONNECT_ASYNC` and `SHUTDOWN` and returns `NOTIMPL` for `BIND`/`LISTEN`/`ACCEPT`/`GET_NAME`/
`RECV`/`SEND`, so `php_stream_xport_create()` fails.

Repro (no test harness needed):

```
$ cat srv.php
<?php $s = @stream_socket_server('tcp://127.0.0.1:0', $e, $s2);
      printf("server ok=%s errno=%d errstr=%s\n", var_export($s!==false,true), $e, $s2);
$ IGNIS_PHPT_MODE=fiber scripts/ignis-php -f srv.php
server ok=false errno=0 errstr=
$ IGNIS_NO_STREAM_HOOK=1 IGNIS_PHPT_MODE=fiber scripts/ignis-php -f srv.php
server ok=true errno=0 errstr=
```

Fix shape: fall back to `ORIG_TCP` when the `flags` argument has `STREAM_XPORT_SERVER` set.
**`STREAM_XPORT_SERVER` is `1`** (`php-src/main/streams/php_stream_transport.h:38`; `CONNECT` is
`2`, `BIND` `4`, `LISTEN` `8`, `CONNECT_ASYNC` `16`). The version of `stream.rs` written at
02:30 while this run was in progress uses `const STREAM_XPORT_SERVER: c_int = 2;`, i.e.
`STREAM_XPORT_CONNECT`, which inverts the guard: on the 02:30:40 build
(md5 `82452349ff70235da19a6220b705d5a3`) a **client** connect inside a fiber falls back to the
stock blocking transport — `stream_get_meta_data()['stream_type']` goes from `ignis_tcp` back to
`tcp_socket` — while `stream_socket_server()` is still hooked and still returns `false`. That
silently un-does E6 (V-12). Changing the constant to `1` fixes both.

### 2. Hooked client streams are not interchangeable with real sockets — blocks the 14 above from passing even after (1)

On a *successful* hooked connect inside a fiber, versus the stock CLI:

| call | stock | ignis (hooked) |
|---|---|---|
| `stream_get_meta_data()['stream_type']` | `tcp_socket` | `ignis_tcp` |
| `stream_socket_get_name($c, true)` | `127.0.0.1:39796` | `false` (`STREAM_XPORT_OP_GET_NAME` → `NOTIMPL`) |
| `stream_select([$c], …)` | `1` | ValueError: "No stream arrays were passed" (`op_cast` returns `FAILURE` by design) |

`ext/standard/tests/streams/stream_get_meta_data_socket_*.phpt` assert `stream_type` is
`tcp_socke%s`, so five of them need the label to match as well as the server fix.

### 3. `PHP_BINARY` is the empty string under the embed SAPI — 1 test here, a landmine everywhere

`sapi_module.executable_location` is never set, so `PHP_BINARY === ''`.
`ext/standard/tests/streams/gh16889.phpt` does `proc_open([PHP_BINARY, "-r", …])` and gets
`ValueError: First element must contain a non-empty program name`. Any suite that re-executes PHP
(Symfony's process tests, PHPUnit's process isolation) hits this. `/proc/self/exe` or `argv[0]`
at `Engine::init()` would close it.

### 4. `open_basedir` applies to the primary script in the embed — 1 test

With `-d open_basedir=/does_not_exist`, php-cli still runs the script (it opens the primary script
before applying the restriction); the embed runs it through the open_basedir-checked stream open
and dies with `Failed opening required '…/glob-wrapper.php'` before line 1.

### 5. Connect errors carry the Rust message, not errno/strerror

On the 02:22 build, a refused connect inside a fiber gives `errno=0`,
`errstr="connect 127.0.0.1:1: Connection refused (os error 111)"` where the CLI gives `errno=111`,
`errstr="Connection refused"`. (The 02:30 build fixes this — but only because client connects
stopped being hooked at all, see (1).) No test in these three suites fails *only* on this, because
they all die earlier on the server socket.

### Not a defect, but the single highest-yield change: STDIN/STDOUT/STDERR

13 of the 15 `main`-mode failures are the embed not defining `STDIN`/`STDOUT`/`STDERR` — directly
(4 tests) or through a child PHP process / the CLI built-in server helper that needs them (9).
Classified **not applicable** because the phpt suite is asserting CLI SAPI surface, but defining
the three constants in the embed (they are just `php://stdin|stdout|stderr` streams) would move
most of them to PASS.

## Harness biases (fiber mode only) — what to discount in the fiber column

41 of the 73 failures are the harness, not the runtime. In descending order:

1. **Stack-trace tail (24 tests).** The body is an `include` inside a pooled fiber, so an uncaught
   Throwable's trace ends with `#N scripts/phpt-harness.php(40): include()` … `#N+5 {main}`
   instead of `#0 {main}`. The message, class, file and line are all correct; only the tail differs.
   An `--EXPECTF--`-friendly harness would need to run the body in a fiber whose entry frame *is*
   `{main}`, which native Fibers cannot give.
2. **Function scope (8 tests).** `include` inside a closure means the test's top-level `$x` is a
   local, so a test-defined function doing `global $x` sees nothing (`destructors_003/006/009/010`,
   `get-return-after-bailout`, `bug60106-001/002`, `gh14506`). This one can bite real code too and
   is the strongest argument for eventually running the body through a `{main}`-scoped mechanism.
3. **Object ids (5 tests).** The harness allocates a `Fiber`, a `Future` and two closures first, so
   `object(Socket)#1` becomes `#6`. Only `--EXPECT--` (not `--EXPECTF--`) tests notice.
4. **`--INI--` applies to the harness too (2 tests).** `fiber.stack_size=1024` makes the *harness's*
   pool fiber fail to start before the test runs (`gh20483`); `open_basedir=.` puts
   `scripts/phpt-harness.php` outside the allowed path (`bug70362`).
5. **GC root order (2 tests).** `destructors_005` and `gh10496-001` see a different destruct order
   because the harness keeps extra objects alive across `gc_collect_cycles()`.

Three further failures are **not applicable** by construction: `fiber-get-current`,
`suspend-outside-fiber` and `ticks` assert that `{main}` is *not* a fiber, which cannot hold when
the body is one.

Also worth knowing about the harness:

* `__FILE__`/`__DIR__` inside the test are the test file (include semantics), and
  `$_SERVER['SCRIPT_NAME']` is absent in **both** Ignis modes (the embed populates no CGI vars) —
  no test in these suites depends on it, but Symfony/FrankenPHP ports will.
* `ob_*` is untouched: run-tests passes `-d output_buffering=Off`, the harness adds no buffer, and
  stdout/stderr go straight to the process (run-tests merges them with `2>&1`).
* The harness re-throws the test fiber's unhandled Throwable from `{main}` after the loop stops, so
  fatal-error tests still print a fatal error rather than exiting 0 silently.
* `Ignis\Loop::runUntil()` must keep polling while a fiber is parked in a C hook (the
  `ignis_inflight() === 0` term in its exit test). Without it the loop leaves as soon as its
  *userland* wait map is empty and **every** socket test dies silently at its first socket call with
  exit code 0 — that was the state of `php/packages/runtime/src/ignis.php` when this task started and it is the reason
  `scripts/phpt-harness.php` still carries an `IGNIS_PHPT_WATCHDOG=1` fallback.

## Reproducing

```
bench/e15-phpt.sh                         # all 9 runs; SUITE_TIMEOUT=1200 TEST_TIMEOUT=15 by default
IGNIS_BIN=/path/to/ignis bench/e15-phpt.sh   # pin a specific binary
```

Artifacts land in `bench/results/e15-phpt/`: `<mode>-<suite>.log` (full run-tests output with
`--show-diff`), `.txt` (failing test paths, from run-tests' own `-w`), `.tsv` (result per test, from
`-W`), `<mode>-diffs/**` (the `.diff`/`.out` run-tests wrote next to each test), and `summary.md`.
Stack traces in the stored `.out`/`.diff` files name `/tmp/e15-phpt/pinned/{php/packages/runtime/src/ignis.php,
scripts/phpt-harness.php}` rather than the repo paths: those are the byte-identical pinned copies
this run used (see Counts). A plain `bench/e15-phpt.sh` shows the repo paths instead.

The script `git clean -fdq`s **only** the three suite directories afterwards, and removes the
`/tmp/<107 × "a">` unix socket `bug60106-00{1,2}` leave behind when they fail (a leftover makes the
next run fail with EADDRINUSE in every mode, including `stock`).
