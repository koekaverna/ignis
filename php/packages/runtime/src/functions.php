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
 * Stops serving: no new requests, the ones in flight finish, and `serve()` returns. Under
 * `--supervise` the thread comes back with a fresh engine — which is how a reload happens.
 */
function stop(): void
{
    Loop::stop();
}

/**
 * Marks the current fiber's blocking calls as accepted for $function (ADR-0043 §5): still
 * counted and reported, but at info instead of failing a strict-mode test or audit.
 */
function allowBlocking(callable $function): mixed
{
    if (!\function_exists('ignis_allow_blocking')) {
        return $function();
    }
    $wasAlreadyAllowed = \ignis_allow_blocking(true);
    try {
        return $function();
    } finally {
        \ignis_allow_blocking($wasAlreadyAllowed);
    }
}

/**
 * Worker mode: serve HTTP forever, one pooled fiber per request.
 * @param callable(Http\Request):(Http\Response|null) $handler
 */
function serve(callable $handler, string $address = '127.0.0.1:8080'): void
{
    Loop::serve($handler, $address);
}
