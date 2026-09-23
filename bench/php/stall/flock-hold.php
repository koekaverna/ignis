<?php

/**
 * Research 50 §A `flock-hold.php`: S-13, a lock held across a yield (R-SESS shape, V-58 regression).
 * Fiber B's `flock` wait becomes a parked retry, so the worker keeps serving `/hello`; expects no
 * `stall` and no `blocking_call` line (the control is the same file under `IGNIS_NO_UNIVERSAL_PARK=1`).
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
