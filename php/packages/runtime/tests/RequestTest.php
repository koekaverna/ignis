<?php

declare(strict_types=1);

namespace Ignis\Tests;

use Ignis\Http\Request;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * `Request::superglobals()` is what the runtime hands the fiber-switch observer, so everything an
 * application reads from `$_GET`/`$_POST`/`$_COOKIE`/`$_SERVER` is decided here. Parsing is pure,
 * which is why it can be tested without the binary — and why it had no tests until now.
 */
#[CoversClass(Request::class)]
final class RequestTest extends TestCase
{
    private static function get(string $uri, array $headers = []): Request
    {
        return new Request('GET', $uri, $headers, '');
    }

    public function testPathIsTheUriWithoutItsQuery(): void
    {
        self::assertSame('/a/b', self::get('/a/b?x=1')->path());
        self::assertSame('/a/b', self::get('/a/b')->path());
        self::assertSame('/', self::get('/?x=1')->path());
    }

    public function testQueryReadsOneParameterAndNullsTheRest(): void
    {
        $r = self::get('/x?a=1&b=two&empty=');
        self::assertSame('1', $r->query('a'));
        self::assertSame('two', $r->query('b'));
        self::assertSame('', $r->query('empty'));
        self::assertNull($r->query('missing'));
    }

    public function testHeaderLookupIsCaseInsensitive(): void
    {
        $r = self::get('/', ['content-type' => 'text/plain']);
        self::assertSame('text/plain', $r->header('Content-Type'));
        self::assertSame('text/plain', $r->header('CONTENT-TYPE'));
        self::assertNull($r->header('x-missing'));
    }

    public function testServerCarriesMethodAndUri(): void
    {
        [$server] = self::get('/a?x=1', ['host' => 'example.test'])->superglobals();
        self::assertSame('GET', $server['REQUEST_METHOD']);
        self::assertSame('/a?x=1', $server['REQUEST_URI']);
    }

    public function testCookiesAreSplitOnSemicolons(): void
    {
        [, , , $cookie] = self::get('/', ['cookie' => 'a=1; b=two; bare'])->superglobals();
        self::assertSame('1', $cookie['a']);
        self::assertSame('two', $cookie['b']);
        self::assertArrayNotHasKey('bare', $cookie, 'a cookie with no "=" is not a cookie');
    }

    public function testUrlencodedBodyBecomesPost(): void
    {
        $r = new Request('POST', '/', ['content-type' => 'application/x-www-form-urlencoded'], 'a=1&b[]=x&b[]=y');
        [, , $post] = $r->superglobals();
        self::assertSame(['a' => '1', 'b' => ['x', 'y']], $post);
    }

    public function testABodyOnGetIsNotParsed(): void
    {
        $r = new Request('GET', '/', ['content-type' => 'application/x-www-form-urlencoded'], 'a=1');
        [, , $post] = $r->superglobals();
        self::assertSame([], $post);
    }

    /** What a browser sends for any `FormData`; without this `$_POST` is silently empty. */
    public function testMultipartFieldsBecomePost(): void
    {
        $body = self::multipart([['name' => 'a', 'value' => '1'], ['name' => 'b[]', 'value' => 'x'], ['name' => 'b[]', 'value' => 'y']]);
        $r = new Request('POST', '/', ['content-type' => 'multipart/form-data; boundary=BNDRY'], $body);
        [, , $post] = $r->superglobals();
        self::assertSame(['a' => '1', 'b' => ['x', 'y']], $post);
    }

    public function testMultipartFilePardsAreSkipped(): void
    {
        $body = self::multipart([
            ['name' => 'a', 'value' => '1'],
            ['name' => 'upload', 'value' => "binary\r\ndata", 'filename' => 'x.bin'],
        ]);
        $r = new Request('POST', '/', ['content-type' => 'multipart/form-data; boundary=BNDRY'], $body);
        [, , $post] = $r->superglobals();
        self::assertSame(['a' => '1'], $post, 'a file part belongs in $_FILES, which is not fiber-scoped');
    }

    #[DataProvider('boundarySpellings')]
    public function testBoundaryIsFoundHoweverItIsSpelled(string $contentType): void
    {
        $body = self::multipart([['name' => 'a', 'value' => '1']]);
        [, , $post] = (new Request('POST', '/', ['content-type' => $contentType], $body))->superglobals();
        self::assertSame(['a' => '1'], $post);
    }

    public static function boundarySpellings(): iterable
    {
        yield 'plain' => ['multipart/form-data; boundary=BNDRY'];
        yield 'quoted' => ['multipart/form-data; boundary="BNDRY"'];
        yield 'charset first' => ['multipart/form-data; charset=utf-8; boundary=BNDRY'];
        yield 'upper case' => ['MULTIPART/FORM-DATA; BOUNDARY=BNDRY'];
    }

    public function testAValueKeepsItsInnerNewlines(): void
    {
        $body = self::multipart([['name' => 'text', 'value' => "line1\r\nline2"]]);
        [, , $post] = (new Request('POST', '/', ['content-type' => 'multipart/form-data; boundary=BNDRY'], $body))->superglobals();
        self::assertSame("line1\r\nline2", $post['text']);
    }

    /** @param list<array{name:string,value:string,filename?:string}> $parts */
    private static function multipart(array $parts, string $boundary = 'BNDRY'): string
    {
        $out = '';
        foreach ($parts as $p) {
            $disposition = 'form-data; name="' . $p['name'] . '"';
            if (isset($p['filename'])) {
                $disposition .= '; filename="' . $p['filename'] . '"';
            }
            $out .= "--{$boundary}\r\nContent-Disposition: {$disposition}\r\n\r\n{$p['value']}\r\n";
        }

        return $out . "--{$boundary}--\r\n";
    }
}
