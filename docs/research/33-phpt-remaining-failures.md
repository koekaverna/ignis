# 33 — phpt remaining failures (E15a), classified

Source: `bench/results/e15-phpt/{stock,main,fiber}-*.tsv/.log` and `bench/results/e15-phpt/<mode>-diffs/**`
from the run committed 2026-09-16 21:4x (the diff trees also contain **stale** `.diff` files
left over from an earlier same-day run for tests that pass today — filtered out below by
comparing each `.diff`'s mtime against its suite's `.tsv` mtime; only files newer than the tsv
are from the current run). Counts: fiber Zend/tests/fibers 108/110 (30 fail), fiber
ext/sockets/tests 84/118 (8 fail, 26 skip), fiber ext/standard/tests/streams 124/160 (15 fail,
21 skip); main ext/sockets/tests 91/118 (1 fail), main ext/standard/tests/streams 133/160
(6 fail); stock ext/sockets/tests 91/118 (1 fail). 60 fresh failing rows total (53 fiber + 7
main), reconciling exactly against `summary.md`.

`scripts/phpt-harness.php`'s own doc comment already names several of the mechanisms below
("Known biases vs {main} mode") — this table confirms which failures they explain and adds the
ones that are not just harness bias.

## Zend/tests/fibers — fiber mode (30 failures)

