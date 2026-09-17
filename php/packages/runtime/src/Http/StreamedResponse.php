<?php

declare(strict_types=1);

namespace Ignis\Http;

/**
 * A body produced while the client is already reading it (R-STREAM). It is a `Response`, so
 * `serve()`'s contract does not change; `$body` stays empty because the body is the producer's
 * to write. See docs/reference/php-api.md for the full contract and what V-77 measured.
 */
final class StreamedResponse extends Response
{
    /**
     * The producer takes **no arguments** and its return value is ignored, exactly as Symfony's and
     * Laravel's `StreamedResponse` does — so `$response->sendContent(...)` is a producer as it
     * stands. It writes with `Ignis\write()`, which awaits the runtime, so a client that falls
     * behind parks the producing fiber and memory stays at one frame; a plain `echo` is framed too
     * but accumulates while a client is behind, because the runtime's write hook runs where a fiber
     * cannot suspend.
     *
     * `\Closure` rather than `callable` so it can be promoted, and because every way of writing a
     * producer already gives one: a closure literal, or first-class callable syntax.
     *
     * @param \Closure(): mixed    $producer
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
