<?php
// E2 / H3: all() of three 200ms sleeps must return in < 230ms; per-fiber overhead < 100us.
declare(strict_types=1);
require __DIR__ . '/../../php/ignis.php';

$t0 = hrtime(true);
$r = Ignis\all([
    Ignis\async(static function () { Ignis\sleep(200); return 'a'; }),
    Ignis\async(static function () { Ignis\sleep(200); return 'b'; }),
    Ignis\async(static function () { Ignis\sleep(200); return 'c'; }),
]);
$allMs = (hrtime(true) - $t0) / 1e6;

// Per-fiber overhead: N fibers doing a 0 ms sleep (still round-trips through tokio).
// Round 1 creates the pool (cold), round 2 reuses it (warm, H5/E2').
$n = (int) (getenv('N') ?: 10000);
ini_set('memory_limit', '1G');
$us = [];
$sum = 0;
foreach (['cold', 'warm'] as $label) {
    $t1 = hrtime(true);
    $fs = [];
    for ($i = 0; $i < $n; $i++) {
        $fs[] = Ignis\async(static function () { Ignis\sleep(0); return 1; });
    }
    Ignis\Loop::run();
    $sum = array_sum(array_map(static fn ($f) => $f->await(), $fs));
    $us[$label] = (hrtime(true) - $t1) / 1e3 / $n;
}

printf("all3x200_ms=%.2f result=%s n=%d per_fiber_us_cold=%.2f per_fiber_us_warm=%.2f fibers_created=%d\n", $allMs, implode('', $r), $sum, $us['cold'], $us['warm'], Ignis\Loop::$fibersCreated);
exit(($allMs < 230 && $us['cold'] < 100 && $sum === $n) ? 0 : 1);
