# Legacy apps: the classic worker loop

A framework front controller (Symfony, Laravel) keeps its state in objects and runs fine in worker
mode with a Fiber per request. A procedural docroot usually does not: it assigns variables at the
top level of `index.php` and reads them back with `global $x` inside functions. That combination
only works if the `include` runs at the **top level of the main script** — not inside a function,
not inside a closure, and not inside a Fiber. V-54 measured all four placements directly: only the
top-level include leaves `global $probe` seeing the value afterwards; a function, a closure, and a
Fiber all leave it `NULL`.

So classic mode offers a loop your own script owns, instead of handing control to
`Ignis\Classic\serve()`'s fiber-based dispatch:

```php
require '/path/to/ignis/php/packages/runtime/src/ignis.php';
require '/path/to/ignis/php/packages/runtime/src/classic.php';

Ignis\Classic\listen('/var/www/html/public', '0.0.0.0:8080');
while ($script = Ignis\Classic\accept()) {
    include $script;          // top level of this script: real globals
    Ignis\Classic\respond();
}
```

Run it with `ignis --threads 4 examples/classic_worker.php /var/www/html/public` — a full,
copy-pasteable version is at `examples/classic_worker.php`.

## What this buys, measured (V-54)

| | stock `php -S` | `Ignis\Classic\serve()` (fibers) | classic worker loop (above) |
|---|---|---|---|
| `$wpdb` at top level → `$GLOBALS['wpdb']` | set | **NULL** | **set** |
| `global $wpdb` in a function | set | **NULL** | **set** |
| 3 requests in a row | 3 distinct values | 3 × NULL | **3 distinct values** |
| 200 sequential requests | — | — | **198 of 200 distinct** (2 collisions were `mt_rand`, not shared state) |
| throughput, 1 thread, `wrk -t1 -c8 -d5s` | — | — | **10,526 req/s** |

## The one thing this does not fix

One request at a time per thread, by construction — the loop is your own `while`, so nothing else
on that thread runs while the current script does. Concurrency comes from threads (`--threads N`),
the same shape RoadRunner and FrankenPHP's worker mode use, not from Fibers.

An unguarded top-level `function foo() {}` still survives into the next request on the same
thread, and PHP's "Cannot redeclare function" is a fatal no handler can catch — it ends the worker
(the supervisor respawns the thread, but the in-flight request is lost). V-54 confirmed there is no
cheap fix for this: the only real one is a full PHP request cycle per HTTP request
(`RINIT`/`RSHUTDOWN`), which is exactly the bootstrap cost the worker model exists to avoid. The
rule instead — the same one every worker runtime documents — is: declare functions and classes
with `require_once` or behind `function_exists()`, and do not suspend inside the script.

Next: [Compatibility](../compatibility.md) for the full list of what does and does not carry over
unmodified.
