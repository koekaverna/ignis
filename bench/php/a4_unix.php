<?php
// A4 (ADR-0018): unix:// client streams park the fiber like tcp:// ones.
// Server half uses the stock unix transport (server sockets are not hooked); N client fibers each
// wait DELAY ms for their answer. Hooked: ~DELAY. Control (IGNIS_NO_UNIX_HOOK=1): serialised.
declare(strict_types=1);
require __DIR__ . '/../../php/packages/runtime/src/ignis.php';
$n = (int) (getenv('N') ?: 10);
$delay = (int) (getenv('DELAY_MS') ?: 200);
$path = sys_get_temp_dir() . '/ignis-a4-' . getmypid() . '.sock';
@unlink($path);
$srv = stream_socket_server("unix://$path", $e, $s);
if ($srv === false) { fwrite(STDERR, "listen: $s\n"); exit(1); }
Ignis\async(static function () use ($srv, $n, $delay) {
    $conns = [];
    for ($i = 0; $i < $n; $i++) { $c = stream_socket_accept($srv, 5); if ($c === false) return; $conns[] = $c; }
    foreach ($conns as $c) {
        Ignis\async(static function () use ($c, $delay) { Ignis\sleep($delay); fwrite($c, 'ok'); fclose($c); });
    }
});
$t0 = hrtime(true);
$fs = [];
for ($i = 0; $i < $n; $i++) {
    $fs[] = Ignis\async(static function () use ($path) {
        $c = stream_socket_client("unix://$path", $e, $s, 5);
        if ($c === false) return "connect-failed:$s";
        $got = fread($c, 2); fclose($c); return $got;
    });
}
$got = array_map(static fn ($f) => $f->await(), $fs);
$wall = (hrtime(true) - $t0) / 1e6;
@unlink($path);
$ok = count(array_filter($got, static fn ($v) => $v === 'ok'));
printf("n=%d delay_ms=%d wall_ms=%.1f ok=%d hook=%s\n", $n, $delay, $wall, $ok, getenv('IGNIS_NO_UNIX_HOOK') ? 'off' : 'on');
exit(($ok === $n && $wall < $delay * 3) ? 0 : 1);
