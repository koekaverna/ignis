<?php

declare(strict_types=1);

namespace Ignis\Http;

use Ignis\Loop;

/**
 * The writer a streaming producer is handed: `write()` until done, and the loop ends it.
 *
 * Shaped after the writers PHP already has — AMPHP's `WritableStream` (`write()`, `end()`), PSR-7's
 * `StreamInterface::write()`, Swoole's `$response->write()` — rather than after Symfony's
 * `StreamedResponse`, whose callback gets no arguments and can only `echo`. Both work here: the
 * runtime frames `echo` too (V-76), and `Ignis\write()` is the same call without the object.
 *
 * **Nothing is sent until the first write.** That is what lets a producer which throws before
 * writing anything still become a `500`: the status line has not gone out yet. Once it has, an
 * exception can only truncate the body, which is all HTTP allows.
 *
 * `write()` awaits the runtime, so a client that falls behind parks the producing fiber instead of
 * filling memory. `echo` inside a producer is framed too, but can only be taken optimistically — the
 * write hook runs where a fiber cannot suspend — so it accumulates while a client is behind.
 *
 * Handlers do not construct this or close it; `Ignis\Http\Response::stream()` and the loop do.
 *
 * @internal to the runtime's streaming path
 */
final class Stream
{
    private bool $started = false;
    private bool $closed = false;

    /** @param array<string,string> $headers */
    public function __construct(
        private readonly int $id,
        private readonly int $status = 200,
        private readonly array $headers = [],
    ) {
    }

    /**
     * Appends one frame, parking this fiber until the runtime has taken it.
     *
     * @throws \RuntimeException when the client has hung up — the producer should stop
     */
    public function write(string $chunk): void
    {
        if ($this->closed) {
            throw new \LogicException('write() after the response was closed');
        }
        if ($chunk === '') {
            return;
        }
        $this->start();
        \Ignis\write($chunk);
    }

    /** Sends the status line and headers, and binds this fiber's output. Idempotent. */
    public function start(): void
    {
        if ($this->started) {
            return;
        }
        $this->started = true;
        if (!\ignis_respond_start($this->id, $this->status, $this->headers)) {
            throw new \RuntimeException('cannot stream request ' . $this->id . ': already answered, or the client is gone');
        }
        // From here this fiber's output IS the response: an `echo` leaves as a frame instead of
        // going to the server's stdout and vanishing from the answer.
        \ignis_stream_bind($this->id);
    }

    /** True once the status line has gone out — after that an error can only truncate the body. */
    public function started(): bool
    {
        return $this->started;
    }

    /**
     * Ends the body. Called by the loop; idempotent.
     *
     * Whatever was `echo`ed since the last frame has not gone out yet — the write hook can only try
     * to send — and here, back in PHP, waiting is legal again.
     */
    public function close(): void
    {
        if ($this->closed || !$this->started) {
            $this->closed = true;

            return;
        }
        $this->closed = true;
        $tail = \ignis_stream_unbind();
        if ($tail !== '') {
            $op = \ignis_respond_chunk($this->id, $tail);
            if ($op > 0) {
                Loop::awaitOp($op);
            }
        }
        \ignis_respond_end($this->id);
    }
}
