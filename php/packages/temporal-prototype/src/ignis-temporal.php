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

    public static function encode(mixed $value): array
    {
        return ['metadata' => ['encoding' => base64_encode(self::ENC)], 'data' => base64_encode(json_encode($value, JSON_THROW_ON_ERROR))];
    }

    public static function decode(?array $payload): mixed
    {
        if ($payload === null || !isset($payload['data'])) {
            return null;
        }
        return json_decode(base64_decode($payload['data']), true);
    }
}

/** Per-run state: the workflow fiber, pending awaits and the commands recorded since the last completion. */
final class WorkflowRun
{
    public ?\Fiber $fiber = null;
    public int $seq = 0;
    /** @var array<int,\Fiber> seq => fiber waiting for that command's resolution */
    public array $waiting = [];
    /** @var list<array> commands to send in the next completion */
    public array $commands = [];
    public mixed $result = null;
    public bool $done = false;
    public ?\Throwable $error = null;

    public function __construct(public readonly string $runId, public readonly string $type)
    {
    }
}

/** What workflow code sees. All waits go through here; nothing else may suspend a workflow fiber. */
final class Context
{
    public function __construct(private readonly WorkflowRun $run, private readonly string $taskQueue)
    {
    }

    public function activity(string $type, array $args = [], int $startToCloseSec = 30): mixed
    {
        $seq = ++$this->run->seq;
        $this->run->commands[] = ['scheduleActivity' => [
            'seq' => $seq, 'activityId' => (string) $seq, 'activityType' => $type, 'taskQueue' => $this->taskQueue,
            'arguments' => array_map(Payloads::encode(...), $args), 'startToCloseTimeout' => $startToCloseSec . 's',
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
        $this->run->waiting[$seq] = \Fiber::getCurrent();
        return \Fiber::suspend();
    }
}

final class Worker
{
    public static int $activations = 0;
    public static int $evictionErrors = 0;
    public static int $activityTasks = 0;
    /** @var array<string,WorkflowRun> */
    private array $runs = [];

    /**
     * @param array<string, callable(Context, mixed...): mixed> $workflows
     * @param array<string, callable(mixed...): mixed> $activities
     */
    public function __construct(private readonly int $worker, private readonly string $taskQueue, private readonly array $workflows, private readonly array $activities)
    {
    }

    /** @return mixed payload string (JSON) or throws on error */
    private static function call(int $opId): string
    {
        $r = Loop::awaitOp($opId);
        if (\is_array($r)) {
            throw new \RuntimeException($r['message'] ?? 'temporal error');
        }
        return (string) $r;
    }

    public static function connect(string $url, string $namespace, string $taskQueue): int
    {
        return (int) json_decode(self::call(\ignis_temporal_connect($url, $namespace, $taskQueue)), true)['worker'];
    }

    /** Replay worker over the run's history fetched from $url by Rust (protobuf, no JSON history). */
    public static function replayWorker(string $url, string $workflowId, string $taskQueue): int
    {
        return (int) json_decode(self::call(\ignis_temporal_replay($url, $workflowId, $taskQueue)), true)['worker'];
    }

    /** Runs the workflow-task loop and (unless replaying) the activity loop until the worker shuts down. */
    public function run(bool $replay = false): void
    {
        $wf = \Ignis\async(fn () => $this->workflowLoop());
        if (!$replay) {
            \Ignis\async(fn () => $this->activityLoop());
        }
        Loop::run();
        $wf->await();
    }

    private function workflowLoop(): void
    {
        while (true) {
            try {
                $act = json_decode(self::call(\ignis_temporal_poll($this->worker)), true, 512, JSON_THROW_ON_ERROR);
            } catch (\RuntimeException $e) {
                fwrite(STDERR, "workflow poll ended: {$e->getMessage()}\n");
                return;
            }
            ++self::$activations;
            fwrite(STDERR, sprintf("activation #%d run=%s jobs=%s replaying=%s\n", self::$activations, $act['runId'], implode(',', array_map(static fn ($j) => (string) array_key_first($j), $act['jobs'])), var_export($act['isReplaying'] ?? null, true)));
            try {
                $completion = $this->handleActivation($act);
                self::call(\ignis_temporal_complete($this->worker, json_encode($completion, JSON_THROW_ON_ERROR)));
                fwrite(STDERR, sprintf("  completed with %d command(s)\n", count($completion['successful']['commands'])));
            } catch (\Throwable $e) {
                fwrite(STDERR, "  activation handling failed: {$e->getMessage()}\n");
                throw $e;
            }
        }
    }

    private function handleActivation(array $act): array
    {
        $runId = $act['runId'];
        $run = $this->runs[$runId] ?? null;
        $evicted = false;
        foreach ($act['jobs'] as $job) {
            // protojson flattens a oneof to its field name: the job IS its single key.
            $kind = (string) array_key_first($job);
            $data = $job[$kind] ?? [];
            switch ($kind) {
                case 'initializeWorkflow':
                    $run = $this->runs[$runId] = new WorkflowRun($runId, $data['workflowType']);
                    $fn = $this->workflows[$data['workflowType']] ?? throw new \RuntimeException("unknown workflow {$data['workflowType']}");
                    $ctx = new Context($run, $this->taskQueue);
                    $args = array_map(Payloads::decode(...), $data['arguments'] ?? []);
                    $run->fiber = new \Fiber(static function () use ($fn, $ctx, $args, $run): void {
                        try {
                            $run->result = $fn($ctx, ...$args);
                        } catch (\Throwable $e) {
                            $run->error = $e;
                        }
                        $run->done = true;
                    });
                    $run->fiber->start();
                    break;
                case 'fireTimer':
                    $this->resume($run, (int) $data['seq'], null);
                    break;
                case 'resolveActivity':
                    $result = $data['result'] ?? [];
                    $value = isset($result['completed']) ? Payloads::decode($result['completed']['result'] ?? null) : null;
                    $this->resume($run, (int) $data['seq'], $value);
                    break;
                case 'removeFromCache':
                    unset($this->runs[$runId]);
                    $evicted = true;
                    $reason = (string) ($data['reason'] ?? 'Unspecified');
                    fwrite(STDERR, sprintf("  evicted: reason=%s %s\n", $reason, $data['message'] ?? ''));
                    if (in_array($reason, ['Nondeterminism', 'LangFail', 'Fatal'], true) || (int) ($data['reason'] ?? 0) === 3) {
                        self::$evictionErrors++;
                    }
                    break;
                default:
                    // Query/signal/update/cancel are out of scope for the prototype; ignore.
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

    private function activityLoop(): void
    {
        while (true) {
            try {
                $task = json_decode(self::call(\ignis_temporal_poll_activity($this->worker)), true, 512, JSON_THROW_ON_ERROR);
            } catch (\RuntimeException $e) {
                fwrite(STDERR, "activity poll ended: {$e->getMessage()}\n");
                return;
            }
            ++self::$activityTasks;
            fwrite(STDERR, sprintf("activity task #%d type=%s\n", self::$activityTasks, $task['start']['activityType'] ?? '?'));
            $token = $task['taskToken'];
            \Ignis\async(function () use ($task, $token): void {
                $start = $task['start'] ?? null;
                if ($start === null) {
                    return; // cancellations out of scope
                }
                $fn = $this->activities[$start['activityType']] ?? null;
                try {
                    if ($fn === null) {
                        throw new \RuntimeException("unknown activity {$start['activityType']}");
                    }
                    $result = $fn(...array_map(Payloads::decode(...), $start['input'] ?? []));
                    $done = ['taskToken' => $token, 'result' => ['completed' => ['result' => Payloads::encode($result)]]];
                } catch (\Throwable $e) {
                    $done = ['taskToken' => $token, 'result' => ['failed' => ['failure' => ['message' => $e->getMessage()]]]];
                }
                self::call(\ignis_temporal_complete_activity($this->worker, json_encode($done, JSON_THROW_ON_ERROR)));
            });
        }
    }
}
