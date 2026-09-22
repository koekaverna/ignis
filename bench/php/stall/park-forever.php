<?php

/**
 * Research 50 §A `park-forever.php`: parked forever.
 *
 * A `Future` nobody ever resolves: `await()` suspends the fiber and nothing -- no timer, no
 * socket, no signal -- will ever wake it. Without a per-request ceiling this hangs until the
 * process exits (V-17's behaviour, S-5's control, `fiber_timeout_ms=0`); with `fiber_timeout_ms`
 * set, S-5 expects a 504 within it plus a `fiber_timeout` line naming where the park happened.
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
