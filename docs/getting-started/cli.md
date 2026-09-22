# Command-line scripts

`ignis script.php` runs a PHP script the way `php script.php` does — one process, `$argv`, stdin and
stdout, the script's own exit status. The difference is that the whole runtime is there: fibers, the
reactor, and universal park. A command can therefore wait on twenty things at once without an async
client library.

There is no "async mode" and no flag for it. **The script opts in**, and it does so with the same
three functions the server side uses.

## The rule

```php
<?php
require 'vendor/autoload.php';  // composer require ignis/runtime — its autoloader needs nothing else

sleep(1);                       // top level: no fiber, so this blocks the thread — as it should

$futures = [];
foreach ($urls as $url) {
    $futures[] = Ignis\async(fn() => file_get_contents($url));   // a fiber: this parks
}
$bodies = Ignis\all($futures);  // all of them wait at once, on one thread
```

The top level of a script is `{main}`, not a fiber, so a blocking call there blocks. That is the
right answer rather than a gap: a single wait has nothing to overlap with, and an implicit
scheduler wrapped around every script would be one more thing to reason about when something hangs.

Concurrency starts exactly where the script says there is a second thing to do.

## What it costs to get it

Nothing is rewritten. `sleep()`, `file_get_contents()`, `curl_exec()`, `fsockopen()`, `PDO` over
PostgreSQL or MySQL are the ordinary blocking calls; inside a fiber their syscalls are interposed
and the fiber parks instead of the thread ([the two mechanisms](../concept/mechanisms.md)).

Measured on one thread (V-60, `examples/cli.php`):

| | sequential | `Ignis\all()` | park disabled |
|---|---|---|---|
| 10 × `sleep(1)` | 10.00 s | **1.00 s** | 10.00 s |
| 20 × `file_get_contents()` of a 200 ms endpoint | 4.07 s | **0.21 s** | — |

The third column is `IGNIS_NO_UNIVERSAL_PARK=1`: with the hook off the same program takes the
sequential time, which is how you can tell the number comes from parking and not from something
else.

## What you do not get

- **Parallel CPU.** All the fibers share one thread. Ten busy loops still run one after another,
  and so does anything that cannot park — a regular file, `SQLite3`, a CPU-bound extension call.
  `--threads N` for a script that should run N times; separate processes for anything else.
- **Concurrent DNS.** `getaddrinfo` is not interposed, so twenty `file_get_contents()` calls to a
  *hostname* resolve one at a time before their sockets ever open. Resolve once and reuse, or use an
  IP, until this is fixed.
- **A different language.** Exceptions, return values and `$argv` behave as they always did; a fatal
  error inside a fiber surfaces through its `Ignis\Future`.

## When it is worth doing

Split by the shape of the work, not by "CLI versus server":

| Command | Worth it? |
|---|---|
| A consumer loop — `messenger:consume`, a queue worker, a poller | Yes. It is a server in a trench coat, and it waits for nearly all of its life. |
| A fan-out job — import, crawl, reindex, "call this API for 50k rows" | Yes, and this is where it pays most. |
| `doctrine:migrations:migrate`, `cache:clear`, a one-off report | No. One thing at a time, nothing to overlap. Run it however you already do. |
| Anything CPU-bound | No, not from parking. More threads, or more processes. |

A Symfony Console command needs no adapter to benefit: the command body is ordinary PHP, so wrapping
the per-item work in `Ignis\async()` and awaiting with `Ignis\all()` is the whole change.

Flags, exit codes, `-r` and stdin scripts: [CLI reference](../reference/cli.md).
