# Classic mode: running a legacy docroot

`Ignis\Classic` runs an unmodified PHP docroot — the `index.php` front controller, the procedural
`*.php` file per URL — on the resident runtime. It has two entry points, and the difference between
them is only about **where the entry script's top-level variables end up**.

## `Ignis\Classic\serve()` — a front controller

```php
require '.../php/packages/runtime/src/ignis.php';
require '.../php/packages/runtime/src/classic.php';
Ignis\Classic\serve('/var/www/html/public', '0.0.0.0:8080');
```

Each request runs in its own fiber, so requests overlap exactly as they do for a native
`Ignis\serve()` handler. This is the mode for a framework front controller — Symfony today (V-16,
V-40) — and for any application that keeps its state in objects rather than in globals. Laravel is
not supported yet: `Container::$instance` is a process-global static that two interleaved fibers
clobber, and the fix is open work (research 25, M3-5a/M3-5b). Serialising it with
`budget.fibers = 1` is the route being tried, not a shipped one.

## `Ignis\Classic\listen()` — real globals, one request at a time

```php
require '.../php/packages/runtime/src/ignis.php';
require '.../php/packages/runtime/src/classic.php';
Ignis\Classic\listen('/var/www/html/public', '0.0.0.0:8080');
while ($script = Ignis\Classic\accept()) {
    include $script;              // top level of the main script: real globals
    Ignis\Classic\respond();
}
```

The loop is the caller's `while`, and the `include` happens at the top level of the main script.
That shape is not a style choice — it is the **only** shape in which an entry script's top-level
variables become real globals.

**V-53 measured every alternative.** Including the script from a function, from a closure, from a
fiber, and with `extract($GLOBALS, EXTR_REFS)` around it: all four leave `$GLOBALS` empty, so
`$GLOBALS['wpdb']` is unset and a `global $wpdb;` inside a function sees `null`. Only an `include`
at the top level of the main script gets real globals. Legacy applications that keep state in
globals — WordPress's `$wpdb`, Drupal, any procedural docroot — need this mode for that reason and
no other.

### What it costs

- **One request at a time per thread, by construction.** The loop is the caller's `while`, so
  nothing else runs while the script does. It is the same trade `Ignis\Classic` already documents
  ("a classic script must not suspend") and the same shape RoadRunner and FrankenPHP's worker mode
  use. Concurrency comes from running more PHP threads (`--threads N`), not from more fibers.
- **Functions declared at top level live for the life of the worker.** A script that declares them
  unguarded fatals on the second request (V-53): use `require_once`, or guard with
  `function_exists()`. That is the rule in every worker runtime.

### How it is wired

`listen()` sets `Ignis\Loop::$rawRequestHandler`, which hands the raw request to the loop's *caller*
instead of spawning a fiber for it — the one place in the runtime where a request is not answered
from inside the loop. `accept()` blocks on that queue, prepares `$_SERVER`/`$_GET`/`php://input`,
output buffering and the session, and returns the script path to `include`.

## Related

- `php/packages/runtime/src/Classic/` — `functions.php` (the four entry points), `Runner.php` (the
  request machinery), `InputStream.php` (the `php://` wrapper), `polyfills.php` (`getallheaders()`
  and friends).
- V-53 in `VALIDATION.md` for the globals measurement.
- `docs/migrate.md` for choosing between the two modes.
