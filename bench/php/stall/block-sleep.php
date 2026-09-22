<?php

/**
 * Research 50 §A `block-sleep.php`: blocked in the shim, policy `block`.
 *
 * The plain builtin `sleep()` (not `Ignis\sleep()`) goes through the interposed `nanosleep`; under
 * the default policy that call parks. Run this fixture with `IGNIS_PARK=` (empty -- disables the
 * whole policy, not just this row) and it blocks the OS thread for real: S-1's second case
 * (`state=blocking_forward`, `library=libphp symbol=nanosleep`), S-8 (L4 signal cancels it),
 * S-9 (`/proc` shows `syscall=230 wchan=hrtimer_nanosleep`).
 *
 * Measured on this box: `sleep()` does not throw on the L4 signal -- glibc's own `sleep()` just
 * returns the number of seconds left un-slept when a signal interrupts it, so the response reports
 * that instead of an exception. `unslept > 0` is the L4 signal's own evidence: without it the
 * blocking `sleep($seconds)` call cannot return before $seconds elapses.
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
