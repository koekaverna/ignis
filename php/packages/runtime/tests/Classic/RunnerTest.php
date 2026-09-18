<?php

declare(strict_types=1);

namespace Ignis\Tests\Classic;

use Ignis\Classic\Runner;
use Ignis\Http\Request;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * `Classic\Runner::resolve()` is the trust boundary of classic mode: it turns a URL path from the
 * network into a file path under a document root, and everything else in the class trusts its
 * answer. `server()` builds the `$_SERVER` an application then reads, `PHP_AUTH_*` included.
 *
 * Both are private, so they are called through reflection rather than made public for a test —
 * the visibility is part of the design. `headerMap()` is the exception: it reads SAPI state
 * (`headers_list()`, empty under php-cli), so its pure half is `parseHeaderLines()`.
 *
 * Never weaken one of the resolve() assertions to make it pass: each one is a path traversal.
 */
#[CoversClass(Runner::class)]
final class RunnerTest extends TestCase
{
    private const DOCROOT = __DIR__ . '/../../../../tests/fixtures/docroot';

    protected function setUp(): void
    {
        Runner::$docroot = realpath(self::DOCROOT) ?: self::DOCROOT;
        Runner::$index = 'index.php';
        Runner::$extra = [];
        Runner::$env = [];
    }

    // ---- resolve() -------------------------------------------------------------------------

    #[DataProvider('traversalAttempts')]
    public function testATraversingPathResolvesToNothing(string $path): void
    {
        self::assertSame([null, '', ''], self::resolve($path), 'a path that escapes the document root must resolve to nothing');
    }

    /** @return iterable<string, array{string}> */
    public static function traversalAttempts(): iterable
    {
        yield 'a plain ..' => ['/../outside-the-docroot.txt'];
        yield 'a .. in the middle' => ['/sub/../../outside-the-docroot.txt'];
        yield 'percent-encoded .. (rawurldecode runs first)' => ['/%2e%2e/outside-the-docroot.txt'];
        yield 'double-encoded .. ' => ['/%2e%2e%2f%2e%2e%2foutside-the-docroot.txt'];
        yield 'a NUL byte' => ['/index.php%00.txt'];
        yield 'a NUL byte after a valid prefix' => ['/sub/page.php%00/../../outside-the-docroot.txt'];
    }

    public function testTheLongestPrefixThatIsAFileWinsAndTheRestIsPathInfo(): void
    {
        [$file, $script, $pathInfo] = self::resolve('/app.php/extra/path');

        self::assertSame(Runner::$docroot . '/app.php', $file);
        self::assertSame('/app.php', $script);
        self::assertSame('/extra/path', $pathInfo);
    }

    public function testADirectoryFallsBackToItsIndex(): void
    {
        self::assertSame([Runner::$docroot . '/index.php', '/index.php', ''], self::resolve('/'));
        self::assertSame([Runner::$docroot . '/sub/index.php', '/sub/index.php', ''], self::resolve('/sub'));
        self::assertSame([Runner::$docroot . '/sub/index.php', '/sub/index.php', ''], self::resolve('/sub/'));
    }

    public function testADirectoryWithNoIndexResolvesToNothing(): void
    {
        self::assertSame([null, '', ''], self::resolve('/noindex'), 'no index.php in there, and a listing is not something classic mode serves');

        Runner::$index = null;
        self::assertSame([null, '', ''], self::resolve('/'), 'index turned off: the document root itself is not servable');
    }

    public function testANonPhpFileResolvesToItself(): void
    {
        self::assertSame([Runner::$docroot . '/static.txt', '/static.txt', ''], self::resolve('/static.txt'));
    }

    public function testAMissingPathResolvesToNothing(): void
    {
        self::assertSame([null, '', ''], self::resolve('/nope.php'));
        self::assertSame([null, '', ''], self::resolve('/sub/nope/deeper.php'));
    }

    // ---- server() --------------------------------------------------------------------------

    public function testBasicAuthIsDecodedIntoThePhpAuthEntries(): void
    {
        $server = self::server(['host' => 'x', 'authorization' => 'Basic ' . base64_encode('ada:lovelace:extra')]);

        self::assertSame('ada', $server['PHP_AUTH_USER']);
        self::assertSame('lovelace:extra', $server['PHP_AUTH_PW'], 'only the first colon splits, so a password may contain one');
        self::assertSame('Basic', $server['AUTH_TYPE']);
        self::assertSame('ada', $server['REMOTE_USER']);

        $noPassword = self::server(['authorization' => 'basic ' . base64_encode('ada')]);
        self::assertSame('ada', $noPassword['PHP_AUTH_USER']);
        self::assertSame('', $noPassword['PHP_AUTH_PW']);

        $notBasic = self::server(['authorization' => 'Bearer abc']);
        self::assertArrayNotHasKey('PHP_AUTH_USER', $notBasic);
        self::assertArrayNotHasKey('AUTH_TYPE', $notBasic);

        $notBase64 = self::server(['authorization' => 'Basic !!!not base64!!!']);
        self::assertArrayNotHasKey('PHP_AUTH_USER', $notBase64);
    }

    public function testTheHostHeaderIsSplitIntoNameAndPort(): void
    {
        $withPort = self::server(['host' => 'example.test:8443']);
        self::assertSame('example.test', $withPort['SERVER_NAME']);
        self::assertSame('8443', $withPort['SERVER_PORT']);

        $withoutPort = self::server(['host' => 'example.test']);
        self::assertSame('example.test', $withoutPort['SERVER_NAME']);
        self::assertSame('80', $withoutPort['SERVER_PORT'], 'no port in the Host header means 80');

        $noHost = self::server([]);
        self::assertSame('localhost', $noHost['SERVER_NAME']);
        self::assertSame('80', $noHost['SERVER_PORT']);
    }

