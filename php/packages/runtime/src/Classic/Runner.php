<?php

declare(strict_types=1);

namespace Ignis\Classic;

use Ignis\Http\Request;
use Ignis\Http\Response;

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

    /** Null means the response already went out mid-script through finish_request(); the loop must not answer twice. */
    public static function handle(Request $request): ?Response
    {
        [$file, $script, $pathInfo] = self::resolve($request->path());
        if ($file === null) {
            return Response::text("404 Not Found\n", 404);
        }
        self::reset();
        self::enterSuperglobals($request, $file, $script, $pathInfo);
        $sessionId = self::sessionBegin();
        try {
            str_ends_with($file, '.php') ? (self::$run)($file) : readfile($file);
        } catch (Finished) {
        } catch (\Throwable $e) {
            http_response_code(500);
            echo "\nFatal error: Uncaught ", $e, "\n  thrown in ", $e->getFile(), " on line ", $e->getLine(), "\n";
        }
        self::sessionEnd($sessionId);
        $response = self::response();
        ob_clean();
        return self::$sent === null ? $response : null;
    }

    /**
     * Populates `$_SERVER` and `$_REQUEST` the way the runtime already did for `REQUEST_*`,
     * `HTTP_*` and `CONTENT_*`; `$_REQUEST` is referenced at load, so a plain assignment disarms
     * the JIT auto-global once per thread.
     */
    private static function enterSuperglobals(Request $request, string $file, string $script, string $pathInfo): void
    {
        $_SERVER = self::server($request, $file, $script, $pathInfo) + $_SERVER;
        $_REQUEST = array_merge($_GET, $_POST, $_COOKIE);
        InputStream::$body = $request->body;
    }

    /** @var list<array{0: int, 1: array<string, mixed>}> requests handed over by the loop, one at a time */
    private static array $inbox = [];
    private static ?int $current = null;
    private static ?string $currentSessionId = null;

    /**
     * `Loop::$rawRequestHandler`: runs on the loop's own stack, so it only parks the request.
     *
     * @param array<string, mixed> $raw
     */
    public static function queue(int $id, array $raw): void
    {
        self::$inbox[] = [$id, $raw];
    }

    /**
     * @see \Ignis\Classic\accept()
     * Null means the loop stopped for good with nothing left pending, not that the wait timed out.
     */
    public static function accept(): ?string
    {
        while (true) {
            \Ignis\Loop::runUntil(static fn(): bool => self::$inbox !== []);
            $next = array_shift(self::$inbox);
            if ($next === null) {
                return null;
            }
            [$id, $raw] = $next;
            $request = self::requestFrom($id, $raw);
            [$file, $script, $pathInfo] = self::resolve($request->path());
            if ($file === null) {
                \ignis_respond($id, 404, ['content-type' => 'text/plain'], "404 Not Found\n");
                continue;
            }
            if (!str_ends_with($file, '.php')) {
                \ignis_respond($id, 200, ['content-type' => 'application/octet-stream'], (string) file_get_contents($file));
                continue;
            }
            self::reset();
            self::enterSuperglobals($request, $file, $script, $pathInfo);
            self::$current = $id;
            self::$currentSessionId = self::sessionBegin();
            \Ignis\Scope::set('ignis.request', $id);
            return $file;
        }
    }

    /** @see \Ignis\Classic\finish() */
    public static function end(): void
    {
        $id = self::$current;
        if ($id === null) {
            return;
        }
        self::sessionEnd(self::$currentSessionId);
        $response = self::response();
        ob_clean();
        if (self::$sent === null) {
            \ignis_respond($id, $response->status, $response->headers, $response->body);
        }
        self::$sent = null;
        self::$current = null;
        self::$currentSessionId = null;
        \Ignis\Scope::set('ignis.request', null);
    }

    public static function finishRequest(): bool
    {
        $id = \Ignis\Scope::get('ignis.request');
        if (!\is_int($id) || self::$sent !== null) {
            return false;
        }
        $response = self::response();
        self::$sent = $id;
        return \ignis_respond($id, $response->status, $response->headers, $response->body);
    }

    /**
     * The raw request handed over by the loop is data off the reactor, not a fact about its shape.
     * @param array<string, mixed> $raw
     */
    private static function requestFrom(int $id, array $raw): Request
    {
        $method = $raw['method'] ?? null;
        $uri = $raw['uri'] ?? null;
        $headers = $raw['headers'] ?? null;
        $body = $raw['body'] ?? null;
        if (!\is_string($method) || !\is_string($uri) || !\is_array($headers) || !\is_string($body)) {
            throw new \UnexpectedValueException('Ignis\\Classic\\Runner: malformed raw request');
        }

        return new Request($method, $uri, self::stringHeaders($headers), $body, $id);
    }

    /**
     * @param array<array-key, mixed> $headers
     * @return array<string, string>
     */
    private static function stringHeaders(array $headers): array
    {
        $out = [];
        foreach ($headers as $name => $value) {
            if (!\is_string($name) || !\is_string($value)) {
                throw new \UnexpectedValueException('Ignis\\Classic\\Runner: malformed raw request headers');
            }
            $out[$name] = $value;
        }

        return $out;
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
        foreach (array_filter($segments, static fn(string $segment): bool => $segment !== '') as $segment) {
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

    /**
     * An empty session cookie parameter is not the same as an unset one: setcookie() reads an empty
     * path as "the current directory", and session.cookie_path may legitimately be set to empty.
     */
    private static function orFallback(string $value, string $fallback): string
    {
        return $value !== '' ? $value : $fallback;
    }

    /**
     * `session.cookie_samesite` is a free-form ini string, while setcookie() accepts three values.
     * Anything else is the configuration being wrong, and silently sending it would let the browser
     * decide -- so an unrecognised value falls back to the default rather than travelling.
     *
     * @return 'Lax'|'None'|'Strict'
     */
    private static function sameSite(string $configured): string
    {
        return match (strtolower($configured)) {
            'none' => 'None',
            'strict' => 'Strict',
            default => 'Lax',
        };
    }

    /** @return array<string, string> */
    private static function server(Request $request, string $file, string $script, string $pathInfo): array
    {
        [$name, $port] = array_pad(explode(':', $request->headers['host'] ?? 'localhost', 2), 2, '80');
        $server = [
            'SERVER_SOFTWARE' => 'Ignis', 'GATEWAY_INTERFACE' => 'CGI/1.1', 'REQUEST_SCHEME' => 'http',
            'SERVER_NAME' => $name, 'SERVER_PORT' => $port, 'DOCUMENT_ROOT' => self::$docroot,
            'SCRIPT_FILENAME' => $file, 'SCRIPT_NAME' => $script, 'DOCUMENT_URI' => $script, 'PHP_SELF' => $script . $pathInfo,
        ];
        $server += $pathInfo === '' ? [] : ['PATH_INFO' => $pathInfo, 'PATH_TRANSLATED' => self::$docroot . $pathInfo];
        if (preg_match('/^Basic\s+(\S+)/i', $request->headers['authorization'] ?? '', $matches) && ($credentials = base64_decode($matches[1], true)) !== false) {
            [$server['PHP_AUTH_USER'], $server['PHP_AUTH_PW']] = array_pad(explode(':', $credentials, 2), 2, '');
            $server += ['AUTH_TYPE' => 'Basic', 'REMOTE_USER' => $server['PHP_AUTH_USER']];
        }
        return $server + self::$extra + self::$env;
    }

    /** Clears per-thread SAPI state left by the previous request: output, header list, status code and status line. */
    private static function reset(): void
    {
        self::ensureRemovableOutputBuffer();
        while (ob_get_level() > 1 && @ob_end_clean()) {
        }
        ob_clean();
        header_remove();
        self::clearStatusLine();
        ini_get('expose_php') && header('X-Powered-By: PHP/' . PHP_VERSION);
        self::$sent = null;
    }

    /** ob_get_level() 0 is the permanent, non-removable buffer -- a script's `while (ob_end_flush())` loop stops here. */
    private static function ensureRemovableOutputBuffer(): void
    {
        if (ob_get_level() === 0) {
            ob_start(null, 0, PHP_OUTPUT_HANDLER_CLEANABLE);
        }
    }

    /** A code change is the only userland path that frees a header('HTTP/…') status line. */
    private static function clearStatusLine(): void
    {
        header('X-Ignis: reset', true, 599);
        header('X-Ignis: reset', true, 200);
        header_remove('X-Ignis');
    }

    /** Always a string body: classic mode writes through output and cannot stream. */
    private static function response(): Response
    {
        while (ob_get_level() > 1 && @ob_end_flush()) {
        }
        $headers = self::headerMap();
        if (!isset(array_change_key_case($headers)['content-type'])) {
            $headers['Content-Type'] = ini_get('default_mimetype') . '; charset=' . ini_get('default_charset');
        }
        $code = http_response_code();
        return new Response((string) ob_get_contents(), \is_int($code) && $code >= 100 ? $code : 200, $headers);
    }

    /**
     * headers_list() as name => value. A repeated name (several Set-Cookie) gets case variants:
     * Ignis responses are maps and hyper lower-cases names on the wire.
     * @return array<string, string>
     */
    public static function headerMap(): array
    {
        return self::parseHeaderLines(headers_list());
    }

    /**
     * The pure half of headerMap(): `headers_list()` returns `[]` under php-cli, so the parsing is
     * split out to be testable without a SAPI. A line with no colon, or a colon at position 0, is a
     * header PHP stored but cannot send (`header('Invalid')`), and is skipped.
     *
     * @param  list<string>          $lines
     * @return array<string, string>
     */
    public static function parseHeaderLines(array $lines): array
    {
        $map = [];
        foreach ($lines as $line) {
            $colon = strpos($line, ':');
            if ($colon === false || $colon === 0) {
                continue;
            }
            $name = trim(substr($line, 0, $colon));
            for ($i = 1, $key = $name; isset($map[$key]) && $i <= strlen($name); $i++) {
                $key = strtoupper(substr($name, 0, $i)) . strtolower(substr($name, $i));
            }
            $map[$key] = ltrim(substr($line, $colon + 1));
        }
        return $map;
    }

    /**
     * Adopts the client's session id or forces a fresh one: PS(id) persists across requests on a
     * thread. Skipped when ext-session is unavailable or is already handling cookies itself --
     * native cookie handling needs SG(headers_sent)=0, which php_embed_init() never leaves.
     */
    private static function sessionBegin(): ?string
    {
        if (!\function_exists('session_status') || ini_get('session.use_cookies') !== '0') {
            return null;
        }
        self::forgetPreviousSession();
        $cookie = $_COOKIE[session_name()] ?? '';
        $sessionId = \is_string($cookie) ? $cookie : '';
        session_id($sessionId);
        return $sessionId;
    }

    /** Worker mode has no RSHUTDOWN, so `$_SESSION` would otherwise still hold the previous request's data. */
    private static function forgetPreviousSession(): void
    {
        unset($_SESSION);
    }

    private static function sessionEnd(?string $cookieSessionId): void
    {
        if ($cookieSessionId === null || session_status() !== PHP_SESSION_ACTIVE) {
            return;
        }
        $sessionId = session_id();
        session_write_close();
        $name = session_name();
        if ($sessionId !== false && $name !== false && $sessionId !== $cookieSessionId) {
            $cookieParameters = session_get_cookie_params();
            setcookie($name, $sessionId, [
                'path' => self::orFallback($cookieParameters['path'], '/'),
                'httponly' => $cookieParameters['httponly'],
                'samesite' => self::sameSite($cookieParameters['samesite']),
            ]);
        }
    }
}
