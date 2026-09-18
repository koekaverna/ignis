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

        self::assertSame(['text/html'], $headers['content-type']);
        self::assertSame(['value'], $headers['x-custom']);
        self::assertArrayNotHasKey('X-Custom', $headers, 'hyper lower-cases names on the wire, so the map is lower-cased here');
    }

    /**
     * Every value keeps its own line rather than being comma-joined. RFC 7230 permits the joined
     * form for most list-valued headers, but not for Set-Cookie, and there is no reason to have two
     * shapes when the boundary carries a list either way.
     */
    public function testARepeatedHeaderKeepsOneValuePerLine(): void
    {
        $response = new Response();
        $response->headers->set('X-Thing', ['first', 'second']);

        self::assertSame(['first', 'second'], self::headers($response)['x-thing']);
    }

    public function testACookieBecomesASetCookieHeader(): void
    {
        $response = new Response();
        $response->headers->setCookie(Cookie::create('session', 'abc123', 0, '/', null, false, true, false, Cookie::SAMESITE_LAX));

        $setCookie = self::headers($response)['set-cookie'];

        self::assertCount(1, $setCookie);
        self::assertStringStartsWith('session=abc123', $setCookie[0]);
        self::assertStringContainsString('httponly', $setCookie[0]);
        self::assertStringContainsString('samesite=lax', $setCookie[0]);
    }

    /**
     * Until 2026-09-18 this kept only the last cookie: `headers()` assigned
     * `$headers['set-cookie']` inside a `foreach`, and the boundary was a flat
     * `array<string, string>` with nowhere to put a second line. `ignis_respond` now accepts a list
     * per name, so every cookie survives -- which matters because a session cookie beside a CSRF or
     * remember-me cookie is the most ordinary response a framework produces.
     *
     * Comma-joining them would not do: RFC 7230 allows it for most list-valued headers and names
     * `Set-Cookie` as the exception.
     */
    public function testEveryCookieSurvivesAsItsOwnSetCookieLine(): void
    {
        $response = new Response();
        $response->headers->setCookie(Cookie::create('session', 'abc123'));
        $response->headers->setCookie(Cookie::create('remember_me', 'token'));
        $response->headers->setCookie(Cookie::create('locale', 'en'));

        $setCookie = self::headers($response)['set-cookie'];

        self::assertCount(3, $setCookie);
        self::assertStringStartsWith('session=abc123', $setCookie[0]);
        self::assertStringStartsWith('remember_me=token', $setCookie[1]);
        self::assertStringStartsWith('locale=en', $setCookie[2]);
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

        // Symfony joins the cache-control directives itself, so this arrives as one value.
        self::assertSame(['max-age=60, public'], self::headers($response)['cache-control']);
    }

    /** @return array<string, list<string>> */
    private static function headers(Response $response): array
    {
        return IgnisWorkerRunner::headers($response);
    }

    /**
     * `SERVER_NAME` and `SERVER_PORT` were `localhost` and `8080` whatever the server was bound to,
     * so every absolute URL Symfony generated — a redirect, a signed URL, a link in a mail — named a
     * host the server was not on.
     */
    public function testTheServerNameAndPortComeFromTheAddressTheServerIsBoundTo(): void
    {
        $parts = new \ReflectionMethod(IgnisWorkerRunner::class, 'listenParts');

        self::assertSame(['example.test', 9000], $parts->invoke(null, 'example.test:9000'));
        self::assertSame(['127.0.0.1', 18080], $parts->invoke(null, '127.0.0.1:18080'));
        self::assertSame(['localhost', 8080], $parts->invoke(null, '0.0.0.0:8080'), 'every interface is not a name a client can resolve');
        self::assertSame(['::1', 8080], $parts->invoke(null, '[::1]:8080'));
    }
}
