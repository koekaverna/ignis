<?php

/**
 * The classic-mode entry points. PSR-4 cannot autoload a function, so `classic.php` requires this.
 */
declare(strict_types=1);

namespace Ignis\Classic;

use Ignis\Http\Request;
use Ignis\Http\Response;

/** Ends the current script now (the classic-mode replacement for exit()). */
function finish(): never
{
    throw new Finished();
}

/**
 * The top-level worker loop: the only shape in which an entry script's top-level variables become
 * real globals (V-53 measured every alternative — a function, a closure, a fiber and
 * `extract($GLOBALS, EXTR_REFS)` all leave `$GLOBALS` empty). Legacy apps that keep state in
 * globals — WordPress's `$wpdb`, Drupal, any procedural docroot — need this; a framework front
 * controller (Symfony, Laravel) does not and can keep using `Ignis\Classic\serve()`.
 *
 *     require '.../php/packages/runtime/src/ignis.php';
 *     require '.../php/packages/runtime/src/classic.php';
 *     Ignis\Classic\listen('/var/www/html/public', '0.0.0.0:8080');
 *     while ($script = Ignis\Classic\accept()) {
 *         include $script;              // top level of the main script: real globals
 *         Ignis\Classic\respond();
 *     }
 *
 * One request at a time per thread, by construction — the loop is the caller's `while`, so nothing
 * else runs while the script does. That is the same trade `Ignis\Classic` already documents ("a
 * classic script must not suspend") and the same shape RoadRunner and FrankenPHP's worker mode use.
 * Functions the script declares at top level still live for the life of the worker, so a script
 * that declares them unguarded fatals on the second request (V-53): `require_once`, or guard with
 * `function_exists()` — the rule in every worker runtime.
 *
 * @param array<string,string> $server extra $_SERVER entries
 */
function listen(string $docroot, string $addr, ?string $index = 'index.php', array $server = []): void
{
    Runner::$docroot = rtrim($docroot, '/');
    Runner::$index = $index;
    Runner::$extra = $server;
    Runner::$env = getenv();
    Runner::$run = static function (string $file): void {
        include $file;
    };
    stream_wrapper_unregister('php');
    stream_wrapper_register('php', InputStream::class);
    \Ignis\Loop::$rawRequestHandler = Runner::queue(...);
    \ignis_serve($addr);
}

/**
 * Blocks until one request arrives, prepares it ($_SERVER, $_GET, php://input, output buffering,
 * the session) and returns the script to `include`. Null means the loop stopped for good.
 * A request for a non-PHP file or a missing path is answered here and never returned.
 */
function accept(): ?string
{
    return Runner::accept();
}

/** Ends the request `accept()` returned: flush the buffer into the response and send it. */
function respond(): void
{
    Runner::end();
}

/** Sends the response now and lets the script go on (fastcgi_finish_request() analogue); later output is dropped. */
function finish_request(): bool
{
    return Runner::finishRequest();
}

/**
 * @param array<string,string> $server extra $_SERVER entries (like FrankenPHP's `env` subdirective)
 * @param null|callable(string):void $run runs one script file; the default is a plain `include`
 */
function serve(string $docroot, string $addr, ?string $index = 'index.php', array $server = [], ?callable $run = null): void
{
    Runner::$docroot = rtrim($docroot, '/');
    Runner::$index = $index;
    Runner::$extra = $server;
    Runner::$env = getenv();
    Runner::$run = $run ?? static function (string $file): void {
        include $file;
    };
    stream_wrapper_unregister('php');
    stream_wrapper_register('php', InputStream::class);
    \Ignis\serve(Runner::handle(...), $addr);
}
