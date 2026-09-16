<?php
/**
 * Ignis classic mode: serve a document root of ordinary PHP scripts, one `include` per request.
 *
 * Copyright (c) 2026 Ignis contributors. MIT License: permission is hereby granted, free of charge, to any
 * person obtaining a copy of this software, to deal in it without restriction, subject to this notice being
 * included in all copies. THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND.
 *
 * Assumptions (embed SAPI, resident script, one fiber per request):
 * - header()/headers_list()/http_response_code() work because php_embed_init() sets no_headers=1. The header
 *   table (SG(sapi_headers)) and the output buffers are per OS thread and never re-activated between requests:
 *   reset() clears them, and a classic script must not suspend (no Ignis\sleep/async I/O inside the include).
 * - exit() is an unwind_exit that escapes the fiber and ends the resident script: scripts call finish() instead.
 * - Sessions need a startup php.ini with `session.use_cookies=0` and `session.cache_limiter=` because
 *   php_embed_init() pins SG(headers_sent)=1; the adapter then adopts/emits the session cookie itself.
 * - php://input is empty under embed (no read_post); InputStream backs it with the request body.
 */
declare(strict_types=1);

namespace Ignis\Classic {

use Ignis\Http\Request;
use Ignis\Http\Response;

/** Thrown by finish() to end the current script; caught by the runner. */
final class Finished extends \RuntimeException {}

/** Ends the current script now (the classic-mode replacement for exit()). */
function finish(): never { throw new Finished(); }

/** Sends the response now and lets the script go on (fastcgi_finish_request() analogue); later output is dropped. */
function finish_request(): bool { return Runner::finishRequest(); }

/**
 * @param array<string,string> $server extra $_SERVER entries (like FrankenPHP's `env` subdirective)
 * @param null|callable(string):void $run runs one script file; the default is a plain `include`
 */
function serve(string $docroot, string $addr, ?string $index = 'index.php', array $server = [], ?callable $run = null): void
{
    Runner::$docroot = rtrim($docroot, '/');
    Runner::$index = $index;
    Runner::$extra = $server;
    Runner::$env = getenv();
    Runner::$run = $run ?? static function (string $file): void { include $file; };
    stream_wrapper_unregister('php');
    stream_wrapper_register('php', InputStream::class);
    \Ignis\serve(Runner::handle(...), $addr);
}

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
        $this->pos = match ($whence) { SEEK_SET => $offset, SEEK_CUR => $this->pos + $offset, default => \strlen(self::$body) + $offset };
        return true;
    }
    public function stream_write(string $data): int|false { return $this->inner ? fwrite($this->inner, $data) : false; }
    public function stream_eof(): bool { return $this->inner ? feof($this->inner) : $this->pos >= strlen(self::$body); }
    public function stream_tell(): int { return $this->inner ? (int) ftell($this->inner) : $this->pos; }
    public function stream_stat(): array|false { return $this->inner ? fstat($this->inner) : ['size' => strlen(self::$body)]; }
    public function stream_flush(): bool { return $this->inner ? fflush($this->inner) : true; }
    public function stream_close(): void { if ($this->inner) { fclose($this->inner); } }
}

final class Runner
{
    public static string $docroot = '';
    public static ?string $index = null;
    /** @var array<string,string> */
    public static array $extra = [];
    /** @var array<string,string> */
    public static array $env = [];
    /** @var callable(string):void */
    public static $run;
    private static ?int $sent = null;

    public static function handle(Request $req): Response
    {
        [$file, $script, $pathInfo] = self::resolve($req->path());
        if ($file === null) {
            return Response::text("404 Not Found\n", 404);
        }
        self::reset();
        $_SERVER = self::server($req, $file, $script, $pathInfo) + $_SERVER; // the runtime already set REQUEST_*, HTTP_*, CONTENT_*
        $_REQUEST = array_merge($_GET, $_POST, $_COOKIE); // referenced at load, so the JIT auto-global is disarmed once per thread
        InputStream::$body = $req->body;
        $sid = self::sessionBegin();
        try {
            str_ends_with($file, '.php') ? (self::$run)($file) : readfile($file);
        } catch (Finished) {
        } catch (\Throwable $e) {
            http_response_code(500);
            echo "\nFatal error: Uncaught ", $e, "\n  thrown in ", $e->getFile(), " on line ", $e->getLine(), "\n";
        }
        self::sessionEnd($sid);
        $response = self::response();
        ob_clean();
        return self::$sent === null ? $response : Response::detached();
    }

    public static function finishRequest(): bool
    {
        $id = \Ignis\Scope::get('ignis.request');
        if (!\is_int($id) || self::$sent !== null) {
            return false;
        }
        $r = self::response();
        self::$sent = $id;
        return \ignis_respond($id, $r->status, $r->headers, $r->body);
    }

    /** @return array{0:?string,1:string,2:string} [file, SCRIPT_NAME, PATH_INFO]: the longest prefix that is a file wins. */
    private static function resolve(string $path): array
    {
        $path = rawurldecode($path);
        $segments = explode('/', $path);
        if (str_contains($path, "\0") || \in_array('..', $segments, true)) {
            return [null, '', ''];
        }
        $script = '';
        foreach (array_filter($segments, 'strlen') as $segment) {
            $script .= '/' . $segment;
            if (is_file(self::$docroot . $script)) {
                return [self::$docroot . $script, $script, substr($path, strlen($script))];
            }
            if (!is_dir(self::$docroot . $script)) {
                return [null, '', ''];
            }
        }
        $index = $script . '/' . self::$index;
        return self::$index !== null && is_file(self::$docroot . $index) ? [self::$docroot . $index, $index, ''] : [null, '', ''];
    }

