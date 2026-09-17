<?php

// A2 "before" yardstick: per-fiber warm round trip via a REAL timer (Ignis\sleep(1)) instead of
// the zero-sleep inline fast path (e2_all.php's per_fiber_us_*, which never spawns a tokio timer
// task since H28). Same warm/cold pool structure as e2_all.php; sleep(1) forces the reactor to
// tokio::spawn + register + fire + cancel-map-remove a real timer per fiber (ADR/roadmap A2 target:
// replace this with a timer wheel). New bench, not in VALIDATION.md yet.
declare(strict_types=1);
require __DIR__ . '/../../php/packages/runtime/src/ignis.php';

$n = (int) (getenv('N') ?: 10000);
ini_set('memory_limit', '1G');
$us = [];
$sum = 0;
foreach (['cold', 'warm'] as $label) {
    $t1 = hrtime(true);
    $fs = [];
    for ($i = 0; $i < $n; $i++) {
        $fs[] = Ignis\async(static function () {
            Ignis\sleep(1);
            return 1;
        });
    }
    Ignis\Loop::run();
    $sum = array_sum(array_map(static fn($f) => $f->await(), $fs));
    $us[$label] = (hrtime(true) - $t1) / 1e3 / $n;
}

printf("n=%d per_fiber_us_cold=%.2f per_fiber_us_warm=%.2f fibers_created=%d\n", $n, $us['cold'], $us['warm'], Ignis\Loop::$fibersCreated);
exit($sum === $n ? 0 : 1);
