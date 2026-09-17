<?php

/**
 * E23: a response produced while the client is already reading it (R-STREAM).
 *
 *   /stream  three chunks 300 ms apart — the client must see them as they are produced
 *   /tick    answers immediately — proves the thread keeps serving while a stream is in flight
 */

declare(strict_types=1);

require __DIR__ . '/../../php/packages/runtime/src/ignis.php';

use Ignis\Http\Request;
use Ignis\Http\Response;
use Ignis\Http\Stream;
use Ignis\Http\StreamedResponse;

Ignis\serve(static function (Request $r): Response {
    if ($r->path() === '/tick') {
        return Response::text("tick\n");
    }

    $n = (int) ($r->query('n') ?? 3);
    $ms = (int) ($r->query('ms') ?? 300);

    $fail = $r->query('fail');

    return new StreamedResponse(static function (Stream $out) use ($n, $ms, $fail): void {
        if ($fail === 'early') {
            throw new \RuntimeException('failed before writing anything');
        }
        try {
            for ($i = 1; $i <= $n; $i++) {
                $out->write("chunk{$i}\n");
                Ignis\sleep($ms);
            }
        } catch (\RuntimeException $e) {
            // the client hung up: stop producing, which is the point of back-pressure
        }
    }, 200, ['content-type' => 'text/plain']);
}, getenv('IGNIS_LISTEN') ?: '127.0.0.1:8199');
