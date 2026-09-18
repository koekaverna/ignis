<?php

/**
 * Ignis Temporal runtime (E9, ADR-0013): workflows run as Fibers; every await records a
 * command and suspends; a run's fiber is resumed only by activation jobs, which makes
 * execution deterministic and replayable. Only JSON crosses to Rust (sdk-core).
 *
 * Superseded by ADR-0040 (the official sdk-php on the core transport) and kept as the reference the
 * replay test was built on. It speaks the same protojson as everything else on this boundary: core's
 * own documents, no schema in between.
 */
declare(strict_types=1);

namespace Ignis\Temporal;

use Ignis\Loop;

final class Payloads
{
    private const ENC = 'json/plain';

    /** @return array{metadata: array<string, string>, data: string} */
    public static function encode(mixed $value): array
    {
        return ['metadata' => ['encoding' => base64_encode(self::ENC)], 'data' => base64_encode(json_encode($value, JSON_THROW_ON_ERROR))];
    }

    /** @param array<mixed>|null $payload */
    public static function decode(?array $payload): mixed
    {
        if ($payload === null || !isset($payload['data'])) {
            return null;
        }
        if (!is_string($payload['data'])) {
            throw new \UnexpectedValueException('a payload "data" field must be a base64 string, got ' . get_debug_type($payload['data']));
        }
        return json_decode(base64_decode($payload['data']), true);
    }

    /**
     * Decodes a protojson payload list (`arguments`/`input`): each element is itself a payload
     * object or absent, never a bare scalar off the wire.
     *
     * @return list<mixed>
     */
    public static function decodeList(mixed $payloads): array
    {
        if (!is_array($payloads)) {
            throw new \UnexpectedValueException('a payload list must be an array, got ' . get_debug_type($payloads));
        }
        $decoded = [];
        foreach ($payloads as $payload) {
            if ($payload !== null && !is_array($payload)) {
                throw new \UnexpectedValueException('a payload list element must be an object or null, got ' . get_debug_type($payload));
            }
            $decoded[] = self::decode($payload);
        }
        return $decoded;
    }
}

/** Per-run state: the workflow fiber, pending awaits and the commands recorded since the last completion. */
final class WorkflowRun
{
    /** @var null|\Fiber<mixed,mixed,mixed,mixed> */
    public ?\Fiber $fiber = null;
    public int $seq = 0;
    /** @var array<int,\Fiber<mixed,mixed,mixed,mixed>> seq => fiber waiting for that command's resolution */
    public array $waiting = [];
    /** @var list<array<string, mixed>> commands to send in the next completion */
    public array $commands = [];
    public mixed $result = null;
    public bool $done = false;
    public ?\Throwable $error = null;

    public function __construct(public readonly string $runId, public readonly string $type) {}
}

/** What workflow code sees. All waits go through here; nothing else may suspend a workflow fiber. */
final class Context
{
    public function __construct(private readonly WorkflowRun $run, private readonly string $taskQueue) {}

    /** @param list<mixed> $arguments */
    public function activity(string $type, array $arguments = [], int $startToCloseSec = 30): mixed
    {
        $seq = ++$this->run->seq;
        $this->run->commands[] = ['scheduleActivity' => [
            'seq' => $seq, 'activityId' => (string) $seq, 'activityType' => $type, 'taskQueue' => $this->taskQueue,
            'arguments' => array_map(Payloads::encode(...), $arguments), 'startToCloseTimeout' => $startToCloseSec . 's',
        ]];
        return $this->await($seq);
    }

    public function timer(int $ms): void
    {
        $seq = ++$this->run->seq;
        $this->run->commands[] = ['startTimer' => [
            'seq' => $seq,
            'startToFireTimeout' => rtrim(rtrim(sprintf('%.3f', $ms / 1000), '0'), '.') . 's',
        ]];
        $this->await($seq);
    }

    private function await(int $seq): mixed
    {
        $this->run->waiting[$seq] = \Fiber::getCurrent()
            ?? throw new \LogicException('a workflow can only await inside a fiber');
        return \Fiber::suspend();
    }
}

/**
 * @phpstan-type WorkflowActivation array{runId: string, jobs: list<mixed>, isReplaying?: mixed}
 * @phpstan-type WorkflowActivationCompletion array{runId: string, successful: array{commands: list<array<string, mixed>>}}
 * @phpstan-type ActivityTask array{taskToken: string, start?: mixed}
 */
