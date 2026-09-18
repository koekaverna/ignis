<?php

/**
 * Ignis side of the core transport (ADR-0040): the ~40 lines that cannot be upstreamed.
 *
 * Everything the translation needs is `ignis/temporal-core-transport` in `php/packages/temporal-core-transport/` —
 * a standalone composer package that knows nothing about Ignis.
 * This file implements its one port over the reactor ops the runtime already exposes
 * (`ignis_temporal_poll`/`complete`, ADR-0013), and starts the workers:
 *
 *   - one workflow factory in one fiber — workflow code never waits on real I/O, so one is enough
 *     and one is required: `WorkerFactory` collects the responses of a dispatch in its own state,
 *     so two batches must never overlap inside one factory;
 *   - N activity factories, one fiber each, so N activities are in flight on one thread. This is
 *     RoadRunner's activity worker pool with fibers instead of processes — the reason an activity
 *     that waits on a database costs a parked fiber here and a whole process there.
 */

declare(strict_types=1);

namespace Ignis\Temporal;

use Ignis\Loop;
use Temporal\Worker\Transport\Core\ActivationSource;
use Temporal\Worker\Transport\Core\HeartbeatSink;
use Temporal\Worker\Transport\Core\CoreWorkerFactory;
use Temporal\Worker\WorkerInterface;

if (!\interface_exists(ActivationSource::class)) {
    // the package's own autoloader is not in play (SDKPHP_VENDOR points at another vendor/)
    foreach (['ActivationSource', 'HeartbeatSink', 'CoreCodec', 'CoreRpc', 'CoreHost', 'CoreWorkerFactory'] as $class) {
        require_once __DIR__ . "/../../temporal-core-transport/src/{$class}.php";
    }
}

final class CoreSource implements ActivationSource, HeartbeatSink
{
    public function __construct(
        private readonly int $worker,
        private readonly string $taskQueue,
        private readonly string $namespace = 'default',
    ) {}

    public static function connect(string $url, string $namespace, string $taskQueue): self
    {
        $worker = self::workerIdOf(self::await(\ignis_temporal_connect($url, $namespace, $taskQueue)));

        return new self($worker, $taskQueue, $namespace);
    }

    /** The `{"worker": id}` connect result, decoded and validated: a malformed document cannot start a worker. */
    private static function workerIdOf(string $resultJson): int
    {
        $document = \json_decode($resultJson, true, 512, \JSON_THROW_ON_ERROR);
        $worker = \is_array($document) ? ($document['worker'] ?? null) : null;
        if (!\is_int($worker)) {
            throw new \RuntimeException('temporal connect did not return a "worker" id');
        }

        return $worker;
    }

    public function poll(string $kind): ?string
    {
        try {
            return self::await($kind === self::ACTIVITY
                ? \ignis_temporal_poll_activity($this->worker)
                : \ignis_temporal_poll($this->worker));
        } catch (\RuntimeException) {
            return null;   // the worker is shutting down; end sdk-php's loop
        }
    }

    public function complete(string $kind, string $json): void
    {
        self::await($kind === self::ACTIVITY
            ? \ignis_temporal_complete_activity($this->worker, $json)
            : \ignis_temporal_complete($this->worker, $json));
    }

    /**
     * Synchronous on purpose: core's `record_activity_heartbeat` only enqueues, so there is nothing
     * to await and no fiber to park. The empty return says "still running" — core reports activity
     * cancellation on the task stream, never through this call.
     */
    public function heartbeat(string $taskToken, array $details): array
    {
        \ignis_temporal_heartbeat($this->worker, \json_encode([
            'task_token' => \array_values(\unpack('C*', $taskToken) ?: []),
            'details' => $details,
        ], \JSON_THROW_ON_ERROR));

        return [];
    }

    public function taskQueue(): string
    {
        return $this->taskQueue;
    }

    public function namespace(): string
    {
        return $this->namespace;
    }

    public function shutdown(): void
    {
        self::await(\ignis_temporal_shutdown($this->worker));
    }

    /** Parks the calling fiber on a reactor op; the thread keeps serving everything else. */
    private static function await(int $opId): string
    {
        return self::resultOf(Loop::awaitOp($opId));
    }

    /** A reactor completion is either the op's own result or `{kind: 'error', message: ...}`; nothing else is valid. */
    private static function resultOf(mixed $completion): string
    {
        if (\is_array($completion)) {
            $message = $completion['message'] ?? 'temporal op failed';
            throw new \RuntimeException(\is_string($message) ? $message : 'temporal op failed');
        }
        if (!\is_string($completion)) {
            throw new \RuntimeException('temporal op returned neither a string result nor an error: got ' . \get_debug_type($completion));
        }

        return $completion;
    }
}

/**
 * Runs a sdk-php worker on Ignis until the source shuts down.
 *
 * @param callable(WorkerInterface): void $register registers workflow types and activity instances
 */
function serve(CoreSource $source, callable $register, int $activityFibers = 8): void
{
    $factory = static function () use ($source, $register): CoreWorkerFactory {
        $f = CoreWorkerFactory::forSource($source);
        $register($f->newWorker($source->taskQueue()));

        return $f;
    };

    $workflows = $factory();
    $main = \Ignis\async(static fn() => $workflows->run($workflows->host($source, ActivationSource::WORKFLOW)));

    for ($i = 0; $i < \max(1, $activityFibers); $i++) {
        $activities = $factory();
        \Ignis\async(static fn() => $activities->run($activities->host($source, ActivationSource::ACTIVITY)));
    }

    Loop::run();
    $main->await();
}
