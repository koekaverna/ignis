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
                // Not `ob_start()`: the output-buffer stack is per THREAD, and `sendContent()`
                // parks on I/O, so a plain buffer here collects what every other fiber echoes in
                // the meantime — two responses swapped bodies in V-72. `Output::capture()` attributes
                // output to the running fiber inside the runtime (`sapi_module.ub_write` is ours),
                // so concurrent streamed responses stay apart and do not serialise (V-73).
                // It still collects the whole body in memory: real streaming needs a chunked
                // response op on the Rust side, which does not exist yet (BACKLOG R-STREAM).
                $content = \Ignis\Output::capture(static fn () => $response->sendContent());
            } else {
                $content = (string) $response->getContent();
            }
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
