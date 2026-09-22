<?php

/**
 * Research 50 §A `flock-hold.php`: a lock held across a yield (R-SESS shape, V-58 regression).
 *
 * Fiber A takes `flock(LOCK_EX)`, then `Ignis\sleep()`s while holding it; fiber B asks for the same
 * lock while A holds it. `flock` is one of the interposed calls (`libphp:flock` in the default
 * policy), so B's wait becomes `LOCK_NB` plus a parked retry -- the worker keeps serving `/hello`
 * for the whole hold. This must produce no `stall` and no `blocking_call` line (S-13); with
 * `IGNIS_NO_UNIVERSAL_PARK=1` the same file becomes the control, a genuine blocking `flock` line
 * and a stall.
 */

declare(strict_types=1);

require __DIR__ . '/common.php';

use Ignis\Http\Request;
use Ignis\Http\Response;

serveStallFixture(static function (Request $request): Response {
    $holdMilliseconds = (int) ($request->query('hold_ms') ?? 2000);
    $path = sys_get_temp_dir() . '/ignis-stall-flock-hold.lock';
    file_put_contents($path, '');

    $outcome = Ignis\all([
        Ignis\async(static function () use ($path, $holdMilliseconds): string {
            $handle = fopen($path, 'c');
            flock($handle, LOCK_EX);
            Ignis\sleep($holdMilliseconds);
            flock($handle, LOCK_UN);
            fclose($handle);
            return 'holder released';
        }),
        Ignis\async(static function () use ($path): string {
            Ignis\sleep(50);
            $handle = fopen($path, 'c');
            flock($handle, LOCK_EX);
            flock($handle, LOCK_UN);
            fclose($handle);
            return 'waiter acquired';
        }),
    ]);

    return Response::json($outcome);
});
