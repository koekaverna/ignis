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
    /** @param array<string, string> $headers */
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

    /** @return iterable<string, array{0: string}> */
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

    /**
     * The three cases the differential test against PHP's own parser caught (E22, V-71). Kept here
     * as well as there because a unit test fails in milliseconds and needs no servers.
     */
    public function testAValueMayContainTheBoundaryText(): void
    {
        $body = self::multipart([['name' => 't', 'value' => 'x--BNDRY-not-a-boundary']]);
        [, , $post] = (new Request('POST', '/', ['content-type' => 'multipart/form-data; boundary=BNDRY'], $body))->superglobals();
        self::assertSame('x--BNDRY-not-a-boundary', $post['t'], 'a boundary counts only at the start of a line');
    }

    public function testLineFeedOnlyBodiesParse(): void
    {
        $body = \str_replace("\r\n", "\n", self::multipart([['name' => 'lf', 'value' => 'v']]));
        [, , $post] = (new Request('POST', '/', ['content-type' => 'multipart/form-data; boundary=BNDRY'], $body))->superglobals();
        self::assertSame(['lf' => 'v'], $post, 'PHP accepts LF-only multipart, so we must too');
    }

    public function testAnUnquotedNameIsAName(): void
    {
        $body = "--BNDRY\r\nContent-Disposition: form-data; name=a\r\n\r\n1\r\n--BNDRY--\r\n";
        [, , $post] = (new Request('POST', '/', ['content-type' => 'multipart/form-data; boundary=BNDRY'], $body))->superglobals();
        self::assertSame(['a' => '1'], $post);
    }

    public function testAPreambleAndAnEpilogueAreIgnored(): void
    {
        $body = "ignored preamble\r\n" . self::multipart([['name' => 'a', 'value' => '1']]) . "ignored junk\r\n";
        [, , $post] = (new Request('POST', '/', ['content-type' => 'multipart/form-data; boundary=BNDRY'], $body))->superglobals();
        self::assertSame(['a' => '1'], $post);
    }

    public function testNestedAndListNamesFollowPhpsOwnGrammar(): void
    {
        $body = self::multipart([
            ['name' => 'u[profile][name]', 'value' => 'ada'],
            ['name' => 'n[3]', 'value' => 'three'],
            ['name' => 'n[]', 'value' => 'next'],
        ]);
        [, , $post] = (new Request('POST', '/', ['content-type' => 'multipart/form-data; boundary=BNDRY'], $body))->superglobals();
        $profile = $post['u'];
        if (!\is_array($profile)) {
            self::fail('expected $post[\'u\'] to be an array');
        }
        self::assertSame(['name' => 'ada'], $profile['profile']);
        self::assertSame(['3' => 'three', '4' => 'next'], $post['n'], 'parse_str continues after the highest numeric key');
    }

    public function testAnEmptyNameIsDropped(): void
    {
        $body = self::multipart([['name' => '', 'value' => 'v'], ['name' => 'ok', 'value' => '1']]);
        [, , $post] = (new Request('POST', '/', ['content-type' => 'multipart/form-data; boundary=BNDRY'], $body))->superglobals();
        self::assertSame(['ok' => '1'], $post);
    }

    /**
     * `path()`, `query()` and `superglobals()` each scan the URI for `?` on their own, and
     * `superglobals()` then runs `parse_str()` a second time over the same bytes that `query()`
     * already parsed. The three have to agree; these pin what "agree" means before the refactor
     * that collapses them into one scan.
     */
    public function testTheThreeQuestionMarkScansAgreeOnWhereTheQueryStarts(): void
    {
        $r = self::get('/a?b=1?c=2');
        [$server, $get] = $r->superglobals();

        self::assertSame('/a', $r->path(), 'the first ? ends the path');
        self::assertSame('b=1?c=2', $server['QUERY_STRING'], 'every later ? belongs to the query string');
        self::assertSame('1?c=2', $r->query('b'));
        self::assertSame(['b' => '1?c=2'], $get, 'the lazy query() cache and the eager $_GET parse must not diverge');
    }

    public function testAQueryThatIsEmptyOrAbsentLooksTheSameEverywhere(): void
    {
        foreach (['/a?' => '/a', '/a' => '/a', '?x=1' => ''] as $uri => $path) {
            $r = self::get($uri);
            [$server, $get] = $r->superglobals();

            self::assertSame($path, $r->path(), $uri);
            self::assertSame(ltrim(strstr($uri, '?') ?: '', '?'), $server['QUERY_STRING'], $uri);
            self::assertSame($r->query('x'), $get['x'] ?? null, $uri);
        }
    }

    /**
     * `query()` used to be `?string` and cast whatever `parse_str()` produced, so `x[]=…` came back
     * as the literal `Array` with an "Array to string conversion" warning while `$_GET` held the
     * real list. `parse_str()` is the only grammar either side uses, so the two must not diverge:
     * `x[]=` is a list, a plain repeated `x=` is last-wins, and `x[a]=` is a map — PHP's rules, not
     * ours. A `@dataProvider` would hide that the point is the agreement with `$_GET`.
     */
    public function testARepeatedParameterIsTheListThatIsInGet(): void
    {
        foreach (['/a?x[]=1&x[]=2' => ['1', '2'], '/a?x=1&x=2' => '2', '/a?x[a]=1&x[b]=2' => ['a' => '1', 'b' => '2']] as $uri => $expected) {
            $r = self::get($uri);
            [, $get] = $r->superglobals();

            self::assertSame($expected, $r->query('x'), $uri);
            self::assertSame($get['x'], $r->query('x'), $uri . ': query() and $_GET are the same parse');
        }
    }

    public function testASingleValueIsStillAPlainString(): void
    {
        self::assertSame('1', self::get('/a?x=1&y[]=2')->query('x'), 'one scalar stays scalar; only the repeats become lists');
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
