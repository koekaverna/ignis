<?php

/** Research 50 §A `finally-io.php`: S-6, `finally` does I/O during a force-close. */

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
