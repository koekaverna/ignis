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
 * The wire is **protojson — core's own documents**, not a schema of this package's invention. The
 * host hands over a `WorkflowActivation` exactly as core produced it and takes back a
 * `WorkflowActivationCompletion` exactly as core expects it, so every field core has is reachable
 * (retry policies, cancellation types, headers, memo) and a host needs no per-feature code at all.
 * Protojson spells oneofs as their flattened field name, durations as `"5s"`, timestamps as
 * RFC3339 and bytes as base64.
 *
 * No protobuf ever reaches the wire: payloads arrive as `{"metadata":{k:b64},"data":b64}` — which
 * is exactly protojson's `Payload` — and are handed to sdk-php as `Payload` objects built with
 * setters, so the DataConverter still owns decoding without a serialize/parse round trip.
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

/**
 * @phpstan-type Run array{seq: int, bySeq: array<int, array{id: int|string, kind: string}>, byId: array<int|string, array{seq: int, kind: string}>, childStart: array<int, int|string>, queries: list<string>, updates: array<string, string>}
 */
final class CoreCodec implements CodecInterface
{
    private const NS = 1_000_000_000;

    private const ACTIVITY = 'activity';
    private const LOCAL_ACTIVITY = 'local_activity';
    private const TIMER = 'timer';
    private const CHILD = 'child';

    /** @var array<string, Run> */
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

    /** @param array<string, mixed> $headers */
    public function decode(string $batch, array $headers = []): iterable
    {
        $data = \json_decode($batch, true, 512, \JSON_THROW_ON_ERROR);
        if (!\is_array($data)) {
            throw new \LogicException('the core document is not a JSON object');
        }

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
            ? self::json(['taskToken' => $this->taskToken, 'result' => ['failed' => ['failure' => ['message' => $error->getMessage()]]]])
            : self::json(['runId' => $this->runId, 'successful' => ['commands' => [['failWorkflowExecution' => ['failure' => ['message' => $error->getMessage()]]]]]]);
    }

    // ---------------------------------------------------------------- workflow activations

