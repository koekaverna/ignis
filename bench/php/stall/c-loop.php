<?php

/** Research 50 §A `c-loop.php`: S-10, running C (`password_hash()`), no interrupt checks reachable. */

declare(strict_types=1);

require __DIR__ . '/common.php';

use Ignis\Http\Request;
use Ignis\Http\Response;

serveStallFixture(static function (Request $request): Response {
    $cost = (int) ($request->query('cost') ?? 20);
    $hash = password_hash('x', PASSWORD_BCRYPT, ['cost' => $cost]);
    return Response::text("hashed with cost $cost: $hash\n");
});
