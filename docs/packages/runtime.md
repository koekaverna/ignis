# `ignis/runtime`

The userland half of the runtime: the fiber scheduler every other package builds on.

## What it does

`Ignis\Loop` keeps a pool of fibers and hands one to each request; a parked fiber is reused rather
than rebuilt, which is the single biggest win the project has measured (V-4). Around it sit the
things an application actually calls — `Ignis\async()`, `all()`, `sleep()`, `deadline()`,
`serve()`, `Ignis\Scope` for per-fiber state, and `Ignis\Http\Request`/`Response`/`StreamedResponse`.

A PHP thread has exactly one wait point. Everything that waits — a timer, a socket, a gRPC
call — arrives on the same completion channel, which is what keeps the scheduler small enough to
read.

## Install

```
composer require ignis/runtime:@dev
```

Entry scripts that predate the autoloader (benchmarks, the classic-mode adapter) `require` it by
path instead: `php/packages/runtime/src/ignis.php` registers the autoloader and the free functions.

## Write a handler

```php
require '/opt/ignis/php/packages/runtime/src/ignis.php';

Ignis\serve(function (Ignis\Http\Request $request): Ignis\Http\Response {
    [$user, $orders] = Ignis\all([
        Ignis\async(fn () => file_get_contents('https://api.internal/user')),
        Ignis\async(fn () => file_get_contents('https://api.internal/orders')),
    ]);

    return Ignis\Http\Response::json(['user' => $user, 'orders' => $orders]);
}, '0.0.0.0:8080');
```

Both `file_get_contents` calls park the fiber on their sockets; the thread keeps serving other
requests meanwhile, and neither call was written differently for it.

## Streaming

Return an `Ignis\Http\StreamedResponse` with a producer and write from inside it:

```php
return new Ignis\Http\StreamedResponse(function (): void {
    foreach ($rows as $row) {
        Ignis\write(json_encode($row) . "\n");   // or echo — both reach the socket
    }
});
```

The body leaves as it is produced (`Transfer-Encoding: chunked`), the producing fiber waits when
the channel is full, and the thread stays free: time to first byte fell from 906 ms to 1.7 ms when
this replaced buffering (V-74). A producer that fails before its first byte still answers 500; one
that writes nothing answers 204.

## Configure

Nothing in the package — the scheduler reads the process environment, and `ignis.toml` is the
front end for it. The knobs that change *its* behaviour are `IGNIS_FIBER_BUDGET`,
`IGNIS_QUEUE_DEPTH`, `IGNIS_BUDGET_EXEMPT`, `IGNIS_LOOP_GC` and `IGNIS_LOOP_GC_ROOTS`; see the
[configuration reference](../reference/configuration.md).

## Reloading code while you work

In worker mode the kernel is loaded once, so a file you edit changes nothing until the thread that
holds it goes away. `IGNIS_WATCH=1` makes that happen by itself:

```toml
# ignis.toml
supervise = true

[watch]
enabled = true
```

or, without a file, `IGNIS_WATCH=1 ignis --supervise --threads 4 app.php` — the environment wins over
the file, as everywhere else in the runtime.

What it watches is what PHP actually loaded — the module graph, the way `node --watch` does it, not
a directory tree — so Symfony's compiled container is watched because the application loaded it, and
the thousands of vendor files it never touches are not. When one changes, the workers reload **one at
a time**: the rest keep serving, requests in flight finish with the code they started on, and the
listener never closes.

`SIGHUP` does the same thing without a file changing, which is what a deploy or a configuration
change wants. Both need `--supervise` — without something to respawn a worker, a worker that returns
is simply gone, and the runtime refuses to watch rather than take the server down. This reloads the
threads inside one process; with `--workers` > 1 (ADR-0044) it does not yet reach the master, which
also owns a rolling `SIGHUP` reload of whole worker processes — send that one to the master
directly until development reload forwards it (BACKLOG `S-WORKERS-FOLLOW-UP`).

Requests still **overlap** during and after a reload, which is the whole reason it works this way
rather than restarting the worker after each request: a bug that needs two requests at once —
a shared entity manager, a leaked token — has to be reproducible on a development machine.

## Limits

`Ignis\Scope` is keyed by the fiber, and fibers are reused, so anything stored there is cleared at
request end — that is what makes "per fiber" mean "per request" (V-67). State you keep anywhere
else (a static, a container singleton) is shared by every request on the thread, which is the whole
subject of the [Symfony](symfony.md) page.

The full class-by-class surface is the [PHP API reference](../reference/php-api.md).
