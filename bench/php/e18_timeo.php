<?php
// SO_RCVTIMEO under universal park (research 30 finding): a blocking socket_recv() with a kernel
// receive timeout must return false with EAGAIN after ~T ms — and not block the thread meanwhile.
// Usage: ignis bench/php/e18_timeo.php   (sockets.rs is gone; the seed's libphp rows carry this)
require __DIR__ . '/../../php/packages/runtime/src/ignis.php';
$t0 = hrtime(true);
$fs = [];
for ($i = 0; $i < 3; $i++) {
    $fs[] = Ignis\async(static function () use ($i, $t0): array {
        socket_create_pair(AF_UNIX, SOCK_STREAM, 0, $pair);
        [$a, $b] = $pair;
        socket_set_option($a, SOL_SOCKET, SO_RCVTIMEO, ['sec' => 0, 'usec' => 200000]);
        $s = hrtime(true);
        $r = socket_recv($a, $buf, 16, 0);
        $err = socket_last_error($a);
        return ['fiber' => $i, 'result' => $r, 'errno' => $err, 'ms' => intdiv(hrtime(true) - $s, 1_000_000)];
    });
}
$res = Ignis\all($fs);
foreach ($res as $r) printf("  fiber %d: result=%s errno=%d (%s) took %d ms\n", $r['fiber'], var_export($r['result'], true), $r['errno'], socket_strerror($r['errno']), $r['ms']);
printf("e18_timeo: 3 fibers x SO_RCVTIMEO=200ms total %d ms (parked: ~200, blocked: ~600, hung: never returns)\n", intdiv(hrtime(true) - $t0, 1_000_000));
