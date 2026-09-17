<?php

declare(strict_types=1);

namespace Ignis\Http;

final class Response
{
    /** @param array<string,string> $headers */
    public function __construct(
        public readonly string $body = '',
        public readonly int $status = 200,
        public readonly array $headers = [],
    ) {
    }

    public static function text(string $body, int $status = 200): self
    {
        return new self($body, $status, ['content-type' => 'text/plain; charset=utf-8']);
    }

    /** The handler already answered through another channel (gRPC stream, E10); the loop sends nothing. */
    public static function detached(): self
    {
        return new self('', 0, []);
    }

    public static function json(mixed $data, int $status = 200): self
    {
        return new self(json_encode($data, JSON_THROW_ON_ERROR), $status, ['content-type' => 'application/json']);
    }
}
