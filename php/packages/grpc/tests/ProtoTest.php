<?php

declare(strict_types=1);

namespace Ignis\Tests\Grpc;

use Ignis\Grpc\Proto;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * `Ignis\Grpc\Proto` is the only protobuf codec in the process for anything that does not install
 * google/protobuf, and it decodes bytes that came off the network. It had no test at all.
 *
 * Two defects are pinned here rather than papered over:
 *
 * - `Proto::varint()` does not terminate for a negative integer (`testVarintNeverTerminatesForANegativeInteger`);
 * - `Proto::decode()` reads past the end of a truncated buffer (`testDecodeWalksPastTheEndOfATruncatedVarint`).
 *
 * Both live in `packages/grpc/src/ignis-grpc.php`, which is not this agent's to edit.
 */
#[CoversClass(Proto::class)]
final class ProtoTest extends TestCase
{
    public function testEncodeDecodeRoundTripsTheThreeSupportedKinds(): void
    {
        $fields = [1 => 300, 2 => 'hello', 3 => true];

        self::assertSame([1 => 300, 2 => 'hello', 3 => 1], Proto::decode(Proto::encode($fields)), 'a bool comes back as its varint (1/0): the wire has no bool type');
    }

    public function testRepeatedValuesAreEncodedOnceEachAndTheLastOneWinsOnDecode(): void
    {
        $bytes = Proto::encode([1 => ['a', 'b', 'c']]);

        self::assertSame(Proto::varint(1 << 3 | 2) . Proto::varint(1) . 'a' . Proto::varint(1 << 3 | 2) . Proto::varint(1) . 'b' . Proto::varint(1 << 3 | 2) . Proto::varint(1) . 'c', $bytes);
        self::assertSame([1 => 'c'], Proto::decode($bytes), 'decode() documents that the last one wins');
    }

    #[DataProvider('varintBoundaries')]
    public function testVarintEncodesTheContinuationBoundaries(int $value, string $hex): void
    {
        self::assertSame($hex, bin2hex(Proto::varint($value)));
        self::assertSame([1 => $value], Proto::decode(Proto::varint(1 << 3) . Proto::varint($value)), 'and reads back');
    }

    /** @return iterable<string, array{int, string}> */
    public static function varintBoundaries(): iterable
    {
        yield 'zero is one byte' => [0, '00'];
        yield 'the last single byte' => [127, '7f'];
        yield 'the first two-byte value' => [128, '8001'];
        yield 'the protobuf manual example' => [300, 'ac02'];
        yield 'PHP_INT_MAX is nine bytes' => [\PHP_INT_MAX, 'ffffffffffffffff7f'];
    }

    /**
     * Until 2026-09-17 this killed the worker. PHP's `>>` is arithmetic, so `-1 >> 7 === -1` for
     * ever while the loop appended a byte per iteration, and any field carrying a negative int -- a
     * timestamp delta, an error code, `-1` as a sentinel -- ran the thread out of memory. The fix is
     * a logical shift, and what it emits is protobuf's canonical ten-byte int64.
     */
    public function testANegativeIntegerEncodesAsTenBytesAndRoundTrips(): void
    {
        $encoded = Proto::encode([1 => -1]);

        // One key byte (field 1, wire type 0) plus ten varint bytes: nine continuations, then 0x01.
        self::assertSame(11, \strlen($encoded));
        self::assertSame(-1, Proto::decode($encoded)[1]);
        self::assertSame(\PHP_INT_MIN, Proto::decode(Proto::encode([1 => \PHP_INT_MIN]))[1]);
    }

    public function testDecodeReadsAFixed64Field(): void
    {
        self::assertSame([1 => 'ABCDEFGH'], Proto::decode(\chr(1 << 3 | 1) . 'ABCDEFGH'), 'wire type 1 is eight raw bytes, not a number');
    }

    public function testDecodeReadsAFixed32Field(): void
    {
        self::assertSame([2 => 'WXYZ'], Proto::decode(\chr(2 << 3 | 5) . 'WXYZ'), 'wire type 5 is four raw bytes');
    }

    public function testAnUnsupportedWireTypeIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('unsupported wire type 3');

        Proto::decode(\chr(1 << 3 | 3));
    }

    /**
     * These bytes come off the network, so the end of the message is a case rather than an
     * impossibility. Until 2026-09-17 a truncated varint read past the end of the string and
     * returned a fabricated `[1 => 0]`, with the warning rate chosen by whoever sent the message.
     */
    public function testATruncatedVarintIsRefusedRatherThanInvented(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('truncated varint');

        Proto::decode("\x08\x80");   // field 1, varint, continuation bit set, nothing after it
    }

    /**
     * This test pinned the opposite until 2026-09-18: `substr()` clamps, so a field claiming ten
     * bytes with two present decoded to `'hi'` and the caller had no way to know. The length comes
     * off the same network as the data, so it is input to validate, not a fact — which is what
     * `readVarint()` had already concluded for itself.
     */
    public function testATruncatedLengthDelimitedFieldIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('truncated length-delimited field: 10 bytes claimed, 2 left');

        // Field 2, wire type 2, declared length 10, two bytes present.
        Proto::decode("\x12\x0Ahi");
    }

    public function testAFixedWidthFieldRunningOffTheEndIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        // Field 1, wire type 1 (64-bit), three bytes present.
        Proto::decode("\x09abc");
    }

    public function testAWholeLengthDelimitedFieldStillDecodes(): void
    {
        self::assertSame([2 => 'hello'], Proto::decode("\x12\x05hello"));
    }

    public function testAnEmptyMessageDecodesToNothing(): void
    {
        self::assertSame([], Proto::decode(''));
        self::assertSame('', Proto::encode([]));
    }
}
