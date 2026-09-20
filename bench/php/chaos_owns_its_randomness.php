<?php

// V-108: chaos must not touch the RNG the application draws from.
//
// `Chaos::init()` used to call `mt_srand()`, so a suite running under IGNIS_CHAOS got different
// application random values than the same suite without it — a test instrument silently changing
// its subject. Prints the application's own seeded sequence, continued across a loop boot; the
// gate compares the line with chaos on against the line with chaos off.
declare(strict_types=1);
require __DIR__ . '/../../php/packages/runtime/src/ignis.php';

mt_srand(42);
$before = [mt_rand(), mt_rand()];
Ignis\all([Ignis\async(static fn() => Ignis\sleep(1))]);
$after = [mt_rand(), mt_rand()];

printf("app_draws=%s,%s\n", implode(',', $before), implode(',', $after));