final class Worker
{
    /** `RemoveFromCache.EvictionReason.NONDETERMINISM` as prost numbers it. */
    private const EVICTION_NONDETERMINISM = 3;

    public static int $activations = 0;
    public static int $evictionErrors = 0;
    public static int $activityTasks = 0;
    /** @var array<string,WorkflowRun> */
    private array $runs = [];

    /**
     * An eviction that means the workflow was rejected, as opposed to routine cache pressure.
     *
     * The comparison is spelling-insensitive on purpose. Until 2026-09-18 this matched
     * 'Nondeterminism' and the wire carried `NONDETERMINISM`, so `bench/e9-temporal.sh`'s negative
     * control -- the one whose whole job is to fail on a mutated history -- had been reporting
     * REPLAY_OK while sdk-core evicted for nondeterminism in the same log. The spelling changed when
     * V-65 replaced our own schema with core's protojson, which renders enums in SCREAMING_SNAKE.
     * A numeric reason is still accepted: protojson emits the name, prost emits the tag.
     */
    private static function isEvictionAnError(mixed $reason): bool
    {
        if (is_int($reason)) {
            return $reason === self::EVICTION_NONDETERMINISM;
        }
        if (!is_string($reason)) {
            return false;
        }
        $normalised = strtoupper(str_replace(['_', '-', ' '], '', $reason));

        return in_array($normalised, ['NONDETERMINISM', 'LANGFAIL', 'FATAL'], true);
    }


    /**
     * @param array<string, callable(Context, mixed...): mixed> $workflows
     * @param array<string, callable(mixed...): mixed> $activities
     */
    public function __construct(private readonly int $worker, private readonly string $taskQueue, private readonly array $workflows, private readonly array $activities) {}

    /** The op's payload as JSON; a reactor error payload becomes a RuntimeException. */
    private static function call(int $opId): string
    {
        return self::resultOf(Loop::awaitOp($opId));
    }

    /** A reactor completion is either the op's own result or `{kind: 'error', message: ...}`; nothing else is valid. */
    private static function resultOf(mixed $completion): string
    {
        if (is_array($completion)) {
            $message = $completion['message'] ?? 'temporal error';
            throw new \RuntimeException(is_string($message) ? $message : 'temporal error');
        }
        if (!is_string($completion)) {
            throw new \RuntimeException('temporal op returned neither a string result nor an error: got ' . get_debug_type($completion));
        }
        return $completion;
    }

    /** The `{"worker": id}` connect/replay result, decoded and validated: a malformed document cannot start a worker. */
    private static function workerId(string $resultJson): int
    {
        $document = json_decode($resultJson, true, 512, JSON_THROW_ON_ERROR);
        $worker = is_array($document) ? ($document['worker'] ?? null) : null;
        if (!is_int($worker)) {
            throw new \RuntimeException('temporal connect did not return a "worker" id');
        }
        return $worker;
    }

    public static function connect(string $url, string $namespace, string $taskQueue): int
    {
        return self::workerId(self::call(\ignis_temporal_connect($url, $namespace, $taskQueue)));
    }

    /** Replay worker over the run's history fetched from $url by Rust (protobuf, no JSON history). */
    public static function replayWorker(string $url, string $workflowId, string $taskQueue): int
    {
        return self::workerId(self::call(\ignis_temporal_replay($url, $workflowId, $taskQueue)));
    }

    /** Runs the workflow-task loop and (unless replaying) the activity loop until the worker shuts down. */
    public function run(bool $replay = false): void
    {
        $workflowLoop = \Ignis\async(fn() => $this->workflowLoop());
        if (!$replay) {
            \Ignis\async(fn() => $this->activityLoop());
        }
        Loop::run();
        $workflowLoop->await();
    }

