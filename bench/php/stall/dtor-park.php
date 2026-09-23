<?php

/**
 * Research 50 §A `dtor-park.php`: S-12, GC destructor fiber parks (research 49 H1/H2).
 * A destructor's `usleep()` tries to park whichever fiber happens to be running when the
 * collector runs; expects zero ASAN/valgrind reports and flat memory over 10,000 cycles.
 */

declare(strict_types=1);

require __DIR__ . '/common.php';

use Ignis\Http\Request;
use Ignis\Http\Response;

final class ParkingDestructor
{
    public ?self $reference = null;

    public function __destruct()
    {
        usleep(1000);
    }
}

function churnDestructorCycles(int $count): void
{
    for ($i = 0; $i < $count; $i++) {
        $first = new ParkingDestructor();
        $second = new ParkingDestructor();
        $first->reference = $second;
        $second->reference = $first;
        unset($first, $second);
    }
    gc_collect_cycles();
}

serveStallFixture(static function (Request $request): Response {
    $count = (int) ($request->query('count') ?? 10_000);
    $startedAt = hrtime(true);
    churnDestructorCycles($count);
    $elapsedMilliseconds = (int) ((hrtime(true) - $startedAt) / 1_000_000);
    return Response::json(['cycles' => $count, 'elapsed_ms' => $elapsedMilliseconds]);
});
