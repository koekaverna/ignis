<?php
// E6'': server accept inside a fiber, hooked client on the same thread; select + get_name on hooked streams.
declare(strict_types=1);
require __DIR__ . '/../../php/ignis.php';
$srv = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
$addr = stream_socket_get_name($srv, false);
$t0 = hrtime(true);
$server = Ignis\async(static function () use ($srv): array {
    $out = [];
    for ($i = 0; $i < 3; $i++) {
        $c = stream_socket_accept($srv, 5);                   // parks the fiber, not the thread
        $type = stream_get_meta_data($c)['stream_type'];
        $peer = stream_socket_get_name($c, true);
        $line = fgets($c);                                    // adopted socket: read parks too
        Ignis\sleep(100);
        fwrite($c, "echo: $line");
        fclose($c);
        $out[] = "$type peer=" . ($peer !== false ? 'ok' : 'none') . " got=" . trim((string) $line);
    }
    return $out;
});
$clients = [];
for ($i = 0; $i < 3; $i++) {
    $clients[] = Ignis\async(static function () use ($addr, $i): string {
        $c = stream_socket_client("tcp://$addr", $e, $s, 5);
        fwrite($c, "hello $i\n");
        $r = [$c]; $w = null; $x = null;
        $n = stream_select($r, $w, $x, 3);                     // dup'd fd: readiness is real
        $reply = trim((string) fgets($c));
        return "select=$n local=" . (stream_socket_get_name($c, false) !== false ? 'ok' : 'none') . " reply='$reply'";
    });
}
$res = Ignis\all(array_merge([$server], $clients));
printf("accept in a fiber: %d clients served in %.0f ms (3 x 100 ms sleeps interleaved => ~100 ms concurrent, 300 ms sequential)\n", 3, (hrtime(true) - $t0) / 1e6);
foreach ($res[0] as $l) { echo "  server: $l\n"; }
for ($i = 1; $i <= 3; $i++) { echo "  client: {$res[$i]}\n"; }