    private function workflowLoop(): void
    {
        while (true) {
            try {
                $activation = self::decodeActivation(self::call(\ignis_temporal_poll($this->worker)));
            } catch (\RuntimeException $e) {
                fwrite(STDERR, "workflow poll ended: {$e->getMessage()}\n");
                return;
            }
            ++self::$activations;
            fwrite(STDERR, sprintf("activation #%d run=%s jobs=%s replaying=%s\n", self::$activations, $activation['runId'], implode(',', array_map(self::jobKind(...), $activation['jobs'])), var_export($activation['isReplaying'] ?? null, true)));
            try {
                $completion = $this->handleActivation($activation);
                self::call(\ignis_temporal_complete($this->worker, json_encode($completion, JSON_THROW_ON_ERROR)));
                fwrite(STDERR, sprintf("  completed with %d command(s)\n", count($completion['successful']['commands'])));
            } catch (\Throwable $e) {
                fwrite(STDERR, "  activation handling failed: {$e->getMessage()}\n");
                throw $e;
            }
        }
    }

    /**
     * The JSON document sdk-core hands over is trusted only after this: `runId` and `jobs` are the
     * two fields every consumer below indexes without a further check.
     *
     * @return WorkflowActivation
     */
    private static function decodeActivation(string $json): array
    {
        $document = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        $runId = is_array($document) ? ($document['runId'] ?? null) : null;
        $jobs = is_array($document) ? ($document['jobs'] ?? null) : null;
        if (!is_string($runId) || !is_array($jobs) || !array_is_list($jobs)) {
            throw new \RuntimeException('malformed WorkflowActivation: "runId" or "jobs" missing');
        }
        return ['runId' => $runId, 'jobs' => $jobs, 'isReplaying' => $document['isReplaying'] ?? null];
    }

    /** A job's single key, for the activation log line; unlike `handleActivation()` this never throws on a malformed job. */
    private static function jobKind(mixed $job): string
    {
        return is_array($job) ? (string) (array_key_first($job) ?? '?') : '?';
    }

    /**
     * protojson flattens a oneof to its field name, so a job is identified by its one key.
     * @param array<array-key, mixed> $job
     */
    private static function jobDiscriminator(array $job): string
    {
        return (string) array_key_first($job);
    }

    /**
     * Query, signal, update and cancel jobs are out of scope for the prototype and fall through
     * the switch below unhandled.
     *
     * @param  WorkflowActivation $activation
     * @return WorkflowActivationCompletion
     */
    private function handleActivation(array $activation): array
    {
        $runId = $activation['runId'];
        $run = $this->runs[$runId] ?? null;
        $evicted = false;
        foreach ($activation['jobs'] as $job) {
            if (!is_array($job)) {
                throw new \RuntimeException('malformed WorkflowActivation job: not an object');
            }
            $kind = self::jobDiscriminator($job);
            $data = $job[$kind] ?? [];
            if (!is_array($data)) {
                throw new \RuntimeException(sprintf('malformed WorkflowActivation job "%s": not an object', $kind));
            }
            switch ($kind) {
                case 'initializeWorkflow':
                    $workflowType = $data['workflowType'] ?? null;
                    if (!is_string($workflowType)) {
                        throw new \RuntimeException('malformed initializeWorkflow: "workflowType" is not a string');
                    }
                    $run = $this->runs[$runId] = new WorkflowRun($runId, $workflowType);
                    $workflowFunction = $this->workflows[$workflowType] ?? throw new \RuntimeException("unknown workflow {$workflowType}");
                    $context = new Context($run, $this->taskQueue);
                    $arguments = Payloads::decodeList($data['arguments'] ?? []);
                    $run->fiber = new \Fiber(static function () use ($workflowFunction, $context, $arguments, $run): void {
                        try {
                            $run->result = $workflowFunction($context, ...$arguments);
                        } catch (\Throwable $e) {
                            $run->error = $e;
                        }
                        $run->done = true;
                    });
                    $run->fiber->start();
                    break;
                case 'fireTimer':
                    $seq = $data['seq'] ?? null;
                    if (!is_int($seq)) {
                        throw new \RuntimeException('malformed fireTimer: "seq" is not an int');
                    }
                    $this->resume($run, $seq, null);
                    break;
                case 'resolveActivity':
                    $seq = $data['seq'] ?? null;
                    if (!is_int($seq)) {
                        throw new \RuntimeException('malformed resolveActivity: "seq" is not an int');
                    }
                    $result = $data['result'] ?? [];
                    if (!is_array($result)) {
                        throw new \RuntimeException('malformed resolveActivity: "result" is not an object');
                    }
                    $completed = $result['completed'] ?? null;
                    $resultPayload = is_array($completed) ? ($completed['result'] ?? null) : null;
                    if ($resultPayload !== null && !is_array($resultPayload)) {
                        throw new \RuntimeException('malformed resolveActivity: "result.completed.result" is not an object');
                    }
                    $value = Payloads::decode($resultPayload);
                    $this->resume($run, $seq, $value);
                    break;
                case 'removeFromCache':
                    unset($this->runs[$runId]);
                    $evicted = true;
                    $reason = $data['reason'] ?? null;
                    $reasonText = is_scalar($reason) ? (string) $reason : 'Unspecified';
                    $message = $data['message'] ?? '';
                    fwrite(STDERR, sprintf("  evicted: reason=%s %s\n", $reasonText, is_scalar($message) ? $message : ''));
                    if (self::isEvictionAnError($reason)) {
                        self::$evictionErrors++;
                    }
                    break;
            }
        }
        if ($evicted || $run === null) {
            return ['runId' => $runId, 'successful' => ['commands' => []]];
        }
        $commands = $run->commands;
        $run->commands = [];
        if ($run->done) {
            $commands[] = $run->error === null
                ? ['completeWorkflowExecution' => ['result' => Payloads::encode($run->result)]]
                : ['failWorkflowExecution' => ['failure' => ['message' => $run->error->getMessage()]]];
        }
        return ['runId' => $runId, 'successful' => ['commands' => $commands]];
    }

