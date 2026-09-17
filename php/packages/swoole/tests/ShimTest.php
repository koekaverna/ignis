<?php

declare(strict_types=1);

namespace Ignis\Tests\Swoole;

use Ignis\Loop;
use Ignis\Scope;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;
use Swoole\Coroutine;
use Swoole\Coroutine\Channel;
use Swoole\Coroutine\WaitGroup;
use Swoole\Runtime;
use Swoole\Timer;

require_once \dirname(__DIR__) . '/src/shim.php';

/**
 * The Swoole compatibility shim (E15d). Everything here is exercised from {main}, on purpose:
 * `Parking::park()` drives the Ignis loop when there is no fiber to suspend, so a test that waits
 * on something nothing will ever satisfy would spin for ever. The parking paths themselves belong
 * to `bench/e15-swoole.sh` against the real binary; what is unit-testable is the bookkeeping —
 * the queue, the counter, the timer table and the coroutine ids.
 *
 * `CoversNothing` because the shim declares no autoloadable class of its own under `src/`.
 */
#[CoversNothing]
final class ShimTest extends TestCase
{
    protected function setUp(): void
    {
        self::resetStatics();
    }

    protected function tearDown(): void
    {
        // The shim registers Swoole\Event::shutdown() as a shutdown function; a coroutine left
        // alive here would make it drive a loop at the end of the PHPUnit process.
        self::resetStatics();
    }

    public function testAChannelIsAFifoUpToItsCapacity(): void
    {
        $channel = new Channel(2);

        self::assertTrue($channel->isEmpty());
        self::assertSame(2, $channel->capacity);

        self::assertTrue($channel->push('first'));
        self::assertTrue($channel->push('second'));

        self::assertTrue($channel->isFull(), 'a third push would park, which is what the capacity is for');
        self::assertSame(2, $channel->length());
        self::assertSame('first', $channel->pop());
        self::assertSame('second', $channel->pop());
        self::assertTrue($channel->isEmpty());
    }

    public function testAClosedChannelRefusesPushesAndDrainsWhatIsLeft(): void
    {
        $channel = new Channel(4);
        $channel->push('left over');

        self::assertTrue($channel->close());
        self::assertFalse($channel->push('too late'), 'a closed channel takes nothing more');
        self::assertSame('left over', $channel->pop(), 'but what is already queued can still be read');
        self::assertFalse($channel->pop(), 'and then it answers false rather than blocking for ever');
    }

    public function testAWaitGroupCountsUpAndDown(): void
    {
        $group = new WaitGroup(2);
        self::assertSame(2, $group->count());

        $group->add();
        self::assertSame(3, $group->count());

        $group->done();
        $group->done();
        $group->done();
        self::assertSame(0, $group->count());
        self::assertTrue($group->wait(), 'a counter that is already zero does not wait');
    }

    public function testAWaitGroupRefusesToGoNegative(): void
    {
        $group = new WaitGroup();

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('WaitGroup misuse: negative counter');
        $group->done();   // one done() too many is the classic misuse, and it must say so
    }

    public function testATimerCanBeClearedExactlyOnce(): void
    {
        self::assertFalse(Timer::clear(999), 'an id that was never a timer');

        $id = Timer::after(50, static function (): void {});
        self::assertTrue(Timer::clear($id));
        self::assertFalse(Timer::clear($id), 'clearing twice is not an error, it is just false');

        $tick = Timer::tick(10, static function (): void {});
        self::assertNotSame($id, $tick, 'ids are handed out once per process');
        self::assertTrue(Timer::clear($tick));
    }

    public function testTheCoroutineIdsComeFromFiberScopedStorage(): void
    {
        self::assertSame(-1, Coroutine::getCid(), '{main} is not a coroutine');
        self::assertSame(-1, Coroutine::getPcid());

        Scope::set('swoole.cid', 7);
        Scope::set('swoole.pcid', 3);

        self::assertSame(7, Coroutine::getCid(), 'the id is per fiber (ADR-0006), which is what makes it safe under concurrency');
        self::assertSame(3, Coroutine::getPcid());
        self::assertFalse(Coroutine::exists(7), 'and it is not a claim that the coroutine is live');
    }

    public function testDeferredCallbacksAreRecordedAgainstTheCurrentCoroutine(): void
    {
        Scope::set('swoole.cid', 4);
        Coroutine::defer(static function (): void {});
        Coroutine::defer(static function (): void {});

        $defers = (new \ReflectionProperty(Coroutine::class, 'defers'))->getValue();
        self::assertCount(2, $defers[4], 'they run in reverse at the end of coroutine 4');
    }

    public function testTheHookFlagsAreRecordedRatherThanApplied(): void
    {
        self::assertTrue(Runtime::enableCoroutine(true, SWOOLE_HOOK_TCP | SWOOLE_HOOK_SLEEP));
        self::assertSame(SWOOLE_HOOK_TCP | SWOOLE_HOOK_SLEEP, Runtime::getHookFlags());

        Runtime::enableCoroutine(false);
        self::assertSame(0, Runtime::getHookFlags(), 'nothing is unhooked either: what is hooked is decided by the runtime, not here');

        Coroutine::set(['hook_flags' => SWOOLE_HOOK_ALL]);
        self::assertSame(SWOOLE_HOOK_ALL, Runtime::$configured, 'Co::set() is what Co\run() applies');
    }

    public function testStatsCountLiveCoroutinesAndThePeakId(): void
    {
        self::assertSame(['coroutine_num' => 0, 'coroutine_peak_num' => 0], Coroutine::stats());

        Coroutine::create(static function (): void {});

        self::assertSame(['coroutine_num' => 1, 'coroutine_peak_num' => 1], Coroutine::stats(), 'created, but not started: the loop has not run');
    }

    private static function resetStatics(): void
    {
        foreach ([Coroutine::class, Timer::class, Runtime::class, Loop::class] as $class) {
            $reflection = new \ReflectionClass($class);
            foreach ($reflection->getDefaultProperties() as $name => $default) {
                $property = $reflection->getProperty($name);
                if ($property->isStatic()) {
                    $property->setValue(null, $default);
                }
            }
        }
        Scope::clear();
    }
}
