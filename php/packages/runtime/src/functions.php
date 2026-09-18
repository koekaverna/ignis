<?php

/**
 * Free functions of the `Ignis` namespace. PSR-4 cannot autoload a function, so this file is
 * listed in composer.json's `autoload.files` and required by `ignis.php`.
 */
declare(strict_types=1);

namespace Ignis;

/** One wall-clock deadline for the current request, inherited by its Ignis\async children. */
function deadline(int $milliseconds): void
{
    Loop::deadline($milliseconds);
}

/** Non-blocking sleep: the fiber suspends, tokio owns the timer. */
function sleep(int $milliseconds): void
{
    Loop::awaitOp(\ignis_submit_sleep($milliseconds));
}

/** Run $function concurrently in a (pooled) fiber. */
function async(callable $function, mixed ...$arguments): Future
{
    return Loop::spawn($function, ...$arguments);
}

/**
 * Await all futures; returns their values in the same order.
 *
 * @template TKey of array-key
 * @param  iterable<TKey, Future> $futures
 * @return array<TKey, mixed>
 */
function all(iterable $futures): array
{
    $out = [];
    foreach ($futures as $key => $future) {
        $out[$key] = $future->await();
    }
    return $out;
}

/**
 * Sends one frame of the response this fiber is streaming, and waits if the client is behind.
 *
 * Use it instead of `echo` inside a `StreamedResponse` producer. Symfony calls its callback with no
 * arguments, so `echo` is the only channel it offers there — and `echo` can only ever be taken
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
    $streamingSupportedByRuntime = \function_exists('ignis_stream_write');
    if (!$streamingSupportedByRuntime) {
        echo $chunk;
        return;
    }
    $op = \ignis_stream_write($chunk);
    $sentOutright = $op === 0;
    if ($sentOutright) {
        return;
    }
    if ($op < 0) {
        throw new \RuntimeException('Ignis\\write(): this fiber is not streaming a response');
    }
    $result = Loop::awaitOp($op);
    if (\is_array($result)) {
        $message = $result['message'] ?? null;
        throw new \RuntimeException(\is_string($message) ? $message : 'stream write failed');
    }
}

/**
 * Worker mode: serve HTTP forever, one pooled fiber per request.
 *
 * What the handler returns says how the request is answered, so the reader sees it in the
 * signature rather than in a comment:
 *
 * - `Http\Response` — a whole body; the loop sends it;
 * - `Http\StreamedResponse` — a body produced while the client reads (R-STREAM); the loop drives the
 *   producer and ends it;
 * - `null` — answered through another channel entirely, such as a gRPC stream (E10).
 *
 * @param callable(Http\Request):(Http\Response|null) $handler
 */
/**
 * Stops serving: no new requests, the ones in flight finish, and `serve()` returns. Under
 * `--supervise` the thread comes back with a fresh engine — which is how a reload happens.
 */
function stop(): void
{
    Loop::stop();
}

function serve(callable $handler, string $address = '127.0.0.1:8080'): void
{
    Loop::serve($handler, $address);
}
