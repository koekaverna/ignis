<?php

declare(strict_types=1);

namespace Ignis\Tests\Grpc;

use Ignis\Grpc\Client;
use Ignis\Grpc\StatusException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * `Ignis\Grpc\Client` turns a reactor completion into a message or a status. The completion is
 * data off an h2 channel, never a fact about its shape: `result()`, `resultMessage()` and
 * `streamIdFromJson()` are the boundary, exercised here through reflection since they are the
 * private half of a client whose happy path already needs a running server (E10).
 */
#[CoversClass(Client::class)]
final class ClientTest extends TestCase
{
    public function testResultThrowsAStatusExceptionCarryingTheParsedCode(): void
    {
        try {
            self::clientResult(['kind' => 'error', 'message' => 'code=5 not found']);
            self::fail('an error payload must throw');
        } catch (StatusException $e) {
            self::assertSame(5, $e->status);
            self::assertSame('code=5 not found', $e->getMessage());
        }
    }

    public function testResultFallsBackToAGenericMessageWhenTheErrorHasNone(): void
    {
        try {
            self::clientResult(['kind' => 'error']);
            self::fail('an error payload must throw even with no usable message');
        } catch (StatusException $e) {
            self::assertSame('the reactor reported an error with no message', $e->getMessage());
        }
    }

    public function testResultMessageRejectsAPayloadThatIsNotAString(): void
    {
        $this->expectException(StatusException::class);
        (new \ReflectionMethod(Client::class, 'resultMessage'))->invoke(null, 42);
    }

    public function testStreamIdFromJsonRejectsAMissingStreamField(): void
    {
        $this->expectException(StatusException::class);
        $this->expectExceptionMessage('expected {"stream": int} from the reactor, got {}');
        (new \ReflectionMethod(Client::class, 'streamIdFromJson'))->invoke(null, '{}');
    }

    public function testStreamIdFromJsonRejectsANonIntStreamField(): void
    {
        $this->expectException(StatusException::class);
        (new \ReflectionMethod(Client::class, 'streamIdFromJson'))->invoke(null, '{"stream": "5"}');
    }

    private static function clientResult(mixed $payload): mixed
    {
        return (new \ReflectionMethod(Client::class, 'result'))->invoke(null, $payload);
    }
}