    private static function server(Request $req, string $file, string $script, string $pathInfo): array
    {
        [$name, $port] = array_pad(explode(':', $req->headers['host'] ?? 'localhost', 2), 2, '80');
        $s = [
            'SERVER_SOFTWARE' => 'Ignis', 'GATEWAY_INTERFACE' => 'CGI/1.1', 'REQUEST_SCHEME' => 'http',
            'SERVER_NAME' => $name, 'SERVER_PORT' => $port, 'DOCUMENT_ROOT' => self::$docroot,
            'SCRIPT_FILENAME' => $file, 'SCRIPT_NAME' => $script, 'DOCUMENT_URI' => $script, 'PHP_SELF' => $script . $pathInfo,
        ];
        $s += $pathInfo === '' ? [] : ['PATH_INFO' => $pathInfo, 'PATH_TRANSLATED' => self::$docroot . $pathInfo];
        if (preg_match('/^Basic\s+(\S+)/i', $req->headers['authorization'] ?? '', $m) && ($cred = base64_decode($m[1], true)) !== false) {
            [$s['PHP_AUTH_USER'], $s['PHP_AUTH_PW']] = array_pad(explode(':', $cred, 2), 2, '');
            $s += ['AUTH_TYPE' => 'Basic', 'REMOTE_USER' => $s['PHP_AUTH_USER']];
        }
        return $s + self::$extra + self::$env;
    }

    /** Clears per-thread SAPI state left by the previous request: output, header list, status code and status line. */
    private static function reset(): void
    {
        if (ob_get_level() === 0) { // permanent and non-removable, so a script's `while (ob_end_flush())` loop stops here
            ob_start(null, 0, PHP_OUTPUT_HANDLER_CLEANABLE);
        }
        while (ob_get_level() > 1 && @ob_end_clean()) {}
        ob_clean();
        header_remove();
        header('X-Ignis: reset', true, 599); // a code change is the only userland path that frees a header('HTTP/…') status line
        header('X-Ignis: reset', true, 200);
        header_remove('X-Ignis');
        ini_get('expose_php') && header('X-Powered-By: PHP/' . PHP_VERSION);
        self::$sent = null;
    }

    private static function response(): Response
    {
        while (ob_get_level() > 1 && @ob_end_flush()) {}
        $headers = self::headerMap();
        if (!isset(array_change_key_case($headers)['content-type'])) {
            $headers['Content-Type'] = ini_get('default_mimetype') . '; charset=' . ini_get('default_charset');
        }
        $code = http_response_code();
        return new Response((string) ob_get_contents(), \is_int($code) && $code >= 100 ? $code : 200, $headers);
    }

    /** headers_list() as name => value. A repeated name (several Set-Cookie) gets case variants: Ignis responses are maps and hyper lower-cases names on the wire. */
    public static function headerMap(): array
    {
        $map = [];
        foreach (headers_list() as $line) {
            $colon = strpos($line, ':');
            if ($colon === false || $colon === 0) {
                continue; // header('Invalid') is stored by PHP but not sendable
            }
            $name = trim(substr($line, 0, $colon));
            for ($i = 1, $key = $name; isset($map[$key]) && $i <= strlen($name); $i++) {
                $key = strtoupper(substr($name, 0, $i)) . strtolower(substr($name, $i));
            }
            $map[$key] = ltrim(substr($line, $colon + 1));
        }
        return $map;
    }

    /** Adopts the client's session id or forces a fresh one: PS(id) persists across requests on a thread. */
    private static function sessionBegin(): ?string
    {
        if (!\function_exists('session_status') || ini_get('session.use_cookies') !== '0') {
            return null; // native cookie handling needs SG(headers_sent)=0, which php_embed_init() never leaves
        }
        unset($_SESSION); // left over from the previous request on this thread (no RSHUTDOWN in worker mode)
        session_id($sid = $_COOKIE[session_name()] ?? '');
        return $sid;
    }

    private static function sessionEnd(?string $cookieSid): void
    {
        if ($cookieSid === null || session_status() !== PHP_SESSION_ACTIVE) {
            return;
        }
        $sid = session_id();
        session_write_close();
        if ($sid !== $cookieSid) {
            $p = session_get_cookie_params();
            setcookie(session_name(), $sid, ['path' => $p['path'] ?: '/', 'httponly' => $p['httponly'], 'samesite' => $p['samesite'] ?: 'Lax']);
        }
    }
}

} // namespace Ignis\Classic

namespace {

if (!function_exists('getallheaders')) {
    /** Request headers in canonical case, from the HTTP_* entries the runtime put in $_SERVER. */
    function getallheaders(): array
    {
        $h = [];
        foreach ($_SERVER as $k => $v) {
            if (str_starts_with($k, 'HTTP_')) {
                $h[ucwords(strtolower(str_replace('_', '-', substr($k, 5))), '-')] = $v;
            }
        }
        return $h;
    }
    function apache_request_headers(): array { return getallheaders(); }
    function apache_response_headers(): array { return \Ignis\Classic\Runner::headerMap(); }
}

} // namespace
