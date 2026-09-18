<?php

declare(strict_types=1);

namespace Ignis\Http;

/** A whole body, sent in one piece. For a body produced while the client reads, see StreamedResponse. */
class Response
{
    /**
     * A header value may be a list, because a response can carry the same name twice --
     * `Set-Cookie` is the standing case, and RFC 7230 names it as the one that must not be
     * comma-joined. `ignis_respond` emits one line per value.
     */
    /** @param array<string, string|list<string>> $headers */
    public function __construct(
        public readonly string $body = '',
        public readonly int $status = 200,
        public readonly array $headers = [],
    ) {}

    public static function text(string $body, int $status = 200): self
    {
        return new self($body, $status, ['content-type' => 'text/plain; charset=utf-8']);
    }

    public static function json(mixed $data, int $status = 200): self
    {
        return new self(json_encode($data, JSON_THROW_ON_ERROR), $status, ['content-type' => 'application/json']);
    }
}
