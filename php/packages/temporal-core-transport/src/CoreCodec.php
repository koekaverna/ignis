<?php

/**
 * Translates between sdk-core's activation model and sdk-php's command model.
 *
 * The two number things differently, and that is the whole job. sdk-php gives every outgoing
 * request one monotonic **id** and waits for the host to answer that id. sdk-core numbers commands
 * by **seq, per run**, and reports resolutions as activation *jobs*. So this codec keeps, per run,
 * `seq -> id` (to resolve) and `id -> seq` (to cancel), plus the kind of each, because cancelling
 * an activity, a local activity, a timer and a child workflow are four different core commands.
 *
 * No protobuf ever reaches the wire here: payloads arrive as `{"metadata":{k:b64},"data":b64}` and
 * are handed to sdk-php as `Payload` objects built with setters, so the DataConverter still owns
 * decoding (custom converters and codecs keep working) without a serialize/parse round trip.
 *
 * Two correlations have no id at all and are handled by name:
 *
 *   - **updates.** `DoUpdate` carries both the update id sdk-php will quote back and the
 *     `protocol_instance_id` core wants in the response. sdk-php only knows the first, so the map
 *     lives here.
 *   - **queries.** Every sdk-php response to a process-aware route carries the *run* id, not a
 *     query id, so several queries in one activation are indistinguishable by id. Core delivers
 *     queries in their own activation and sdk-php answers them in registration order, so they are
 *     matched first-in-first-out — the same thing RoadRunner's protocol does with the same frames.
 */

declare(strict_types=1);

namespace Temporal\Worker\Transport\Core;

use Temporal\Api\Common\V1\Payload;
use Temporal\Api\Common\V1\Payloads;
use Temporal\DataConverter\DataConverterInterface;
use Temporal\DataConverter\EncodedValues;
use Temporal\DataConverter\ValuesInterface;
use Temporal\Exception\Failure\CanceledFailure;
use Temporal\Worker\Transport\Codec\CodecInterface;
use Temporal\Worker\Transport\Command\Client\UpdateResponse;
use Temporal\Worker\Transport\Command\CommandInterface;
use Temporal\Worker\Transport\Command\RequestInterface;
use Temporal\Worker\Transport\Command\Server\FailureResponse;
use Temporal\Worker\Transport\Command\Server\ServerRequest;
use Temporal\Worker\Transport\Command\Server\SuccessResponse;
use Temporal\Worker\Transport\Command\Server\TickInfo;

final class CoreCodec implements CodecInterface
{
    private const NS = 1_000_000_000;

    private const ACTIVITY = 'activity';
    private const LOCAL_ACTIVITY = 'local_activity';
    private const TIMER = 'timer';
    private const CHILD = 'child';

    /** @var array<string, array{seq:int, bySeq:array<int,array{id:int|string,kind:string}>, byId:array<int|string,array{seq:int,kind:string}>, childStart:array<int,int|string>, queries:list<string>, updates:array<string,string>}> */
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
                    $this->reset($runId);
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

                // Local activities resolve through this job too, with `is_local` set; the seq map
                // does not care which kind it was.
                case 'ResolveActivity':
                    $id = $this->takeId($runId, (int) ($d['seq'] ?? 0));
                    if ($id !== null) {
                        $out[] = $this->resolution($d['result'] ?? [], $id, $info);
                    }
                    break;

                case 'ResolveChildWorkflowExecutionStart':
                    $seq = (int) ($d['seq'] ?? 0);
                    $id = $this->runs[$runId]['childStart'][$seq] ?? null;
                    unset($this->runs[$runId]['childStart'][$seq]);
                    if ($id === null) {
                        break;
                    }
                    $status = \array_key_first($d['status'] ?? []) ?? 'Succeeded';
                    $out[] = $status === 'Succeeded'
                        ? new SuccessResponse(EncodedValues::fromValues([[
                            'ID' => $d['status']['Succeeded']['child_workflow_id'] ?? '',
                            'RunID' => $d['status']['Succeeded']['run_id'] ?? '',
                        ]], $this->converter), $id, $info)
                        : new FailureResponse(new \RuntimeException((string) ($d['status'][$status]['failure']['message'] ?? 'child workflow did not start')), $id, $info);
                    break;

