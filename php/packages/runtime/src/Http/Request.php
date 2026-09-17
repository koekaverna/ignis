<?php

declare(strict_types=1);

namespace Ignis\Http;

final class Request
{
    private ?array $query = null;

    /** @param array<string,string> $headers lower-cased names */
    public function __construct(
        public readonly string $method,
        public readonly string $uri,
        public readonly array $headers,
        public readonly string $body,
        /** Reactor request id (E10: gRPC handlers answer through it; 0 outside a served request). */
        public readonly int $id = 0,
    ) {
    }

    public function path(): string
    {
        $q = strpos($this->uri, '?');
        return $q === false ? $this->uri : substr($this->uri, 0, $q);
    }

    public function query(string $name): ?string
    {
        if ($this->query === null) {
            $q = strpos($this->uri, '?');
            $this->query = [];
            if ($q !== false) {
                parse_str(substr($this->uri, $q + 1), $this->query);
            }
        }
        $v = $this->query[$name] ?? null;
        return $v === null ? null : (string) $v;
    }

    public function header(string $name): ?string
    {
        return $this->headers[strtolower($name)] ?? null;
    }

    /**
     * CGI-style superglobals for this request: [$_SERVER, $_GET, $_POST, $_COOKIE].
     * @return array{0:array,1:array,2:array,3:array}
     */
    public function superglobals(): array
    {
        $q = strpos($this->uri, '?');
        $query = $q === false ? '' : substr($this->uri, $q + 1);
        $server = [
            'REQUEST_METHOD'  => $this->method,
            'REQUEST_URI'     => $this->uri,
            'QUERY_STRING'    => $query,
            'SCRIPT_NAME'     => '/index.php',
            'SERVER_PROTOCOL' => 'HTTP/1.1',
            'REQUEST_TIME'    => time(),
            'REQUEST_TIME_FLOAT' => microtime(true),
        ];
        foreach ($this->headers as $name => $value) {
            $server['HTTP_' . strtoupper(str_replace('-', '_', $name))] = $value;
        }
        if (isset($this->headers['content-type'])) {
            $server['CONTENT_TYPE'] = $this->headers['content-type'];
        }
        if (isset($this->headers['content-length'])) {
            $server['CONTENT_LENGTH'] = $this->headers['content-length'];
        }
        $get = [];
        parse_str($query, $get);
        $post = [];
        $type = $this->headers['content-type'] ?? '';
        if ($this->method === 'POST' && str_starts_with($type, 'application/x-www-form-urlencoded')) {
            parse_str($this->body, $post);
        } elseif ($this->method === 'POST' && preg_match('#^multipart/form-data\b.*boundary="?([^";]+)"?#i', $type, $m) === 1) {
            parse_str(self::multipartQuery($this->body, $m[1]), $post);
        }
        $cookie = [];
        foreach (explode(';', $this->headers['cookie'] ?? '') as $pair) {
            if (($eq = strpos($pair, '=')) !== false) {
                $cookie[trim(substr($pair, 0, $eq))] = urldecode(trim(substr($pair, $eq + 1)));
            }
        }
        return [$server, $get, $post, $cookie];
    }

    /**
     * Field parts of a `multipart/form-data` body as a query string, so `parse_str()` applies PHP's
     * own `name[]` / `name[key]` rules instead of a second implementation of them. A browser sends
     * this encoding for any `FormData`, so without it `$_POST` is empty and every field falls back
     * to its default — which looks like bad input, not like a missing parser.
     *
     * File parts are skipped rather than half-supported: they belong in `$_FILES`, and that is not
     * one of the four fiber-scoped superglobals (ADR-0006), so one shared array would hand a
     * concurrent request someone else's upload.
     */
    private static function multipartQuery(string $body, string $boundary): string
    {
        $pairs = [];
        foreach (explode('--' . $boundary, $body) as $part) {
            $part = ltrim($part, "\r\n");
            if ($part === '' || str_starts_with($part, '--')) {
                continue;
            }
            [$headers, $value] = array_pad(explode("\r\n\r\n", $part, 2), 2, '');
            if (str_contains($headers, 'filename=') || preg_match('/\bname="([^"]*)"/', $headers, $m) !== 1) {
                continue;
            }
            $pairs[] = rawurlencode($m[1]) . '=' . rawurlencode(rtrim($value, "\r\n"));
        }

        return implode('&', $pairs);
    }
}
