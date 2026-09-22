<?php

/**
 * Research 50 §A `swallow-cancel.php`: cancellation swallowed.
 *
 * Research 50 shapes this as `try { PDO query } catch (\Throwable) { sleep-park again; }`; this
 * fixture has no database attached, so `Ignis\sleep()` stands in for the query -- what matters for
 * S-6 is the shape, a broad `catch (\Throwable)` that parks again instead of letting the
 * cancellation propagate, not which call was parked. On a client disconnect the loop's cancel
 * (L1) throws into this fiber; catching it here and sleeping again is exactly the application bug
 * `on_swallowed_cancel` exists for: `force-close` must end the fiber at its *next* park regardless,
 * `log` must let it run on and emit one warning line.
 */

declare(strict_types=1);

require __DIR__ . '/common.php';

use Ignis\Http\Request;
use Ignis\Http\Response;

serveStallFixture(static function (Request $request): Response {
    $milliseconds = (int) ($request->query('ms') ?? 30000);
    try {
        Ignis\sleep($milliseconds);
    } catch (\Throwable) {
        Ignis\sleep($milliseconds);
    }
    return Response::text("finished without ever seeing the cancellation\n");
});
