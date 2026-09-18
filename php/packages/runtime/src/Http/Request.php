<?php

declare(strict_types=1);

namespace Ignis\Http;

final class Request
{
    /** @var null|array<array-key, string|array<mixed>> parsed from the URI on the first query() call */
    private ?array $query = null;

    /** @param array<string,string> $headers lower-cased names */
    public function __construct(
        public readonly string $method,
        public readonly string $uri,
        public readonly array $headers,
        public readonly string $body,
        /** Reactor request id (E10: gRPC handlers answer through it; 0 outside a served request). */
        public readonly int $id = 0,
    ) {}

    public function path(): string
    {
        $q = strpos($this->uri, '?');
        return $q === false ? $this->uri : substr($this->uri, 0, $q);
    }

    /**
     * One parameter exactly as `$_GET` holds it, because both go through `parse_str()`: `?x[]=1&x[]=2`
     * is the list `['1', '2']`, a plain repeated `?x=1&x=2` is `'2'`, and `?x[a]=1` is `['a' => '1']`.
     * @return string|array<mixed>|null
     */
    public function query(string $name): string|array|null
    {
        if ($this->query === null) {
            $parsed = [];
            parse_str($this->queryString(), $parsed);
            $this->query = $parsed;
        }
        return $this->query[$name] ?? null;
    }

    /** Everything after the first `?`; every later `?` belongs to the query string itself. */
    private function queryString(): string
    {
        $q = strpos($this->uri, '?');
        return $q === false ? '' : substr($this->uri, $q + 1);
    }

    public function header(string $name): ?string
    {
        return $this->headers[strtolower($name)] ?? null;
    }

    /**
     * CGI-style superglobals for this request: [$_SERVER, $_GET, $_POST, $_COOKIE].
     * @return array{0:array<string,mixed>,1:array<array-key,mixed>,2:array<array-key,mixed>,3:array<string,string>}
     */
    public function superglobals(): array
    {
        $query = $this->queryString();
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
     * The embed SAPI has no `read_post` (`php-src/sapi/embed/php_embed.c:145`), so `main/rfc1867.c`
     * never runs and this is the only parser in the process. PHP's own behaviour is therefore the
     * specification, quirks included, and `bench/e22/e22-multipart.sh` checks case for case against
     * it by posting the same bytes to `php -S`, which does have a `read_post`. Three of the rules
     * below exist because that comparison failed:
     *
     * - a boundary counts only at the start of a line, so a value may contain `--BOUNDARY`;
     * - the header/body separator and the line endings may be LF, not just CRLF;
     * - `name=x` without quotes is as valid as `name="x"`.
     *
     * File parts are skipped rather than half-supported: they belong in `$_FILES`, and that is not
     * one of the four fiber-scoped superglobals (ADR-0006), so one shared array would hand a
     * concurrent request someone else's upload.
     */
    private static function multipartQuery(string $body, string $boundary): string
    {
        $parts = \preg_split('#(?:\r\n|\n|^)--' . \preg_quote($boundary, '#') . '#', $body);
        if ($parts === false) {
            return '';
        }

        $pairs = [];
        foreach ($parts as $part) {
            if ($part === '' || \str_starts_with($part, '--')) {
                continue;
            }
            $part = \ltrim($part, "\r\n");
            $split = \preg_split('#\r\n\r\n|\n\n#', $part, 2);
            if ($split === false || \count($split) !== 2) {
                continue;
            }
            [$headers, $value] = $split;
            if (\str_contains($headers, 'filename=')) {
                continue;
            }
            if (\preg_match('#\bname=(?:"([^"]*)"|([^;\r\n]*))#i', $headers, $m) !== 1) {
                continue;
            }
            $name = $m[1] !== '' ? $m[1] : ($m[2] ?? '');
            $pairs[] = \rawurlencode($name) . '=' . \rawurlencode(\preg_replace('#\r\n$|\n$#', '', $value) ?? $value);
        }

        return \implode('&', $pairs);
    }
}
