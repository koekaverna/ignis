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
    foreach (['ActivationSource', 'HeartbeatSink', 'ServiceCall', 'CoreCodec', 'CoreRpc', 'CoreHost', 'CoreWorkerFactory', 'DetachedStub', 'CoreServiceClient'] as $class) {
        require \dirname(__DIR__) . "/src/{$class}.php";
    }
}

use Temporal\Worker\Transport\Core\ActivationSource;
use Temporal\Worker\Transport\Core\CoreServiceClient;
use Temporal\Worker\Transport\Core\ServiceCall;
use Temporal\Worker\Transport\Core\CoreWorkerFactory;

require __DIR__ . '/workflow.php';

/** Plays a recorded script; a host only ever gets the tasks of its own kind, in order. */
final class RecordedSource implements ActivationSource, \Temporal\Worker\Transport\Core\HeartbeatSink
{
    /** @var list<array{token: string, details: array<int|string, mixed>}> */
    public array $heartbeats = [];

    /** @var list<array{0: string, 1: array<string, mixed>}> */
    private array $script;
    /** @var list<array{0: string, 1: array<string, mixed>}> */
    public array $completions = [];
    /** @var list<string> exactly what went to the host — what the Rust side has to accept */
    public array $raw = [];

    /** @param list<array{0: string, 1: array<string, mixed>}> $script */
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
        $this->raw[] = $json;
        $this->completions[] = [$kind, \json_decode($json, true, 512, \JSON_THROW_ON_ERROR)];
    }

    public function heartbeat(string $taskToken, array $details): array
    {
        $this->heartbeats[] = ['token' => $taskToken, 'details' => $details];

        return [];
    }

    public function taskQueue(): string
    {
        return 'ignis';
    }

    public function namespace(): string
    {
        return 'default';
    }
}

/** @return array{metadata: array{encoding: string}, data: string} */
function payload(mixed $value): array
{
    return [
        'metadata' => ['encoding' => \base64_encode('json/plain')],
        'data' => \base64_encode(\json_encode($value, \JSON_THROW_ON_ERROR)),
    ];
}

/**
 * protojson flattens a oneof to its field name, so a job is just `{fieldName: {...}}`.
 *
 * @param array<string, mixed> $data
 *
 * @return array<string, array<string, mixed>>
 */
function job(string $name, array $data): array
{
    return [$name => $data];
}

/**
 * The command name of a protojson command, and its body.
 *
 * @param array<string, mixed> $command
 */
function cmdName(array $command): string
{
    return (string) \array_key_first($command);
}

$runId = 'run-1';
$activation = static fn(array $jobs, array $extra = []): array => ['runId' => $runId, 'isReplaying' => false, 'historyLength' => 1, 'jobs' => $jobs] + $extra;

