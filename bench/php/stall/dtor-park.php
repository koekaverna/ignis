<?php

/**
 * Research 50 §A `dtor-park.php`: GC destructor fiber parks (research 49 H1/H2).
 *
 * `ParkingDestructor` objects that reference each other are a reference cycle plain refcounting
 * never frees; `gc_collect_cycles()` frees them and runs `__destruct()`, and `usleep()` inside a
 * destructor is one of the interposed calls, so it tries to park the fiber that happens to be
 * running when the collector runs -- which may not be the fiber the objects were built on. This is
 * the hazard research 49 H1/H2 names: S-12 expects zero ASAN/valgrind reports and a flat memory
 * curve over 10,000 cycles; the tree at `e909c86` is the control that is expected to report a
 * use-after-free or leak the fiber under the same fixture.
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
