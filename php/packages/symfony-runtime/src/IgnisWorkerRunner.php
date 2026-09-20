<?php

declare(strict_types=1);

namespace Ignis\Symfony;

use Ignis\Http\Request as IgnisRequest;
use Ignis\Http\Response as IgnisResponse;
use Ignis\Http\StreamedResponse as IgnisStreamedResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\HttpKernel\TerminableInterface;
use Symfony\Component\Runtime\RunnerInterface;

/** Boots the kernel once; every HTTP request runs in its own fiber via Ignis\serve(). */
final class IgnisWorkerRunner implements RunnerInterface
{
    public function __construct(private readonly HttpKernelInterface $kernel, private readonly string $listen) {}

    public function run(): int
    {
        $kernel = $this->kernel;
        [$serverName, $serverPort] = self::listenParts($this->listen);
        \Ignis\serve(static function (IgnisRequest $ignisRequest) use ($kernel, $serverName, $serverPort): IgnisResponse {
            $request = self::toSymfony($ignisRequest, $serverName, $serverPort);
            $response = $kernel->handle($request);
            $headers = self::headers($response);

            try {
                if ($response instanceof StreamedResponse) {
                    return new IgnisStreamedResponse($response->sendContent(...), $response->getStatusCode(), $headers);
                }

                return new IgnisResponse((string) $response->getContent(), $response->getStatusCode(), $headers);
            } finally {
                if ($kernel instanceof TerminableInterface) {
                    $kernel->terminate($request, $response);
                }
            }
        }, $this->listen);

        return 0;
    }

    /**
     * The address the server is actually bound to, as `SERVER_NAME` and `SERVER_PORT`.
     *
     * Those two were hardcoded to `localhost` and `8080` until 2026-09-18, with the real address in
     * the constructor two methods above: every absolute URL Symfony generated — a redirect, a signed
     * URL, a mail link — named a host the server was not on. `0.0.0.0` becomes `localhost`, because
     * "every interface" is not a name a client can resolve.
     *
     * @return array{0: string, 1: int}
     */
    private static function listenParts(string $listen): array
    {
        $colon = strrpos($listen, ':');
        if ($colon === false) {
            return [$listen, 80];
        }
        $host = trim(substr($listen, 0, $colon), '[]');
        $port = (int) substr($listen, $colon + 1);

        return [$host === '' || $host === '0.0.0.0' || $host === '::' ? 'localhost' : $host, $port > 0 ? $port : 80];
    }

    /** The CGI-shaped request Symfony expects, from the one the runtime handed us. */
    private static function toSymfony(IgnisRequest $ignisRequest, string $serverName, int $serverPort): Request
    {
        // $_SERVER/$_GET/$_POST/$_COOKIE are already this fiber's (ADR-0006); add what the CGI
        // model expects on top.
        $_SERVER['SCRIPT_NAME'] = '/index.php';
        $_SERVER['SCRIPT_FILENAME'] = 'index.php';
        $_SERVER['SERVER_NAME'] = $serverName;
        $_SERVER['SERVER_PORT'] = $serverPort;
        $_SERVER['PATH_INFO'] = $ignisRequest->path();

        // One construction, and Symfony's own. This used to build a Request and then throw it away
        // for any body the runtime had not parsed into `$_POST`, because `php://input` was empty
        // outside classic mode. `Loop::enterRequest()` backs it now, so `createFromGlobals()` is
        // correct for every method and content type -- including the `PUT` with a urlencoded body
        // that the discarded-and-rebuilt path silently delivered empty, since the runtime fills
        // `$_POST` for `POST` alone and Symfony re-parses `php://input` for PUT/PATCH/DELETE.
        return Request::createFromGlobals();
    }

    /**
     * Symfony's header bag, as one list of values per name.
     *
     * Every value is kept separately rather than comma-joined. RFC 7230 lets most list-valued
     * headers be combined that way, but `Set-Cookie` is the standing exception and must go out as
     * one line per cookie -- and until 2026-09-18 this method assigned `$headers['set-cookie']`
     * inside a foreach, so a response with a session cookie and a CSRF cookie sent only the last.
     *
     * @internal Public only so the test that pins this contract can call it: reaching it by
     *           reflection erased the return type, which is what hid the multi-cookie bug.
     *
     * @return array<string, list<string>>
     */
    public static function headers(Response $response): array
    {
        $headers = [];
        foreach ($response->headers->allPreserveCase() as $name => $values) {
            $headers[strtolower($name)] = $values;
        }

        return $headers;
    }
}
