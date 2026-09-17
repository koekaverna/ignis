<?php

/**
 * Free functions of the `Ignis` namespace. PSR-4 cannot autoload a function, so this file is
 * listed in composer.json's `autoload.files` and required by `ignis.php`.
 */
declare(strict_types=1);

namespace Ignis;

/** One wall-clock deadline for the current request, inherited by its Ignis\async children. */
function deadline(int $ms): void
{
    Loop::deadline($ms);
}

/** Non-blocking sleep: the fiber suspends, tokio owns the timer. */
function sleep(int $ms): void
{
    Loop::awaitOp(\ignis_submit_sleep($ms));
}

/** Run $fn concurrently in a (pooled) fiber. */
function async(callable $fn, mixed ...$args): Future
{
    return Loop::spawn($fn, ...$args);
}

/**
 * Await all futures; returns their values in the same order.
 * @param iterable<Future> $futures
 */
function all(iterable $futures): array
{
    $out = [];
    foreach ($futures as $k => $f) {
        $out[$k] = $f->await();
    }
    return $out;
}

/**
 * Worker mode: serve HTTP forever, one pooled fiber per request.
 *
 * What the handler returns says how the request is answered, so the reader sees it in the
 * signature rather than in a comment:
 *
 * - `Http\Response` — a whole body; the loop sends it;
 * - `Http\Stream` — the answer is already flowing (R-STREAM); the loop ends it;
 * - `null` — answered through another channel entirely, such as a gRPC stream (E10).
 *
 * @param callable(Http\Request):(Http\Response|Http\Stream|null) $handler
 */
function serve(callable $handler, string $addr = '127.0.0.1:8080'): void
{
    Loop::serve($handler, $addr);
}
