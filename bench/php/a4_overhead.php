<?php
// A4 kill-criterion #2 (ADR-0018): cost of the hook on the NON-parking path.
//
// A UDP socket is always writable, so every socket_sendto takes the ready_now() fast path and
// never parks — which is what this measures. An IP literal is used on purpose: a hostname would
// go through php_network_gethostbyname(), the blocking DNS path the ADR lists as out of scope.
// The destination is a BOUND socket in this process: sending to a closed port instead makes the
// kernel answer every packet with ICMP port-unreachable, which slowed the whole box by ~10x after
// a few hundred thousand sends and made the first version of this bench useless.
// Compare `hook=on` against `IGNIS_NO_SOCKETS_HOOK=1` (`hook=off`); the difference is the hook.
declare(strict_types=1);
require __DIR__ . '/../../php/ignis.php';

$n = (int) (getenv('N') ?: 100000);
$s = socket_create(AF_INET, SOCK_DGRAM, SOL_UDP);
$sink = socket_create(AF_INET, SOCK_DGRAM, SOL_UDP);
if ($s === false || $sink === false || !socket_bind($sink, '127.0.0.1', 0)) {
    fwrite(STDERR, "socket setup failed\n");
    exit(1);
}
socket_getsockname($sink, $sinkHost, $sinkPort);
$f = Ignis\async(static function () use ($s, $n, $sinkPort) {
    $t0 = hrtime(true);
    for ($i = 0; $i < $n; $i++) {
        @socket_sendto($s, 'x', 1, 0, '127.0.0.1', $sinkPort);
    }
    return (hrtime(true) - $t0) / 1e3 / $n;
});
printf("n=%d us_per_call=%.4f hook=%s\n", $n, $f->await(), getenv('IGNIS_NO_SOCKETS_HOOK') ? 'off' : 'on');
