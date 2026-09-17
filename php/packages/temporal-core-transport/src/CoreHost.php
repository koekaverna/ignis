<?php

/**
 * sdk-php's host connection, backed by an ActivationSource instead of RoadRunner's pipes.
 *
 * One host serves one kind of task. That is not a simplification of RoadRunner's model, it *is*
 * RoadRunner's model — a workflow worker and activity workers are separate processes there — and it
 * is also what keeps `WorkerFactory` safe: the factory accumulates the responses of one dispatch in
 * its own state, so a second batch must never be pulled while the first is still running. Under
 * Ignis the two hosts run as two fibers; concurrency past that comes from more factories, one per
 * fiber, which is the worker pool with fibers instead of processes.
 */

declare(strict_types=1);

namespace Temporal\Worker\Transport\Core;

use Temporal\Worker\Transport\CommandBatch;
use Temporal\Worker\Transport\HostConnectionInterface;

final class CoreHost implements HostConnectionInterface
{
    private int $handled = 0;

    public function __construct(
        private readonly ActivationSource $source,
        private readonly CoreCodec $codec,
        private readonly string $kind = ActivationSource::WORKFLOW,
    ) {}

    public function waitBatch(): ?CommandBatch
    {
        $task = $this->source->poll($this->kind);
        if ($task === null) {
            return null;   // shutting down: ends sdk-php's loop
        }

        $this->codec->forBatch($this->kind);

        // `taskQueue` is what WorkerFactory routes on: without it the factory-level router answers
        // (and knows only GetWorkerInfo) instead of the worker registered for this queue.
        return new CommandBatch($task, ['taskQueue' => $this->source->taskQueue()]);
    }

    /** @param array<string, mixed> $headers */
    public function send(string $frame, array $headers = []): void
    {
        ++$this->handled;
        $this->source->complete($this->kind, $frame);
    }

    public function error(\Throwable $error): void
    {
        $this->source->complete($this->kind, $this->codec->encodeFailure($error));
    }

    public function handled(): int
    {
        return $this->handled;
    }
}
