<?php

// E1 on backend (b): N engine coroutines (Fiber::start() becomes a coroutine spawn when a
// scheduler is registered) each awaiting a 1000 ms tokio timer via ignis_park_on() + Fiber::suspend().
// The main flow ends, the scheduler runs, the last coroutine prints the wall time.
declare(strict_types=1);
$n  = (int) (getenv('N') ?: 10000);
$ms = (int) (getenv('MS') ?: 1000);
if (!function_exists('ignis_park_on')) {
    echo "backend (b) not compiled in\n";
    exit(2);
}
$t0 = hrtime(true);
$done = 0;
$fibers = [];
for ($i = 0; $i < $n; $i++) {
    $f = new Fiber(static function () use (&$done, $n, $ms, $t0): void {
        $id = ignis_submit_sleep($ms);
        ignis_park_on($id);   // record: this coroutine waits for op $id
        Fiber::suspend();     // engine hands control back to the starter; idle hook re-enqueues us
        $late = ignis_op_result($id);
        if (++$done === $n) {
            printf(
                "backend=b n=%d sleep_ms=%d wall_ms=%.1f overhead_ms=%.1f last_timer_late_us=%d\n",
                $n,
                $ms,
                (hrtime(true) - $t0) / 1e6,
                (hrtime(true) - $t0) / 1e6 - $ms,
                $late,
            );
        }
    });
    $f->start();
    $fibers[] = $f;
}
printf("spawned=%d in %.1f ms (main flow ends now; scheduler takes over)\n", $n, (hrtime(true) - $t0) / 1e6);
