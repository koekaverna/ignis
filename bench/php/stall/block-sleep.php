<?php

/**
 * Research 50 §A `block-sleep.php`: blocked in the shim, policy `block`.
 *
 * The plain builtin `sleep()` (not `Ignis\sleep()`) goes through the interposed `nanosleep`; under
 * the default policy that call parks. Run this fixture with `IGNIS_PARK=` (empty -- disables the
 * whole policy, not just this row) and it blocks the OS thread for real: S-1's second case
 * (`state=blocking_forward`, `library=libphp symbol=nanosleep`), S-8 (L4 signal cancels it),
 * S-9 (`/proc` shows `syscall=230 wchan=hrtimer_nanosleep`).
 */

declare(strict_types=1);

require __DIR__ . '/common.php';

use Ignis\Http\Request;
use Ignis\Http\Response;

serveStallFixture(static function (Request $request): Response {
    $seconds = (int) ($request->query('s') ?? 30);
    \sleep($seconds);
    return Response::text("slept $seconds s (blocking)\n");
});
