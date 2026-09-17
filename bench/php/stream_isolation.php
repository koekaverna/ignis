<?php

/**
 * While one fiber streams, another simply `echo`es — without calling `Output` at all. Where do its
 * bytes go?
 *
 * With PHP's own `ob_start()` they went into the streaming response's body, because the buffer stack
 * is per **thread** and by the time the handler runs the bytes are already mixed (V-76). The runtime
 * forwards from `ub_write` instead, which runs at the moment of the write and knows whose it is.
 *
 *   IGNIS_LISTEN=127.0.0.1:8203 ignis --threads 1 bench/php/stream_isolation.php
 *   curl -sN .../stream &  sleep 0.2; curl -s .../noise
 *
 * `/stream` must contain only its own `A-1` and `A-2`; the noise belongs on the server's stdout.
 */

declare(strict_types=1);

require __DIR__ . '/../../php/packages/runtime/src/ignis.php';
use Ignis\Http\{Request, Response, Stream, StreamedResponse};

Ignis\serve(function (Request $r): Response {
    if ($r->path() === '/noise') {
        echo 'NOISE-FROM-B';          // no Output::capture, no ob — a bare echo
        return Response::text("noise sent\n");
    }
    // The first write binds this fiber's output to the response, so a plain `echo` leaves as a frame.
    return new StreamedResponse(static function (Stream $out): void {
        $out->write("A-1\n");
        Ignis\sleep(400);             // /noise runs entirely inside this park
        echo "A-2\n";                 // echo is framed too, and stays this response's
    }, 200, ['content-type' => 'text/plain']);
}, getenv('IGNIS_LISTEN') ?: '127.0.0.1:8203');
