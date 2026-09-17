<?php

/**
 * S1-FLOCK: a blocking `flock(LOCK_EX)` held across a yield used to take the OS thread down.
 *
 * A regular file cannot be registered with epoll (research 30 group (d)), so the call could not
 * park; it blocked the thread, so the loop could never resume the holder, so the lock was never
 * released — a deadlock, not a stall (V-58). With `flock` interposed it becomes LOCK_NB plus a
 * parked retry, which is the shape Symfony's cache lock uses by hand.
 *
 * Three fibers: a holder, a waiter, and a ticker whose count is the evidence that the thread kept
 * serving while the waiter waited.
 */

declare(strict_types=1);

require __DIR__ . '/../../php/packages/runtime/src/ignis.php';

$path = sys_get_temp_dir() . '/ignis-flock-park.lock';
file_put_contents($path, '');

$holdMs = (int) (getenv('HOLD_MS') ?: 400);
$tickMs = 10;
$result = ['holder_released_ms' => null, 'waiter_acquired_ms' => null, 'ticks' => 0];
$startedAt = hrtime(true);
$elapsed = static fn(): int => (int) ((hrtime(true) - $startedAt) / 1e6);

Ignis\all([
    Ignis\async(static function () use ($path, $holdMs, &$result, $elapsed): void {
        $handle = fopen($path, 'c');
        flock($handle, LOCK_EX);
        Ignis\sleep($holdMs);          // the yield the old code could not survive
        flock($handle, LOCK_UN);
        fclose($handle);
        $result['holder_released_ms'] = $elapsed();
    }),
    Ignis\async(static function () use ($path, &$result, $elapsed): void {
        Ignis\sleep(50);               // arrive while the holder still has it
        $handle = fopen($path, 'c');
        flock($handle, LOCK_EX);       // blocking on purpose: this is the call under test
        $result['waiter_acquired_ms'] = $elapsed();
        flock($handle, LOCK_UN);
        fclose($handle);
    }),
    Ignis\async(static function () use ($holdMs, $tickMs, &$result): void {
        $ticks = intdiv($holdMs, $tickMs) + 5;
        for ($i = 0; $i < $ticks; $i++) {
            Ignis\sleep($tickMs);
            $result['ticks']++;
        }
    }),
]);

echo json_encode($result), "\n";
