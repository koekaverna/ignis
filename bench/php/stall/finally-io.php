<?php

/**
 * Research 50 §A `finally-io.php`: `finally` does I/O during a force-close.
 *
 * The `try` sleeps long enough for a client disconnect to reach it; the `finally` then does I/O of
 * its own. S-6 expects that I/O to get `FiberError`/`ECANCELED` immediately -- it must never park
 * (the fiber is already being torn down) and never block the thread for the connect timeout. The
 * blackhole target is `stallFixtureBlackhole()` (see `common.php`) for the same reason
 * `block-curl.php` uses it: the RFC 1918 address research 50 names is intercepted by this box's
 * sandbox and answers instantly instead of blocking.
 */

declare(strict_types=1);

require __DIR__ . '/common.php';

use Ignis\Http\Request;
use Ignis\Http\Response;

serveStallFixture(static function (Request $request): Response {
    $milliseconds = (int) ($request->query('ms') ?? 30000);
    try {
        Ignis\sleep($milliseconds);
        return Response::text("finished without disconnect\n");
    } finally {
        Ignis\Scope::set('finally_ran', true);
        [$host, $port] = explode(':', stallFixtureBlackhole());
        @file_get_contents("http://$host:$port/");
    }
});
