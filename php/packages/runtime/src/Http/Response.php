<?php

declare(strict_types=1);

namespace Ignis\Http;

/**
 * What a handler answers with: a body, or a producer that writes one while the client reads.
 */
final class Response
{
    /**
     * @param array<string,string>          $headers
     * @param (\Closure(Stream): void)|null $producer set by `stream()`; the loop drives it
     */
    public function __construct(
        public readonly string $body = '',
        public readonly int $status = 200,
        public readonly array $headers = [],
        public readonly ?\Closure $producer = null,
    ) {
    }

    public static function text(string $body, int $status = 200): self
    {
        return new self($body, $status, ['content-type' => 'text/plain; charset=utf-8']);
    }

    public static function json(mixed $data, int $status = 200): self
    {
        return new self(json_encode($data, JSON_THROW_ON_ERROR), $status, ['content-type' => 'application/json']);
    }

    /**
     * A body produced while the client is already reading it (R-STREAM).
     *
     * The producer is handed an `Ignis\Http\Stream` and writes through it — the shape AMPHP's
     * `WritableStream` and Swoole's `$response->write()` use, and the one PSR-7 describes. Symfony
     * and Laravel instead call a callback that `echo`es, which works here too because the runtime
     * frames output; this form is for code that wants the writer.
     *
     * **The loop owns the lifetime**, and that is why this takes a callback rather than handing back
     * an open stream. Nothing is sent until the first write, so a producer that throws before
     * writing anything still becomes a real `500` — with an already-open stream the client has been
     * told `200` and can only be given a truncated body. Measured, before this existed: a handler
     * that threw immediately after opening answered `HTTP/1.1 200 OK` with an empty chunked body.
     *
     *     return Response::stream(function (Stream $out) use ($rows): void {
     *         foreach ($rows as $row) {
     *             $out->write($row . "\n");   // parks here when the client is behind
     *         }
     *     }, 200, ['content-type' => 'text/plain']);
     *
     * @param callable(Stream): void $producer
     * @param array<string,string>   $headers
     */
    public static function stream(callable $producer, int $status = 200, array $headers = []): self
    {
        return new self('', $status, $headers, $producer(...));
    }
}
