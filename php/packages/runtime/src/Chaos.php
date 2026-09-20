<?php

declare(strict_types=1);

namespace Ignis;

/**
 * Deliberate non-determinism for the test suites: an extra fiber switch after a completion, and a
 * shuffled dispatch order when more than one op is ready. It finds the bugs that only appear when
 * two fibers interleave in an order the box does not normally produce (research 20, V-33).
 *
 * It lives here rather than in `Loop` because it is the one thing in that file the product never
 * runs: `IGNIS_CHAOS` is a gate tool. `Loop` keeps three lines that ask this class a question —
 * `init()` at boot, `fires()` at the await point, `shuffled()` before dispatch — and none of them
 * needs the scheduler to know how chaos is configured or seeded.
 *
 * A separate loop class was considered and rejected (DECISIONS.md 2026-09-20): `Loop` is entirely
 * static and every call site names it literally, so a `DebugLoop` subclass would share the parent's
 * static storage rather than being a second loop, and selecting it would put a dynamic static call
 * on the hottest path in the runtime to save one short-circuited property read.
 */
final class Chaos
{
    /** Read by `bench/e15-chaos.sh`, which reports them beside the suite's own counters. */
    public static bool $on = false;
    public static float $probability = 0.5;
    public static int $yields = 0;

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
        mt_srand($seed !== '' ? (int) $seed : (int) (hrtime(true) % 2147483647));
    }

    /**
     * Whether this await point should yield once more.
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
        return self::$on && mt_rand() / mt_getrandmax() < self::$probability;
    }

    /**
     * Ready ops in an order the reactor would not have produced. Keys are preserved because the
     * caller dispatches by op id.
     *
     * @param  array<int, mixed> $events
     * @return array<int, mixed>
     */
    public static function shuffled(array $events): array
    {
        $keys = array_keys($events);
        shuffle($keys);
        $shuffled = [];
        foreach ($keys as $key) {
            $shuffled[$key] = $events[$key];
        }

        return $shuffled;
    }
}
