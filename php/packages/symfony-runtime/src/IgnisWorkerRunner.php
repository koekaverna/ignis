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
    public function __construct(private readonly HttpKernelInterface $kernel, private readonly string $listen)
    {
    }

    public function run(): int
    {
        $kernel = $this->kernel;
        \Ignis\serve(static function (IgnisRequest $ignisRequest) use ($kernel): IgnisResponse {
            $request = self::toSymfony($ignisRequest);
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

    /** The CGI-shaped request Symfony expects, from the one the runtime handed us. */
    private static function toSymfony(IgnisRequest $ignisRequest): Request
    {
        // $_SERVER/$_GET/$_POST/$_COOKIE are already this fiber's (ADR-0006); add what the CGI
        // model expects on top.
        $_SERVER['SCRIPT_NAME'] = '/index.php';
        $_SERVER['SCRIPT_FILENAME'] = 'index.php';
        $_SERVER['SERVER_NAME'] = 'localhost';
        $_SERVER['SERVER_PORT'] = 8080;
        $_SERVER['PATH_INFO'] = $ignisRequest->path();

        $request = Request::createFromGlobals();
        // A body the runtime did not parse into $_POST (anything but urlencoded) has to be handed
        // over as the raw body instead, or Symfony sees an empty request.
        if ($ignisRequest->body !== '' && !str_starts_with($ignisRequest->header('content-type') ?? '', 'application/x-www-form-urlencoded')) {
            $request = new Request($_GET, $_POST, [], $_COOKIE, [], $_SERVER, $ignisRequest->body);
        }

        return $request;
    }

    /**
     * Symfony's header bag as the runtime's flat map.
     *
     * @return array<string,string>
     */
    private static function headers(Response $response): array
    {
        $headers = [];
        foreach ($response->headers->allPreserveCase() as $name => $values) {
            $headers[strtolower($name)] = implode(', ', $values);
        }
        foreach ($response->headers->getCookies() as $cookie) {
            $headers['set-cookie'] = (string) $cookie;   // one cookie per response in this cycle (E8 caveat)
        }

        return $headers;
    }
}
