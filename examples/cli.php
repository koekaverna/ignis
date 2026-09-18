<?php

/**
 * examples/cli.php — a command-line script that overlaps its own waits.
 *
 *   ignis examples/cli.php                              concurrent, ~1 s
 *   ignis examples/cli.php seq                          sequential control, ~10 s
 *   IGNIS_NO_UNIVERSAL_PARK=1 ignis examples/cli.php    hook off (V-60), ~10 s
 *
 * `sleep()` below is PHP's own blocking sleep — nothing here is an async client library. Inside a
 * fiber its syscall is interposed and the fiber parks (ADR-0020), so ten waits happen at once on
 * one thread. At the top level of the script there is no fiber and the same call blocks the
 * thread, which is the right answer: a single wait has nothing to overlap with.
 *
 * What this does NOT give you: parallel CPU. All ten fibers share one thread, so ten busy loops
 * would still run one after another — that is what `--offload N` or `--threads N` are for.
 */
require __DIR__ . '/../php/packages/runtime/src/ignis.php';

$n = 10;
$t = microtime(true);

if (($argv[1] ?? '') === 'seq') {
    for ($i = 0; $i < $n; $i++) {
        sleep(1);
    }
} else {
    $futures = [];
    for ($i = 0; $i < $n; $i++) {
        $futures[] = Ignis\async(static function () use ($i): int {
            sleep(1);   // unmodified blocking call — parks the fiber, does not block the thread
            return $i;
        });
    }
    printf("finished: %d\n", count(Ignis\all($futures)));
}

printf("%d x sleep(1): %.2f s\n", $n, microtime(true) - $t);
