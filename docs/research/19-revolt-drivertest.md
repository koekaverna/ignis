# Research 19 — Revolt's abstract `DriverTest` against `IgnisDriver` (E15b / H22b)

Date: 2026-09-16 (Cycle 17, porter). Script: `bench/e15-revolt.sh`.
Subject under test: `Ignis\Revolt\IgnisDriver` (`php/amphp/src/IgnisDriver.php`, ADR-0008, research 07).
Suite: `revolt/event-loop` 1.x `test/Driver/DriverTest.php` (65 abstract test methods) via the new
`php/amphp/test/IgnisDriverTest.php` + `php/amphp/test/bootstrap.php`.
Machine: 4 vCPU, load average 4.4–6.1 during the runs (a php-src `.phpt` suite was running on the same box).

## The two summary lines

```
ignis  IgnisDriver          Tests: 81, Assertions: 222, Errors: 1, Failures: 1, Skipped: 8.
stock  StreamSelectDriver   Tests: 81, Assertions: 222, Errors: 1,               Skipped: 8.
```

(`Failures: 1` is the modal result; it is 0, 1 or 2 depending on timing — see "Flakiness" below.)

For reference, the suite as revolt ships it — `StreamSelectDriverTest.php` under the stock CLI,
i.e. without the data-provider repairs described below:

```
stock  StreamSelectDriverTest (as shipped)   Tests: 69, Assertions: 94, Errors: 4, Skipped: 7.
```

Commands (both inside `bench/e15-revolt.sh`):

```
IGNIS_PHP_INI=php/amphp/ignis.ini IGNIS_NO_STREAM_HOOK=1 IGNIS_NO_SLEEP_HOOK=1 \
  timeout 300 ./target/release/ignis /tmp/e15-revolt/phpunit-run.php \
  --no-configuration --bootstrap php/amphp/test/bootstrap.php php/amphp/test/IgnisDriverTest.php

timeout 300 /opt/php85-zts/bin/php /tmp/e15-revolt/phpunit-run.php \
  --no-configuration --bootstrap php/amphp/test/bootstrap.php /tmp/e15-revolt/SelectDriverCompatTest.php
```

## What phpunit needed in order to run inside the embed binary

Four obstacles, all in the harness, none in the driver. The script writes a 8-line entry point
(`/tmp/e15-revolt/phpunit-run.php`) that works around the first three; the fourth is a bootstrap file.

1. **Shebang.** `vendor/bin/phpunit` and `vendor/phpunit/phpunit/phpunit` both start with
   `#!/usr/bin/env php`. php-cli sets `CG(skip_shebang)`; the Ignis embed SAPI does not, so the line is
   parsed as inline HTML and the next statement dies with
   `Fatal error: Namespace declaration statement has to be the very first statement ... on line 13`.
   Fix in the entry point: no shebang, and call
   `exit((new PHPUnit\TextUI\Application)->run($_SERVER['argv']))` directly — exactly what the stock
   binary's last line does. *Candidate runtime fix: set `CG(skip_shebang) = 1` for the main script.*
2. **`$_SERVER['PHP_SELF']` is not set** under embed. PHPUnit 12's
   `TextUI\Configuration\Merger::merge()` does `realpath($_SERVER['PHP_SELF'])` unconditionally
   (`Merger.php:91`) → `realpath(): Argument #1 ($path) must be of type string, null given`, and PHPUnit
   aborts before running a single test. The entry point sets `PHP_SELF`, `SCRIPT_NAME` and
   `SCRIPT_FILENAME` from `$argv[0]`. *Candidate runtime fix: fill these three in at RINIT like php-cli.*
3. **ext-dom / ext-libxml / ext-xmlwriter are not in the Ignis extension set**, and `vendor/bin/phpunit`
   hard-gates on them (`PHPUnit requires the "dom", "libxml", "xmlwriter" extensions ...`, exit 1).
   This is *not* Ignis-specific: the stock `/opt/php85-zts/bin/php` fails the same gate. With
   `--no-configuration` PHPUnit never touches them, so skipping the gate is sound. It does mean no
   `phpunit.xml.dist` (reading an XML config needs ext-dom) — hence `test/bootstrap.php` instead.
4. **The abstract suite is not autoloadable.** Composer applies `autoload-dev` only to the root package,
   so `revolt/event-loop`'s `Revolt\EventLoop\ => test/` mapping is absent from `vendor/autoload.php`.
   `php/amphp/test/bootstrap.php` registers it (plus `Ignis\Revolt\Test\ => php/amphp/test/`).

`$argv`/`$_SERVER['argv']` and `STDIN`/`STDOUT`/`STDERR` were already correct (Cycle 15/17 work): the
counts are unchanged with and without `IGNIS_PHP_INI=php/amphp/ignis.ini`, so the `auto_prepend_file`
that defines the stream constants is no longer needed for this suite.

