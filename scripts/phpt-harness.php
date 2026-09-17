<?php
/**
 * E15a fiber-mode harness for php-src .phpt tests (scripts/ignis-php with IGNIS_PHPT_MODE=fiber).
 *
 * Runs the test body inside an Ignis fiber so the tcp:// stream hook and the fiber-switch
 * superglobal observer are active for it, then drives Ignis\Loop until that fiber is done.
 * The test path arrives in IGNIS_PHPT_FILE (the wrapper sets it) so the harness does not
 * depend on $argv.
 *
 * Requires Ignis\Loop::runUntil() to keep polling while a fiber is parked inside a C hook
 * (the `ignis_inflight() === 0` term in its exit test). Without it the loop leaves as soon as
 * its *userland* wait map is empty and every test dies silently at its first socket call.
 * Set IGNIS_PHPT_WATCHDOG=1 to force a 5 ms keep-alive timer instead, for a runtime that
 * does not have that check.
 *
 * Known biases vs {main} mode, measured in docs/research/17-phpt-under-ignis.md:
 *   - the test body executes in *function* scope (an `include` inside a closure), so top-level
 *     `$x` is not a global and `global $x` in a test-defined function sees nothing;
 *   - an uncaught Throwable is re-thrown from {main} after the loop stops, so the message is
 *     preserved but the stack trace carries harness frames instead of `#0 {main}`;
 *   - Fiber::getCurrent() is non-null and Fiber::suspend() is legal inside the test body;
 *   - object ids are shifted (the harness allocates a Fiber/Future/closure first) and --INI--
 *     settings such as fiber.stack_size and open_basedir also apply to the harness itself;
 *   - __FILE__/__DIR__ inside the test are still the test file (include semantics).
 */
declare(strict_types=1);

require __DIR__ . '/../php/packages/runtime/src/ignis.php';

$file = getenv('IGNIS_PHPT_FILE');
if ($file === false || $file === '') {
    fwrite(STDERR, "phpt-harness: IGNIS_PHPT_FILE not set\n");
    exit(2);
}

$done = false;

$future = Ignis\async(static function () use ($file, &$done) {
    try {
        include $file;
    } finally {
        $done = true;
    }
});

if (getenv('IGNIS_PHPT_WATCHDOG')) {
    Ignis\async(static function () use (&$done) {
        while (!$done) {
            Ignis\sleep((int) (getenv('IGNIS_PHPT_TICK_MS') ?: 5));
        }
    });
}

Ignis\Loop::runUntil(static fn (): bool => $done);

// Surface a Throwable the test left unhandled the way the CLI would (as a fatal error),
// instead of letting the pooled fiber swallow it into an unawaited Future.
$future->await();
