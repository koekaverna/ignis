<?php

// Functions the offload workers can run (E16). Loaded on every offload thread via IGNIS_OFFLOAD_PRELUDE.
declare(strict_types=1);

function e16_slow(int $ms): array
{
    usleep($ms * 1000); // blocking on purpose: this is the worker thread
    return ['thread' => gettid_compat(), 'ms' => $ms];
}

function e16_echo(array $a): array
{
    return $a;
}

function e16_with_cb(callable $cb, int $n): int
{
    $sum = 0;
    for ($i = 1; $i <= $n; $i++) {
        $sum += (int) $cb($i);
    }
    return $sum;
}

function e16_throw(): void
{
    throw new DomainException('boom from the worker', 42);
}

function e16_pg(string $dsn, float $seconds): array
{
    static $pdo = null; // one connection per worker thread
    $pdo ??= new PDO($dsn, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $row = $pdo->query(sprintf('SELECT pg_backend_pid() AS pid, pg_sleep(%.3f)', $seconds))->fetch(PDO::FETCH_ASSOC);
    return ['pid' => (int) $row['pid']];
}

function gettid_compat(): int
{
    // No gettid() in PHP; the file /proc/thread-self/stat starts with the tid.
    $s = @file_get_contents('/proc/thread-self/stat');
    return $s === false ? 0 : (int) strtok($s, ' ');
}
