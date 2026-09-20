<?php

declare(strict_types=1);

namespace Ignis\Tests;

use Ignis\Env;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The rules `Loop` used to spell out at each call site, where nothing could reach them. The one
 * that matters most is the last: `IGNIS_LOOP_GC` ships **on** and `IGNIS_CHAOS` ships **off**, and
 * before this they were two hand-written expressions differing by an inverted first clause.
 */
#[CoversClass(Env::class)]
final class EnvTest extends TestCase
{
    private const NAME = 'IGNIS_TEST_ENV_PROBE';

    protected function tearDown(): void
    {
        putenv(self::NAME);
    }

    /** @return iterable<string, array{0: string|null, 1: bool, 2: bool}> */
    public static function flags(): iterable
    {
        yield 'unset takes the default, off'   => [null, false, false];
        yield 'unset takes the default, on'    => [null, true, true];
        yield 'empty is off even when the default is on' => ['', true, false];
        yield 'the string zero is off'         => ['0', true, false];
        yield 'one is on'                      => ['1', false, true];
        yield 'any other word is on'           => ['yes', false, true];
        yield 'the string false is on, because only "" and "0" are off' => ['false', false, true];
    }

    #[DataProvider('flags')]
    public function testFlagIsOnWhenSetToAnythingButEmptyOrZero(?string $value, bool $default, bool $expected): void
    {
        $value === null ? putenv(self::NAME) : putenv(self::NAME . '=' . $value);

        self::assertSame($expected, Env::flag(self::NAME, $default));
    }

    public function testIntegerFloorsAndLeavesTheDefaultForAnythingNotANumber(): void
    {
        putenv(self::NAME . '=7');
        self::assertSame(7, Env::integer(self::NAME, 3));

        putenv(self::NAME . '=-5');
        self::assertSame(0, Env::integer(self::NAME, 3), 'floored at the minimum, which defaults to zero');
        self::assertSame(100, Env::integer(self::NAME, 3, 100), 'and at an explicit one');

        putenv(self::NAME . '=nonsense');
        self::assertSame(3, Env::integer(self::NAME, 3), 'a value that is not a number is not a value');

        putenv(self::NAME);
        self::assertSame(3, Env::integer(self::NAME, 3));
    }

    public function testNumberClampsIntoTheRange(): void
    {
        putenv(self::NAME . '=0.25');
        self::assertSame(0.25, Env::number(self::NAME, 0.5, 0.0, 1.0));

        putenv(self::NAME . '=9');
        self::assertSame(1.0, Env::number(self::NAME, 0.5, 0.0, 1.0));

        putenv(self::NAME . '=-9');
        self::assertSame(0.0, Env::number(self::NAME, 0.5, 0.0, 1.0));

        putenv(self::NAME . '=nonsense');
        self::assertSame(0.5, Env::number(self::NAME, 0.5, 0.0, 1.0));
    }

    public function testCommaListTrimsAndDropsEmptiesAndKeepsTheDefaultForAnEmptyValue(): void
    {
        putenv(self::NAME . '= /_ignis/ , /health ,, ');
        self::assertSame(['/_ignis/', '/health'], Env::commaList(self::NAME, ['/default/']));

        putenv(self::NAME . '=');
        self::assertSame(['/default/'], Env::commaList(self::NAME, ['/default/']), 'empty is an absence for a list');

        putenv(self::NAME);
        self::assertSame(['/default/'], Env::commaList(self::NAME, ['/default/']));
    }

    /** Unlike a list, an empty string is a real answer here — the seed reader relies on telling them apart. */
    public function testTextKeepsAnEmptyValueAndOnlyFallsBackWhenUnset(): void
    {
        putenv(self::NAME . '=');
        self::assertSame('', Env::text(self::NAME, 'fallback'));

        putenv(self::NAME);
        self::assertSame('fallback', Env::text(self::NAME, 'fallback'));
    }
}
