<?php

/**
 * Research 50 §A `c-loop.php`: S-10, running C (`password_hash()`), no interrupt checks reachable.
 * Measured on this box: cost 20 takes 61.5s, the smallest cost that clears the 60s bar the ADR
 * asks for -- override with `?cost=` for a faster or slower box.
 */

declare(strict_types=1);

require __DIR__ . '/common.php';

use Ignis\Http\Request;
use Ignis\Http\Response;

serveStallFixture(static function (Request $request): Response {
    $cost = (int) ($request->query('cost') ?? 20);
    $hash = password_hash('x', PASSWORD_BCRYPT, ['cost' => $cost]);
    return Response::text("hashed with cost $cost: $hash\n");
});