                case 'ResolveChildWorkflowExecution':
                    $id = $this->takeId($runId, (int) ($d['seq'] ?? 0));
                    if ($id !== null) {
                        $out[] = $this->resolution($d['result'] ?? [], $id, $info);
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

                case 'QueryWorkflow':
                    $this->runs[$runId]['queries'][] = (string) ($d['query_id'] ?? '');
                    $out[] = new ServerRequest(
                        name: 'InvokeQuery',
                        info: $info,
                        options: ['name' => $d['query_type'] ?? '', 'runId' => $runId],
                        payloads: $this->values($d['arguments'] ?? []),
                        id: $runId,
                    );
                    break;

                case 'DoUpdate':
                    $updateId = (string) ($d['id'] ?? '');
                    $this->runs[$runId]['updates'][$updateId] = (string) ($d['protocol_instance_id'] ?? $updateId);
                    $out[] = new ServerRequest(
                        name: 'InvokeUpdate',
                        info: $info,
                        options: [
                            'updateId' => $updateId,
                            'name' => $d['name'] ?? '',
                            'runId' => $runId,
                            // sdk-php reads this as "skip the validator"; core says so by clearing
                            // run_validator during replay.
                            'replay' => !($d['run_validator'] ?? true),
                        ],
                        payloads: $this->values($d['input'] ?? []),
                        id: $runId,
                    );
                    break;

                case 'CancelWorkflow':
                    $out[] = new ServerRequest(
                        name: 'CancelWorkflow',
                        info: $info,
                        options: ['runId' => $runId],
                        payloads: $this->values($d['details'] ?? []),
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

                // UpdateRandomSeed / NotifyHasPatch carry nothing sdk-php acts on through this
                // transport; Nexus is out of scope.
            }
        }

