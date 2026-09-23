<?php

/**
 * S-5b: a spawned job parks forever on a reactor op — userland (`Ignis\sleep`) or C-side (`fread` on
 * a socket nobody writes to) — and only the park ceiling (`IGNIS_PARK_TIMEOUT_MS`) can end it.
 */

declare(strict_types=1);

require __DIR__ . '/common.php';

use Ignis\DeadlineExceededException;
use Ignis\Http\Request;
use Ignis\Http\Response;

serveStallFixture(static function (Request $request): Response {
    $job = $request->query('kind') === 'c' ? Ignis\async(readFromASilentSocket(...)) : Ignis\async(static fn(): null => Ignis\sleep(3_600_000));
    try {
        $job->await();
    } catch (DeadlineExceededException $exception) {
        return Response::text('caught: ' . $exception->getMessage() . "\n");
    }
    return Response::text("unreachable\n");
});

function readFromASilentSocket(): string
{
    [$reader, $writer] = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, 0) ?: throw new RuntimeException('socketpair');
    return (string) fread($reader, 1);
}