    /**
     * @param  array<array-key, mixed> $act
     * @return list<ServerRequest|SuccessResponse|FailureResponse>
     */
    private function decodeActivation(array $act): array
    {
        $this->runId = $runId = self::required($act['runId'] ?? null, 'runId');
        $info = new TickInfo(
            time: $this->timestamp($act['timestamp'] ?? null),
            historyLength: \max(0, self::intField($act, 'historyLength') ?? 0),
            historySize: \max(0, self::intField($act, 'historySizeBytes') ?? 0),
            continueAsNewSuggested: (bool) ($act['continueAsNewSuggested'] ?? false),
            isReplaying: (bool) ($act['isReplaying'] ?? false),
        );

        $out = [];
        foreach (self::listOfArraysField($act, 'jobs') as $job) {
            // protojson flattens a oneof to its field name, so the job IS its single key.
            $name = \array_key_first($job);
            if ($name === null) {
                continue;
            }
            $d = self::arrayField($job, $name);

            switch ($name) {
                case 'initializeWorkflow':
                    $this->reset($runId);
                    $out[] = new ServerRequest(
                        name: 'StartWorkflow',
                        info: $info,
                        options: ['info' => $this->workflowInfo($runId, $d, $info)],
                        payloads: $this->values(self::payloadListField($d, 'arguments', 'jobs.initializeWorkflow.arguments')),
                        id: $runId,
                    );
                    break;

                case 'fireTimer':
                    $id = $this->takeId($runId, self::intField($d, 'seq') ?? 0);
                    if ($id !== null) {
                        $out[] = new SuccessResponse(null, $id, $info);
                    }
                    break;

                    // Local activities resolve through this job too, with `is_local` set; the seq map
                    // does not care which kind it was.
                case 'resolveActivity':
                    $id = $this->takeId($runId, self::intField($d, 'seq') ?? 0);
                    if ($id !== null) {
                        $out[] = $this->resolution(self::arrayField($d, 'result'), $id, $info);
                    }
                    break;

                case 'resolveChildWorkflowExecutionStart':
                    $seq = self::intField($d, 'seq') ?? 0;
                    $id = $this->runs[$runId]['childStart'][$seq] ?? null;
                    unset($this->runs[$runId]['childStart'][$seq]);
                    if ($id === null) {
                        break;
                    }
                    $succeeded = self::arrayField($d, 'succeeded');
                    $out[] = isset($d['succeeded'])
                        ? new SuccessResponse(EncodedValues::fromValues([[
                            'ID' => self::stringField($succeeded, 'childWorkflowId') ?? '',
                            'RunID' => self::stringField($succeeded, 'runId') ?? '',
                        ]], $this->converter), $id, $info)
                        : new FailureResponse(new \RuntimeException(
                            self::stringField(self::arrayField($d, 'failed'), 'failure')
                                ?? self::stringField(self::arrayField($d, 'cancelled'), 'failure')
                                ?? 'child workflow did not start',
                        ), $id, $info);
                    break;

                case 'resolveChildWorkflowExecution':
                    $id = $this->takeId($runId, self::intField($d, 'seq') ?? 0);
                    if ($id !== null) {
                        $out[] = $this->resolution(self::arrayField($d, 'result'), $id, $info);
                    }
                    break;

                case 'signalWorkflow':
                    $out[] = new ServerRequest(
                        name: 'InvokeSignal',
                        info: $info,
                        options: ['name' => self::stringField($d, 'signalName') ?? '', 'runId' => $runId],
                        payloads: $this->values(self::payloadListField($d, 'input', 'jobs.signalWorkflow.input')),
                        id: $runId,
                    );
                    break;

                case 'queryWorkflow':
                    $this->runs[$runId]['queries'][] = self::stringField($d, 'queryId') ?? '';
                    $out[] = new ServerRequest(
                        name: 'InvokeQuery',
                        info: $info,
                        options: ['name' => self::stringField($d, 'queryType') ?? '', 'runId' => $runId],
                        payloads: $this->values(self::payloadListField($d, 'arguments', 'jobs.queryWorkflow.arguments')),
                        id: $runId,
                    );
                    break;

                case 'doUpdate':
                    $updateId = self::stringField($d, 'id') ?? '';
                    $this->runs[$runId]['updates'][$updateId] = self::stringField($d, 'protocolInstanceId') ?? $updateId;
                    $out[] = new ServerRequest(
                        name: 'InvokeUpdate',
                        info: $info,
                        options: [
                            'updateId' => $updateId,
                            'name' => self::stringField($d, 'name') ?? '',
                            'runId' => $runId,
                            // sdk-php reads this as "skip the validator"; core says so by clearing
                            // run_validator during replay.
                            'replay' => !($d['runValidator'] ?? true),
                        ],
                        payloads: $this->values(self::payloadListField($d, 'input', 'jobs.doUpdate.input')),
                        id: $runId,
                    );
                    break;

                case 'cancelWorkflow':
                    $out[] = new ServerRequest(
                        name: 'CancelWorkflow',
                        info: $info,
                        options: ['runId' => $runId],
                        payloads: $this->values(self::payloadListField($d, 'details', 'jobs.cancelWorkflow.details')),
                        id: $runId,
                    );
                    break;

                case 'removeFromCache':
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

    /**
     * @param  iterable<CommandInterface> $commands
     * @return non-empty-string
     */
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
            $suboptions = self::arrayField($options, 'options');

            switch ($c->getName()) {
                case 'ExecuteActivity':
                    $seq = $this->nextSeq($runId, $c->getID(), self::ACTIVITY);
                    $out[] = ['scheduleActivity' => \array_filter([
                        'seq' => $seq,
                        'activityId' => (string) $seq,
                        'activityType' => self::stringField($options, 'name') ?? '',
                        'taskQueue' => self::stringField($suboptions, 'TaskQueueName') ?? $this->taskQueue,
                        'arguments' => $this->payloads($c->getPayloads()),
                        'startToCloseTimeout' => $this->duration($suboptions['StartToCloseTimeout'] ?? null, 30),
                        'scheduleToCloseTimeout' => $this->duration($suboptions['ScheduleToCloseTimeout'] ?? null),
                        'scheduleToStartTimeout' => $this->duration($suboptions['ScheduleToStartTimeout'] ?? null),
                        'heartbeatTimeout' => $this->duration($suboptions['HeartbeatTimeout'] ?? null),
                        'retryPolicy' => $this->retryPolicy($suboptions['RetryPolicy'] ?? null),
                    ], static fn($v): bool => $v !== null && $v !== [])];
                    break;

                case 'ExecuteLocalActivity':
                    $seq = $this->nextSeq($runId, $c->getID(), self::LOCAL_ACTIVITY);
                    $out[] = ['scheduleLocalActivity' => \array_filter([
                        'seq' => $seq,
                        'activityId' => (string) $seq,
                        'activityType' => self::stringField($options, 'name') ?? '',
                        'arguments' => $this->payloads($c->getPayloads()),
                        'startToCloseTimeout' => $this->duration($suboptions['StartToCloseTimeout'] ?? null, 30),
                        'scheduleToCloseTimeout' => $this->duration($suboptions['ScheduleToCloseTimeout'] ?? null),
                        'retryPolicy' => $this->retryPolicy($suboptions['RetryPolicy'] ?? null),
                    ], static fn($v): bool => $v !== null && $v !== [])];
                    break;

                case 'NewTimer':
                    $out[] = ['startTimer' => [
                        'seq' => $this->nextSeq($runId, $c->getID(), self::TIMER),
                        'startToFireTimeout' => $this->durationMs(self::intField($options, 'ms') ?? 0),
                    ]];
                    break;

                case 'ExecuteChildWorkflow':
                    $seq = $this->nextSeq($runId, $c->getID(), self::CHILD);
                    $out[] = ['startChildWorkflowExecution' => \array_filter([
                        'seq' => $seq,
                        'workflowId' => self::stringField($suboptions, 'WorkflowID') ?? \uniqid('child-', true),
                        'workflowType' => self::stringField($options, 'name') ?? '',
                        'taskQueue' => self::stringField($suboptions, 'TaskQueueName') ?? $this->taskQueue,
                        'input' => $this->payloads($c->getPayloads()),
                        'workflowExecutionTimeout' => $this->duration($suboptions['WorkflowExecutionTimeout'] ?? null),
                        'workflowRunTimeout' => $this->duration($suboptions['WorkflowRunTimeout'] ?? null),
                        'workflowTaskTimeout' => $this->duration($suboptions['WorkflowTaskTimeout'] ?? null),
                        'retryPolicy' => $this->retryPolicy($suboptions['RetryPolicy'] ?? null),
                    ], static fn($v): bool => $v !== null && $v !== [])];
                    break;

                case 'GetChildWorkflowExecution':
                    // No command: this one is answered when core reports the child has started.
                    // It names the ExecuteChildWorkflow request it belongs to.
                    $seq = $this->runs[$runId]['byId'][self::stringField($options, 'id') ?? '']['seq'] ?? null;
                    if ($seq !== null) {
                        $this->runs[$runId]['childStart'][$seq] = $c->getID();
                    }
                    break;

                case 'Cancel':
                    foreach (self::listOfKeysField($options, 'ids') as $id) {
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
                        $failure === null => ['completeWorkflowExecution' => \array_filter(['result' => $payloads[0] ?? null])],
                        $failure instanceof CanceledFailure => ['cancelWorkflowExecution' => new \stdClass()],
                        default => ['failWorkflowExecution' => ['failure' => ['message' => $failure->getMessage()]]],
                    };
                    break;

                case 'Panic':
                    $out[] = ['failWorkflowExecution' => ['failure' => ['message' => $this->failureOf($c)?->getMessage() ?? 'workflow panic']]];
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

        return self::json(['runId' => $runId, 'successful' => ['commands' => $out]]);
    }

    /**
     * `UpdateValidated`/`UpdateCompleted` -> core's accepted / rejected / completed.
     * @return array<string, mixed>
     */
    private function updateResponse(string $runId, UpdateResponse $c): array
    {
        $updateId = (string) ($c->getOptions()['id'] ?? '');
        $instance = $this->runs[$runId]['updates'][$updateId] ?? $updateId;
        $failure = $c->getFailure();

        if ($failure !== null) {
            return ['updateResponse' => ['protocolInstanceId' => $instance, 'rejected' => ['message' => $failure->getMessage()]]];
        }
        if ($c->getCommand() === UpdateResponse::COMMAND_VALIDATED) {
            return ['updateResponse' => ['protocolInstanceId' => $instance, 'accepted' => new \stdClass()]];
        }

        $values = $c->getPayloads();

        return ['updateResponse' => [
            'protocolInstanceId' => $instance,
            'completed' => ($values === null ? null : ($this->payloads($values)[0] ?? null)) ?? new \stdClass(),
        ]];
    }

    /** @return array<string, mixed> */
    private function queryResult(string $queryId, CommandInterface $c): array
    {
        $failure = $this->failureOf($c);
        if ($failure !== null) {
            return ['respondToQuery' => ['queryId' => $queryId, 'failed' => ['message' => $failure->getMessage()]]];
        }

        $values = \method_exists($c, 'getPayloads') ? $c->getPayloads() : null;

        return ['respondToQuery' => [
            'queryId' => $queryId,
            'succeeded' => \array_filter(['response' => $values === null ? null : ($this->payloads($values)[0] ?? null)]),
        ]];
    }

    /** @return null|array<string, mixed> */
    private function cancelOf(string $runId, int|string $id): ?array
    {
        $entry = $this->runs[$runId]['byId'][$id] ?? null;
        if ($entry === null) {
            return null;
        }

        return match ($entry['kind']) {
            self::TIMER => ['cancelTimer' => ['seq' => $entry['seq']]],
            self::ACTIVITY => ['requestCancelActivity' => ['seq' => $entry['seq']]],
            self::LOCAL_ACTIVITY => ['requestCancelLocalActivity' => ['seq' => $entry['seq']]],
            self::CHILD => ['cancelChildWorkflowExecution' => ['childWorkflowSeq' => $entry['seq'], 'reason' => 'cancelled by the workflow']],
            default => null,
        };
    }

    // ---------------------------------------------------------------- activity tasks

    /**
     * @param  array<array-key, mixed> $task
     * @return list<ServerRequest>
     */
    private function decodeActivityTask(array $task): array
    {
        $this->taskToken = self::stringField($task, 'taskToken');
        if (!isset($task['start'])) {
            return [];   // a cancellation: not translated yet
        }
        $start = self::arrayField($task, 'start');

        $execution = self::arrayField($start, 'workflowExecution');
        $activityId = self::required($start['activityId'] ?? null, 'start.activityId');

        return [new ServerRequest(
            // Core delivers local activities on this same stream, flagged; sdk-php routes them to
            // a different handler, so the flag decides the route name.
            name: ($start['isLocal'] ?? false) ? 'InvokeLocalActivity' : 'InvokeActivity',
            info: new TickInfo(time: $this->timestamp($start['startedTime'] ?? null)),
            options: [
                'info' => [
                    'TaskToken' => $this->tokenBase64(),
                    'ActivityID' => $activityId,
                    'ActivityType' => ['Name' => self::stringField($start, 'activityType') ?? ''],
                    'TaskQueue' => $this->taskQueue,
                    'WorkflowNamespace' => $this->namespace,
                    'WorkflowType' => ['Name' => self::stringField($start, 'workflowType') ?? ''],
                    'WorkflowExecution' => [
                        'ID' => self::stringField($execution, 'workflowId') ?? '',
                        'RunID' => self::stringField($execution, 'runId') ?? '',
                    ],
                    'Attempt' => self::intField($start, 'attempt') ?? 1,
                    'HeartbeatTimeout' => $this->nanos($start['heartbeatTimeout'] ?? null),
                    'ScheduledTime' => $this->rfc3339($start['scheduledTime'] ?? null),
                    'StartedTime' => $this->rfc3339($start['startedTime'] ?? null),
                    'Deadline' => $this->rfc3339($start['scheduledTime'] ?? null),
                ],
            ],
            payloads: $this->values(self::payloadListField($start, 'input', 'start.input')),
            id: $activityId,
        )];
    }

    /**
     * @param  iterable<CommandInterface> $commands
     * @return non-empty-string
     */
    private function encodeActivityResult(iterable $commands): string
    {
        $result = ['completed' => new \stdClass()];
        foreach ($commands as $c) {
            $failure = $this->failureOf($c);
            if ($failure !== null) {
                $result = ['failed' => ['failure' => ['message' => $failure->getMessage()]]];
                break;
            }
            if (\method_exists($c, 'getPayloads')) {
                $payload = $this->payloads($c->getPayloads())[0] ?? null;
                $result = ['completed' => $payload === null ? new \stdClass() : ['result' => $payload]];
            }
        }

        return self::json(['taskToken' => $this->taskToken, 'result' => $result]);
    }

    // ---------------------------------------------------------------- helpers

    /**
     * @param  array<string, mixed> $document
     * @return non-empty-string
     */
    private static function json(array $document): string
    {
        return \json_encode($document, \JSON_THROW_ON_ERROR) ?: throw new \JsonException('the completion encoded to an empty document');
    }

    /**
     * Core names every run and every activity, and sdk-php correlates its own requests by that
     * name — so a document that arrives without one would have every request of every run share
     * the empty id. A missing name is a malformed document and fails the task here.
     *
     * @return non-empty-string
     */
    private static function required(mixed $name, string $field): string
    {
        $value = self::stringOrNull($name) ?? '';
        if ($value === '') {
            throw new \LogicException(\sprintf('the core document has no "%s"', $field));
        }

        return $value;
    }

    // ---------------------------------------------------------------- reading a wire document
    //
    // protojson omits a field entirely once it is absent or the zero value, so the wire never
    // distinguishes "not sent" from "sent as the default". Every field below is read through one
    // of these rather than indexed directly: each mirrors the `?? default` a direct read would
    // have used, but only after confirming the value is what it claims to be.

    private static function stringOrNull(mixed $value): ?string
    {
        return \is_string($value) || \is_int($value) ? (string) $value : null;
    }

    private static function intOrNull(mixed $value): ?int
    {
        if (\is_int($value)) {
            return $value;
        }

        return \is_string($value) && \is_numeric($value) ? (int) $value : null;
    }

    private static function stringField(mixed $source, int|string $key): ?string
    {
        return self::stringOrNull(\is_array($source) ? ($source[$key] ?? null) : null);
    }

    private static function intField(mixed $source, int|string $key): ?int
    {
        return self::intOrNull(\is_array($source) ? ($source[$key] ?? null) : null);
    }

    /** @return array<array-key, mixed> */
    private static function arrayField(mixed $source, int|string $key): array
    {
        $value = \is_array($source) ? ($source[$key] ?? null) : null;

        return \is_array($value) ? $value : [];
    }

    /** @return list<array<array-key, mixed>> */
    private static function listOfArraysField(mixed $source, int|string $key): array
    {
        $value = \is_array($source) ? ($source[$key] ?? null) : null;
        if (!\is_array($value)) {
            return [];
        }

        $out = [];
        foreach ($value as $item) {
            if (\is_array($item)) {
                $out[] = $item;
            }
        }

        return $out;
    }

    /** @return list<int|string> */
    private static function listOfKeysField(mixed $source, int|string $key): array
    {
        $value = \is_array($source) ? ($source[$key] ?? null) : null;
        if (!\is_array($value)) {
            return [];
        }

        $out = [];
        foreach ($value as $item) {
            if (\is_int($item) || \is_string($item)) {
                $out[] = $item;
            }
        }

        return $out;
    }

    /**
     * A payload is core's own `{metadata, data}` document, and a list of them is core's own
     * encoding of a `Payloads` message; unlike a scalar field, that shape is never optional-by-
     * omission, so anything else found here is a malformed document rather than a value to default
     * away.
     *
     * @return list<array{metadata?: array<string, string>, data?: string}>
     */
    private static function payloadListField(mixed $source, int|string $key, string $field): array
    {
        $value = \is_array($source) ? ($source[$key] ?? null) : null;
        if ($value === null) {
            return [];
        }
        if (!\is_array($value)) {
            throw new \LogicException(\sprintf('the core document\'s "%s" is not a list of payloads', $field));
        }

        return \array_values(\array_map(
            static fn(mixed $payload): array => self::payloadOf($payload, $field),
            $value,
        ));
    }

    /** @return array{metadata?: array<string, string>, data?: string} */
    private static function payloadOf(mixed $payload, string $field): array
    {
        if (!\is_array($payload)) {
            throw new \LogicException(\sprintf('a payload in "%s" is not an object', $field));
        }

        $out = [];
        if (isset($payload['metadata'])) {
            $out['metadata'] = self::stringMapOf($payload['metadata'], $field);
        }
        if (isset($payload['data'])) {
            if (!\is_string($payload['data'])) {
                throw new \LogicException(\sprintf('the "data" of a payload in "%s" is not a base64 string', $field));
            }
            $out['data'] = $payload['data'];
        }

        return $out;
    }

    /** @return array<string, string> */
    private static function stringMapOf(mixed $map, string $field): array
    {
        if (!\is_array($map)) {
            throw new \LogicException(\sprintf('the metadata of a payload in "%s" is not an object', $field));
        }

        $out = [];
        foreach ($map as $key => $value) {
            if (!\is_string($key) || !\is_string($value)) {
                throw new \LogicException(\sprintf('the metadata of a payload in "%s" has a non-string key or value', $field));
            }
            $out[$key] = $value;
        }

        return $out;
    }

    private function reset(string $runId): void
    {
        $this->runs[$runId] = self::emptyRun();
    }

    /** @return Run */
    private static function emptyRun(): array
    {
        return ['seq' => 0, 'bySeq' => [], 'byId' => [], 'childStart' => [], 'queries' => [], 'updates' => []];
    }

    private function nextSeq(string $runId, int|string $id, string $kind): int
    {
        $this->runs[$runId] ??= self::emptyRun();
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

    /**
     * An ActivityResolution / ChildWorkflowResult -> the response sdk-php is waiting for.
     * @param array<array-key, mixed> $result
     */
    private function resolution(array $result, int|string $id, TickInfo $info): SuccessResponse|FailureResponse
    {
        if (isset($result['completed'])) {
            $payload = self::arrayField($result, 'completed')['result'] ?? null;

            return new SuccessResponse($this->values($payload === null ? [] : [self::payloadOf($payload, 'result')]), $id, $info);
        }

        $kind = \array_key_first($result) ?? 'failed';
        $message = self::stringField(self::arrayField(self::arrayField($result, $kind), 'failure'), 'message')
            ?? ('activity ' . $kind);

        return new FailureResponse(
            $kind === 'cancelled' ? new CanceledFailure($message) : new \RuntimeException($message),
            $id,
            $info,
        );
    }

    /**
     * `StartWorkflow` options are unmarshalled into `Temporal\Workflow\WorkflowInfo`, so these keys
     * are fixed by that class's `#[Marshal]` names, and the timeouts by DateIntervalType's default
     * unit — nanoseconds.
     *
     * @param  array<array-key, mixed> $start the activation's `startWorkflow` job
     * @return array<string, mixed>
     */
    private function workflowInfo(string $runId, array $start, TickInfo $info): array
    {
        return [
            'WorkflowExecution' => ['ID' => self::stringField($start, 'workflowId') ?? '', 'RunID' => $runId],
            'WorkflowType' => ['Name' => self::stringField($start, 'workflowType') ?? ''],
            'TaskQueueName' => $this->taskQueue,
            'Namespace' => $this->namespace,
            'Attempt' => self::intField($start, 'attempt') ?? 1,
            'WorkflowExecutionTimeout' => $this->nanos($start['workflowExecutionTimeout'] ?? null),
            'WorkflowRunTimeout' => $this->nanos($start['workflowRunTimeout'] ?? null),
            'WorkflowTaskTimeout' => $this->nanos($start['workflowTaskTimeout'] ?? null),
            'HistoryLength' => $info->historyLength,
            'HistorySize' => $info->historySize,
            'ShouldContinueAsNew' => $info->continueAsNewSuggested,
            'CronSchedule' => $start['cronSchedule'] ?? null,
            'ContinuedExecutionRunID' => $start['continuedFromExecutionRunId'] ?? null,
            'FirstRunID' => $start['firstExecutionRunId'] ?? $runId,
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
                if (\is_string($k) && \is_string($v)) {
                    $metadata[$k] = \base64_encode($v);
                }
            }
            $out[] = ['metadata' => $metadata, 'data' => \base64_encode($p->getData())];
        }

        return $out;
    }

    private function failureOf(CommandInterface $c): ?\Throwable
    {
        return \method_exists($c, 'getFailure') ? $c->getFailure() : null;
    }

    /** protojson carries `bytes` as base64, which is also what sdk-php's ActivityInfo wants. */
    private function tokenBase64(): string
    {
        return \is_string($this->taskToken) ? $this->taskToken : '';
    }

    /** protojson duration (`"5s"`, `"1.500s"`) -> nanoseconds, the unit sdk-php's marshaller uses. */
    private function nanos(mixed $duration): int
    {
        if (!\is_string($duration) || !\str_ends_with($duration, 's')) {
            return 0;
        }

        return (int) \round(((float) \substr($duration, 0, -1)) * self::NS);
    }

    /** sdk-php marshals every timeout as nanoseconds; protojson wants seconds with a suffix. */
    private function duration(mixed $nanoseconds, ?int $defaultSeconds = null): ?string
    {
        $ns = self::intOrNull($nanoseconds) ?? 0;
        if ($ns <= 0) {
            return $defaultSeconds === null ? null : $defaultSeconds . 's';
        }

        return \rtrim(\rtrim(\sprintf('%.9f', $ns / self::NS), '0'), '.') . 's';
    }

    private function durationMs(int $ms): string
    {
        return \rtrim(\rtrim(\sprintf('%.3f', $ms / 1000), '0'), '.') . 's';
    }

    /**
     * sdk-php's marshalled RetryOptions -> core's temporal.api.common.v1.RetryPolicy.
     * @return null|array<string, mixed>
     */
    private function retryPolicy(mixed $policy): ?array
    {
        if (!\is_array($policy)) {
            return null;
        }

        $out = \array_filter([
            'initialInterval' => $this->duration($policy['InitialInterval'] ?? null),
            'backoffCoefficient' => ($policy['BackoffCoefficient'] ?? 0) ?: null,
            'maximumInterval' => $this->duration($policy['MaximumInterval'] ?? null),
            'maximumAttempts' => ($policy['MaximumAttempts'] ?? 0) ?: null,
            'nonRetryableErrorTypes' => $policy['NonRetryableErrorTypes'] ?? null,
        ], static fn($v): bool => $v !== null && $v !== []);

        return $out === [] ? null : $out;
    }

    /** protojson timestamps are RFC3339 strings. */
    private function timestamp(mixed $ts): \DateTimeImmutable
    {
        if (!\is_string($ts) || $ts === '') {
            return new \DateTimeImmutable();
        }

        try {
            return new \DateTimeImmutable($ts);
        } catch (\Throwable) {
            return new \DateTimeImmutable();
        }
    }

    private function rfc3339(mixed $ts): string
    {
        return $this->timestamp($ts)->format(\DateTimeInterface::RFC3339);
    }
}
