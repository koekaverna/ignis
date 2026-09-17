<?php

declare(strict_types=1);

namespace Ignis;

/**
 * Capturing output is a thread resource, not a fiber one — so only one fiber may hold it.
 *
 * PHP's output-buffer stack lives in the per-thread executor globals, and `ob_start()` in a fiber
 * pushes onto the same stack every other fiber on that thread writes to. Measured (V-72): with two
 * fibers, one inside `ob_start()` and suspended, the other's `echo` landed in the first one's buffer
 * and came back from its `ob_get_clean()` — `A sees: 'from-Afrom-B'`. In a server that is one
 * response's body appended to another's, which is a disclosure, not a formatting nuisance.
 *
 * `php/packages/runtime/src/classic.php` already states the rule for classic mode ("a classic script
 * must not suspend"). This is the same rule with the enforcement supplied: a fiber that wants the
 * buffer takes it, and any other fiber that wants it **parks** until it is free. Two streamed
 * responses on one thread therefore serialise — which is the honest cost, and much smaller than the
 * bug it replaces.
 *
 * It does not make output streaming: the body is still collected into a string, because
 * `ignis_respond()` takes a whole body and there is no chunked-response op yet. What it does is make
 * the collected body *this request's*.
 */
final class Output
{
    /** Resolved when the fiber holding the buffer lets go. Null means nobody holds it. */
    private static ?Future $busy = null;

    /** The fiber that holds it, so a nested capture in the same fiber does not wait for itself. */
    private static ?\Fiber $holder = null;

    /**
     * Runs `$emit` with an output buffer this fiber owns and returns what it wrote.
     *
     * @param callable():void $emit
     */
    public static function capture(callable $emit): string
    {
        // Nesting inside the fiber that already holds it is safe and must not wait: a fiber cannot
        // interleave with itself, so its buffers always close last-in-first-out.
        if (self::$busy !== null && self::$holder === \Fiber::getCurrent()) {
            return self::buffer($emit);
        }

        // A loop, not an `if`: several fibers can be waiting and only one wins each release.
        while (self::$busy !== null) {
            self::$busy->await();
        }

        $done = self::$busy = new Future();
        self::$holder = \Fiber::getCurrent();
        try {
            return self::buffer($emit);
        } finally {
            self::$busy = null;
            self::$holder = null;
            $done->resolve(null);
        }
    }

    /** @param callable():void $emit */
    private static function buffer(callable $emit): string
    {
        \ob_start();
        try {
            $emit();
        } finally {
            $captured = \ob_get_clean();
        }

        return $captured === false ? '' : $captured;
    }

    /** True while some fiber on this thread holds the buffer. For tests and metrics. */
    public static function isHeld(): bool
    {
        return self::$busy !== null;
    }
}
