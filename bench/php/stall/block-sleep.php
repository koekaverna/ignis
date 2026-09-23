<?php

/**
 * Research 50 §A `block-sleep.php`: S-1/S-8/S-9, blocked in the shim, policy `block`.
 * Measured: glibc's `sleep()` does not throw on the L4 signal, it returns the seconds left
 * un-slept, which the `unslept_s` field surfaces as the signal's own evidence.
 */

declare(strict_types=1);

require __DIR__ . '/common.php';

use Ignis\Http\Request;
use Ignis\Http\Response;

serveStallFixture(static function (Request $request): Response {
    $seconds = (int) ($request->query('s') ?? 30);
    $unslept = \sleep($seconds);
    return Response::json(['requested_s' => $seconds, 'unslept_s' => $unslept]);
});
