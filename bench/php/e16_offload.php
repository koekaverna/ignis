<?php

// E16 / H24: offload pool. Run: ignis --offload 8 bench/php/e16_offload.php  (env: FIBERS=100, MS=200, N=2000, PG_DSN)
declare(strict_types=1);
require __DIR__ . '/../../php/packages/runtime/src/ignis.php';
require __DIR__ . '/../../php/packages/offload/src/ignis-offload.php';

$fibers = (int) (getenv('FIBERS') ?: 100);
$ms = (int) (getenv('MS') ?: 200);
$n = (int) (getenv('N') ?: 2000);
$stats = Ignis\Offload\Client::stats();
$workers = (int) $stats['workers'];
printf("pool: %d workers\n", $workers);

$main = Ignis\async(static function () use ($fibers, $ms, $n, $workers): void {
    // 1. FIBERS concurrent blocking calls of MS ms on one fiber thread; the fiber thread stays responsive.
    $ticks = 0;
    $stop = false;
    $ticker = Ignis\async(static function () use (&$ticks, &$stop): void {
        while (!$stop) {
            Ignis\sleep(10);
            $ticks++;
        }
    });
    $t0 = hrtime(true);
    $fs = [];
    for ($i = 0; $i < $fibers; $i++) {
        $fs[] = Ignis\async(static fn() => Ignis\offload('e16_slow', $ms));
    }
    $rs = Ignis\all($fs);
    $wall = (hrtime(true) - $t0) / 1e6;
    $stop = true;
    $threads = count(array_unique(array_column($rs, 'thread')));
    printf(
        "blocking calls: %d x %d ms through %d workers: %.0f ms wall (bound = ceil(%d/%d) x %d = %d ms); distinct worker threads used: %d; fiber thread ticked %d times (10 ms sleeps) meanwhile\n",
        $fibers,
        $ms,
        $workers,
        $wall,
        $fibers,
        $workers,
        $ms,
        (int) ceil($fibers / $workers) * $ms,
        $threads,
        $ticks,
    );

    // 2. Copy overhead per call: empty args, 1 KB array, 64 KB array (serialize + channel + unserialize, both ways).
    foreach (['empty' => [], '1KB' => array_fill(0, 64, str_repeat('x', 12)), '64KB' => array_fill(0, 1024, str_repeat('y', 60))] as $label => $payload) {
        $t = hrtime(true);
        for ($i = 0; $i < $n; $i++) {
            Ignis\offload('e16_echo', $payload);
        }
        $us = (hrtime(true) - $t) / 1e3 / $n;
        printf("copy overhead: %s args, %d sequential calls: %.1f us per call round trip (serialized size %d B)\n", $label, $n, $us, strlen(serialize($payload)));
    }

    // 3. Callbacks: the worker calls a caller-side closure 5 times; it runs on this thread.
    $tid = getmypid();
    $r = Ignis\offload('e16_with_cb', static fn(int $i): int => $i * 2, 5);
    printf("callbacks: worker called back 5 times, sum=%d (expect 30), callbacks run on the caller: %d\n", $r, Ignis\Offload\Client::$callbacksRun);

    // 4. Exceptions cross back as RemoteException.
    try {
        Ignis\offload('e16_throw');
        echo "exception: NOT propagated\n";
    } catch (Ignis\Offload\RemoteException $e) {
        printf("exception: %s(%s, code %d) propagated as RemoteException\n", $e->remoteClass, $e->getMessage(), $e->getCode());
    }

    // 5. pdo_pgsql (if built): FIBERS concurrent 200 ms queries, one PDO per worker.
    $dsn = getenv('PG_DSN') ?: 'pgsql:host=127.0.0.1;dbname=ignis;user=ignis;password=ignis';
    if (extension_loaded('pdo_pgsql')) {
        $t0 = hrtime(true);
        $fs = [];
        for ($i = 0; $i < $fibers; $i++) {
            $fs[] = Ignis\async(static fn() => Ignis\offload('e16_pg', $dsn, $ms / 1000));
        }
        $rs = Ignis\all($fs);
        $wall = (hrtime(true) - $t0) / 1e6;
        printf("pdo_pgsql: %d x %d ms queries through %d workers: %.0f ms wall (bound %d ms); distinct backend pids: %d\n", $fibers, $ms, $workers, $wall, (int) ceil($fibers / $workers) * $ms, count(array_unique(array_column($rs, 'pid'))));
    } else {
        echo "pdo_pgsql: extension not built in this libphp (rebuild with --with-pdo-pgsql)\n";
    }
    printf("stats: %s\n", json_encode(Ignis\Offload\Client::stats()));
});
Ignis\Loop::run();
