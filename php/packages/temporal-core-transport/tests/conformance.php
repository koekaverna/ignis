<?php

/**
 * Conformance test for the core transport, with no Temporal server and no Ignis: a recorded script
 * of sdk-core activations is fed to a **stock** temporalio/sdk-php worker through CoreHost/CoreCodec,
 * and the completions it produces are asserted. This is the test that has to pass before anything is
 * claimed about the real thing, and the one that would travel with the package if it is upstreamed.
 *
 *   composer install && php tests/conformance.php        (no Temporal server, no particular host)
 *
 * SDKPHP_VENDOR points at the composer vendor/ that holds temporal/sdk.
 */

declare(strict_types=1);

$vendor = \getenv('SDKPHP_VENDOR') ?: \dirname(__DIR__) . '/vendor/autoload.php';
if (!\is_file($vendor)) {
    \fwrite(\STDERR, "sdk-php not installed: set SDKPHP_VENDOR=/path/to/vendor/autoload.php\n");
    exit(2);
}
require $vendor;
if (!\interface_exists(\Temporal\Worker\Transport\Core\ActivationSource::class)) {
    // installed without this package's own autoloader (e.g. SDKPHP_VENDOR points elsewhere)
    foreach (['ActivationSource', 'HeartbeatSink', 'CoreCodec', 'CoreRpc', 'CoreHost', 'CoreWorkerFactory'] as $class) {
        require \dirname(__DIR__) . "/src/{$class}.php";
    }
}

use Temporal\Worker\Transport\Core\ActivationSource;
use Temporal\Worker\Transport\Core\CoreWorkerFactory;

require __DIR__ . '/workflow.php';

/** Plays a recorded script; a host only ever gets the tasks of its own kind, in order. */
final class RecordedSource implements ActivationSource, \Temporal\Worker\Transport\Core\HeartbeatSink
{
    /** @var list<array{token:string,details:array}> */
    public array $heartbeats = [];

    /** @var list<array{0:string,1:array}> */
    private array $script;
    /** @var list<array{0:string,1:array}> */
    public array $completions = [];

    public function __construct(array $script)
    {
        $this->script = $script;
    }

    public function poll(string $kind): ?string
    {
        $head = $this->script[0] ?? null;
        if ($head === null || $head[0] !== $kind) {
            return null;
        }
        \array_shift($this->script);

        return \json_encode($head[1], \JSON_THROW_ON_ERROR);
    }

    public function complete(string $kind, string $json): void
    {
        $this->completions[] = [$kind, \json_decode($json, true, 512, \JSON_THROW_ON_ERROR)];
    }

    public function heartbeat(string $taskToken, array $details): array
    {
        $this->heartbeats[] = ['token' => $taskToken, 'details' => $details];

        return [];
    }

    public function taskQueue(): string { return 'ignis'; }

    public function namespace(): string { return 'default'; }
}

function payload(mixed $value): array
{
    return [
        'metadata' => ['encoding' => \base64_encode('json/plain')],
        'data' => \base64_encode(\json_encode($value, \JSON_THROW_ON_ERROR)),
    ];
}

function job(string $name, array $data): array
{
    return ['variant' => [$name => $data]];
}

$runId = 'run-1';
$activation = static fn(array $jobs, array $extra = []): array => ['run_id' => $runId, 'is_replaying' => false, 'history_length' => 1, 'jobs' => $jobs] + $extra;

$source = new RecordedSource([
    [ActivationSource::WORKFLOW, $activation([job('InitializeWorkflow', [
        'workflow_type' => 'GreetWorkflow',
        'workflow_id' => 'wf-1',
        'arguments' => [payload('Ada')],
        'attempt' => 1,
    ])])],
    [ActivationSource::ACTIVITY, [
        'task_token' => [1, 2, 3],
        'variant' => ['Start' => [
            'activity_type' => 'greet',
            'activity_id' => '1',
            'input' => [payload('Ada')],
            'workflow_execution' => ['workflow_id' => 'wf-1', 'run_id' => $runId],
            'workflow_type' => 'GreetWorkflow',
            'attempt' => 1,
        ]],
    ]],
    [ActivationSource::WORKFLOW, $activation([job('ResolveActivity', [
        'seq' => 1,
        'result' => ['status' => ['Completed' => ['result' => payload('Hello, Ada!')]]],
    ])])],
    [ActivationSource::WORKFLOW, $activation([job('FireTimer', ['seq' => 2])])],
    [ActivationSource::WORKFLOW, $activation([job('RemoveFromCache', ['reason' => 'WorkflowExecutionEnded'])])],
]);

