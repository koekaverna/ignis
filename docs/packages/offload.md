# `ignis/offload`

For the calls that cannot park: run them on a synchronous worker thread and suspend the fiber
meanwhile (ADR-0016).

## What it does

Park works when there is readiness to wait for — a socket. A regular file has none: the kernel will
not tell you when a read will be fast, so `pdo_sqlite`, an image library or a CPU-bound extension
blocks the OS thread and every fiber on it. Offload moves that call to a worker thread with its own
PHP context: arguments are serialized in, the result serialized back, and the calling fiber parks on
the answer like it would on a socket.

## Install

```
composer require ignis/offload:@dev
```

and start the runtime with workers:

```
ignis --offload 4 app.php          # or offload = 4 in ignis.toml
```

Without `--offload N` the pool does not exist and `Ignis\offload()` throws — deliberately, rather
than silently running the blocking call in the request thread.

## Use it

```php
$thumbnail = Ignis\offload('make_thumbnail', $bytes, 200);
```

`"Class::method"` works too. A `\Closure` among the arguments becomes a callback the worker can
call back on the calling thread, in its own fiber, so it may await.

## Configure

| Setting | Default | What it does |
|---|---|---|
| `--offload N` / `offload = N` | 0 (off) | Worker threads. Each is a full PHP context. |
| `IGNIS_OFFLOAD_CLASSES` | `SQLite3` | Classes whose `new` is routed to a worker automatically when it happens inside a fiber. |
| `IGNIS_OFFLOAD_FUNCTIONS` | empty | Internal functions routed the same way. |
| `IGNIS_NO_OFFLOAD_ROUTE` | unset | Any value disables auto-routing entirely — the hook-off control. |

Auto-routing is why an application usually calls nothing: `new SQLite3(...)` inside a fiber runs on
a worker and comes back as a proxy that `instanceof SQLite3` still recognises. Outside a fiber the
original runs, unchanged.

## What is not routed, and why

`curl_*` and `PDO` left the default list once park could handle them: routing a socket-backed call
to a worker is strictly slower — 100 concurrent 200 ms `pdo_pgsql` queries take **303 ms** parked
against **2,753 ms** through eight workers (V-59). The routing decision is made when the VM executes
`new`, before the constructor's arguments exist, so `pgsql:` and `sqlite:` cannot be told apart at
that moment; routing the class that is always file-backed is the honest default (`R-PDO-SQLITE`).

## Limits

Copy-in/copy-out means the arguments and the result must survive `serialize()`. A handle stays
pinned to the worker that created it, so a proxy is not something to pass between threads. And a
pool of N workers is a queue: past N concurrent offloaded calls, fibers wait.
