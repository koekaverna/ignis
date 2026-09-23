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
 * One is then overridden on purpose: `booted` is forced true, which stops `runUntil()` re-running
 * `Loop::boot()` — and with it `gcInit()`, which calls `gc_disable()` on the whole process, and
 * `budgetInit()`, which would read whatever `IGNIS_FIBER_BUDGET` the ambient environment holds.
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
     * Also frees any fiber a test left parked forever: resetting `Loop`'s statics drops its last
     * external reference, and `gc_collect_cycles()` reclaims the cycle here rather than in a later test (ADR-0043 §7).
     */
    protected static function resetLoop(): void
    {
        $class = new \ReflectionClass(Loop::class);
        foreach ($class->getDefaultProperties() as $name => $default) {
            $class->getProperty($name)->setValue(null, $default);
        }
        self::set('booted', true);
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