        return $out;
    }

    /** @param iterable<CommandInterface> $commands */
    private function encodeCompletion(iterable $commands): string
    {
        $runId = $this->runId;
        $out = [];

        foreach ($commands as $c) {
            // Updates answer with a ResponseInterface, not a request, so they must be matched
            // before the request switch — this is exactly what made updates look untranslatable.
            if ($c instanceof UpdateResponse) {
                $out[] = $this->updateResponse($runId, $c);
                continue;
            }

            if (!$c instanceof RequestInterface) {
                // A response to one of our ServerRequests. A query's answer is a real command;
                // acks for StartWorkflow/InvokeSignal/DestroyWorkflow have no counterpart in core.
                // The run's state is already gone after a RemoveFromCache job, and the ack for
                // DestroyWorkflow arrives here — so read through it, never into it.
                if (($this->runs[$runId]['queries'] ?? []) !== []) {
                    $out[] = $this->queryResult((string) \array_shift($this->runs[$runId]['queries']), $c);
                }
                continue;
            }

            $options = $c->getOptions();

            switch ($c->getName()) {
                case 'ExecuteActivity':
                    $out[] = [
                        'cmd' => 'ScheduleActivity',
                        'seq' => $this->nextSeq($runId, $c->getID(), self::ACTIVITY),
                        'activity_type' => (string) ($options['name'] ?? ''),
                        'task_queue' => (string) ($options['options']['TaskQueueName'] ?? $this->taskQueue),
                        'args' => $this->payloads($c->getPayloads()),
                        'start_to_close_sec' => $this->seconds($options['options']['StartToCloseTimeout'] ?? null, 30),
                    ];
                    break;

                case 'ExecuteLocalActivity':
                    $out[] = [
                        'cmd' => 'ScheduleLocalActivity',
                        'seq' => $this->nextSeq($runId, $c->getID(), self::LOCAL_ACTIVITY),
                        'activity_type' => (string) ($options['name'] ?? ''),
                        'args' => $this->payloads($c->getPayloads()),
                        'start_to_close_sec' => $this->seconds($options['options']['StartToCloseTimeout'] ?? null, 30),
                    ];
                    break;

                case 'NewTimer':
                    $out[] = [
                        'cmd' => 'StartTimer',
                        'seq' => $this->nextSeq($runId, $c->getID(), self::TIMER),
                        'ms' => (int) ($options['ms'] ?? 0),
                    ];
                    break;

                case 'ExecuteChildWorkflow':
                    $out[] = [
                        'cmd' => 'StartChildWorkflow',
                        'seq' => $this->nextSeq($runId, $c->getID(), self::CHILD),
                        'workflow_type' => (string) ($options['name'] ?? ''),
                        'workflow_id' => (string) ($options['options']['WorkflowID'] ?? \uniqid('child-', true)),
                        'task_queue' => (string) ($options['options']['TaskQueueName'] ?? $this->taskQueue),
                        'args' => $this->payloads($c->getPayloads()),
                    ];
                    break;

                case 'GetChildWorkflowExecution':
                    // No command: this one is answered when core reports the child has started.
                    // It names the ExecuteChildWorkflow request it belongs to.
                    $seq = $this->runs[$runId]['byId'][$options['id'] ?? '']['seq'] ?? null;
                    if ($seq !== null) {
                        $this->runs[$runId]['childStart'][$seq] = $c->getID();
                    }
                    break;

                case 'Cancel':
                    foreach ($options['ids'] ?? [] as $id) {
                        $cmd = $this->cancelOf($runId, $id);
                        if ($cmd !== null) {
                            $out[] = $cmd;
                        }
                    }
                    break;

                case 'CompleteWorkflow':
                    $failure = $this->failureOf($c);
                    $payloads = $this->payloads($c->getPayloads());
                    $out[] = match (true) {
                        $failure === null => ['cmd' => 'CompleteWorkflow', 'result' => $payloads[0] ?? null],
                        $failure instanceof CanceledFailure => ['cmd' => 'CancelWorkflow'],
                        default => ['cmd' => 'FailWorkflow', 'message' => $failure->getMessage()],
                    };
                    break;

                case 'Panic':
                    $out[] = ['cmd' => 'FailWorkflow', 'message' => $this->failureOf($c)?->getMessage() ?? 'workflow panic'];
                    break;

                default:
                    // Loud on purpose: a dropped command is a workflow that hangs until its task
                    // timeout, which is a much worse bug to find than this exception.
                    throw new \LogicException(\sprintf(
                        'sdk-php asked the host for "%s"; this core transport does not translate it (research 35 §3).',
                        $c->getName(),
                    ));
            }
        }

        return \json_encode(['run_id' => $runId, 'commands' => $out], \JSON_THROW_ON_ERROR);
    }

    /** `UpdateValidated`/`UpdateCompleted` -> core's accepted / rejected / completed. */
    private function updateResponse(string $runId, UpdateResponse $c): array
    {
        $updateId = (string) ($c->getOptions()['id'] ?? '');
        $instance = $this->runs[$runId]['updates'][$updateId] ?? $updateId;
        $failure = $c->getFailure();

        if ($failure !== null) {
            return ['cmd' => 'UpdateRejected', 'protocol_instance_id' => $instance, 'message' => $failure->getMessage()];
        }
        if ($c->getCommand() === UpdateResponse::COMMAND_VALIDATED) {
            return ['cmd' => 'UpdateAccepted', 'protocol_instance_id' => $instance];
        }

        $values = $c->getPayloads();

        return [
            'cmd' => 'UpdateCompleted',
            'protocol_instance_id' => $instance,
            'result' => $values === null ? null : ($this->payloads($values)[0] ?? null),
        ];
    }

    private function queryResult(string $queryId, CommandInterface $c): array
    {
        $failure = $this->failureOf($c);
        if ($failure !== null) {
            return ['cmd' => 'RespondToQuery', 'query_id' => $queryId, 'failure' => $failure->getMessage()];
        }

        $values = \method_exists($c, 'getPayloads') ? $c->getPayloads() : null;

        return [
            'cmd' => 'RespondToQuery',
            'query_id' => $queryId,
            'result' => $values === null ? null : ($this->payloads($values)[0] ?? null),
        ];
    }

    private function cancelOf(string $runId, int|string $id): ?array
    {
        $entry = $this->runs[$runId]['byId'][$id] ?? null;
        if ($entry === null) {
            return null;
        }

        return match ($entry['kind']) {
            self::TIMER => ['cmd' => 'CancelTimer', 'seq' => $entry['seq']],
            self::ACTIVITY => ['cmd' => 'CancelActivity', 'seq' => $entry['seq']],
            self::LOCAL_ACTIVITY => ['cmd' => 'CancelLocalActivity', 'seq' => $entry['seq']],
            self::CHILD => ['cmd' => 'CancelChildWorkflow', 'seq' => $entry['seq'], 'reason' => 'cancelled by the workflow'],
            default => null,
        };
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
            // Core delivers local activities on this same stream, flagged; sdk-php routes them to
            // a different handler, so the flag decides the route name.
            name: ($start['is_local'] ?? false) ? 'InvokeLocalActivity' : 'InvokeActivity',
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

    // ---------------------------------------------------------------- helpers

    private function reset(string $runId): void
    {
        $this->runs[$runId] = ['seq' => 0, 'bySeq' => [], 'byId' => [], 'childStart' => [], 'queries' => [], 'updates' => []];
    }

    private function nextSeq(string $runId, int|string $id, string $kind): int
    {
        isset($this->runs[$runId]) or $this->reset($runId);
        $seq = ++$this->runs[$runId]['seq'];
        $this->runs[$runId]['bySeq'][$seq] = ['id' => $id, 'kind' => $kind];
        $this->runs[$runId]['byId'][$id] = ['seq' => $seq, 'kind' => $kind];

        return $seq;
    }

    private function takeId(string $runId, int $seq): int|string|null
    {
        $entry = $this->runs[$runId]['bySeq'][$seq] ?? null;
        if ($entry === null) {
            return null;
        }
        unset($this->runs[$runId]['bySeq'][$seq], $this->runs[$runId]['byId'][$entry['id']]);

        return $entry['id'];
    }

    /** An ActivityResolution / ChildWorkflowResult -> the response sdk-php is waiting for. */
    private function resolution(array $result, int|string $id, TickInfo $info): SuccessResponse|FailureResponse
    {
        $status = $result['status'] ?? [];
        if (isset($status['Completed'])) {
            $payload = $status['Completed']['result'] ?? null;

            return new SuccessResponse($this->values($payload === null ? [] : [$payload]), $id, $info);
        }

        $kind = \array_key_first($status) ?? 'Failed';
        $message = $status[$kind]['failure']['message'] ?? 'activity ' . \strtolower((string) $kind);

        return new FailureResponse(
            $kind === 'Cancelled' ? new CanceledFailure($message) : new \RuntimeException($message),
            $id,
            $info,
        );
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
