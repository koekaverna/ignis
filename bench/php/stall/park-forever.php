<?php

/**
 * Research 50 §A `park-forever.php`: S-5, parked forever (a `Future` nobody ever resolves).
 * With `fiber_timeout_ms` set, expects a 504 within it plus a `fiber_timeout` line naming the park.
 */

declare(strict_types=1);

require __DIR__ . '/common.php';

use Ignis\Http\Request;
use Ignis\Http\Response;

serveStallFixture(static function (Request $request): Response {
    $future = new Ignis\Future();
    $future->await();
    return Response::text("unreachable\n");
});
