<?php
declare(strict_types=1);

namespace Ignis\Symfony;

use Ignis\Http\Request as IgnisRequest;
use Ignis\Http\Response as IgnisResponse;
use Symfony\Component\HttpFoundation\Request;
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
            // $_SERVER/$_GET/$_POST/$_COOKIE are already this fiber's (ADR-0006); add what the CGI model expects.
            $_SERVER['SCRIPT_NAME'] = '/index.php';
            $_SERVER['SCRIPT_FILENAME'] = 'index.php';
            $_SERVER['SERVER_NAME'] = 'localhost';
            $_SERVER['SERVER_PORT'] = 8080;
            $_SERVER['PATH_INFO'] = $ignisRequest->path();
            $request = Request::createFromGlobals();
            if ($ignisRequest->body !== '' && !str_starts_with($ignisRequest->header('content-type') ?? '', 'application/x-www-form-urlencoded')) {
                $request = new Request($_GET, $_POST, [], $_COOKIE, [], $_SERVER, $ignisRequest->body);
            }
            $response = $kernel->handle($request);
            if ($response instanceof StreamedResponse) {
                // Stream it: the status and headers go out now and the body follows as frames, so
                // the client reads while the callback is still producing (R-STREAM). `write()`
                // awaits the runtime, which is the client's back-pressure reaching the handler.
                $headers = [];
                foreach ($response->headers->allPreserveCase() as $name => $values) {
                    $headers[strtolower($name)] = implode(', ', $values);
                }
                foreach ($response->headers->getCookies() as $cookie) {
                    $headers['set-cookie'] = (string) $cookie;
                }
                unset($headers['content-length']);   // there is no length yet, and hyper frames it chunked
                $out = \Ignis\Http\Stream::open($ignisRequest, $response->getStatusCode(), $headers);
                try {
                    \Ignis\Output::captureChunked($out, static fn () => $response->sendContent());
                } finally {
                    $out->close();
                }
                if ($kernel instanceof TerminableInterface) {
                    $kernel->terminate($request, $response);
                }

                return IgnisResponse::detached();
            }

            $content = (string) $response->getContent();
            $headers = [];
            foreach ($response->headers->allPreserveCase() as $name => $values) {
                $headers[strtolower($name)] = implode(', ', $values);
            }
            foreach ($response->headers->getCookies() as $cookie) {
                $headers['set-cookie'] = (string) $cookie; // single cookie per response in this cycle (E8 caveat)
            }
            if ($kernel instanceof TerminableInterface) {
                $kernel->terminate($request, $response);
            }
            return new IgnisResponse($content, $response->getStatusCode(), $headers);
        }, $this->listen);
        return 0;
    }
}
