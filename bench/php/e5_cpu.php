<?php
// E5 in-process: each PHP thread runs this script; fixed CPU-bound work, prints its own wall time.
// Run with IGNIS_THREADS=1 and =4; throughput scaling = (4 x work) / (max thread time) vs 1-thread time.
declare(strict_types=1);
$iters = (int) (getenv('ITERS') ?: 300000);
$t0 = hrtime(true);
$acc = 0;
$arr = [];
for ($i = 0; $i < $iters; $i++) {
    $arr[$i % 1024] = md5((string) $i);
    $acc += strlen($arr[$i % 1024]) + ($i % 7);
}
usort($arr, static fn ($a, $b) => strcmp($a, $b));
printf("thread=%s iters=%d ms=%.1f acc=%d\n", getenv('IGNIS_THREADS') ?: '1', $iters, (hrtime(true) - $t0) / 1e6, $acc);
