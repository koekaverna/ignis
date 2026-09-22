<?php

/**
 * Research 50 §A `file-read.php`: blocking forward on a regular file.
 *
 * `/dev/zero` is a character device, not a regular file, but it shares the property the scenario
 * needs: it can never be registered with `epoll` (research 30 group (d)), so the interposer's read
 * is always "ready" and the call is forwarded rather than parked -- it still blocks the thread for
 * as long as the read takes. A 1 GiB read of it takes 0.6 s on this box (measured with the loop
 * below run standalone, 2026-09-22), comfortably over the ">100 ms" research 50 asks for, and
 * needs no `dd`-throttled loop device or scratch file on disk.
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
