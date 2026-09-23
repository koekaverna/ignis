<?php

/**
 * Research 50 §A `file-read.php`: blocking forward on a regular file (`/dev/zero`, never `epoll`-ready).
 * Measured: a 1 GiB read takes 0.6s on this box, comfortably over the ">100ms" research 50 asks for.
 */

declare(strict_types=1);

require __DIR__ . '/common.php';

use Ignis\Http\Request;
use Ignis\Http\Response;

function readZeroBytes(int $totalBytes): int
{
    $handle = fopen('/dev/zero', 'rb');
    $readSoFar = 0;
    while ($readSoFar < $totalBytes) {
        $chunk = fread($handle, 8 * 1024 * 1024);
        if ($chunk === false || $chunk === '') {
            break;
        }
        $readSoFar += strlen($chunk);
    }
    fclose($handle);
    return $readSoFar;
}

serveStallFixture(static function (Request $request): Response {
    $totalBytes = (int) ($request->query('bytes') ?? 1024 * 1024 * 1024);
    $startedAt = hrtime(true);
    $bytesRead = readZeroBytes($totalBytes);
    $elapsedMilliseconds = (int) ((hrtime(true) - $startedAt) / 1_000_000);
    return Response::json(['bytes_read' => $bytesRead, 'elapsed_ms' => $elapsedMilliseconds]);
});
