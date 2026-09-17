<?php

/**
 * Translates between sdk-core's activation model and sdk-php's command model.
 *
 * The two number things differently, and that is the whole job. sdk-php gives every outgoing
 * request one monotonic **id** and waits for the host to answer that id. sdk-core numbers commands
 * by **seq, per run**, and reports resolutions as activation *jobs*. So this codec keeps, per run,
 * `id -> seq` and `seq -> id`, and turns `resolveActivity{seq}` back into the success or failure
 * response for the id sdk-php used when it asked.
 *
 * No protobuf ever reaches the wire here: payloads arrive as `{"metadata":{k:b64},"data":b64}` and
 * are handed to sdk-php as `Payload` objects built with setters, so the DataConverter still owns
 * decoding (custom converters and codecs keep working) without a serialize/parse round trip.
 *
 * Scope: what sdk-core's Rust side already translates (research 35 §4) — start, activities,
 * timers, completion, failure, eviction, signals. Anything else makes `encode()` throw by name
 * rather than silently drop a command, because a dropped command is a workflow that hangs.
 */

declare(strict_types=1);

namespace Temporal\Worker\Transport\Core;

use Temporal\Api\Common\V1\Payload;
use Temporal\Api\Common\V1\Payloads;
use Temporal\DataConverter\DataConverterInterface;
use Temporal\DataConverter\EncodedValues;
use Temporal\DataConverter\ValuesInterface;
use Temporal\Worker\Transport\Codec\CodecInterface;
use Temporal\Worker\Transport\Command\CommandInterface;
use Temporal\Worker\Transport\Command\RequestInterface;
use Temporal\Worker\Transport\Command\Server\FailureResponse;
use Temporal\Worker\Transport\Command\Server\ServerRequest;
use Temporal\Worker\Transport\Command\Server\SuccessResponse;
use Temporal\Worker\Transport\Command\Server\TickInfo;

final class CoreCodec implements CodecInterface
{
    private const NS = 1_000_000_000;

    /** @var array<string, array{seq:int, bySeq:array<int, int|string>}> run id => correlation state */
    private array $runs = [];

    /** The batch being decoded/encoded right now; set by CoreHost before each dispatch. */
    private string $kind = ActivationSource::WORKFLOW;
    private string $runId = '';
    private mixed $taskToken = null;

    public function __construct(
        private readonly DataConverterInterface $converter,
        private readonly string $taskQueue,
        private readonly string $namespace,
    ) {}

    public function forBatch(string $kind): void
    {
        $this->kind = $kind;
        $this->runId = '';
        $this->taskToken = null;
    }

    public function decode(string $batch, array $headers = []): iterable
    {
        $data = \json_decode($batch, true, 512, \JSON_THROW_ON_ERROR);

        return $this->kind === ActivationSource::ACTIVITY
            ? $this->decodeActivityTask($data)
            : $this->decodeActivation($data);
    }

    public function encode(iterable $commands): string
    {
        return $this->kind === ActivationSource::ACTIVITY
            ? $this->encodeActivityResult($commands)
            : $this->encodeCompletion($commands);
    }

    // ---------------------------------------------------------------- workflow activations

