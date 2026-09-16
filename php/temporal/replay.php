<?php
// Replay test: feed a recorded history to a replay worker and run the same workflow code against it.
declare(strict_types=1);
require __DIR__ . '/../ignis.php';
require __DIR__ . '/ignis-temporal.php';
if (!defined('STDERR')) { define('STDERR', fopen('php://stderr', 'w')); }
$app = require __DIR__ . '/demo.php';
$workerId = Ignis\Temporal\Worker::replayWorker(getenv('TEMPORAL_URL') ?: 'http://127.0.0.1:7233', getenv('WORKFLOW_ID') ?: 'demo-1', 'ignis');
$w = new Ignis\Temporal\Worker($workerId, 'ignis', $app['workflows'], $app['activities']);
$w->run(replay: true);
printf("REPLAY_%s activations=%d eviction_errors=%d\n", Ignis\Temporal\Worker::$evictionErrors ? 'FAILED' : 'OK', Ignis\Temporal\Worker::$activations, Ignis\Temporal\Worker::$evictionErrors);
exit(Ignis\Temporal\Worker::$evictionErrors ? 1 : 0);
