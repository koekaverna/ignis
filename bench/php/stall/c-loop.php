<?php

/**
 * Research 50 §A `c-loop.php`: running C, no interrupt checks.
 *
 * `password_hash()` runs libxcrypt's bcrypt entirely in C; it never returns to a VM opcode, so
 * `zend_interrupt_function` (L3's mechanism) never runs and the fiber cannot be killed -- only
 * abandoned (L5, S-10). Cost is measured on this box, not assumed:
 *
 * | cost | time  |
 * |------|-------|
 * | 10   | 0.06s |
 * | 12   | 0.24s |
 * | 14   | 0.96s |
 * | 16   | 3.87s |
 * | 18   | 15.3s |
 * | 20   | 61.5s |
 *
 * (`/opt/php85-zts/bin/php`, one run each, 2026-09-22). Research 50's own text says cost 20 is
 * "~1 s" on its reference box; here it is 61.5 s, so **20** is the smallest cost this box needs to
 * clear the 60 s bar the ADR asks for -- override with `?cost=` for a faster or slower box.
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
