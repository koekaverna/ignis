<?php

declare(strict_types=1);

namespace Ignis\Classic;

use Ignis\Http\Request;
use Ignis\Http\Response;

/** php://input backed by the current request body; every other php:// path is re-opened with PHP's own wrapper. */
final class InputStream
{
    public static string $body = '';
    /** @var resource|null */
    public $context;
    /** @var resource|null */
    private $inner = null;
    private int $pos = 0;

    public function stream_open(string $path, string $mode, int $options, ?string &$opened): bool
    {
        if (strcasecmp($path, 'php://input') === 0) {
            return true;
        }
        stream_wrapper_restore('php'); // re-registering below costs ~160 B of request heap per open (a zend resource)
        try {
            $this->inner = @fopen($path, $mode, false, $this->context) ?: null;
        } finally {
            stream_wrapper_unregister('php');
            stream_wrapper_register('php', self::class);
        }
        return $this->inner !== null;
    }
    public function stream_read(int $n): string|false
    {
        $chunk = $this->inner ? fread($this->inner, $n) : substr(self::$body, $this->pos, $n);
        $this->pos += \strlen((string) $chunk);
        return $chunk;
    }
    public function stream_seek(int $offset, int $whence = SEEK_SET): bool
    {
        if ($this->inner) {
            return fseek($this->inner, $offset, $whence) === 0;
        }
        $this->pos = match ($whence) {
            SEEK_SET => $offset, SEEK_CUR => $this->pos + $offset, default => \strlen(self::$body) + $offset,
        };
        return true;
    }
    public function stream_write(string $data): int|false
    {
        return $this->inner ? fwrite($this->inner, $data) : false;
    }
    public function stream_eof(): bool
    {
        return $this->inner ? feof($this->inner) : $this->pos >= strlen(self::$body);
    }
    public function stream_tell(): int
    {
        return $this->inner ? (int) ftell($this->inner) : $this->pos;
    }
    /** @return array<int|string, int>|false */
    public function stream_stat(): array|false
    {
        return $this->inner ? fstat($this->inner) : ['size' => strlen(self::$body)];
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
