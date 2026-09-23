<?php

/** Research 50 §A `swallow-cancel.php`: S-6, cancellation swallowed. */

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