    /** @return list<ServerRequest|SuccessResponse|FailureResponse> */
    private function decodeActivation(array $act): array
    {
        $this->runId = $runId = (string) ($act['run_id'] ?? '');
        $info = new TickInfo(
            time: $this->timestamp($act['timestamp'] ?? null),
            historyLength: (int) ($act['history_length'] ?? 0),
            historySize: (int) ($act['history_size_bytes'] ?? 0),
            continueAsNewSuggested: (bool) ($act['continue_as_new_suggested'] ?? false),
            isReplaying: (bool) ($act['is_replaying'] ?? false),
        );

        $out = [];
        foreach ($act['jobs'] ?? [] as $job) {
            $variant = $job['variant'] ?? [];
            $name = \array_key_first($variant);
            $d = $variant[$name] ?? [];

            switch ($name) {
                case 'InitializeWorkflow':
                    $this->runs[$runId] = ['seq' => 0, 'bySeq' => []];
                    $out[] = new ServerRequest(
                        name: 'StartWorkflow',
                        info: $info,
                        options: ['info' => $this->workflowInfo($runId, $d, $info)],
                        payloads: $this->values($d['arguments'] ?? []),
                        id: $runId,
                    );
                    break;

                case 'FireTimer':
                    $id = $this->takeId($runId, (int) ($d['seq'] ?? 0));
                    if ($id !== null) {
                        $out[] = new SuccessResponse(null, $id, $info);
                    }
                    break;

                case 'ResolveActivity':
                    $id = $this->takeId($runId, (int) ($d['seq'] ?? 0));
                    if ($id === null) {
                        break;
                    }
                    $status = $d['result']['status'] ?? [];
                    if (isset($status['Completed'])) {
                        $result = $status['Completed']['result'] ?? null;
                        $out[] = new SuccessResponse($this->values($result === null ? [] : [$result]), $id, $info);
                    } else {
                        $kind = \array_key_first($status) ?? 'Failed';
                        $message = $status[$kind]['failure']['message'] ?? 'activity ' . \strtolower((string) $kind);
                        $out[] = new FailureResponse(new \RuntimeException($message), $id, $info);
                    }
                    break;

                case 'SignalWorkflow':
                    $out[] = new ServerRequest(
                        name: 'InvokeSignal',
                        info: $info,
                        options: ['name' => $d['signal_name'] ?? '', 'runId' => $runId],
                        payloads: $this->values($d['input'] ?? []),
                        id: $runId,
                    );
                    break;

                case 'RemoveFromCache':
                    unset($this->runs[$runId]);
                    $out[] = new ServerRequest(
                        name: 'DestroyWorkflow',
                        info: $info,
                        options: ['runId' => $runId],
                        id: $runId,
                    );
                    break;

                // UpdateRandomSeed / NotifyHasPatch carry nothing sdk-php acts on here; queries,
                // updates and cancellation are not translated yet (research 35 §5).
            }
        }

        return $out;
    }

    /** @param iterable<CommandInterface> $commands */
    private function encodeCompletion(iterable $commands): string
    {
        $out = [];
        foreach ($commands as $c) {
            if (!$c instanceof RequestInterface) {
                continue;   // acks for StartWorkflow/DestroyWorkflow: core has no counterpart
            }
            $options = $c->getOptions();

            switch ($c->getName()) {
                case 'ExecuteActivity':
                    $out[] = [
                        'cmd' => 'ScheduleActivity',
                        'seq' => $this->nextSeq($this->runId, $c->getID()),
                        'activity_type' => (string) ($options['name'] ?? ''),
                        'task_queue' => (string) ($options['options']['TaskQueueName'] ?? $this->taskQueue),
                        'args' => $this->payloads($c->getPayloads()),
                        'start_to_close_sec' => $this->seconds($options['options']['StartToCloseTimeout'] ?? null, 30),
                    ];
                    break;

                case 'NewTimer':
                    $out[] = [
                        'cmd' => 'StartTimer',
                        'seq' => $this->nextSeq($this->runId, $c->getID()),
                        'ms' => (int) ($options['ms'] ?? 0),
                    ];
                    break;

                case 'CompleteWorkflow':
                    $failure = $this->failureOf($c);
                    $payloads = $this->payloads($c->getPayloads());
                    $out[] = $failure === null
                        ? ['cmd' => 'CompleteWorkflow', 'result' => $payloads[0] ?? null]
                        : ['cmd' => 'FailWorkflow', 'message' => $failure->getMessage()];
                    break;

                case 'Panic':
                    $out[] = ['cmd' => 'FailWorkflow', 'message' => $this->failureOf($c)?->getMessage() ?? 'workflow panic'];
                    break;

                default:
                    // Loud on purpose: a dropped command is a workflow that hangs until its task
                    // timeout, which is a much worse bug to find than this exception.
                    throw new \LogicException(\sprintf(
                        'sdk-php asked the host for "%s"; this core transport does not translate it yet (research 35 §3).',
                        $c->getName(),
                    ));
            }
        }

        return \json_encode(['run_id' => $this->runId, 'commands' => $out], \JSON_THROW_ON_ERROR);
    }

