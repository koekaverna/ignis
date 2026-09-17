<?php

declare(strict_types=1);

namespace Ignis\Http;

/**
 * A body produced while the client is already reading it (R-STREAM).
 *
 * The producer takes **no arguments**, exactly as Symfony's and Laravel's `StreamedResponse` does —
 * so `$response->sendContent(...)` is a producer as it stands, and an existing callback needs no
 * rewriting. It writes with `Ignis\write()` (or `echo`, which the runtime frames just the same):
 *
 *     return new StreamedResponse(function () use ($rows): void {
 *         foreach ($rows as $row) {
 *             Ignis\write($row . "\n");   // parks here when the client is behind
 *         }
 *     }, 200, ['content-type' => 'text/plain']);
 *
 * `Ignis\write()` awaits the runtime, so a client that falls behind parks the producing fiber and
 * memory stays at one frame. A plain `echo` is framed too, but can only be taken optimistically —
 * the runtime's write hook runs where a fiber cannot suspend — so it accumulates while a client is
 * behind and goes out when the producer returns.
 *
 * **Nothing is sent until the first byte**, which is why the loop owns the lifetime: a producer that
 * throws before writing anything still becomes a real `500`. Once the status line is out an error
 * can only truncate the body, which is all HTTP allows. Measured before this existed: a handler that
 * threw straight after opening a stream answered `HTTP/1.1 200 OK` with an empty chunked body (V-77).
 *
 * It is a `Response`, so `serve()`'s contract does not change; its `$body` stays empty because the
 * body is the producer's to write.
 */
final class StreamedResponse extends Response
{
    /**
     * `\Closure` rather than `callable` so it can be promoted, and because every way of writing a
     * producer already gives one: a closure literal, or first-class callable syntax —
     * `$response->sendContent(...)`, `$this->rows(...)`.
     *
     * @param \Closure(): void     $producer
     * @param array<string,string> $headers
     */
    public function __construct(
        public readonly \Closure $producer,
        int $status = 200,
        array $headers = [],
    ) {
        unset($headers['content-length']);
        parent::__construct('', $status, $headers);
    }
}
