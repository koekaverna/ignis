<?php

declare(strict_types=1);

namespace Ignis;

/**
 * Everything a fiber writes, and nothing another fiber wrote.
 *
 * Under Ignis this is done by the runtime: `sapi_module.ub_write` is ours, so every `echo` is
 * attributed to `EG(active_fiber)` and lands in that fiber's buffer (`crates/ignis/src/php/output.rs`).
 * There is nothing to lock and nothing to serialise — two streamed responses on one thread capture
 * at the same time.
 *
 * The fallback below is for a PHP-only run (a unit test, an older binary): it uses `ob_start()`,
 * which is a **thread** resource, so it also takes a lock. V-72 measured what happens without one —
 * nested buffers only behave if they close last-in-first-out, interleaved fibers do not, and two
 * responses swapped bodies. The lock is correct and costs serialisation; the native path costs
 * neither.
 *
 * Neither path streams: `ignis_respond()` takes a whole body and there is no chunked response op
 * yet (BACKLOG R-STREAM). What this decides is whose bytes they are, not when they leave.
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
        if (\function_exists('ignis_capture_start')) {
            \ignis_capture_start();
            try {
                $emit();
            } finally {
                $captured = \ignis_capture_take();
            }

            return $captured;
        }

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

    /**
     * Runs `$emit` and forwards its output to a stream in chunks instead of collecting it.
     *
     * This is the one place `ob_start()` is still the right tool: only PHP's own buffer layer has a
     * **callback**, and a callback is what turns "output" into "a chunk to send now". Measured
     * (V-72): a fiber can suspend inside that callback, so `$out->write()` awaits the reactor there
     * and the producer feels the client's back-pressure.
     *
     * The price is the lock. `ob_start()` is a thread resource whatever sits under it, so while one
     * fiber streams this way another must wait — streamed responses on one thread serialise. Ordinary
     * responses are untouched, and `Stream` used directly (no `ob`) does not serialise at all.
     *
     * @param callable():void $emit
     */
    public static function captureChunked(\Ignis\Http\Stream $out, callable $emit, int $chunk = 8192): void
    {
        while (self::$busy !== null && self::$holder !== \Fiber::getCurrent()) {
            self::$busy->await();
        }
        $done = self::$busy = new Future();
        self::$holder = \Fiber::getCurrent();
        try {
            \ob_start(static function (string $buf) use ($out): string {
                if ($buf !== '') {
                    $out->write($buf);      // awaits: this is where a slow client parks the producer
                }

                return '';
            }, \max(1, $chunk));
            try {
                $emit();
            } finally {
                \ob_end_flush();
            }
        } finally {
            self::$busy = null;
            self::$holder = null;
            $done->resolve(null);
        }
    }

    /** True while some fiber on this thread holds the **fallback** buffer. For tests. */
    public static function isHeld(): bool
    {
        return self::$busy !== null;
    }

    /** Request end: drop anything a fiber left behind before it goes back to the pool (V-67). */
    public static function reset(): void
    {
        if (\function_exists('ignis_capture_reset')) {
            \ignis_capture_reset();
        }
    }
}
