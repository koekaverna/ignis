<?php

/** Research 50 §A `spin.php`: S-1/S-7/S-9, running PHP (VM) with no suspension point. */

declare(strict_types=1);

require __DIR__ . '/common.php';

use Ignis\Http\Request;
use Ignis\Http\Response;

function spinForever(): never
{
    $count = 0;
    while (true) {
        $count++;
    }
}

serveStallFixture(static function (Request $request): Response {
    spinForever();
});
