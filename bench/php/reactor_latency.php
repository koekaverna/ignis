<?php

// Reactor round-trip latency at low concurrency (H30). Three legs that differ in exactly one thing:
//   php-only   : ignis_watch() on an already-ready fd -> completes inside the zif, tokio never sees it.
//   channels   : Ignis\sleep(0) -> crosses the mpsc to the dispatcher and the crossbeam back, no timer,
//                no epoll, no dup (reactor.rs: `Op::Sleep { us: 0 }` completes in the dispatcher task).
//   epoll      : a real Op::Watch -> dup + AsyncFd (epoll_ctl ADD) + wait + drop (DEL) + close.
// Env: N (ops per leg, default 5000), CONC (fiber counts for the amortization curve).
declare(strict_types=1);
require __DIR__ . '/../../php/packages/runtime/src/ignis.php';

use Ignis\Loop;

use function Ignis\all;
use function Ignis\async;

$N = (int) (getenv('N') ?: 5000);
$reps = (int) (getenv('REPS') ?: 3);

function pair(): array
{
    $p = \stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, 0);
    \stream_set_blocking($p[0], false);
    \stream_set_blocking($p[1], false);
    return $p;
}

/** us per op, median of $reps. */
function timed(int $reps, int $n, callable $body): float
{
    $r = [];
    for ($i = 0; $i < $reps; $i++) {
        $t = hrtime(true);
        $body($n);
        $r[] = (hrtime(true) - $t) / 1e3 / $n;
    }
    sort($r);
    return $r[intdiv(count($r), 2)];
}

[$a, $b] = pair();

// 1. PHP-side only: the write end of a socketpair is always writable, so zif_ignis_watch
//    polls it ready and completes the op without ever reaching tokio.
$phpOnly = timed($reps, $N, function (int $n) use ($a) {
    for ($i = 0; $i < $n; $i++) {
        Loop::awaitOp(\ignis_watch($a, 2));
    }
});

// 2. Both channels, nothing else.
$channels = timed($reps, $N, function (int $n) {
    for ($i = 0; $i < $n; $i++) {
        Loop::awaitOp(\ignis_submit_sleep(0));
    }
});

// 3. A real epoll round trip: two fibers ping-pong over a socketpair, so each side arms its
//    watch while the fd is empty and is woken by the peer's write. 2 real watches per round.
$epoll = timed($reps, $N, function (int $n) use ($a, $b) {
    $rounds = intdiv($n, 2);
    $f1 = async(function () use ($a, $rounds) {
        for ($i = 0; $i < $rounds; $i++) {
            \fwrite($a, 'x');
            Loop::awaitOp(\ignis_watch($a, 1));
            \fread($a, 1);
        }
    });
    $f2 = async(function () use ($b, $rounds) {
        for ($i = 0; $i < $rounds; $i++) {
            Loop::awaitOp(\ignis_watch($b, 1));
            \fread($b, 1);
            \fwrite($b, 'x');
        }
    });
    all([$f1, $f2]);
});

printf("php-only (no tokio)      : %6.1f us/op\n", $phpOnly);
printf("channels (sleep 0)       : %6.1f us/op\n", $channels);
printf("epoll    (real watch)    : %6.1f us/op   [2 watches per ping-pong round]\n", $epoll);
printf("  -> channel cost        : %6.1f us  (channels - php-only)\n", $channels - $phpOnly);
printf("  -> epoll registration  : %6.1f us  (epoll - channels)\n", $epoll - $channels);

// Amortization: the same channel round trip with more fibers in flight, since one poll()
// drains every ready completion at once.
echo "\namortization of the channel round trip (Ignis\\sleep(0)):\n";
foreach ([1, 2, 4, 8, 32, 128] as $c) {
    $per = timed($reps, $N, function (int $n) use ($c) {
        $each = max(1, intdiv($n, $c));
        $fu = [];
        for ($k = 0; $k < $c; $k++) {
            $fu[] = async(function () use ($each) {
                for ($i = 0; $i < $each; $i++) {
                    Loop::awaitOp(\ignis_submit_sleep(0));
                }
            });
        }
        all($fu);
    });
    printf("  %4d fibers: %6.2f us/op\n", $c, $per);
}
