<?php

declare(strict_types=1);

namespace Ignis\Tests\Offload;

use Ignis\Offload\CallbackRef;
use Ignis\Offload\Client;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/fake-offload.php';

/**
 * The caller side of an offload job. `Client::call()` itself parks the fiber, which belongs to
 * E16 against the real binary; what is unit-testable is how it prepares the arguments — the
 * closures it lifts out of them, and where those closures are then kept.
 */
#[CoversClass(Client::class)]
final class ClientTest extends TestCase
{
    protected function setUp(): void
    {
        FakeOffload::reset();
        self::set('pending', []);
        self::set('callbacks', []);
        self::set('nextCb', 1);
    }

    protected function tearDown(): void
    {
        self::set('pending', []);
        self::set('callbacks', []);
    }

    public function testEveryClosureAmongTheArgumentsBecomesACallbackRef(): void
    {
        $onRow = static fn(string $row): string => $row;
        $onEnd = static fn(): null => null;

        $sent = self::extract(['SELECT 1', ['on' => ['row' => $onRow, 'end' => $onEnd]], 7], $callbacks);

        self::assertSame('SELECT 1', $sent[0]);
        self::assertSame(7, $sent[2]);
        self::assertInstanceOf(CallbackRef::class, $sent[1]['on']['row'], 'a closure cannot be serialized, so it travels as a handle');
        self::assertInstanceOf(CallbackRef::class, $sent[1]['on']['end']);
        self::assertSame([1, 2], [$sent[1]['on']['row']->id, $sent[1]['on']['end']->id], 'ids are handed out in traversal order');
        self::assertSame([1 => $onRow, 2 => $onEnd], $callbacks, 'and the caller keeps the real closures for the run of this job');
        self::assertIsString(serialize($sent), 'whatever comes back has to survive serialize()');
    }

    public function testArgumentsWithNoClosureInThemAreHandedOverUnchanged(): void
    {
        $args = ['a', 1, null, true, ['nested' => ['deep' => 'value']]];

        self::assertSame($args, self::extract($args, $callbacks));
        self::assertSame([], $callbacks);
    }

    /**
     * DEFECT (pinned). `extractCallbacks()` writes each closure into **two** maps:
     * `Client::$callbacks[$op]`, which `call()` unsets when the job returns, and `Client::$pending`,
     * which nothing ever prunes. `$pending` is the one the callback handler reads, so it is the map
     * that has to exist — and it grows by one closure, with everything that closure captures, for
     * every offload call that takes one. `$callbacks` is the opposite: written, unset, never read.
     *
     * The fix is to key `$pending` by op the way `$callbacks` is and let `call()`'s existing
     * `unset()` free it — which also deletes `$callbacks` outright.
     */
    public function testTheClosureMapGrowsForEverAndTheOtherOneIsNeverReadBug(): void
    {
        for ($call = 0; $call < 3; $call++) {
            $payload = str_repeat('x', 1024);
            self::extract([static fn(): string => $payload], $callbacks);
        }

        self::assertCount(3, self::get('pending'), 'one closure per offload call, kept for the life of the process');
        self::assertSame([1, 2, 3], array_keys(self::get('pending')), 'keyed by a process-wide counter, so nothing can ever match them back to a finished job');
        self::assertSame([], self::get('callbacks'), 'the per-job map the closures are also written into is never the one that is read');
    }

    public function testStatsComeStraightFromTheRuntime(): void
    {
        self::assertSame(['workers' => 2, 'this' => 0], Client::stats());
    }

    /** @param list<mixed> $args */
    private static function extract(array $args, mixed &$callbacks): mixed
    {
        $callbacks = [];

        return (new \ReflectionMethod(Client::class, 'extractCallbacks'))->invokeArgs(null, [$args, &$callbacks]);
    }

    private static function set(string $property, mixed $value): void
    {
        (new \ReflectionProperty(Client::class, $property))->setValue(null, $value);
    }

    private static function get(string $property): mixed
    {
        return (new \ReflectionProperty(Client::class, $property))->getValue();
    }
}