| test | mode | asserts | decisive diff line | class |
|---|---|---|---|---|
| backtrace-deep-nesting | fiber | stack trace ends `#N {main}` | `018+ #15 .../phpt-harness.php(40): include(...)` (harness frames appended) | HARNESS |
| backtrace-nested | fiber | same | `006+ #3 .../phpt-harness.php(40): include(...)` | HARNESS |
| backtrace-object | fiber | same | `005+ #2 .../phpt-harness.php(40): include(...)` | HARNESS |
| call-to-ctor-of-terminated-fiber | fiber | same | `007+ #1 .../phpt-harness.php(40): include(...)` | HARNESS |
| debug-backtrace | fiber | `debug_backtrace()` ends at `Fiber->start()` | `004+ #3 .../phpt-harness.php(40): include(...)` | HARNESS |
| destructors_003 | fiber | resuming a fiber from inside a GC-triggered `__destruct()` works, prints `0/1: Start/End destruct` | `004+ Fatal error: Uncaught Error: Call to a member function resume() on null` | OURS |
| destructors_004 | fiber | same shape, 2 nested exceptions | only appended harness frames in all 3 hunks | HARNESS |
| destructors_005 | fiber | destructors run for id 0 then 1 (order) | `001+ 1: Start destruct` before `003+ 0: Start destruct` (reordered) | OURS |
| destructors_006 | fiber | `$f2` visible inside destructor, resumes cleanly | `009+ Warning: Undefined variable $f2` then `Call to a member function resume() on null` | OURS |
| destructors_009 | fiber | `$weakRef->get()` inside destructor returns bool | `001+ Warning: Undefined variable $weakRef` then `Call to a member function get() on null` | OURS |
| destructors_010 | fiber | same, second assertion | `001+ Warning: Undefined variable $weakRef` then `Call to a member function get() on null` | OURS |
| double-start | fiber | stack trace ends `#N {main}` | `004+ #1 .../phpt-harness.php(40): include(...)` | HARNESS |
| failing-fiber | fiber | same | `007+ #2 .../phpt-harness.php(40): include(...)` | HARNESS |
| failing-nested-fiber | fiber | same | `009+ #4 .../phpt-harness.php(40): include(...)` | HARNESS |
| fiber-get-current | fiber | `Fiber::getCurrent()` is `NULL` outside any fiber | `001- NULL` / `001+ object(Fiber)#3 (0) {}` | NOT-APPLICABLE — every test body already runs inside the Loop's pool fiber; "outside any fiber" is not a reachable state under Ignis's execution model |
| get-return-after-bailout | fiber | `getReturn()` after an OOM bailout throws `FiberError` | `003+ Fatal error: Uncaught Error: Call to a member function getReturn() on null` (register_shutdown_function's `global $fiber` is null, not the expected Fiber object) | OURS |
| get-return-after-throwing | fiber | stack trace ends `#N {main}` | `006+ #1 .../phpt-harness.php(40): include(...)` | HARNESS |
| get-return-from-unstarted-fiber | fiber | same | `004+ #1 .../phpt-harness.php(40): include(...)` | HARNESS |
| get-return-in-unfinished-fiber | fiber | same | `006+ #1 .../phpt-harness.php(40): include(...)` | HARNESS |
| gh20483 | fiber | tiny `fiber.stack_size` INI throws a catchable `Exception` from the *test's* `$fiber->start()` | `001+ Fatal error: Uncaught Exception: ... in /home/koe/projects/ignis/php/ignis.php:272` — the INI directive also undersizes the Loop **pool's own** wrapper fiber, so `ignis.php`'s own `$fiber->start($job)` throws first, before the test's try/catch ever runs | NOT-APPLICABLE — `--INI--` is process-global; fiber mode cannot start a test's pool wrapper fiber and isolate it from a test-set `fiber.stack_size` |
| multiple-calls-to-ctor | fiber | stack trace ends `#N {main}` | `004+ #1 .../phpt-harness.php(40): include(...)` | HARNESS |
| resume-non-running-fiber | fiber | same | `004+ #1 .../phpt-harness.php(40): include(...)` | HARNESS |
| resume-previous-fiber | fiber | same | `008+ #5 .../phpt-harness.php(40): include(...)` | HARNESS |
| resume-running-fiber | fiber | same | `006+ #3 .../phpt-harness.php(40): include(...)` | HARNESS |
| resume-terminated-fiber | fiber | same | `004+ #1 .../phpt-harness.php(40): include(...)` | HARNESS |
| start-arguments | fiber | same | `007+ #2 .../phpt-harness.php(40): include(...)` | HARNESS |
| suspend-outside-fiber | fiber | `Fiber::suspend()` outside a fiber throws `FiberError` | `001+ Fatal error: Uncaught LogicException: Ignis\Future::await(): the loop stopped with this future unsettled ... in .../ignis.php:69` — the call is legal (we ARE inside the pool fiber) and actually suspends it; nothing ever resumes it, so the loop drains and `Future::await()` throws instead | NOT-APPLICABLE — same "always inside a fiber" reason as fiber-get-current |
| throw-into-non-running-fiber | fiber | stack trace ends `#N {main}` | `004+ #1 .../phpt-harness.php(40): include(...)` | HARNESS |
| throw | fiber | same | `005+ #0 .../phpt-harness.php(40): include()` | HARNESS |
| ticks | fiber | a tick handler that checks `Fiber::getCurrent()!==null` only fires inside the test's own fiber | `001- 1` / `002- ` missing from actual — the FIRST tick (before the test even creates its own Fiber) already sees a non-null current fiber (the pool wrapper) and suspends it, so `"1\n"` never prints | OURS/NOT-APPLICABLE boundary — same root cause as fiber-get-current, but a tick handler firing on *every* statement makes it hit unconditionally; grouped with that root cause below |

## ext/sockets/tests — fiber mode (8 failures)

| test | mode | asserts | decisive diff line | class |
|---|---|---|---|---|
| socket_addrinfo_bind | fiber | `var_dump($socket)` prints `object(Socket)#2` | `001- object(Socket)#2` / `001+ object(Socket)#7` | HARNESS — harness allocates Fiber/Loop objects before the test body runs, shifting every object handle |
| socket_addrinfo_connect | fiber | same | `#2` vs `#7` | HARNESS |
| socket_create_listen | fiber | binds fixed port 31338 | `001+ Warning: socket_create_listen(): unable to bind ... Address already in use [98]` | OURS — fiber-mode-only; main mode's identical test (same port) passes in the same run. `scripts/ignis-php`'s own comment (search "A7 (C22)") documents this exact failure class (an earlier hung test's socket surviving a `timeout -s KILL`); this looks like a live recurrence, possibly via a subprocess that inherits the fd across the spawn path in root cause "subprocess/server spawn" below |
| socket_create_listen_invalid_port | fiber | `object(Socket)#1` | `#1` vs `#6` | HARNESS (object-id shift) |
| socket_create_pair | fiber | two `object(Socket)#1`/`#2` | `#1`→`#6`, `#2`→`#7` | HARNESS (object-id shift) |
| socket_read_params | fiber | `socket_read()` on a listening (unconnected) socket returns a `Warning` immediately | `001+ ** ERROR: process timed out **` | OURS — `socket_read()` on a non-connected/listening fd should return `ENOTCONN`/`EINVAL` synchronously; under fiber mode it parks instead and the 20s `timeout -s KILL` fires. Mechanism: universal park (`crates/ignis/src/php/park.rs`) treats this read as "would block", not as an immediate error |
| socket_sendto_zerocopy | fiber | `MSG_ZEROCOPY` sendto/recv roundtrip | `002- 16 received!` / `002+  received!` (0 bytes) | UPSTREAM — identical diff on `stock` and `main` too (`stock-diffs/ext/sockets/tests/socket_sendto_zerocopy.diff` byte-for-byte matches); kernel/env MSG_ZEROCOPY limitation, not Ignis |
| socket_set_nonblock | fiber | `object(Socket)#1` | `#1` vs `#6` | HARNESS (object-id shift) |

## ext/standard/tests/streams — fiber mode (15 failures)

| test | mode | asserts | decisive diff line | class |
|---|---|---|---|---|
| bug51056 | fiber | TLS client/server round trip via `ServerClientTestCase.inc` | `001+ Fatal error: Uncaught Exception: Failed server start` | HARNESS — spawns `$php_executable` (`scripts/ignis-php`) recursively as a server subprocess; see "subprocess/server spawn" root cause |
| bug60106-001 | fiber | `stream_socket_server()` truncates an over-long `unix://` path and warns | `005+ Warning: unlink(...aaa...): File name too long` (108 more `a`s than expected — the path used for `unlink()` was never truncated) plus a spurious `substr(): Passing null ... deprecated` | OURS — the truncated-length callback (`set_error_handler` capturing the warning text) behaves differently, so `$socket_file` keeps its untruncated form downstream |
| bug60106-002 | fiber | abstract-namespace unix socket length probing | `010+ Warning: Undefined variable $max_normal_length` (twice) | OURS — same root cause as bug60106-001: the length-probe warning text/side effect differs, so `$max_normal_length` is never set |
| bug64433 | fiber | HTTP redirect-following via a spawned PHP built-in-server-style child | `004+ Warning: include(/home/koe/php-src/sapi/cli/tests/phpt-harness): Failed to open stream: Success` | HARNESS — the recursively-spawned fiber-mode child resolves the harness include path relative to the *test's* directory instead of `scripts/`, so the nested `ignis-php` invocation can't find `phpt-harness.php` |
| gh10031 | fiber | large-file server round trip, spawned child | same `include(/home/koe/php-src/sapi/cli/tests/phpt-harness)` failure | HARNESS (same as bug64433) |
| gh11418 | fiber | `ServerClientTestCase.inc` round trip | `001+ Fatal error: Uncaught Exception: Failed server start` | HARNESS (same family as bug51056) |
| gh14506 | fiber | `fclose()` on a manually-uncloseable stream emits `Warning`s, not a `TypeError` | `001+ Fatal error: Uncaught TypeError: fclose(): Argument #1 ($stream) must be of type resource, null given` | OURS — real behavior diff (passes on stock and on main); needs a standalone repro of `fclose(null)` reached through a `Bomb`-style user stream wrapper's `stream_read()` under fiber mode |
| gh8409 | fiber | stack trace ends `#N {main}` | `005+ #2 .../phpt-harness.php(40): include(...)` | HARNESS |
| bug70362 | fiber | `copy('data://...')` + `file_get_contents()` under `open_basedir=.` | `001+ Warning: Unknown: open_basedir restriction ... File(/home/koe/projects/ignis/scripts/phpt-harness.php) is not within the allowed path(s): (.)` | HARNESS — documented bias ("open_basedir ... also applies to the harness itself" in `phpt-harness.php`'s own header comment): the test's `--INI-- open_basedir=.` also gates the harness's own subsequent include |
| glob-wrapper | fiber | `opendir()` under `open_basedir=/does_not_exist` warns, `glob://` bypasses it | `001+ Warning: Unknown: open_basedir restriction ... File(/home/koe/projects/ignis/scripts/phpt-harness.php) ...` | HARNESS — same as bug70362 |
| stream_context_tcp_nodelay_server | fiber | `ServerClientTestCase.inc` round trip | `001+ Fatal error: Uncaught Exception: Failed server start` | HARNESS (same family as bug51056) |
| stream_select_null_usec | fiber | stack trace ends `#N {main}` | `010+ #1 .../phpt-harness.php(40): include(...)` | HARNESS |
| stream_socket_recvfrom | fiber | `stream_socket_server()` binds `tcp://127.0.0.1:31856` | `001+ Warning: stream_socket_server(): Unable to connect to tcp://127.0.0.1:31856 (Address already in use)` | OURS — same family as socket_create_listen above: a fixed port left bound from an earlier fiber-mode test in the same run |
| user_streams_context_001 | fiber | stack trace ends `#N {main}` | `004+ #1 .../phpt-harness.php(40): include(...)` | HARNESS |
| bug77664 | fiber | stack trace ends `#N {main}` | `005+ #2 .../phpt-harness.php(40): include(...)` | HARNESS |

## main mode (7 failures)

| test | mode | asserts | decisive diff line | class |
|---|---|---|---|---|
| socket_sendto_zerocopy | main | same as fiber row above | identical diff to stock's | UPSTREAM |
| bug51056 | main | `ServerClientTestCase.inc` round trip | `001+ Fatal error: Uncaught Exception: Failed server start` | HARNESS (subprocess spawn family) |
| bug64433 | main | spawned-child HTTP redirect test | `004+ Warning: Unknown: Failed to open stream: Cannot allocate memory` then `Fatal error: Failed opening required '.../sapi/cli/tests/bug64433'` | HARNESS — the spawned `ignis` child (fork+exec from inside the already multi-threaded parent) fails to open its own script; worth a follow-up bench to confirm this is fork-safety inside a multi-threaded process rather than a resource limit, but it's `TEST_PHP_EXECUTABLE` subprocess spawning either way, not the runtime under normal single-process use |
| gh10031 | main | large-file spawned-server test | same `Cannot allocate memory` / `Failed opening required` shape | HARNESS (same as bug64433) |
| gh11418 | main | `ServerClientTestCase.inc` round trip | `001+ Fatal error: Uncaught Exception: Failed server start` | HARNESS (subprocess spawn family) |
| glob-wrapper | main | `opendir()` under `open_basedir=/does_not_exist` warns with the *test's own* file/line | `001+ Warning: Unknown: open_basedir restriction in effect. File(/home/koe/php-src/ext/standard/tests/streams/glob-wrapper.php) is not within the allowed path(s)` | OURS — main mode has **no** include wrapper (`scripts/ignis-php` execs `ignis "$script"` directly, confirmed by reading the script); this is the embed SAPI itself applying `open_basedir` to the top-level script file, where stock php-cli exempts the entry script named via `-f`. Repro: `php -d open_basedir=/does_not_exist -f glob-wrapper.php` under stock passes the `** Opening` line before any warning; under Ignis main mode it never reaches `--FILE--` at all |
| stream_context_tcp_nodelay_server | main | `ServerClientTestCase.inc` round trip | `001+ Fatal error: Uncaught Exception: Failed server start` | HARNESS (subprocess spawn family) |

## Root causes, by how many tests each explains

Ranked by row count (a `mode|test` pair each), 60 rows total:

1. **Harness wrapper pollutes EXPECT baselines — trailing stack frames (24 rows) + shifted object-handle numbers (5 rows) = 29 rows, HARNESS.** `phpt-harness.php` runs every fiber-mode test body as `include $file` inside a closure inside a pool `Fiber`, so (a) any uncaught-exception stack trace picks up `#N /scripts/phpt-harness.php(40): include(...)` / `Ignis\Loop::poolBody` / `Fiber->start` frames the `.phpt`'s literal `#N {main}` doesn't expect, and (b) the harness's own `Fiber`/`Future`/closure allocations happen before the test's first `new Socket`/`Fiber`, shifting every `object(X)#N` id it prints. Both are already named in the harness's own header comment. Fix path: either post-process the diff (strip harness frames / renumber object ids before compare) or have `run-tests.php`'s comparator accept a documented offset — not a runtime change.
2. **Subprocess/server spawn via `TEST_PHP_EXECUTABLE` is broken (10 rows, 5 unique tests × 2 modes), HARNESS.** Any `.phpt` that spawns a server subprocess (`ServerClientTestCase.inc`, or a raw `proc_open($php_executable ...)`) fails: fiber mode resolves the recursively-spawned harness's include path wrong (`sapi/cli/tests/phpt-harness` instead of `scripts/phpt-harness.php`); main mode's spawned child hits `Cannot allocate memory` opening its own script. Same category (`scripts/ignis-php` used recursively as `TEST_PHP_EXECUTABLE`), two different symptoms.
3. **GC-triggered `__destruct()` that resumes/reads a `Fiber` or `WeakReference` sees a null global (5 rows, `Zend/tests/fibers/destructors_003/005/006/009/010`), OURS — the largest real defect here.** Resuming a `Fiber` (or reading a `WeakReference`) from inside a destructor called by `gc_collect_cycles()` finds the captured `global $f`/`$f2`/`$weakRef` undefined/null, and in one case (destructors_005) the destruct order itself is reordered. Smallest repro: `class C{public $self;function __construct(){$this->self=$this;}function __destruct(){global $f;var_dump($f);$f->resume();}} $f=new Fiber(function(){while(true)Fiber::suspend();}); $f->start(); new C(); gc_collect_cycles();` under `IGNIS_PHPT_MODE=fiber` (or any real Ignis-run script that GCs a cycle holding a Fiber). Likely mechanism: Zend's internal `gc_destructor_fiber()` (the engine's own fiber for running destructors that touch Fibers safely) interacting with Ignis's pool/superglobal fiber-switch bookkeeping.
4. **"No fiber outside a fiber" is structurally unreachable in fiber mode (4 rows: fiber-get-current, suspend-outside-fiber, ticks, gh20483), NOT-APPLICABLE.** Every test body already executes inside the Loop's pool `Fiber`, so `Fiber::getCurrent()` is never `NULL`, `Fiber::suspend()` at "top level" is legal and just leaks the future, a tick handler checking `getCurrent()!==null` fires unconditionally, and a test-set `fiber.stack_size` INI undersizes the pool's own wrapper fiber before the test's own fiber is even constructed. Permanent non-goal for fiber mode as currently built, not a bug to fix.
5. **`open_basedir` gates the harness's own files, not just the test (3 rows: bug70362, glob-wrapper×2), HARNESS in fiber mode / OURS in main mode.** In fiber mode this is the documented include-wrapper bias. In main mode (no wrapper — `scripts/ignis-php` execs `ignis "$script"` directly, confirmed by reading the script) it's real: the embed SAPI applies `open_basedir` to the top-level script file itself, where php-cli exempts the `-f` entry script.
6. **A fixed-port listener from an earlier fiber-mode test is still bound when the next one starts (2 rows: socket_create_listen, stream_socket_recvfrom), OURS.** `scripts/ignis-php`'s own comment documents this exact failure class from an earlier incident (orphaned binary after a killed hung test); this looks like a live recurrence. socket_read_params (row above, same suite) hangs and gets `timeout -s KILL`ed in the same run — plausible that its socket, or a spawned child's inherited fd, survives the kill and poisons a later port.
7. **`stream_socket_server()` unix-socket path-length truncation reporting is wrong (2 rows: bug60106-001/002), OURS.** The truncated-length warning text/side effect differs from stock, so the test's own length bookkeeping (`$max_normal_length`) never gets set and downstream `unlink()` uses the untruncated path.
8. Three singleton OURS bugs: `socket_read_params` (`socket_read()` on a listening socket parks forever instead of returning an immediate error — universal park treating a real error as "would block"); `get-return-after-bailout` (after an OOM bailout inside a fiber, `register_shutdown_function`'s captured `global $fiber` is null instead of the expected `Fiber` object); `gh14506` (`fclose()` on a non-closeable stream throws an uncaught `TypeError` instead of the expected `Warning` sequence).
9. `socket_sendto_zerocopy` (2 rows, fiber+main): UPSTREAM — byte-identical diff on stock PHP too (`stock-diffs/ext/sockets/tests/socket_sendto_zerocopy.diff`).

## Where stock disagrees with the baseline file

No disagreement found: `bench/results/e15-baseline.txt`'s only sockets-related note is the
`phpt.*.ext_sockets_tests` minimums, and stock's own 1 failure (`socket_sendto_zerocopy`) is the
same test that fails identically under main and fiber — consistent with it being upstream, not a
regression the baseline should be gated against.

model=porter
