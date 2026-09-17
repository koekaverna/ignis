<?php

declare(strict_types=1);

namespace Ignis\Http;

use Ignis\Loop;

/**
 * A response the handler produces while the client is already reading it (R-STREAM).
 *
 * `Ignis\Http\Response` carries a whole body and goes out in one `ignis_respond()`. That is right
 * for almost everything and wrong for an export, a log tail or a server-sent-event feed: the client
 * waits for the last byte before seeing the first, and the runtime holds the whole thing twice.
 *
 * Here the status and headers go out immediately and the body follows as frames. hyper marks it
 * `Transfer-Encoding: chunked`; there is no `Content-Length`, because there is no length yet.
 *
 * **`write()` is where the back-pressure is.** It awaits a reactor op that completes only once the
 * runtime has accepted the chunk, and the runtime cannot accept one while the queue to the socket is
 * full. So a slow client parks the producing fiber — the thread keeps serving every other request,
 * and memory stays at one chunk instead of the whole body. `IGNIS_STREAM_CHUNKS` (default 2) is how
 * many chunks may sit between the two.
 *
 * **Return it.** The handler's return type is the contract: a `Response` is a body the loop sends,
 * a `Stream` is an answer already on its way, and the loop ends it — so there is no `finally
 * { close() }` to remember and no sentinel status to explain.
 *
 *     Ignis\serve(function (Request $r): Response|Stream {
 *         $out = Stream::open($r, 200, ['content-type' => 'text/plain']);
 *         foreach ($rows as $row) {
 *             $out->write($row . "\n");   // parks here when the client is slow
 *         }
 *         return $out;
 *     });
 */
final class Stream
{
    private bool $closed = false;

    private function __construct(private readonly int $id)
    {
    }

    /**
     * Sends the status line and headers, and returns the handle the body is written through.
     *
     * @param array<string,string> $headers
     *
     * @throws \RuntimeException if the request is unknown or already answered
     */
    public static function open(Request $request, int $status = 200, array $headers = []): self
    {
        if (!\ignis_respond_start($request->id, $status, $headers)) {
            throw new \RuntimeException('cannot stream request ' . $request->id . ': already answered, or the client is gone');
        }

        return new self($request->id);
    }

    /**
     * Appends one frame, parking this fiber until the runtime has taken it.
     *
     * While the client keeps up this costs no reactor round trip at all: the chunk goes straight
     * into the channel and `write()` returns. It only parks when the channel is full, which is
     * exactly when the client is behind.
     *
     * @throws \RuntimeException when the client has hung up — the handler should stop producing
     */
    public function write(string $chunk): void
    {
        if ($this->closed) {
            throw new \LogicException('write() after close()');
        }
        if ($chunk === '') {
            return;
        }
        $op = \ignis_respond_chunk($this->id, $chunk);
        if ($op === 0) {
            return;   // there was room: the runtime took it without an op to wait on
        }
        if ($op < 0) {
            $this->closed = true;
            throw new \RuntimeException('stream ' . $this->id . ' is closed: the client is gone');
        }
        // The queue to the socket is full, so this is the back-pressure: park until it drains.
        $r = Loop::awaitOp($op);
        if (\is_array($r)) {
            $this->closed = true;   // the client is gone; there is nowhere to write
            throw new \RuntimeException($r['message'] ?? 'stream write failed');
        }
    }

    /** Ends the body. Idempotent, so it is safe in a `finally`. */
    public function close(): void
    {
        if ($this->closed) {
            return;
        }
        $this->closed = true;
        \ignis_respond_end($this->id);
    }

    /** The request this stream answers; `Output::captureChunked()` binds a fiber's output to it. */
    public function id(): int
    {
        return $this->id;
    }

    public function isClosed(): bool
    {
        return $this->closed;
    }
}
