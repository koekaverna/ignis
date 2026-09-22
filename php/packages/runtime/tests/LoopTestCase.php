<?php

declare(strict_types=1);

namespace Ignis\Tests;

use Ignis\Loop;
use PHPUnit\Framework\TestCase;

/**
 * `Ignis\Loop` is entirely static and PHPUnit shares one process, so one test's leftovers are the
 * next test's starting state. Every static goes back to its declared default before and after each
 * test, read from the class itself (`getDefaultProperties()`) so a new field needs no change here.
 *
 * Two are then overridden on purpose:
 *
 * - `booted` is forced true, which stops `runUntil()` re-running `Loop::boot()` — and with it
 *   `gcInit()`, which calls `gc_disable()` on the whole process, and `budgetInit()`, which would
 *   read whatever `IGNIS_FIBER_BUDGET` the ambient environment happens to hold;
 * - `canPublishStats` is forced false, because only `boot()` ever asks whether
 *   `ignis_publish_stats()` exists and the answer here is no — leaving the declared default `true`
 *   would end every driven loop on an undefined function before its first poll.
 *
 * The reactor underneath is `tests/fake-reactor.php`, whose header states the bound: the E-suites
 * are the contract, this only exercises userland bookkeeping.
 */
abstract class LoopTestCase extends TestCase
{
    protected function setUp(): void
    {
        self::resetLoop();
        FakeReactor::reset();
    }

    protected function tearDown(): void
    {
        self::resetLoop();
        FakeReactor::reset();
    }

    /**
     * Resetting every static to its declared default is also what turns a fiber a test left
     * parked forever (many do, deliberately) into unreachable self-referencing garbage: `poolBody()`
     * keeps a reference to its own fiber for as long as its job runs (ADR-0043 §7), so wiping
     * `Loop`'s own maps out from under a still-suspended fiber is the last external reference
     * gone, not a free. `gc_collect_cycles()` here is what actually frees it, in this test's own
     * teardown rather than whenever some later test's own force-close happens to collect it —
     * which is what a stray `504 fiber killed` answered against a request id from a different test
     * would otherwise mean.
     */
    protected static function resetLoop(): void
    {
        $class = new \ReflectionClass(Loop::class);
        foreach ($class->getDefaultProperties() as $name => $default) {
            $class->getProperty($name)->setValue(null, $default);
        }
        self::set('booted', true);
        self::set('canPublishStats', false);
        gc_collect_cycles();
    }

    protected static function set(string $property, mixed $value): void
    {
        (new \ReflectionProperty(Loop::class, $property))->setValue(null, $value);
    }

    protected static function get(string $property): mixed
    {
        return (new \ReflectionProperty(Loop::class, $property))->getValue();
    }

    protected static function call(string $method, mixed ...$arguments): mixed
    {
        return (new \ReflectionMethod(Loop::class, $method))->invoke(null, ...$arguments);
    }

    /**
     * Runs the loop until the fake reactor has nothing left in flight, which is how it says "stop".
     * Anything else — an unobserved rejection rethrown by `runUntil()`, say — comes straight out.
     */
    protected static function drive(): void
    {
        try {
            Loop::run();
        } catch (StopLoop) {
        }
    }

    /**
     * The raw array the reactor delivers for an HTTP request.
     * @return array<string, mixed>
     */
    protected static function rawRequest(string $uri = '/', string $method = 'GET', string $body = ''): array
    {
        return ['method' => $method, 'uri' => $uri, 'headers' => [], 'body' => $body];
    }
}
