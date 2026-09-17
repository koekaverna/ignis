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
 * Sends one frame of the response this fiber is streaming, and waits if the client is behind.
 *
 * Use it instead of `echo` inside a `StreamedResponse` callback. Symfony calls that callback with no
 * arguments, so `echo` is the only channel it offers — and `echo` can only ever be taken
 * optimistically, because the runtime's write hook runs where a fiber cannot suspend. This call
 * can wait, so a slow client parks the producing fiber instead of filling memory:
 *
 *     return new StreamedResponse(function () use ($rows): void {
 *         foreach ($rows as $row) {
 *             Ignis\write($row . "\n");   // parks here when the client is behind
 *         }
 *     });
 *
 * Anything `echo`ed earlier is sent first, so the two can be mixed without reordering the response.
 *
 * @throws \RuntimeException if this fiber is not streaming, or the client has gone
 */
function write(string $chunk): void
{
    if ($chunk === '') {
        return;
    }
    if (!\function_exists('ignis_stream_write')) {
        echo $chunk;   // no runtime to frame it: the ordinary output path
        return;
    }
    $op = \ignis_stream_write($chunk);
    if ($op === 0) {
        return;            // taken outright
    }
    if ($op < 0) {
        throw new \RuntimeException('Ignis\\write(): this fiber is not streaming a response');
    }
    $r = Loop::awaitOp($op);
    if (\is_array($r)) {
        throw new \RuntimeException($r['message'] ?? 'stream write failed');
    }
}

/**
 * Worker mode: serve HTTP forever, one pooled fiber per request.
 *
 * What the handler returns says how the request is answered, so the reader sees it in the
 * signature rather than in a comment:
 *
 * - `Http\Response` — a whole body; the loop sends it;
 * - `Http\Response::stream(...)` — a body produced while the client reads (R-STREAM); the loop
 *   drives the producer and ends it;
 * - `null` — answered through another channel entirely, such as a gRPC stream (E10).
 *
 * @param callable(Http\Request):(Http\Response|null) $handler
 */
function serve(callable $handler, string $addr = '127.0.0.1:8080'): void
{
    Loop::serve($handler, $addr);
}
