<?php

declare(strict_types=1);

namespace Ignis\Tests\Temporal;

use Ignis\Temporal\Payloads;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * `Payloads` is the whole wire format of the frozen prototype runtime (ADR-0013, V-19): every
 * workflow argument, activity result and timer payload goes through it, and a replay compares what
 * comes back out. `json/plain` is Temporal's own encoding name, base64 on both halves.
 */
#[CoversClass(Payloads::class)]
final class PayloadsTest extends TestCase
{
    #[DataProvider('values')]
    public function testEncodeAndDecodeRoundTrip(mixed $value): void
    {
        self::assertSame($value, Payloads::decode(Payloads::encode($value)));
    }

    /** @return iterable<string, array{mixed}> */
    public static function values(): iterable
    {
        yield 'null' => [null];
        yield 'false' => [false];
        yield 'zero' => [0];
        yield 'an empty string' => [''];
        yield 'a string' => ['hello'];
        yield 'a float' => [1.5];
        yield 'a list' => [[1, 2, 3]];
        yield 'a map' => [['a' => 1, 'b' => ['c' => true]]];
        yield 'an empty array' => [[]];
        yield 'utf-8' => ['héllo — ünicode'];
    }

    public function testTheEncodingNameIsTemporalsOwnAndBothHalvesAreBase64(): void
    {
        $payload = Payloads::encode(['x' => 1]);

        self::assertSame('json/plain', base64_decode($payload['metadata']['encoding'], true));
        self::assertSame('{"x":1}', base64_decode($payload['data'], true));
    }

    public function testAMissingPayloadIsNullRatherThanAnError(): void
    {
        self::assertNull(Payloads::decode(null), 'a command with no result is normal, not a failure');
        self::assertNull(Payloads::decode([]), 'and so is one whose data never arrived');
        self::assertNull(Payloads::decode(['metadata' => ['encoding' => base64_encode('json/plain')]]));
    }

    public function testAnObjectDecodesAsAnArrayAndNotAsItself(): void
    {
        $encoded = Payloads::encode((object) ['a' => 1]);

        self::assertSame(['a' => 1], Payloads::decode($encoded), 'decode() passes true to json_decode, so nothing comes back as an object');
    }

    public function testSomethingJsonCannotCarryIsRejectedLoudly(): void
    {
        $this->expectException(\JsonException::class);

        Payloads::encode(\NAN);
    }
}
