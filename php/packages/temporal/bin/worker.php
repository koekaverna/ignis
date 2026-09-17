<?php

/**
 * A Temporal worker built from the official PHP SDK, served by Ignis (ADR-0040).
 *
 *   SDKPHP_VENDOR=/path/to/vendor/autoload.php \
 *   ignis php/packages/temporal/bin/worker.php
 *
 * TEMPORAL_URL (default http://127.0.0.1:7233), TEMPORAL_NAMESPACE (default), TEMPORAL_TASK_QUEUE
 * (ignis) and IGNIS_ACTIVITY_FIBERS (8) configure it. Build with `--features temporal`.
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
    \define('STDERR', \fopen('php://stderr', 'w'));
}

$source = Ignis\Temporal\CoreSource::connect(
    \getenv('TEMPORAL_URL') ?: 'http://127.0.0.1:7233',
    \getenv('TEMPORAL_NAMESPACE') ?: 'default',
    \getenv('TEMPORAL_TASK_QUEUE') ?: 'ignis',
);

\fwrite(\STDERR, "sdk-php worker on ignis: task queue '" . $source->taskQueue() . "'\n");

Ignis\Temporal\serve(
    $source,
    static function (Temporal\Worker\WorkerInterface $worker): void {
        $worker->registerWorkflowTypes(GreetWorkflow::class);
        $worker->registerActivityImplementations(new GreetActivity());
    },
    (int) (\getenv('IGNIS_ACTIVITY_FIBERS') ?: 8),
);