$factory = CoreWorkerFactory::forSource($source);
$worker = $factory->newWorker('ignis');
$worker->registerWorkflowTypes(GreetWorkflow::class);
$worker->registerActivityImplementations(new GreetActivity());

$wf = $factory->host($source, ActivationSource::WORKFLOW);
$act = $factory->host($source, ActivationSource::ACTIVITY);

// Each run() consumes tasks of its own kind until the script's next entry is of the other kind.
$factory->run($wf);
$factory->run($act);
$factory->run($wf);

$failures = 0;
function check(string $what, mixed $got, mixed $want): void
{
    global $failures;
    $ok = $got === $want;
    $failures += $ok ? 0 : 1;
    \printf("%-46s %s\n", $what, $ok ? 'ok' : \sprintf("FAIL\n    got  %s\n    want %s", \json_encode($got), \json_encode($want)));
}

$c = $source->completions;
\printf("completions: %d\n", \count($c));
foreach ($c as $i => [$kind, $body]) {
    \printf("  [%d] %-8s %s\n", $i, $kind, \json_encode($body['commands'] ?? $body));
}

check('1. start -> one ScheduleActivity', \array_column($c[0][1]['commands'] ?? [], 'cmd'), ['ScheduleActivity']);
check('   activity type reaches core', $c[0][1]['commands'][0]['activity_type'] ?? null, 'greet');
check('   StartToCloseTimeout survives', $c[0][1]['commands'][0]['start_to_close_sec'] ?? null, 5);
check('   argument survives the DataConverter', \json_decode(\base64_decode($c[0][1]['commands'][0]['args'][0]['data'] ?? ''), true), 'Ada');
check('2. activity ran, token echoed', $c[1][1]['task_token'] ?? null, [1, 2, 3]);
check('   activity result', \json_decode(\base64_decode($c[1][1]['result']['data'] ?? ''), true), 'Hello, Ada!');
check('3. resolve -> StartTimer', \array_column($c[2][1]['commands'] ?? [], 'cmd'), ['StartTimer']);
check('   timer interval in ms', $c[2][1]['commands'][0]['ms'] ?? null, 1000);
check('4. fire -> CompleteWorkflow', \array_column($c[3][1]['commands'] ?? [], 'cmd'), ['CompleteWorkflow']);
check('   workflow return value', \json_decode(\base64_decode($c[3][1]['commands'][0]['result']['data'] ?? ''), true), 'HELLO, ADA!');
check('5. eviction -> no commands', $c[4][1]['commands'] ?? null, []);

// ---------------------------------------------------------------------------------------------
// Scenario 2: an update with a validator, a local activity, a query, and an activity heartbeat —
// the features a real workflow reaches for once activities and timers are not enough.

$runId2 = 'run-2';
$activation2 = static fn(array $jobs): array => ['run_id' => $runId2, 'is_replaying' => false, 'history_length' => 1, 'jobs' => $jobs];

