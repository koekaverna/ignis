<?php

declare(strict_types=1);

namespace Ignis;

/**
 * `php://input` backed by the current request body; every other `php://` path is re-opened with
 * PHP's own wrapper.
 *
 * This is a runtime guarantee, not a classic-mode one. The embed SAPI has no `read_post`, so
 * `php://input` is empty unless something backs it — and until 2026-09-20 only `Classic\serve()`
 * registered this, which left `serve()` with an empty `php://input` and cost a measured defect:
 * a `PUT` with a urlencoded body reached Symfony with **no body at all**, because the runtime
 * populates `$_POST` for `POST` only and Symfony re-parses `php://input` for PUT/PATCH/DELETE.
 */
final class InputStream
{
    /**
     * This request's `php://input`, fiber-scoped for the reason `Classic\Runner::SENT` gives: requests
     * overlap, and a per-thread static handed the second one's body to a script still reading the
     * first. Outside a fiber — `Classic\listen()`, one request at a time — `Ignis\Scope` is a plain
     * bag and this behaves as the static did.
     */
    private const BODY = 'ignis.request.body';

    private static bool $registered = false;

    /**
     * Makes `php://input` this request's body, once per thread. Idempotent because both entry
     * points want it — `Classic\serve()` at boot and `Loop::enterRequest()` per request — and
     * re-registering a stream wrapper on every request would be a wrapper object per request.
     */
    public static function register(): void
    {
        if (self::$registered) {
            return;
        }
        self::$registered = true;
        stream_wrapper_unregister('php');
        stream_wrapper_register('php', self::class);
    }

    public static function setBody(string $body): void
    {
        \Ignis\Scope::set(self::BODY, $body);
    }

    private static function body(): string
    {
        $body = \Ignis\Scope::get(self::BODY, '');

        return \is_string($body) ? $body : '';
    }
    /** @var resource|null */
    public $context;
    /** @var resource|null */
    private $inner = null;
    private int $position = 0;

    /** Restores the real `php://` wrapper around the actual fopen() -- re-registering ours here too would cost ~160 B of request heap per open (a zend resource). */
    public function stream_open(string $path, string $mode, int $options, ?string &$opened): bool
    {
        if (strcasecmp($path, 'php://input') === 0) {
            return true;
        }
        stream_wrapper_restore('php');
        try {
            $this->inner = @fopen($path, $mode, false, $this->context) ?: null;
        } finally {
            stream_wrapper_unregister('php');
            stream_wrapper_register('php', self::class);
        }
        return $this->inner !== null;
    }
    public function stream_read(int $length): string|false
    {
        if ($length < 1) {
            return '';
        }
        $chunk = $this->inner ? fread($this->inner, $length) : substr(self::body(), $this->position, $length);
        $this->position += \strlen((string) $chunk);
        return $chunk;
    }
    public function stream_seek(int $offset, int $whence = SEEK_SET): bool
    {
        if ($this->inner) {
            return fseek($this->inner, $offset, $whence) === 0;
        }
        $this->position = match ($whence) {
            SEEK_SET => $offset, SEEK_CUR => $this->position + $offset, default => \strlen(self::body()) + $offset,
        };
        return true;
    }
    public function stream_write(string $data): int|false
    {
        return $this->inner ? fwrite($this->inner, $data) : false;
    }
    public function stream_eof(): bool
    {
        return $this->inner ? feof($this->inner) : $this->position >= strlen(self::body());
    }
    public function stream_tell(): int
    {
        return $this->inner ? (int) ftell($this->inner) : $this->position;
    }
    /** @return array<int|string, int>|false */
    public function stream_stat(): array|false
    {
        return $this->inner ? fstat($this->inner) : ['size' => strlen(self::body())];
    }
    public function stream_flush(): bool
    {
        return $this->inner ? fflush($this->inner) : true;
    }
    public function stream_close(): void
    {
        if ($this->inner) {
            fclose($this->inner);
        }
    }
}
