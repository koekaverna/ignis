<?php

// amphp/amp v3: async() + delay() + Future::await — three 200 ms delays concurrently.
declare(strict_types=1);
require __DIR__ . '/../vendor/autoload.php';

use function Amp\async;
use function Amp\delay;
use function Amp\Future\await;

$t0 = hrtime(true);
$results = await([
    async(static function (): string {
        delay(0.2);
        return 'a';
    }),
    async(static function (): string {
        delay(0.2);
        return 'b';
    }),
    async(static function (): string {
        delay(0.2);
        return 'c';
    }),
]);
printf("amp: %s in %d ms bucket\n", implode('', $results), (int) round((hrtime(true) - $t0) / 1e6 / 100) * 100);