$source2 = new RecordedSource([
    [ActivationSource::WORKFLOW, $activation2([job('InitializeWorkflow', [
        'workflow_type' => 'FeatureWorkflow',
        'workflow_id' => 'wf-2',
        'arguments' => [],
        'attempt' => 1,
    ])])],
    [ActivationSource::WORKFLOW, $activation2([job('DoUpdate', [
        'id' => 'u-1',
        'protocol_instance_id' => 'pi-1',
        'name' => 'submit',
        'input' => [payload('x')],
        'run_validator' => true,
    ])])],
    [ActivationSource::WORKFLOW, $activation2([job('QueryWorkflow', [
        'query_id' => 'q-1',
        'query_type' => 'state',
        'arguments' => [],
    ])])],
    [ActivationSource::ACTIVITY, [
        'task_token' => [9, 9],
        'variant' => ['Start' => [
            'activity_type' => 'projection.jobStarted',
            'activity_id' => '1',
            'input' => [payload('x')],
            'is_local' => true,
            'workflow_execution' => ['workflow_id' => 'wf-2', 'run_id' => $runId2],
            'workflow_type' => 'FeatureWorkflow',
            'attempt' => 1,
        ]],
    ]],
    [ActivationSource::WORKFLOW, $activation2([job('ResolveActivity', [
        'seq' => 1,
        'is_local' => true,
        'result' => ['status' => ['Completed' => ['result' => payload('started:x')]]],
    ])])],
    [ActivationSource::ACTIVITY, [
        'task_token' => [7],
        'variant' => ['Start' => [
            'activity_type' => 'work',
            'activity_id' => '2',
            'input' => [payload('beat')],
            'workflow_execution' => ['workflow_id' => 'wf-2', 'run_id' => $runId2],
            'workflow_type' => 'FeatureWorkflow',
            'attempt' => 1,
        ]],
    ]],
]);

$factory2 = CoreWorkerFactory::forSource($source2);
$worker2 = $factory2->newWorker('ignis');
$worker2->registerWorkflowTypes(FeatureWorkflow::class);
$worker2->registerActivityImplementations(new ProjectionActivity(), new HeartbeatActivity());

$wf2 = $factory2->host($source2, ActivationSource::WORKFLOW);
$act2 = $factory2->host($source2, ActivationSource::ACTIVITY);

$factory2->run($wf2);   // init, update, query
$factory2->run($act2);  // the local activity
$factory2->run($wf2);   // its resolution
$factory2->run($act2);  // the heartbeating activity

$d = $source2->completions;
\printf("\ncompletions (scenario 2): %d\n", \count($d));
foreach ($d as $i => [$kind, $body]) {
    \printf("  [%d] %-8s %s\n", $i, $kind, \json_encode($body['commands'] ?? $body));
}

$cmds = static fn(int $i): array => \array_column($d[$i][1]['commands'] ?? [], 'cmd');
$cmd = static fn(int $i, int $j): array => $d[$i][1]['commands'][$j] ?? [];

check('6. start -> workflow awaits, no commands', $cmds(0), []);
check('7. update -> accepted, then a LOCAL activity', $cmds(1), ['UpdateAccepted', 'ScheduleLocalActivity']);
check('   the validator ran and passed', $cmd(1, 0)['protocol_instance_id'] ?? null, 'pi-1');
check('   prefix from #[LocalActivityInterface]', $cmd(1, 1)['activity_type'] ?? null, 'projection.jobStarted');
check('8. query -> RespondToQuery by its own id', $cmds(2), ['RespondToQuery']);
check('   query answered from workflow state', \json_decode(\base64_decode($cmd(2, 0)['result']['data'] ?? ''), true), 'new');
check('9. local activity ran on the activity stream', \json_decode(\base64_decode($d[3][1]['result']['data'] ?? ''), true), 'started:x');
check('10. resolve -> update completes, then workflow', $cmds(4), ['UpdateCompleted', 'CompleteWorkflow']);
check('    update result reaches core', \json_decode(\base64_decode($cmd(4, 0)['result']['data'] ?? ''), true), 'ok:x');
check('    protocol_instance_id, not the update id', $cmd(4, 0)['protocol_instance_id'] ?? null, 'pi-1');
check('11. heartbeat reached the host', \count($source2->heartbeats), 1);
check('    with the task token and the detail', \json_decode(\base64_decode($source2->heartbeats[0]['details'][0]['data'] ?? ''), true), ['at' => 'beat']);

\printf("\n%s\n", $failures === 0 ? 'core transport: GREEN' : "core transport: {$failures} FAILED");
exit($failures === 0 ? 0 : 1);
