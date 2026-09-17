<?php
// A4 / H29 (ADR-0018): N concurrent ext/sockets reads, each waiting DELAY ms, on ONE PHP thread.
//
// The server half uses stream_socket_server/accept, which the ADR-0007 transport hook already
// parks (E6''), so the only thing under test is the CLIENT half: socket_create/socket_connect/
// socket_read from ext/sockets, which goes nowhere near php_stream.
//
// Hooked   : every socket_read parks its fiber -> wall ~ DELAY, independent of N.
// Unhooked (IGNIS_NO_SOCKETS_HOOK=1): the first socket_read blocks the OS thread, so the server
// fibers never get to run and nothing completes -- the control is expected to STALL, which is the
// point: it is what "blocks the thread" looks like from outside.
declare(strict_types=1);
require __DIR__ . '/../../php/packages/runtime/src/ignis.php';

$n = (int) (getenv('N') ?: 20);
$delayMs = (int) (getenv('DELAY_MS') ?: 200);

$srv = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
if ($srv === false) {
    fwrite(STDERR, "listen failed: $errstr\n");
    exit(1);
}
$addr = stream_socket_get_name($srv, false);
[$host, $port] = explode(':', $addr);

// Server: accept all N first (fast, backlog), then answer each after DELAY ms concurrently.
Ignis\async(static function () use ($srv, $n, $delayMs) {
    $conns = [];
    for ($i = 0; $i < $n; $i++) {
        $c = stream_socket_accept($srv, 5);
        if ($c === false) {
            return;
        }
        $conns[] = $c;
    }
    foreach ($conns as $c) {
        Ignis\async(static function () use ($c, $delayMs) {
            Ignis\sleep($delayMs);
            fwrite($c, 'ok');
            fclose($c);
        });
    }
});

$t0 = hrtime(true);
$clients = [];
for ($i = 0; $i < $n; $i++) {
    $clients[] = Ignis\async(static function () use ($host, $port) {
        $s = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
        if ($s === false || !socket_connect($s, $host, (int) $port)) {
            return 'connect-failed';
        }
        $got = socket_read($s, 2);          // <-- the hooked call under test
        socket_close($s);
        return $got === false ? 'read-failed' : $got;
    });
}
$got = array_map(static fn ($f) => $f->await(), $clients);
$wallMs = (hrtime(true) - $t0) / 1e6;

$ok = count(array_filter($got, static fn ($v) => $v === 'ok'));
printf("n=%d delay_ms=%d wall_ms=%.1f ok=%d hook=%s\n", $n, $delayMs, $wallMs, $ok, getenv('IGNIS_NO_SOCKETS_HOOK') ? 'off' : 'on');
// Concurrency holds when the wall stays near one delay instead of growing with N.
exit(($ok === $n && $wallMs < $delayMs * 3) ? 0 : 1);