    // ---------------------------------------------------------------- activity tasks

    /** @return list<ServerRequest> */
    private function decodeActivityTask(array $task): array
    {
        $this->taskToken = $task['task_token'] ?? null;
        $start = $task['variant']['Start'] ?? null;
        if ($start === null) {
            return [];   // a cancellation: not translated yet
        }

        $execution = $start['workflow_execution'] ?? [];

        return [new ServerRequest(
            name: 'InvokeActivity',
            info: new TickInfo(time: $this->timestamp($start['started_time'] ?? null)),
            options: [
                'info' => [
                    'TaskToken' => \base64_encode($this->tokenBytes()),
                    'ActivityID' => (string) ($start['activity_id'] ?? ''),
                    'ActivityType' => ['Name' => (string) ($start['activity_type'] ?? '')],
                    'TaskQueue' => $this->taskQueue,
                    'WorkflowNamespace' => $this->namespace,
                    'WorkflowType' => ['Name' => (string) ($start['workflow_type'] ?? '')],
                    'WorkflowExecution' => [
                        'ID' => (string) ($execution['workflow_id'] ?? ''),
                        'RunID' => (string) ($execution['run_id'] ?? ''),
                    ],
                    'Attempt' => (int) ($start['attempt'] ?? 1),
                    'HeartbeatTimeout' => $this->nanos($start['heartbeat_timeout'] ?? null),
                    'ScheduledTime' => $this->rfc3339($start['scheduled_time'] ?? null),
                    'StartedTime' => $this->rfc3339($start['started_time'] ?? null),
                    'Deadline' => $this->rfc3339($start['scheduled_time'] ?? null),
                ],
            ],
            payloads: $this->values($start['input'] ?? []),
            id: (string) ($start['activity_id'] ?? ''),
        )];
    }

    /** @param iterable<CommandInterface> $commands */
    private function encodeActivityResult(iterable $commands): string
    {
        $done = ['task_token' => $this->taskToken, 'result' => null];
        foreach ($commands as $c) {
            $failure = $this->failureOf($c);
            if ($failure !== null) {
                $done['result'] = null;
                $done['failure'] = $failure->getMessage();
                break;
            }
            if (\method_exists($c, 'getPayloads')) {
                $done['result'] = $this->payloads($c->getPayloads())[0] ?? null;
            }
        }

        return \json_encode($done, \JSON_THROW_ON_ERROR);
    }

    /**
     * The loop's last resort: sdk-php threw out of `dispatch()`. Answer the task with a failure
     * rather than nothing, so core fails the run now instead of at its task timeout.
     */
    public function encodeFailure(\Throwable $error): string
    {
        return $this->kind === ActivationSource::ACTIVITY
            ? \json_encode(['task_token' => $this->taskToken, 'result' => null, 'failure' => $error->getMessage()], \JSON_THROW_ON_ERROR)
            : \json_encode(['run_id' => $this->runId, 'commands' => [['cmd' => 'FailWorkflow', 'message' => $error->getMessage()]]], \JSON_THROW_ON_ERROR);
    }

    // ---------------------------------------------------------------- helpers

    private function nextSeq(string $runId, int|string $id): int
    {
        $this->runs[$runId] ??= ['seq' => 0, 'bySeq' => []];
        $seq = ++$this->runs[$runId]['seq'];
        $this->runs[$runId]['bySeq'][$seq] = $id;

        return $seq;
    }

    private function takeId(string $runId, int $seq): int|string|null
    {
        $id = $this->runs[$runId]['bySeq'][$seq] ?? null;
        unset($this->runs[$runId]['bySeq'][$seq]);

        return $id;
    }

