<?php

declare(strict_types=1);

namespace Ignis\Tests\Symfony;

use Ignis\Symfony\IgnisWorkerRunner;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\Response;

/**
 * `IgnisWorkerRunner::headers()` flattens Symfony's header bag into the map the runtime sends.
 * `run()` itself boots a kernel and calls `Ignis\serve()`, which is E8's to measure.
 */
#[CoversClass(IgnisWorkerRunner::class)]
final class IgnisWorkerRunnerTest extends TestCase
{
    public function testHeaderNamesAreLowerCasedForTheWire(): void
    {
        $response = new Response('body', 200, ['Content-Type' => 'text/html', 'X-Custom' => 'value']);

        $headers = self::headers($response);

        self::assertSame('text/html', $headers['content-type']);
        self::assertSame('value', $headers['x-custom']);
        self::assertArrayNotHasKey('X-Custom', $headers, 'hyper lower-cases names on the wire, so the map is lower-cased here');
    }

    public function testARepeatedHeaderIsJoinedWithACommaAsHttpAllows(): void
    {
        $response = new Response();
        $response->headers->set('X-Thing', ['first', 'second']);

        self::assertSame('first, second', self::headers($response)['x-thing']);
    }

    public function testACookieBecomesASetCookieHeader(): void
    {
        $response = new Response();
        $response->headers->setCookie(Cookie::create('session', 'abc123', 0, '/', null, false, true, false, 'Lax'));

        $setCookie = self::headers($response)['set-cookie'];

        self::assertStringStartsWith('session=abc123', $setCookie);
        self::assertStringContainsString('httponly', $setCookie);
        self::assertStringContainsString('samesite=lax', $setCookie);
    }

    /**
     * DEFECT (pinned, not fixed — the fix is Rust-side). `headers()` assigns
     * `$headers['set-cookie']` inside a `foreach` over the response's cookies, so every cookie but
     * the last is silently dropped. A framework that sets a session cookie and a remember-me cookie
     * in the same response sends one of them.
     *
     * It cannot be fixed here: the runtime's response headers are a flat `array<string,string>`
     * (`ignis_respond(int, int, array, string)`), so several `Set-Cookie` lines have nowhere to go.
     * `Classic\Runner::parseHeaderLines()` works around the same limit with case variants of the
     * name; the real fix is a shape change in `ignis_respond` — another agent's file.
     */
    public function testAllButTheLastCookieAreLostBug(): void
    {
        $response = new Response();
        $response->headers->setCookie(Cookie::create('session', 'abc123'));
        $response->headers->setCookie(Cookie::create('remember_me', 'token'));
        $response->headers->setCookie(Cookie::create('locale', 'en'));

        $headers = self::headers($response);

        self::assertCount(3, $response->headers->getCookies(), 'Symfony has all three');
        self::assertStringStartsWith('locale=en', $headers['set-cookie'], 'and the flat map keeps only the last one');
        self::assertSame(1, \count(array_filter(array_keys($headers), static fn(string $name): bool => str_contains($name, 'set-cookie'))));
    }

    public function testAResponseWithNoCookiesHasNoSetCookieHeader(): void
    {
        self::assertArrayNotHasKey('set-cookie', self::headers(new Response()));
    }

    public function testTheCacheControlSymfonyAddsSurvivesTheFlattening(): void
    {
        $response = new Response('body');
        $response->setPublic();
        $response->setMaxAge(60);

        self::assertSame('max-age=60, public', self::headers($response)['cache-control']);
    }

    /** @return array<string, string> */
    private static function headers(Response $response): array
    {
        return (new \ReflectionMethod(IgnisWorkerRunner::class, 'headers'))->invoke(null, $response);
    }
}
