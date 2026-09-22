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
 * returns the number of seconds left un-slept when a signal interrupts it. The shim records that
 * return as `blocking_call ... errno=125` (ECANCELED), and the interrupt function force-closes the
 * fiber at its very next opcode, so under the ladder the client gets the watchdog's 504 and never
 * this handler's answer. The `unslept_s` field is what a run without the kill sees (a hand-sent
 * `SIGRTMIN+2` with `IGNIS_STALL_KILL_MS=0`): `unslept > 0` is then the signal's own evidence,
 * because a blocking `sleep($seconds)` cannot otherwise return before $seconds elapses.
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
