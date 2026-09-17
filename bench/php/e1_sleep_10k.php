<?php
// E1 / H2: N fibers on one thread each sleeping $ms via tokio; wall time must be < 1.2 s for N=10000, ms=1000.
declare(strict_types=1);
require __DIR__ . '/../../php/packages/runtime/src/ignis.php';

// $argv is not registered under the embed SAPI: parameters come from the environment.
$n  = (int) (getenv('N') ?: 10000);
$ms = (int) (getenv('MS') ?: 1000);
// Each started fiber owns a 16 KiB VM stack page (ZEND_FIBER_VM_STACK_SIZE) from the request allocator,
// so 10k fibers need > 160 MiB; the default 128M limit is too small.
ini_set('memory_limit', '1G');
if (($fs = getenv('FIBER_STACK')) !== false) {
    ini_set('fiber.stack_size', $fs);
}

$rounds = (int) (getenv('ROUNDS') ?: 1); // round 2+ runs on a warm fiber pool (H5)
$ok = true;
for ($round = 1; $round <= $rounds; $round++) {
    Ignis\Loop::$phaseNs = ['start' => 0, 'ready' => 0, 'poll' => 0, 'resume' => 0];
    $t0 = hrtime(true);
    $futures = [];
    for ($i = 0; $i < $n; $i++) {
        $futures[] = Ignis\async(static function () use ($ms): int {
            Ignis\sleep($ms);
            return 1;
        });
    }
    $tSpawned = hrtime(true);
    Ignis\Loop::run();
    $tDone = hrtime(true);

    $sum = array_sum(array_map(static fn (Ignis\Future $f) => $f->await(), $futures));
    $ok = $ok && $sum === $n;
    $wall = ($tDone - $t0) / 1e6;
    $ph = array_map(static fn (int $ns) => round($ns / 1e6, 1), Ignis\Loop::$phaseNs);
    printf("round=%d phases_ms start=%.1f ready=%.1f poll=%.1f resume=%.1f\n", $round, $ph['start'], $ph['ready'], $ph['poll'], $ph['resume']);
    printf("round=%d n=%d sleep_ms=%d completed=%d wall_ms=%.1f spawn_ms=%.1f run_ms=%.1f overhead_ms=%.1f resumes=%d fibers_created=%d peak_rss_kb=%d\n",
        $round, $n, $ms, $sum, $wall, ($tSpawned - $t0) / 1e6, ($tDone - $tSpawned) / 1e6, $wall - $ms, Ignis\Loop::$resumes, Ignis\Loop::$fibersCreated, memory_get_peak_usage(true) >> 10);
}
exit($ok ? 0 : 1);
