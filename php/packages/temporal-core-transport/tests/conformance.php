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
    foreach (['ActivationSource', 'CoreCodec', 'CoreHost', 'CoreWorkerFactory'] as $class) {
        require \dirname(__DIR__) . "/src/{$class}.php";
    }
}

use Temporal\Worker\Transport\Core\ActivationSource;
use Temporal\Worker\Transport\Core\CoreWorkerFactory;

require __DIR__ . '/workflow.php';

/** Plays a recorded script; a host only ever gets the tasks of its own kind, in order. */
final class RecordedSource implements ActivationSource
{
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

\printf("\n%s\n", $failures === 0 ? 'core transport: GREEN' : "core transport: {$failures} FAILED");
exit($failures === 0 ? 0 : 1);