    /**
     * `StartWorkflow` options are unmarshalled into `Temporal\Workflow\WorkflowInfo`, so these keys
     * are fixed by that class's `#[Marshal]` names, and the timeouts by DateIntervalType's default
     * unit — nanoseconds.
     */
    private function workflowInfo(string $runId, array $d, TickInfo $info): array
    {
        return [
            'WorkflowExecution' => ['ID' => (string) ($d['workflow_id'] ?? ''), 'RunID' => $runId],
            'WorkflowType' => ['Name' => (string) ($d['workflow_type'] ?? '')],
            'TaskQueueName' => $this->taskQueue,
            'Namespace' => $this->namespace,
            'Attempt' => (int) ($d['attempt'] ?? 1),
            'WorkflowExecutionTimeout' => $this->nanos($d['workflow_execution_timeout'] ?? null),
            'WorkflowRunTimeout' => $this->nanos($d['workflow_run_timeout'] ?? null),
            'WorkflowTaskTimeout' => $this->nanos($d['workflow_task_timeout'] ?? null),
            'HistoryLength' => $info->historyLength,
            'HistorySize' => $info->historySize,
            'ShouldContinueAsNew' => $info->continueAsNewSuggested,
            'CronSchedule' => $d['cron_schedule'] ?? null,
            'ContinuedExecutionRunID' => $d['continued_from_execution_run_id'] ?? null,
            'FirstRunID' => $d['first_execution_run_id'] ?? $runId,
            'OriginalRunID' => $runId,
        ];
    }

    /** @param list<array{metadata?:array<string,string>,data?:string}> $payloads */
    private function values(array $payloads): ValuesInterface
    {
        if ($payloads === []) {
            return EncodedValues::empty();
        }

        $protos = [];
        foreach ($payloads as $p) {
            $metadata = [];
            foreach ($p['metadata'] ?? [] as $k => $v) {
                $metadata[$k] = \base64_decode((string) $v);
            }
            $proto = new Payload();
            $proto->setMetadata($metadata);
            $proto->setData(\base64_decode((string) ($p['data'] ?? '')));
            $protos[] = $proto;
        }

        return EncodedValues::fromPayloads(new Payloads(['payloads' => $protos]), $this->converter);
    }

    /** @return list<array{metadata:array<string,string>,data:string}> */
    private function payloads(ValuesInterface $values): array
    {
        // sdk-php builds its own responses with `EncodedValues::fromValues()` and no converter,
        // and `toPayloads()` then refuses. The converter is ours to supply.
        if ($values instanceof EncodedValues) {
            $values->setDataConverter($this->converter);
        }

        $out = [];
        foreach ($values->toPayloads()->getPayloads() as $p) {
            $metadata = [];
            foreach ($p->getMetadata() as $k => $v) {
                $metadata[$k] = \base64_encode((string) $v);
            }
            $out[] = ['metadata' => $metadata, 'data' => \base64_encode($p->getData())];
        }

        return $out;
    }

    private function failureOf(CommandInterface $c): ?\Throwable
    {
        return \method_exists($c, 'getFailure') ? $c->getFailure() : null;
    }

    private function tokenBytes(): string
    {
        $t = $this->taskToken;
        if (\is_string($t)) {
            return $t;
        }

        return \is_array($t) ? \pack('C*', ...$t) : '';
    }

    /** proto Duration -> nanoseconds, the unit sdk-php's DateIntervalType marshals. */
    private function nanos(mixed $duration): int
    {
        if (!\is_array($duration)) {
            return 0;
        }

        return (int) ($duration['seconds'] ?? 0) * self::NS + (int) ($duration['nanos'] ?? 0);
    }

    private function seconds(mixed $nanoseconds, int $default): int
    {
        $s = \intdiv((int) $nanoseconds, self::NS);

        return $s > 0 ? $s : $default;
    }

    private function timestamp(mixed $ts): \DateTimeImmutable
    {
        $seconds = \is_array($ts) ? (int) ($ts['seconds'] ?? 0) : 0;

        return $seconds > 0 ? new \DateTimeImmutable('@' . $seconds) : new \DateTimeImmutable();
    }

    private function rfc3339(mixed $ts): string
    {
        return $this->timestamp($ts)->format(\DateTimeInterface::RFC3339);
    }
}
