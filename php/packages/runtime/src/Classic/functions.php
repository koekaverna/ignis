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
 * real globals, and therefore the mode a legacy docroot needs. One request at a time per thread.
 * See docs/classic-mode.md — V-53 measured every alternative and they all leave `$GLOBALS` empty.
 *
 * @param array<string,string> $server extra $_SERVER entries
 */
function listen(string $docroot, string $addr, ?string $index = 'index.php', array $server = []): void
{
    configureRunner($docroot, $index, $server, null);
    \Ignis\Loop::$rawRequestHandler = Runner::queue(...);
    \ignis_serve($addr);
}

/**
 * Everything both entry points need before a request can arrive: the docroot, the `$_SERVER`
 * extras, and the `php://` wrapper that makes `php://input` this request's body.
 *
 * @param array<string,string>       $server
 * @param null|callable(string):void $run   runs one script file; the default is a plain `include`
 */
function configureRunner(string $docroot, ?string $index, array $server, ?callable $run): void
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
 * A docroot behind the fiber-per-request loop: the mode for a framework front controller, where
 * requests overlap. See docs/classic-mode.md for the choice between this and `listen()`.
 *
 * @param array<string,string> $server extra $_SERVER entries (like FrankenPHP's `env` subdirective)
 * @param null|callable(string):void $run runs one script file; the default is a plain `include`
 */
function serve(string $docroot, string $addr, ?string $index = 'index.php', array $server = [], ?callable $run = null): void
{
    configureRunner($docroot, $index, $server, $run);
    \Ignis\serve(Runner::handle(...), $addr);
}
