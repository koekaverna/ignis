<?php

declare(strict_types=1);

namespace Ignis\Tests\Grpc;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * `Ignis\Grpc\router()` turns a method map into an `Ignis\serve()` handler: a gRPC request is
 * answered through the gRPC channel and the handler returns null, anything else goes to the
 * fallback.
 *
 * REGRESSION. When these tests were written the closure was declared `: Response` while two of its
 * paths returned null, so under `declare(strict_types=1)` **every** gRPC request that reached a
 * handler died with
 *
 *     TypeError: {closure:Ignis\Grpc\router():100}(): Return value must be of type
 *                Ignis\Http\Response, null returned
 *
 * *after* the gRPC answer had gone out — and `Loop`'s catch-all then wrote a second answer, an HTTP
 * 500, onto the same stream. The signature is now `: ?Response`;
 * `testEveryGrpcPathAnswersThroughTheChannelAndReturnsNull` is what holds it that way.
 *
 * The dispatch runs in a child process (`tests/fixtures/grpc-router.php`) so that
 * `ignis_grpc_send`/`ignis_grpc_end` can be declared before anything else defines them.
 */
final class RouterTest extends TestCase
{
    public function testANonGrpcContentTypeGetsTheDefault404(): void
    {
        $out = self::dispatch('not-grpc');

        self::assertStringContainsString('returned=Ignis\Http\Response', $out);
        self::assertStringContainsString('status=404', $out);
        self::assertStringContainsString('404 not a gRPC request', $out);
    }

    public function testANonGrpcContentTypeGoesToTheFallbackWhenThereIsOne(): void
    {
        $out = self::dispatch('not-grpc-fallback');

        self::assertStringContainsString('status=200', $out);
        self::assertStringContainsString("body=ok\n", $out);
    }

    public function testAnUnknownMethodEndsTheCallWithUnimplemented(): void
    {
        $out = self::dispatch('unknown-method');

        self::assertStringContainsString('end=7:12:unknown method /pkg.Svc/Nope', $out, 'status 12 is UNIMPLEMENTED');
    }

    public function testAUnaryHandlerSendsItsReplyAndEndsOk(): void
    {
        $out = self::dispatch('unary');

        self::assertStringContainsString('send=7:reply:ping', $out);
        self::assertStringContainsString('end=7:0:', $out, 'status 0 is OK');
    }

    public function testAStatusExceptionBecomesItsOwnCode(): void
    {
        $out = self::dispatch('status-exception');

        self::assertStringContainsString('end=7:3:bad argument', $out, 'status 3 is INVALID_ARGUMENT');
    }

    public function testACancelledExceptionBecomesStatusCancelled(): void
    {
        $out = self::dispatch('cancelled');

        self::assertStringContainsString('end=7:1:cancelled', $out, 'status 1 is CANCELLED');
    }

    /** The return type has to stay `?Response`, or the answer is followed by a second one. */
    #[DataProvider('everyGrpcPath')]
    public function testEveryGrpcPathAnswersThroughTheChannelAndReturnsNull(string $case): void
    {
        $out = self::dispatch($case);

        self::assertStringContainsString('returned=null', $out, 'null is the contract for "answered elsewhere"; a `: Response` return type turns it into a TypeError');
        self::assertStringNotContainsString('threw=', $out);
        self::assertStringContainsString('end=7:', $out, 'and the call really was closed on the gRPC channel');
    }

    /** @return iterable<string, array{string}> */
    public static function everyGrpcPath(): iterable
    {
        yield 'unknown method' => ['unknown-method'];
        yield 'unary reply' => ['unary'];
        yield 'StatusException' => ['status-exception'];
        yield 'CancelledException' => ['cancelled'];
    }

    private static function dispatch(string $case): string
    {
        $fixture = \dirname(__DIR__, 3) . '/tests/fixtures/grpc-router.php';
        $command = escapeshellarg(\PHP_BINARY) . ' ' . escapeshellarg($fixture) . ' ' . escapeshellarg($case) . ' 2>&1';

        return (string) shell_exec($command);
    }
}
