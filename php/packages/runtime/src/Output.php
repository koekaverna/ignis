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
 * `capture()` collects; `captureChunked()` forwards to an `Ignis\Http\Stream` as it goes, which is
 * how a Symfony `StreamedResponse` reaches the client while it is still being produced (V-74).
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
     * Runs `$emit` and forwards its output to a stream as it is written, instead of collecting it.
     *
     * The runtime does the forwarding: `ignis_stream_bind()` binds **this fiber's** output to the
     * response, and `sapi_module.ub_write` frames it. That matters because PHP's own `ob_start()`
     * cannot do it safely — its buffer stack is per **thread**, so a fiber that merely echoes while
     * another is streaming writes into that other buffer, and by the time the handler runs the bytes
     * are already mixed and unattributable. Measured: a second request's `echo` was delivered inside
     * the first one's streamed body (V-76). `ub_write` runs at the moment of the write and knows
     * whose it is, which is the only place the question can still be answered.
     *
     * It also needs no lock, so two streamed responses on one thread no longer serialise.
     *
     * **The trade, stated plainly.** `ub_write` runs inside an internal frame, where a fiber cannot
     * suspend, so it can only `try_send`. While the client keeps up that is the whole story; when it
     * falls behind the bytes accumulate in this fiber's pending buffer and go out at `close()`,
     * where awaiting is legal again. So this path bounds *nothing* if a producer outruns a slow
     * client without ever returning — use `Stream::write()` directly for that, where every write
     * awaits and the back-pressure is exact.
     *
     * **There is no PHP output buffer on this path**, on purpose — that is what closes the hazard.
     * So `ob_flush()` has nothing to flush and raises the notice PHP raises under
     * `output_buffering=0`; an application streaming through Ignis does not need it, because an
     * `echo` already leaves as a frame. `flush()` is harmless and also unnecessary.
     *
     * @param callable():void $emit
     */
    public static function captureChunked(\Ignis\Http\Stream $out, callable $emit, int $chunk = 8192): void
    {
        if (\function_exists('ignis_stream_bind')) {
            \ignis_stream_bind($out->id());
            try {
                $emit();
            } finally {
                $tail = \ignis_stream_unbind();
                if ($tail !== '') {
                    $out->write($tail);
                }
            }

            return;
        }

        // Plain php-cli: no runtime to bind to, so PHP's own buffer layer and the lock it needs.
        while (self::$busy !== null && self::$holder !== \Fiber::getCurrent()) {
            self::$busy->await();
        }
        $done = self::$busy = new Future();
        self::$holder = \Fiber::getCurrent();
        try {
            \ob_start(static function (string $buf) use ($out): string {
                if ($buf !== '') {
                    $out->write($buf);
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

    /** @var resource|null */
    private static $stdout = null;

    /** Where output goes when no fiber is capturing it. Opened once. */
    private static function stdout(): mixed
    {
        return self::$stdout ??= (\defined('STDOUT') ? \STDOUT : \fopen('php://stdout', 'w'));
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
