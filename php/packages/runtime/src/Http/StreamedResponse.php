<?php

declare(strict_types=1);

namespace Ignis\Http;

/**
 * A body produced while the client is already reading it (R-STREAM).
 *
 * The producer is handed an `Ignis\Http\Stream` and writes through it — the shape AMPHP's
 * `WritableStream` and Swoole's `$response->write()` use, and the one PSR-7 describes. Symfony and
 * Laravel instead call a callback that `echo`es, which works here too because the runtime frames
 * output; this form is for code that wants the writer and the exact back-pressure it gives.
 *
 * **The loop owns the lifetime**, which is why this carries a producer rather than an already-open
 * stream. Nothing is sent until the first write, so a producer that throws before writing anything
 * still becomes a real `500`; with an open stream the client has been told `200` and can only be
 * handed a truncated body. Measured before this existed: a handler that threw immediately after
 * opening answered `HTTP/1.1 200 OK` with an empty chunked body (V-77).
 *
 *     return new StreamedResponse(function (Stream $out) use ($rows): void {
 *         foreach ($rows as $row) {
 *             $out->write($row . "\n");   // parks here when the client is behind
 *         }
 *     }, 200, ['content-type' => 'text/plain']);
 *
 * It is a `Response`, so `serve()`'s contract does not change and anything that type-hints one keeps
 * working; its `$body` stays empty because the body is the producer's to write.
 */
final class StreamedResponse extends Response
{
    /** @var \Closure(Stream): void */
    public readonly \Closure $producer;

    /**
     * @param callable(Stream): void $producer
     * @param array<string,string>   $headers
     */
    public function __construct(callable $producer, int $status = 200, array $headers = [])
    {
        parent::__construct('', $status, $headers);
        $this->producer = $producer(...);
    }
}
