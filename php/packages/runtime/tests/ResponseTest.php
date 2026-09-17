<?php

declare(strict_types=1);

namespace Ignis\Tests;

use Ignis\Http\Response;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(Response::class)]
final class ResponseTest extends TestCase
{
    public function testTextDefaultsTo200(): void
    {
        $r = Response::text("hi\n");
        self::assertSame("hi\n", $r->body);
        self::assertSame(200, $r->status);
    }

    public function testTextCarriesItsStatus(): void
    {
        self::assertSame(404, Response::text('nope', 404)->status);
    }

    public function testJsonEncodesAndSetsTheContentType(): void
    {
        $r = Response::json(['a' => 1, 'b' => [true, null]]);
        self::assertSame('{"a":1,"b":[true,null]}', $r->body);
        self::assertSame('application/json', $r->headers['content-type']);
    }

    public function testJsonThrowsRatherThanEmittingHalfADocument(): void
    {
        $this->expectException(\JsonException::class);
        Response::json(["\xB1\x31"]);   // invalid UTF-8
    }

    /** Status 0 is the contract for "answered through another channel" (gRPC, E10). */
    public function testDetachedIsStatusZero(): void
    {
        self::assertSame(0, Response::detached()->status);
    }
}