    private function resume(?WorkflowRun $run, int $seq, mixed $value): void
    {
        if ($run === null || !isset($run->waiting[$seq])) {
            return;
        }
        $fiber = $run->waiting[$seq];
        unset($run->waiting[$seq]);
        $fiber->resume($value);
    }

    /** @return ActivityTask */
    private static function decodeActivityTask(string $json): array
    {
        $document = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        $taskToken = is_array($document) ? ($document['taskToken'] ?? null) : null;
        if (!is_string($taskToken)) {
            throw new \RuntimeException('malformed ActivityTask: "taskToken" missing');
        }
        return ['taskToken' => $taskToken, 'start' => $document['start'] ?? null];
    }

    /** The started activity's type, for the task log line; unlike `requireActivityType()` this never throws. */
    private static function startedActivityType(mixed $start): string
    {
        return is_array($start) && is_string($start['activityType'] ?? null) ? $start['activityType'] : '?';
    }

    /** The started activity's type, or a fatal error: dispatch has no fallback to log and move on. */
    private static function requireActivityType(mixed $start): string
    {
        if (!is_array($start) || !is_string($start['activityType'] ?? null)) {
            throw new \RuntimeException('malformed ActivityTask: "start.activityType" is not a string');
        }
        return $start['activityType'];
    }

    private function activityLoop(): void
    {
        while (true) {
            try {
                $task = self::decodeActivityTask(self::call(\ignis_temporal_poll_activity($this->worker)));
            } catch (\RuntimeException $e) {
                fwrite(STDERR, "activity poll ended: {$e->getMessage()}\n");
                return;
            }
            ++self::$activityTasks;
            fwrite(STDERR, sprintf("activity task #%d type=%s\n", self::$activityTasks, self::startedActivityType($task['start'] ?? null)));
            $token = $task['taskToken'];
            \Ignis\async(function () use ($task, $token): void {
                $start = $task['start'] ?? null;
                if ($start === null) {
                    return;
                }
                if (!is_array($start)) {
                    throw new \RuntimeException('malformed ActivityTask: "start" is not an object');
                }
                $activityType = self::requireActivityType($start);
                $activityFunction = $this->activities[$activityType] ?? null;
                try {
                    if ($activityFunction === null) {
                        throw new \RuntimeException("unknown activity {$activityType}");
                    }
                    $result = $activityFunction(...Payloads::decodeList($start['input'] ?? []));
                    $done = ['taskToken' => $token, 'result' => ['completed' => ['result' => Payloads::encode($result)]]];
                } catch (\Throwable $e) {
                    $done = ['taskToken' => $token, 'result' => ['failed' => ['failure' => ['message' => $e->getMessage()]]]];
                }
                self::call(\ignis_temporal_complete_activity($this->worker, json_encode($done, JSON_THROW_ON_ERROR)));
            });
        }
    }
}