$source = new RecordedSource([
    [ActivationSource::WORKFLOW, $activation([job('initializeWorkflow', [
        'workflowType' => 'GreetWorkflow',
        'workflowId' => 'wf-1',
        'arguments' => [payload('Ada')],
        'attempt' => 1,
    ])])],
    [ActivationSource::ACTIVITY, [
        'taskToken' => \base64_encode('tok-1'),
        'start' => [
            'activityType' => 'greet',
            'activityId' => '1',
            'input' => [payload('Ada')],
            'workflowExecution' => ['workflowId' => 'wf-1', 'runId' => $runId],
            'workflowType' => 'GreetWorkflow',
            'attempt' => 1,
        ],
    ]],
    [ActivationSource::WORKFLOW, $activation([job('resolveActivity', [
        'seq' => 1,
        'result' => ['completed' => ['result' => payload('Hello, Ada!')]],
    ])])],
    [ActivationSource::WORKFLOW, $activation([job('fireTimer', ['seq' => 2])])],
    [ActivationSource::WORKFLOW, $activation([job('removeFromCache', ['reason' => 'WorkflowExecutionEnded'])])],
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

/** A counter rather than a `global`, so what increments it is visible from where it is read. */
final class Failures
{
    public static int $count = 0;
}

function check(string $what, mixed $got, mixed $want): void
{
    $ok = $got === $want;
    Failures::$count += $ok ? 0 : 1;
    \printf("%-46s %s\n", $what, $ok ? 'ok' : \sprintf("FAIL\n    got  %s\n    want %s", \json_encode($got), \json_encode($want)));
}

$c = $source->completions;
\printf("completions: %d\n", \count($c));
foreach ($c as $i => [$kind, $body]) {
    \printf("  [%d] %-8s %s\n", $i, $kind, \json_encode($body['successful']['commands'] ?? $body));
}

$names1 = static fn(int $i): array => \array_map(cmdName(...), $c[$i][1]['successful']['commands'] ?? []);
$body1 = static fn(int $i, int $j): array => \array_values($c[$i][1]['successful']['commands'][$j] ?? [[]])[0];

check('1. start -> one scheduleActivity', $names1(0), ['scheduleActivity']);
check('   activity type reaches core', $body1(0, 0)['activityType'] ?? null, 'greet');
check('   StartToCloseTimeout as a protojson duration', $body1(0, 0)['startToCloseTimeout'] ?? null, '5s');
check('   argument survives the DataConverter', \json_decode(\base64_decode($body1(0, 0)['arguments'][0]['data'] ?? ''), true), 'Ada');
check('2. activity ran, token echoed', $c[1][1]['taskToken'] ?? null, \base64_encode('tok-1'));
check('   activity result', \json_decode(\base64_decode($c[1][1]['result']['completed']['result']['data'] ?? ''), true), 'Hello, Ada!');
check('3. resolve -> startTimer', $names1(2), ['startTimer']);
check('   timer interval as a duration', $body1(2, 0)['startToFireTimeout'] ?? null, '1s');
check('4. fire -> completeWorkflowExecution', $names1(3), ['completeWorkflowExecution']);
check('   workflow return value', \json_decode(\base64_decode($body1(3, 0)['result']['data'] ?? ''), true), 'HELLO, ADA!');
check('5. eviction -> no commands', $names1(4), []);

// ---------------------------------------------------------------------------------------------
// Scenario 2: an update with a validator, a local activity, a query, and an activity heartbeat —
// the features a real workflow reaches for once activities and timers are not enough.

$runId2 = 'run-2';
$activation2 = static fn(array $jobs): array => ['runId' => $runId2, 'isReplaying' => false, 'historyLength' => 1, 'jobs' => $jobs];

$source2 = new RecordedSource([
    [ActivationSource::WORKFLOW, $activation2([job('initializeWorkflow', [
        'workflowType' => 'FeatureWorkflow',
        'workflowId' => 'wf-2',
        'arguments' => [],
        'attempt' => 1,
    ])])],
    [ActivationSource::WORKFLOW, $activation2([job('doUpdate', [
        'id' => 'u-1',
        'protocolInstanceId' => 'pi-1',
        'name' => 'submit',
        'input' => [payload('x')],
        'runValidator' => true,
    ])])],
    [ActivationSource::WORKFLOW, $activation2([job('queryWorkflow', [
        'queryId' => 'q-1',
        'queryType' => 'state',
        'arguments' => [],
    ])])],
    [ActivationSource::ACTIVITY, [
        'taskToken' => \base64_encode('tok-local'),
        'start' => [
            'activityType' => 'projection.jobStarted',
            'activityId' => '1',
            'input' => [payload('x')],
            'isLocal' => true,
            'workflowExecution' => ['workflowId' => 'wf-2', 'runId' => $runId2],
            'workflowType' => 'FeatureWorkflow',
            'attempt' => 1,
        ],
    ]],
    [ActivationSource::WORKFLOW, $activation2([job('resolveActivity', [
        'seq' => 1,
        'isLocal' => true,
        'result' => ['completed' => ['result' => payload('started:x')]],
    ])])],
    [ActivationSource::ACTIVITY, [
        'taskToken' => \base64_encode('tok-beat'),
        'start' => [
            'activityType' => 'work',
            'activityId' => '2',
            'input' => [payload('beat')],
            'workflowExecution' => ['workflowId' => 'wf-2', 'runId' => $runId2],
            'workflowType' => 'FeatureWorkflow',
            'attempt' => 1,
        ],
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
    \printf("  [%d] %-8s %s\n", $i, $kind, \json_encode($body['successful']['commands'] ?? $body));
}

$cmds = static fn(int $i): array => \array_map(cmdName(...), $d[$i][1]['successful']['commands'] ?? []);
$cmd = static fn(int $i, int $j): array => \array_values($d[$i][1]['successful']['commands'][$j] ?? [[]])[0];

check('6. start -> workflow awaits, no commands', $cmds(0), []);
check('7. update -> accepted, then a LOCAL activity', $cmds(1), ['updateResponse', 'scheduleLocalActivity']);
check('   the validator ran and passed', $cmd(1, 0)['protocolInstanceId'] ?? null, 'pi-1');
check('   accepted, not rejected', \array_key_exists('accepted', $cmd(1, 0)), true);
check('   prefix from #[LocalActivityInterface]', $cmd(1, 1)['activityType'] ?? null, 'projection.jobStarted');
check('8. query -> respondToQuery by its own id', $cmds(2), ['respondToQuery']);
check('   query answered from workflow state', \json_decode(\base64_decode($cmd(2, 0)['succeeded']['response']['data'] ?? ''), true), 'new');
check('9. local activity ran on the activity stream', \json_decode(\base64_decode($d[3][1]['result']['completed']['result']['data'] ?? ''), true), 'started:x');
check('10. resolve -> update completes, then workflow', $cmds(4), ['updateResponse', 'completeWorkflowExecution']);
check('    update result reaches core', \json_decode(\base64_decode($cmd(4, 0)['completed']['data'] ?? ''), true), 'ok:x');
check('    protocolInstanceId, not the update id', $cmd(4, 0)['protocolInstanceId'] ?? null, 'pi-1');
check('11. heartbeat reached the host', \count($source2->heartbeats), 1);
check('    with the task token and the detail', \json_decode(\base64_decode($source2->heartbeats[0]['details'][0]['data'] ?? ''), true), ['at' => 'beat']);

// ---------------------------------------------------------------------------------------------
// Scenario 3: the client half. sdk-php's own WorkflowClient, in a build with no gRPC extension,
// with every call answered by a ServiceCall instead of a channel.

final class RecordingCall implements ServiceCall
{
    /** @var list<array{path:string,request:string}> */
    public array $calls = [];
    private string $reply = '';

    public function willReply(\Google\Protobuf\Internal\Message $response): void
    {
        $this->reply = $response->serializeToString();
    }

    public function call(string $path, string $request): string
    {
        $this->calls[] = ['path' => $path, 'request' => $request];

        return $this->reply;
    }
}

\printf("\nclient: ext-grpc loaded = %s\n", \extension_loaded('grpc') ? 'yes' : 'no');

$transport = new RecordingCall();
$transport->willReply((new \Temporal\Api\Workflowservice\V1\StartWorkflowExecutionResponse())->setRunId('run-from-core'));

$clientOk = true;
try {
    $wfClient = \Temporal\Client\WorkflowClient::create(CoreServiceClient::for($transport));
    $stub = $wfClient->newUntypedWorkflowStub('GreetWorkflow', \Temporal\Client\WorkflowOptions::new()->withTaskQueue('ignis'));
    $run = $wfClient->start($stub, 'Ada');
    check('12. WorkflowClient::start() without ext-grpc', $run->getExecution()->getRunID(), 'run-from-core');
} catch (\Throwable $e) {
    $clientOk = false;
    check('12. WorkflowClient::start() without ext-grpc', $e::class . ': ' . $e->getMessage(), 'run-from-core');
}

if ($clientOk) {
    $sent = $transport->calls[0] ?? ['path' => '', 'request' => ''];
    check('    reached the right gRPC method', $sent['path'], '/temporal.api.workflowservice.v1.WorkflowService/StartWorkflowExecution');

    $req = new \Temporal\Api\Workflowservice\V1\StartWorkflowExecutionRequest();
    $req->mergeFromString($sent['request']);
    check('    the request is a real protobuf', $req->getWorkflowType()?->getName(), 'GreetWorkflow');
    check('    task queue survives', $req->getTaskQueue()?->getName(), 'ignis');
    check('    argument survives', \json_decode($req->getInput()?->getPayloads()[0]?->getData() ?? '', true), 'Ada');
}

if (\getenv('CORE_DUMP_RAW')) {
    \printf("\n--- raw completions (protojson, verbatim) ---\n");
    foreach ([...$source->raw, ...$source2->raw] as $r) {
        \printf("%s\n", $r);
    }
}

\printf("\n%s\n", Failures::$count === 0 ? 'core transport: GREEN' : \sprintf('core transport: %d FAILED', Failures::$count));
exit(Failures::$count === 0 ? 0 : 1);
