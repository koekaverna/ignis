<?php

// The E9 workflow: two activities and a timer. Same file serves the live worker and the replay test.
declare(strict_types=1);

/** DEMO_MUTATE=1 drops the timer: the negative replay test must then fail with a nondeterminism eviction. */
function demoShouldRunTimer(): bool
{
    return !getenv('DEMO_MUTATE');
}

return [
    'workflows' => [
        'Demo' => static function (Ignis\Temporal\Context $context, string $name): string {
            $greeting = $context->activity('greet', [$name]);
            if (demoShouldRunTimer()) {
                $context->timer(500);
            }
            $shouted = $context->activity('shout', [$greeting]);
            if (!is_string($shouted)) {
                throw new UnexpectedValueException('activity "shout" must return a string, got ' . get_debug_type($shouted));
            }
            return $shouted;
        },
    ],
    'activities' => [
        'greet' => static fn(string $name): string => "hello $name",
        'shout' => static function (string $text): string {
            Ignis\sleep(50);
            return strtoupper($text) . '!';
        },
    ],
];
