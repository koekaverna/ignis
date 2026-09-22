<?php

/**
 * Research 50 §A `spin.php`: Running PHP (VM), no suspension point.
 *
 * `while (true) { $i++; }` never calls into the interpreter's I/O hooks, so it is only visible to
 * the ticker's `/proc` classification (running, `interrupt_acks` advancing once L3 delivers a
 * signal) -- S-1 (detection), S-7 (L3 kill), S-9 (classification).
 *
 * `?jit=1` on `/stuck` is a marker only: the loop below does not change, but running this same
 * file with `IGNIS_PHP_INI` pointing at an ini that sets `opcache.jit=tracing` and
 * `opcache.jit_buffer_size` is the "under JIT" arm of S-7/S-9, because a JIT-compiled loop still
 * has to honour `zend_interrupt_function` at its own back-edge checks -- the fixture does not need
 * two code paths, only two ways of starting it.
 */

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