### Data-provider repair (why 81 tests and not 69)

revolt's suite is written for PHPUnit 9: four of its tests take arguments from
`provideRegistrationArgs()`, declared with a `/** @dataProvider */` doc-comment on a **non-static**
method. PHPUnit 12 ignores doc-comment metadata and requires static providers, so it collects those
four tests with zero arguments and each one dies with `ArgumentCountError`. That happens on **any**
driver — it is the 4-error line of the stock `StreamSelectDriverTest` run above.

`php/amphp/test/IgnisDriverTest.php` re-enables three of them (`testDisableWithConsecutiveCancel`,
`testCallbackReferenceInfo`, `testCallbackRegistrationAndCancellationInfo`) with `#[DataProvider]`
attributes over a static copy of the provider, which turns 3 collected-but-dead tests into 18 real ones
(defer / delay / repeat / onWritable / onReadable / onSignal) and raises assertions from 94 to 222.
`testNoMemoryLeak` cannot be revived: its body calls `getTestResultObject()`, removed in PHPUnit 10.
`bench/e15-revolt.sh` generates the identical class over `StreamSelectDriver` (in `/tmp/e15-revolt/`)
so the baseline covers exactly the same 81 tests.

## Classification

| test | label | reason | first assertion message |
|---|---|---|---|
| `testExecutionOrderGuarantees` | **ours** | `IgnisDriver::arm()`/`dispatch()`: `ignis_watch()` is a one-shot submitted to the tokio reactor, and `dispatch(false)` calls `ignis_poll(0)` immediately afterwards. An fd that is *already* writable is therefore usually not reported in the tick that armed it, so 2–4 of the eight tick-0 callbacks run one tick late. `StreamSelectDriver` learns this synchronously from `stream_select()`. | `Failed asserting that two strings are identical.` expected `'01 02 03 04 05 05 05 05 05 05 05 05 10 11 12 13 13 13 13 20 21 21 21 21 30 40 41 '`, actual e.g. `'01 02 03 04 05 05 05 05 10 11 12 05 05 05 05 13 13 13 13 20 ...'` |
| `testDeferEnabledInNextTick` | **ours** | Same root cause, stated more sharply: an enabled `onWritable(STDOUT)` callback must fire once in each of four `run()`/`stop()` ticks. Under Ignis it fires 1–2 times out of 4, because a tick that consists of one `defer` + `stop` never gives the reactor round trip time to complete. | `Failed asserting that 1 is identical to 4.` (also seen: `2 is identical to 4`) — `DriverTest.php:1362` |
| `testNoMemoryLeak` | **upstream** | Identical error on `StreamSelectDriver` under the stock CLI. revolt's PHPUnit-9 suite vs PHPUnit 12: doc-comment `@dataProvider` is ignored, and the body calls `getTestResultObject()` which PHPUnit 10 removed, so it cannot be repaired from a subclass either. | `ArgumentCountError: Too few arguments to function Revolt\EventLoop\Driver\DriverTest::testNoMemoryLeak(), 0 passed ... and exactly 2 expected` — `DriverTest.php:465` |
| `testDisableWithConsecutiveCancel`, `testCallbackReferenceInfo`, `testCallbackRegistrationAndCancellationInfo` (unrepaired form) | **upstream** | Same PHPUnit-9-vs-12 provider problem; errors identically on the stock `StreamSelectDriverTest` run. Repaired in `IgnisDriverTest` (see above) — all 15 non-signal data sets then **pass** on IgnisDriver. | `ArgumentCountError: Too few arguments to function ...(), 0 passed ... and exactly 2 expected` |
| `testOnSignalCallback`, `testInitiallyDisabledOnSignalCallback`, `testOnSignalCallbackKeepAliveRunResult`, `testSignalExecutionOrder`, `testRethrowsFromCallbacks`, and the `@onSignal` data set of the three repaired tests (8 tests) | **not applicable** | `DriverTest::checkForSignalCapability()` skips before it ever reaches the driver: **ext-posix is not built** in this PHP. The same 8 tests skip in the stock `StreamSelectDriver` baseline, so the counts are comparable — but note that `IgnisDriver::activate()` throws `UnsupportedFeatureException('Signals are not supported by IgnisDriver yet')`, so signal support is *untested*, not proven. | `ext-posix is required for sending test signals. Skipping.` |

Everything else — 72 of the 81 tests, including every `onReadable`/`onWritable` registration,
reference/unreference, `queue` microtask ordering, `repeat`, cancellation, `UncaughtThrowable`
wrapping, `InvalidCallbackError`, fiber-local state and `run()`/`stop()` re-entrancy — **passes on
IgnisDriver**, with the same assertion count as StreamSelectDriver (222).

