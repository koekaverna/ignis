# Quickstart

## Hello world

`examples/hello_server.php` is a worker-mode script: it stays resident, and every request runs in
a pooled Fiber.

```php
<?php
declare(strict_types=1);
require __DIR__ . '/../php/ignis.php';

use Ignis\Http\Request;
use Ignis\Http\Response;

Ignis\serve(static function (Request $req): Response {
    return match ($req->path()) {
        '/' => Response::text("Hello, World!\n"),
        default => Response::text("not found\n", 404),
    };
}, '127.0.0.1:8080');
```

Run it (after [Install](install.md)):

```
LD_LIBRARY_PATH=/opt/php85-zts/lib ./target/release/ignis serve examples/hello_server.php
curl http://127.0.0.1:8080/
```

or, with the Docker image and nothing built locally:

```
docker run -p 8080:8080 ghcr.io/koekaverna/ignis
curl http://127.0.0.1:8080/
```

## Configure

Copy `ignis.toml.example` to `ignis.toml` next to your app. Every key is optional; an unknown key
is an error, not a silently-ignored typo. Precedence, highest first: CLI flag, `IGNIS_*`
environment variable, the file, the product default. Full key reference:
[Configuration](../reference/configuration.md).

```toml
entry = "examples/hello_server.php"
listen = "127.0.0.1:8080"
# threads = 4              # default: available parallelism
# offload = 4              # synchronous workers for what cannot park (SQLite3, file-backed PDO,
#                           # CPU-bound calls); default 0. curl parks, it needs no worker.
```

## An async call, unmodified

The point of the runtime shows up the moment a request waits on something. This handler makes
three concurrent HTTP calls to itself — on **one thread**, with plain `file_get_contents()`:

```php
'/fetch' => (static function () use ($listen): Response {
    $bodies = Ignis\all([
        Ignis\async(static fn () => file_get_contents("http://$listen/sleep?ms=200")),
        Ignis\async(static fn () => file_get_contents("http://$listen/sleep?ms=200")),
        Ignis\async(static fn () => file_get_contents("http://$listen/sleep?ms=200")),
    ]);
    return Response::json(['bodies' => $bodies]);
})(),
```

Three 200 ms calls return in ~201–202 ms, not 600 ms (V-3) — each `file_get_contents()` parked its
Fiber instead of blocking the thread, and the thread served other requests while all three were in
flight. See [Compatibility](../compatibility.md) for the full list of what parks unmodified.

## Health

Every `ignis serve` process answers its own health check — 200 while at least one worker thread is
alive and not stalled, 503 otherwise, so a load balancer can stop routing to a wedged process even
when PHP itself cannot say so:

```
curl http://127.0.0.1:8080/_ignis/health
```

Next: [Symfony](symfony.md) for a real framework, or [Legacy apps](legacy.md) for a procedural
docroot that keeps state in globals.
