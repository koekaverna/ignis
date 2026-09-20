<?php

declare(strict_types=1);

namespace Ignis;

use Random\Engine\Mt19937;
use Random\Randomizer;

/**
 * Deliberate non-determinism for the test suites: an extra fiber switch after a completion, and a
 * shuffled order when more than one thing is ready at once. It finds the bugs that only appear when
 * two fibers interleave in an order the box does not normally produce (research 20, V-33).
 *
 * It lives here rather than in `Loop` because it is the one thing in that file the product never
 * runs: `IGNIS_CHAOS` is a gate tool. `Loop` keeps four call sites that ask this class a question
 * and knows nothing about how it is configured, seeded or counted.
 *
 * A separate loop class was considered and rejected (DECISIONS.md 2026-09-20): `Loop` is entirely
 * static and every call site names it literally, so a `DebugLoop` subclass would share the parent's
 * static storage rather than being a second loop, and selecting it would put a dynamic static call
 * on the hottest path in the runtime to save one short-circuited property read.
 */
final class Chaos
{
    /**
     * The probability is compared as a scaled integer rather than with `Randomizer::nextFloat()`,
     * which needs a newer PHP than the analyser's declared floor (`A-PHP-FLOOR`). A millionth is
     * finer than any chaos probability anyone sets.
     */
    private const RESOLUTION = 1_000_000;

    /** Read on the hot path by `Loop`, which short-circuits on it before calling anything here. */
    public static bool $on = false;

    private static float $probability = 0.5;
    private static int $yields = 0;

    /**
     * Its own generator, never the process-wide one. `mt_srand()` here used to reseed the RNG the
     * **application under test** draws from: measured, a script that seeded `mt_srand(42)` and read
     * two values, ran the loop, then read two more got a different continuation under every chaos
     * seed (V-108). A test instrument that silently changes its subject's random values is worse
     * than no instrument, and `shuffle()` drew from the same global.
     */
    private static ?Randomizer $randomizer = null;

    /**
     * `IGNIS_CHAOS_SEED` fixes the sequence the decisions are drawn from. It does **not** make a run
     * reproducible, whatever the reference page used to say: the loop is driven by real timers, so
     * completions arrive in an order that varies anyway and the same seed lands its draws on a
     * different schedule — five runs of one script at seed 1 gave 3, 3, 3, 5, 3 extra yields, and
     * they did so before this class existed too. Unseeded, the clock is used, because a chaos run
     * that cannot be narrowed at all has told you less than it appears to.
     */
    public static function init(): void
    {
        self::$on = Env::flag('IGNIS_CHAOS');
        if (!self::$on) {
            return;
        }
        self::$probability = Env::number('IGNIS_CHAOS_P', self::$probability, 0.0, 1.0);
        $seed = Env::text('IGNIS_CHAOS_SEED');
        self::$randomizer = new Randomizer(new Mt19937($seed !== '' ? (int) $seed : (int) (hrtime(true) % 2147483647)));
    }

    /**
     * Whether this await point should yield once more, counting itself when it says yes so that no
     * caller has to remember to.
     *
     * `Loop` guards the call with `Chaos::$on &&`, so chaos being off costs a property read rather
     * than a function call, and the duplicated check in here is deliberate. That guard is kept by
     * construction rather than by measurement, and the honest version of the measurement is: three
     * runs without it read 4.37-4.90 us against 4.09-4.60 for the old inline code, which looked
     * like the call; six runs each could not tell them apart (4.26-4.73 against 4.12-4.83, means
     * 1.3 % apart inside a 16 % spread). The instrument on this box does not resolve one PHP call
     * per await, so the cheap shape is chosen and not claimed as a win.
     */
    public static function fires(): bool
    {
        if (!self::$on || self::$randomizer === null
            || self::$randomizer->getInt(0, self::RESOLUTION - 1) >= self::$probability * self::RESOLUTION) {
            return false;
        }
        ++self::$yields;

        return true;
    }

    /**
     * The same entries in an order the runtime would not have produced. Keys are preserved because
     * one caller dispatches completions by op id.
     *
     * @template TEntry
     * @param  array<int, TEntry> $entries
     * @return array<int, TEntry>
     */
    public static function shuffled(array $entries): array
    {
        if (self::$randomizer === null) {
            return $entries;
        }
        $shuffled = [];
        foreach (self::$randomizer->shuffleArray(array_keys($entries)) as $key) {
            $shuffled[$key] = $entries[$key];
        }

        return $shuffled;
    }

    /**
     * What a run did, for `bench/e15-chaos.sh` to print beside the suite's own counters. A reader
     * rather than public properties, because `$yields` is this class's to count.
     *
     * @return array{on: bool, probability: float, yields: int}
     */
    public static function report(): array
    {
        return ['on' => self::$on, 'probability' => self::$probability, 'yields' => self::$yields];
    }
}