### Flakiness (numbers)

13 full runs of `IgnisDriverTest` (10 at `IGNIS_NO_STREAM_HOOK=1 IGNIS_NO_SLEEP_HOOK=1`, 3 through
`bench/e15-revolt.sh`), load average 4.4–6.1:

| outcome | runs |
|---|---|
| `testExecutionOrderGuarantees` failed | 9 / 13 |
| `testDeferEnabledInNextTick` failed | 3 / 13 |
| only the 1 upstream error, no failures | 2 / 13 |

Both tests also fail intermittently when run alone (`--filter`): 1/5 and 2/5 respectively. The stock
`StreamSelectDriver` baseline produced the identical line in 4/4 runs — it is not flaky.
Environment variants change nothing systematic (one run each, the same 0–1 failures, same counts):
`IGNIS_NO_STREAM_HOOK=1` only, `IGNIS_NO_SLEEP_HOOK=1` only, both hooks on, and no `IGNIS_PHP_INI`
all report `Tests: 81, Assertions: 222, Errors: 1, Skipped: 8` with 0 or 1 timing failure. In
particular the new sleep hook is harmless here: the one `usleep(700000)` inside
`testExecutionOrderGuarantees` runs in Revolt's callback fiber and parks on a reactor timer without
disturbing the driver's own `ignis_poll`.

### One more observation: intermittent `free(): invalid pointer` at shutdown

In a burst of 5 consecutive runs (03:02–03:04Z) the process printed the correct PHPUnit summary and
*then* died with `free(): invalid pointer` / SIGABRT (exit 134) — including a `--filter testHandle`
run that executes a single assertion. The next 21 runs of the same command were clean (exit 2). Raw
output kept in `/tmp/e15-revolt/ignis-compat.txt`, `cmp-1..3.txt`, `iso-*.txt`. Label: **ours**
(runtime teardown, glibc `free`, not mimalloc, so PHP-side), but not reproducible on demand and not
attributable to a driver method yet. Worth one look at the embed shutdown path; do not spend a cycle
on it without a reproduction.

## Driver methods to fix, ranked by tests affected

1. **`IgnisDriver::arm()` + `IgnisDriver::dispatch()` — readiness is one dispatch late (2 tests, both
   flaky, 9/13 and 3/13).** `ignis_watch()` submits `Op::Watch` over a channel to the tokio dispatcher,
   which spawns a task that dups the fd and awaits `AsyncFd`; `Reactor::poll(Some(ZERO))` then almost
   always times out before the completion lands. Revolt's contract is that a stream that is ready *now*
   is dispatched in the current tick. Options: (a) after arming a new watch, do a `stream_select()`
   with timeout 0 over the newly armed fds (keeps tokio for blocking waits only); (b) make the watch
   level-triggered and persistent in Rust, with a cancel op, so readiness is known before the tick
   starts; (c) give `ignis_poll` a "drain already-completed ops before returning" fast path plus a
   bounded spin. (b) also fixes item 3.
2. **`IgnisDriver::activate()` — `SignalCallback` throws `UnsupportedFeatureException` (8 tests, all
   currently skipped for another reason).** Those 8 tests are skipped only because ext-posix is missing;
   the moment posix/pcntl is in the build they become real failures. Signals need a reactor op
   (`tokio::signal`) delivered as a completion, or `pcntl_signal_dispatch()` from `dispatch()`.
3. **`IgnisDriver::deactivate()` — a cancelled watch leaks its dup'd fd (0 tests, 110 fds measured).**
   Known consequence in ADR-0008. Measured with `/tmp/e15-revolt/fdleak.php`: 200 arm/cancel cycles on
   a never-readable `stream_socket_pair` grow `/proc/self/fd` from 20 to 130 (**+110 fds**). The suite
   does not catch it because `testNoMemoryLeak` cannot run under PHPUnit 12 and its `onReadable` data
   set uses `STDIN`. A cancel op on the reactor fixes it and is a prerequisite for option 1(b).
4. **Runtime, not the driver: `CG(skip_shebang)`, `$_SERVER['PHP_SELF']`.** Both are one-liners in the
   embed SAPI and would let `bench/e15-revolt.sh` call `vendor/bin/phpunit` directly, which every other
   PHP test suite we want to port (E15e Symfony/Doctrine) will also need.

## Verdict on H22b

**REFUTED as stated** ("passes 100%"): 72/81 pass, 8 skip for a missing extension (identically on the
baseline), 1 errors upstream, and 1–2 timing-sensitive failures are ours. The gap is a single root
cause — one-shot watches whose readiness cannot be observed in the tick that armed them — plus an
untested signal path. Everything the abstract suite checks about callback lifecycle, ordering of
defer/queue/timers, error propagation and fiber-locals is correct.
