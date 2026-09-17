<?php

// E13 (c): cost of a suspend/resume pair, with vs without the superglobals observer (IGNIS_NO_SUPERGLOBALS=1).
declare(strict_types=1);
$n = (int) (getenv('N') ?: 1000000);
ignis_set_superglobals(['REQUEST_URI' => '/x'], ['a' => 1], [], []);
$f = new Fiber(static function () use ($n): void {
    ignis_set_superglobals(['REQUEST_URI' => '/y'], ['b' => 2], [], []);
    for ($i = 0; $i < $n; $i++) {
        Fiber::suspend();
    }
});
$t0 = hrtime(true);
$f->start();
for ($i = 0; $i < $n; $i++) {
    $f->resume();
}
$ns = (hrtime(true) - $t0) / $n;
printf("switch_pairs=%d ns_per_pair=%.0f ns_per_switch=%.0f observer=%s\n", $n, $ns, $ns / 2, getenv('IGNIS_NO_SUPERGLOBALS') ? 'off' : 'on');
