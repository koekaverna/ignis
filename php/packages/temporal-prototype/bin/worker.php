<?php

// Live worker: connects to the dev server and serves task queue "ignis" until shut down.
declare(strict_types=1);
require __DIR__ . '/../../runtime/src/ignis.php';
require __DIR__ . '/../src/ignis-temporal.php';
if (!defined('STDERR')) {
    define('STDERR', fopen('php://stderr', 'w') ?: throw new RuntimeException('cannot open php://stderr'));
}
$app = require __DIR__ . '/../src/demo.php';
$workerId = Ignis\Temporal\Worker::connect(getenv('TEMPORAL_URL') ?: 'http://127.0.0.1:7233', 'default', 'ignis');
fwrite(STDERR, "worker $workerId connected\n");
(new Ignis\Temporal\Worker($workerId, 'ignis', $app['workflows'], $app['activities']))->run();