    public function testExtraBeatsEnvAndTheCgiEntriesBeatBoth(): void
    {
        Runner::$extra = ['APP_ENV' => 'prod', 'SERVER_SOFTWARE' => 'not-this'];
        Runner::$env = ['APP_ENV' => 'dev', 'HOME' => '/root'];

        $server = self::server(['host' => 'x']);

        self::assertSame('prod', $server['APP_ENV'], '$extra wins over $env');
        self::assertSame('/root', $server['HOME'], '$env fills in what $extra does not set');
        self::assertSame('Ignis', $server['SERVER_SOFTWARE'], 'the CGI entries win over both: `$server + $extra + $env`');
    }

    public function testPathInfoAndPathTranslatedAppearOnlyWhenThereIsPathInfo(): void
    {
        $withPathInfo = self::server(['host' => 'x'], '/app.php', '/extra');
        self::assertSame('/extra', $withPathInfo['PATH_INFO']);
        self::assertSame(Runner::$docroot . '/extra', $withPathInfo['PATH_TRANSLATED']);
        self::assertSame('/app.php/extra', $withPathInfo['PHP_SELF']);

        $without = self::server(['host' => 'x'], '/app.php', '');
        self::assertArrayNotHasKey('PATH_INFO', $without);
        self::assertArrayNotHasKey('PATH_TRANSLATED', $without);
        self::assertSame('/app.php', $without['PHP_SELF']);
    }

    // ---- parseHeaderLines() ----------------------------------------------------------------

    public function testHeaderLinesBecomeANameValueMap(): void
    {
        self::assertSame(
            ['Content-Type' => 'text/html', 'X-Empty' => ''],
            Runner::parseHeaderLines(['Content-Type: text/html', 'X-Empty:']),
        );
    }

    public function testALineWithNoUsableNameIsDropped(): void
    {
        self::assertSame([], Runner::parseHeaderLines(['Invalid', ': no name', '']), "header('Invalid') is stored by PHP but is not sendable");
    }

    public function testARepeatedNameGetsCaseVariantsSoNothingIsLost(): void
    {
        $map = Runner::parseHeaderLines(['Set-Cookie: a=1', 'Set-Cookie: b=2', 'Set-Cookie: c=3']);

        self::assertSame(['a=1', 'b=2', 'c=3'], array_values($map), 'three cookies survive a flat map');
        self::assertCount(3, $map);
        self::assertSame(['Set-Cookie', 'Set-cookie', 'SEt-cookie'], array_keys($map), 'the loop upper-cases one more leading character per collision; hyper lower-cases the name again on the wire');
    }

    // ---- requestFrom() (the raw request off the loop) --------------------------------------

    public function testARequestCompletionWithANonStringMethodIsRejected(): void
    {
        $this->expectException(\UnexpectedValueException::class);
        self::requestFrom(1, ['method' => 7, 'uri' => '/', 'headers' => [], 'body' => '']);
    }

    public function testARequestCompletionMissingAFieldIsRejected(): void
    {
        $this->expectException(\UnexpectedValueException::class);
        self::requestFrom(1, ['method' => 'GET', 'uri' => '/', 'headers' => []]);
    }

    public function testARequestCompletionWithANonStringHeaderValueIsRejected(): void
    {
        $this->expectException(\UnexpectedValueException::class);
        self::requestFrom(1, ['method' => 'GET', 'uri' => '/', 'headers' => ['x' => 7], 'body' => '']);
    }

    // ---- helpers ---------------------------------------------------------------------------

    /** @param array<string, mixed> $raw */
    private static function requestFrom(int $id, array $raw): Request
    {
        $result = (new \ReflectionMethod(Runner::class, 'requestFrom'))->invoke(null, $id, $raw);
        if (!$result instanceof Request) {
            throw new \LogicException('Runner::requestFrom() did not return a Request');
        }

        return $result;
    }

    /** @return array{0:?string,1:string,2:string} */
    private static function resolve(string $path): array
    {
        $result = (new \ReflectionMethod(Runner::class, 'resolve'))->invoke(null, $path);
        if (!\is_array($result) || !\array_is_list($result) || \count($result) !== 3) {
            throw new \LogicException('Runner::resolve() did not return the expected tuple');
        }
        [$file, $script, $pathInfo] = $result;
        if (($file !== null && !\is_string($file)) || !\is_string($script) || !\is_string($pathInfo)) {
            throw new \LogicException('Runner::resolve() did not return the expected tuple');
        }

        return [$file, $script, $pathInfo];
    }

    /**
     * @param  array<string, string> $headers
     * @return array<string, mixed>
     */
    private static function server(array $headers, string $script = '/index.php', string $pathInfo = ''): array
    {
        $request = new Request('GET', '/', $headers, '');
        $result = (new \ReflectionMethod(Runner::class, 'server'))
            ->invoke(null, $request, Runner::$docroot . $script, $script, $pathInfo);

        return self::asStringKeyedArray($result);
    }

    /** @return array<string, mixed> */
    private static function asStringKeyedArray(mixed $value): array
    {
        if (!\is_array($value)) {
            throw new \LogicException('Runner::server() did not return an array');
        }
        $out = [];
        foreach ($value as $key => $item) {
            if (!\is_string($key)) {
                throw new \LogicException('Runner::server() did not return a string-keyed array');
            }
            $out[$key] = $item;
        }

        return $out;
    }
}
