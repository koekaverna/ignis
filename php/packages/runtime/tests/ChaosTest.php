<?php

declare(strict_types=1);

namespace Ignis\Tests;

use Ignis\Chaos;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * The half of chaos that is not the loop: what it decides, what it counts, and whose random numbers
 * it uses. The last one is the reason this file exists — `init()` called `mt_srand()`, so a suite
 * run under chaos drew different application random values than the same suite without it (V-108).
 */
#[CoversClass(Chaos::class)]
final class ChaosTest extends TestCase
{
    protected function tearDown(): void
    {
        putenv('IGNIS_CHAOS');
        putenv('IGNIS_CHAOS_P');
        putenv('IGNIS_CHAOS_SEED');
        Chaos::init();
    }

    private static function start(string $probability, string $seed = '1'): void
    {
        putenv('IGNIS_CHAOS=1');
        putenv('IGNIS_CHAOS_P=' . $probability);
        putenv('IGNIS_CHAOS_SEED=' . $seed);
        Chaos::init();
    }

    public function testInitDoesNotTouchTheRngTheApplicationDrawsFrom(): void
    {
        mt_srand(42);
        $expected = [mt_rand(), mt_rand(), mt_rand()];

        mt_srand(42);
        $first = mt_rand();
        self::start('1.0', '20260920');
        $rest = [mt_rand(), mt_rand()];

        self::assertSame($expected, [$first, ...$rest], "the application's sequence continues untouched");
    }

    public function testFiresAlwaysAtOneAndNeverAtZeroAndCountsWhatItSaidYesTo(): void
    {
        self::start('1.0');
        $before = Chaos::report()['yields'];
        for ($i = 0; $i < 5; $i++) {
            self::assertTrue(Chaos::fires(), 'probability 1 fires at every await point');
        }
        self::assertSame($before + 5, Chaos::report()['yields'], 'and counts itself, so no caller has to');

        self::start('0.0');
        $none = Chaos::report()['yields'];
        for ($i = 0; $i < 5; $i++) {
            self::assertFalse(Chaos::fires());
        }
        self::assertSame($none, Chaos::report()['yields']);
    }

    public function testFiresIsFalseWhenChaosWasNeverTurnedOn(): void
    {
        putenv('IGNIS_CHAOS');
        Chaos::init();

        self::assertFalse(Chaos::$on);
        self::assertFalse(Chaos::fires(), 'and does not reach for a randomizer that was never built');
    }

    public function testShuffledKeepsEveryEntryUnderItsOwnKey(): void
    {
        self::start('1.0');
        $events = [11 => 'a', 22 => 'b', 33 => 'c', 44 => 'd', 55 => 'e'];

        $shuffled = Chaos::shuffled($events);

        $restored = $shuffled;
        ksort($restored);
        self::assertSame($events, $restored, 'every key still carries its own value');
        self::assertCount(5, $shuffled);
        self::assertNotSame(array_keys($events), array_keys($shuffled), 'at seed 1 this order does change');
    }

    /** With chaos off there is no randomizer, and reordering nothing is the right answer. */
    public function testShuffledIsIdentityWhenChaosIsOff(): void
    {
        putenv('IGNIS_CHAOS');
        Chaos::init();

        self::assertSame([1 => 'a', 2 => 'b'], Chaos::shuffled([1 => 'a', 2 => 'b']));
    }
}
