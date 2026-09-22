<?php

/**
 * Replay gate for the official PHP SDK on Ignis (E9): a run's recorded history is fetched by the
 * runtime and fed to the same workflow code through a replay worker. Prints `REPLAY_OK` when the
 * code agrees with the history and `REPLAY_FAILED` when core evicts it for nondeterminism.
 *
 *   SDKPHP_VENDOR=/path/to/vendor/autoload.php WORKFLOW_ID=demo-1 \
 *   ignis php/packages/temporal/bin/replay.php
 *
 * TEMPORAL_URL (default http://127.0.0.1:7233) and TEMPORAL_TASK_QUEUE (ignis) configure it, and
 * DEMO_MUTATE=1 is the negative control: the fixture then skips its timer. Build with
 * `--features temporal`.
 */

declare(strict_types=1);

require __DIR__ . '/../../runtime/src/ignis.php';

$vendor = \getenv('SDKPHP_VENDOR') ?: __DIR__ . '/../../temporal-core-transport/vendor/autoload.php';
if (!\is_file($vendor)) {
    \fwrite(\STDERR, "sdk-php not installed: set SDKPHP_VENDOR=/path/to/vendor/autoload.php (bench/e20-sdkphp.sh installs it)\n");
    exit(2);
}
require $vendor;
require __DIR__ . '/../src/CoreSource.php';
require __DIR__ . '/../../temporal-core-transport/tests/workflow.php';

if (!\defined('STDERR')) {
    \define('STDERR', \fopen('php://stderr', 'w') ?: throw new \RuntimeException('cannot open php://stderr'));
}

$source = Ignis\Temporal\CoreSource::replay(
    \getenv('TEMPORAL_URL') ?: 'http://127.0.0.1:7233',
    \getenv('WORKFLOW_ID') ?: 'demo-1',
    \getenv('TEMPORAL_TASK_QUEUE') ?: 'ignis',
);

Ignis\Temporal\serve(
    $source,
    static function (Temporal\Worker\WorkerInterface $worker): void {
        $worker->registerWorkflowTypes(GreetWorkflow::class);
        $worker->registerActivityImplementations(new GreetActivity());
    },
    0,
);

$failed = $source->nondeterministicEvictions() > 0;
\printf("REPLAY_%s nondeterministic_evictions=%d\n", $failed ? 'FAILED' : 'OK', $source->nondeterministicEvictions());
exit($failed ? 1 : 0);
