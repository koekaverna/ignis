<?php

// The E9 workflow: two activities and a timer. Same file serves the live worker and the replay test.
declare(strict_types=1);

return [
    'workflows' => [
        'Demo' => static function (Ignis\Temporal\Context $ctx, string $name): string {
            $greeting = $ctx->activity('greet', [$name]);
            // DEMO_MUTATE=1 drops the timer: the negative replay test must then fail with a nondeterminism eviction.
            if (!getenv('DEMO_MUTATE')) {
                $ctx->timer(500);
            }
            return $ctx->activity('shout', [$greeting]);
        },
    ],
    'activities' => [
        'greet' => static fn(string $name): string => "hello $name",
        'shout' => static function (string $s): string {
            Ignis\sleep(50);
            return strtoupper($s) . '!';
        },
    ],
];
