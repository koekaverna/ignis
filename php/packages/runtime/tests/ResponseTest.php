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
        $response = Response::text("hi\n");
        self::assertSame("hi\n", $response->body);
        self::assertSame(200, $response->status);
    }

    public function testTextCarriesItsStatus(): void
    {
        self::assertSame(404, Response::text('nope', 404)->status);
    }

    public function testJsonEncodesAndSetsTheContentType(): void
    {
        $response = Response::json(['a' => 1, 'b' => [true, null]]);
        self::assertSame('{"a":1,"b":[true,null]}', $response->body);
        self::assertSame('application/json', $response->headers['content-type']);
    }

    /** The two shapes are two types, so the loop dispatches on one and a reader sees which at a glance. */
    public function testAStreamedResponseIsAResponseAndCarriesItsProducer(): void
    {
        $response = new \Ignis\Http\StreamedResponse(static function (): void {
            \Ignis\write('x');
        }, 202, ['content-type' => 'text/plain']);

        self::assertInstanceOf(Response::class, $response, 'serve()\'s contract stays Response|null');
        self::assertInstanceOf(\Closure::class, $response->producer);
        self::assertSame('', $response->body, 'the body is the producer\'s to write');
        self::assertSame(202, $response->status);
        self::assertSame('text/plain', $response->headers['content-type']);
    }

    public function testJsonThrowsRatherThanEmittingHalfADocument(): void
    {
        $invalidUtf8 = ["\xB1\x31"];
        $this->expectException(\JsonException::class);
        Response::json($invalidUtf8);
    }
}
