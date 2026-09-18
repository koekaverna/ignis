# `ignis/swoole`

A shim for the part of the Swoole coroutine API that test suites and libraries actually reach
(E15).

## What it does

Maps a small, deliberate subset of Swoole onto Ignis fibers: `Coroutine`/`Co`,
`Coroutine\Channel`, `Coroutine\WaitGroup`, `Coroutine\System`, `Timer`, `Event::wait`, `Co\run`,
`go()` and the `SWOOLE_HOOK_*` constants. A coroutine is a pool fiber; a channel is a queue of
suspended fibers. It exists so code written against Swoole can be run — and its suite ported — not
so anyone writes new code against it.

## Install

```
composer require ignis/swoole:@dev
```

The shim has no autoloader entry on purpose: it defines global classes and functions, so it is
loaded before the application, with `auto_prepend_file` in the ini the runtime is given
(`IGNIS_PHP_INI`):

```ini
auto_prepend_file=/opt/ignis/php/packages/swoole/src/shim.php
```

## Limits

This is the honest part. The shim changes nothing about **what is hooked**: a `tcp://` stream
created inside a fiber suspends, and `sleep`, file I/O and UDP behave as they do everywhere else in
the runtime — park covers what has readiness, and nothing here adds coverage. Anything outside the
listed subset does not exist, and Swoole's server, process and table APIs are not part of it.

If a suite needs more than the subset, the answer is the [runtime](runtime.md) API, not a bigger
shim.
