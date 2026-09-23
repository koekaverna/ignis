<?php

/**
 * Research 50 §A `swallow-cancel.php`: S-6, cancellation swallowed.
 * A broad `catch (\Throwable)` parks again instead of letting the cancellation propagate;
 * `force-close` must end the fiber at its next park regardless, `log` must emit one warning line.
 */

declare(strict_types=1);

require __DIR__ . '/common.php';

use Ignis\Http\Request;
use Ignis\Http\Response;

serveStallFixture(static function (Request $request): Response {
    $milliseconds = (int) ($request->query('ms') ?? 30000);
    try {
        Ignis\sleep($milliseconds);
    } catch (\Throwable) {
        Ignis\sleep($milliseconds);
    }
    return Response::text("finished without ever seeing the cancellation\n");
});
