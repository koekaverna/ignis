<?php
// H36 (ADR-0020 acceptance 5): a library that holds a pthread mutex across a blocking read.
// Two fibers on ONE thread call it against an empty pipe; a writer fiber feeds the pipe after
// 200 ms. Expected: with the library on a `park` row the second fiber finds the mutex held by a
// parked fiber (result -2 = "would have deadlocked"); with it on `block` the calls serialize.
// Usage: IGNIS_LOCKLIB=/tmp/e18/liblocklib.so IGNIS_THREADS=1 ignis bench/php/e18_deadlock.php
declare(strict_types=1);
require __DIR__ . '/../../php/ignis.php';

if (!function_exists('ignis_locklib_call')) {
    fwrite(STDERR, "ignis_locklib_call() missing: build with IGNIS_LOCKLIB pointing at liblocklib.so\n");
    exit(2);
}

$policy = getenv('IGNIS_PARK') === false ? 'seed' : (getenv('IGNIS_PARK') ?: 'empty');
[$r, $w] = [null, null];
$pipe = ignis_locklib_pipe();          // [read fd, write fd] of a fresh empty pipe

$t0 = hrtime(true);
$fs = [];
foreach ([0, 1] as $i) {
    $fs[] = Ignis\async(static function () use ($i, $pipe, $t0): array {
        $s = hrtime(true);
        $n = ignis_locklib_call($pipe[0], 16, /* trylock */ $i === 1);
        return ['fiber' => $i, 'result' => $n, 'ms' => intdiv(hrtime(true) - $s, 1_000_000)];
    });
}
$fs[] = Ignis\async(static function () use ($pipe): void {
    Ignis\sleep(200);                   // let both fibers reach the call first
    ignis_locklib_write($pipe[1], "x");
});
foreach (Ignis\all($fs) as $res) {
    if (is_array($res)) {
        printf("  fiber %d: result=%d (%s) after %d ms\n", $res['fiber'], $res['result'],
            $res['result'] === -2 ? 'MUTEX HELD BY A PARKED FIBER — the hazard' : 'returned', $res['ms']);
    }
}
printf("e18_deadlock: policy=%s total %d ms\n", $policy, intdiv(hrtime(true) - $t0, 1_000_000));
