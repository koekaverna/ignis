<?php

/**
 * Does one fiber's output land in another fiber's captured body?
 *
 * PHP's output-buffer stack is per OS thread, so a fiber that suspends inside `ob_start()` keeps the
 * buffer open while every other fiber on that thread writes into it. `Ignis\Output::capture()` makes
 * it exclusive; `IGNIS_RAW_OB=1` runs the same program with a plain `ob_start()` instead, and that
 * arm MUST leak — a control that stops reproducing the defect means the probe stopped measuring.
 *
 *   ignis bench/php/output_isolation.php              → isolated
 *   IGNIS_RAW_OB=1 ignis bench/php/output_isolation.php → leaked
 */

declare(strict_types=1);

require __DIR__ . '/../../php/packages/runtime/src/ignis.php';

$raw = (bool) getenv('IGNIS_RAW_OB');

$capture = static function (callable $emit) use ($raw): string {
    if (!$raw) {
        return Ignis\Output::capture($emit);
    }
    ob_start();
    try {
        $emit();
    } finally {
        $out = ob_get_clean();
    }

    return $out === false ? '' : $out;
};

$results = [];
$body = static function (string $tag, int $ms) use ($capture, &$results): callable {
    return static function () use ($tag, $ms, $capture, &$results): void {
        $results[$tag] = $capture(static function () use ($tag, $ms): void {
            echo $tag, '-start';
            Ignis\sleep($ms);        // parks: the other fiber opens its own buffer here
            echo $tag, '-end';
        });
    };
};

// A opens its buffer first and closes it FIRST, while B's is still open. Nested buffers only
// behave if they close last-in-first-out, and interleaved fibers do not: A's `ob_get_clean()` then
// pops B's buffer and A walks away with B's body.
Ignis\all([Ignis\async($body('A', 10)), Ignis\async($body('B', 120))]);

ksort($results);
$want = ['A' => 'A-startA-end', 'B' => 'B-startB-end'];
$leaked = $results != $want;
printf("mode=%s A=%s B=%s leaked=%s\n", $raw ? 'raw-ob' : 'Output::capture',
    var_export($results['A'] ?? null, true), var_export($results['B'] ?? null, true), $leaked ? 'yes' : 'no');
exit($leaked ? 1 : 0);
