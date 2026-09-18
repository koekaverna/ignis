<?php

// H31 reproducer: N concurrent hooked file_get_contents against a server that is ALSO under
// inbound load. Run a separate `ignis --threads 1 examples/hello_server.php`, put ~50 concurrent
// curls on /sleep?ms=200 against it, then run this. Against an *idle* server it is clean
// (0 of 1500); under load roughly 1 in 400-1200 returns false with
//   "Failed to open stream: HTTP request failed!"
// i.e. the connection is accepted but no readable status line ever arrives, and the server logs
// nothing at RUST_LOG=debug. Env: URL, N (default 150), ROUNDS (default 10).
declare(strict_types=1);
require __DIR__ . '/../../php/packages/runtime/src/ignis.php';
use function Ignis\all;
use function Ignis\async;

$url = getenv('URL');
$N = (int) (getenv('N') ?: 150);
$R = (int) (getenv('ROUNDS') ?: 10);
$fail = 0;
$tot = 0;
for ($r = 0; $r < $R; $r++) {
    $fu = [];
    for ($i = 0; $i < $N; $i++) {
        $fu[] = async(static fn() => @file_get_contents($url));
    }
    foreach (all($fu) as $b) {
        $tot++;
        if ($b === false) {
            $fail++;
        }
    }
    if ($fail) {
        printf("round %d: %d failures of %d total\n", $r, $fail, $tot);
        break;
    }
}
printf("total=%d failures=%d (%.2f%%)\n", $tot, $fail, $tot ? $fail * 100 / $tot : 0);
