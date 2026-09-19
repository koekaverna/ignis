<?php

/**
 * A fiber that dies mid-capture must take its buffer with it.
 *
 * `ub_write` keys each fiber's buffers and response binding by its `zend_fiber_context` address, and
 * until 2026-09-19 nothing removed an entry when the fiber was destroyed — the `// SAFETY:` note
 * argued that `ignis_capture_reset()` at request end made a stale key impossible, which is a promise
 * made by PHP code rather than an invariant of the map. The paths that skip it are the interesting
 * ones: a fatal, an unwound cancellation, a fiber simply abandoned. Zend then reuses the address, and
 * the next fiber inherits a live entry — one request's echo in another's body, the class V-72 and
 * V-76 are about.
 *
 * This abandons many capturing fibers, lets them be collected, and then captures normally. Under the
 * defect a later capture eventually carries a dead fiber's bytes.
 *
 *   ignis bench/php/output_abandoned_fiber.php
 */

declare(strict_types=1);

require __DIR__ . '/../../php/packages/runtime/src/ignis.php';

$rounds = (int) (getenv('ROUNDS') ?: 400);
$leaked = [];

for ($round = 0; $round < $rounds; $round++) {
    // A fiber that binds its output to a response and is never resumed: it is destroyed with the
    // binding open. A bogus request id is the point -- the reactor has never heard of it, so anything
    // routed there is dropped, which is how a stale binding makes a later fiber's output vanish.
    $abandoned = new Fiber(static function () use ($round): void {
        \ignis_stream_bind(900000 + $round, 200, []);
        echo "abandoned-{$round}";
        Fiber::suspend();
        \ignis_stream_unbind();
    });
    $abandoned->start();
    unset($abandoned);
    gc_collect_cycles();

    // The live capture runs in a fiber of its own: that is the only way it can land on the context
    // address the abandoned one just freed, which is the whole mechanism under test.
    $own = null;
    $live = new Fiber(static function () use ($round, &$own): void {
        $own = Ignis\Output::capture(static function () use ($round): void {
            echo "live-{$round}";
        });
    });
    $live->start();
    if ($own !== "live-{$round}") {
        $leaked[] = ['round' => $round, 'got' => var_export($own, true)];
    }
}

printf(
    "output_abandoned_fiber rounds=%d leaked=%d%s\n",
    $rounds,
    \count($leaked),
    $leaked === [] ? '' : ' first=' . json_encode($leaked[0], JSON_UNESCAPED_SLASHES),
);
exit($leaked === [] ? 0 : 1);
