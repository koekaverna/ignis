<?php
// Replay test: feed a recorded history to a replay worker and run the same workflow code against it.
declare(strict_types=1);
require __DIR__ . '/../ignis.php';
require __DIR__ . '/ignis-temporal.php';
if (!defined('STDERR')) { define('STDERR', fopen('php://stderr', 'w')); }
$app = require __DIR__ . '/demo.php';
$historyFile = getenv('HISTORY') ?: '/tmp/ignis-e9-history.json';
$workerId = Ignis\Temporal\Worker::replayWorker(file_get_contents($historyFile), getenv('WORKFLOW_ID') ?: 'demo-1', 'ignis');
$w = new Ignis\Temporal\Worker($workerId, 'ignis', $app['workflows'], $app['activities']);
$w->run(replay: true);
printf("REPLAY_DONE activations=%d\n", Ignis\Temporal\Worker::$activations);
