<?php

declare(strict_types=1);

namespace Ignis\Tests\Doctrine\Pool;

use Doctrine\DBAL\Driver;
use Doctrine\DBAL\Driver\Connection as DriverConnection;
use Ignis\Doctrine\Pool\ConnectionPool;
use Ignis\Doctrine\Pool\PoolTimeoutException;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * The pool's bookkeeping: warming, reuse, the limit and what happens to a connection that cannot be
 * cleaned — and, since 2026-09-18, the parking half as well. That half said here that it "needs the
 * loop and is measured by `bench/e24` against a real PostgreSQL", which left the one path with a
 * resource leak in it untested on a box with no database: `php/tests/fake-reactor.php` is loaded for
 * the whole suite and gives the loop everything a wait needs.
 */
#[CoversClass(ConnectionPool::class)]
#[AllowMockObjectsWithoutExpectations]
final class ConnectionPoolTest extends TestCase
{
    /** @var list<MockObject&DriverConnection> */
    private array $connected = [];

    public function testItOpensTheWarmCountWhenItIsCreated(): void
    {
        $pool = $this->pool(limit: 5, warmCount: 3);

        self::assertCount(3, $this->connected);
        self::assertSame(['limit' => 5, 'open' => 3, 'idle' => 3, 'waiting' => 0], $pool->stats());
    }

    public function testWarmingNeverGoesPastTheLimit(): void
    {
        $pool = $this->pool(limit: 2, warmCount: 10);

        self::assertCount(2, $this->connected);
        self::assertSame(2, $pool->stats()['open']);
    }

    public function testAReleasedConnectionIsTheNextOneHandedOut(): void
    {
        $pool = $this->pool(limit: 5, warmCount: 0);

        $first = $pool->acquire();
        $pool->release($first);

        self::assertSame($first, $pool->acquire(), 'the point of a pool is that this is the same socket');
        self::assertCount(1, $this->connected, 'and that no second one was opened');
    }

    public function testALeasedConnectionIsNotHandedToAnybodyElse(): void
    {
        $pool = $this->pool(limit: 5, warmCount: 1);

        $first = $pool->acquire();
        $second = $pool->acquire();

        self::assertNotSame($first, $second);
        self::assertSame(['limit' => 5, 'open' => 2, 'idle' => 0, 'waiting' => 0], $pool->stats());
    }

    public function testThePoolRefusesToOpenMoreThanTheLimit(): void
    {
        $pool = $this->pool(limit: 2, warmCount: 0, waitMilliseconds: 0);
        $pool->acquire();
        $pool->acquire();

        $this->expectException(PoolTimeoutException::class);
        $this->expectExceptionMessage('pool limit 2');

        $pool->acquire();
    }

    public function testAConnectionThatCannotBeResetIsDroppedInsteadOfReused(): void
    {
        $pool = $this->pool(limit: 5, warmCount: 1, reset: 'DISCARD TEMP');
        $broken = $this->connected[0];
        $broken->method('exec')->willThrowException(new \RuntimeException('server closed the connection'));

        $pool->release($pool->acquire());

        self::assertSame(['limit' => 5, 'open' => 0, 'idle' => 0, 'waiting' => 0], $pool->stats());
        self::assertNotSame($broken, $pool->acquire(), 'a dead connection must not come back out');
    }

    public function testEveryReturnedConnectionIsCleanedFirst(): void
    {
        $pool = $this->pool(limit: 5, warmCount: 1, reset: 'DISCARD TEMP');
        $this->connected[0]->expects($this->once())->method('exec')->with('DISCARD TEMP');

        $pool->release($pool->acquire());
    }

    public function testAFailedConnectAttemptDoesNotConsumeCapacity(): void
    {
        $driver = $this->createMock(Driver::class);
        $driver->method('connect')->willThrowException(new \RuntimeException('connection refused'));
        $pool = new ConnectionPool($driver, ['driver' => 'pdo_pgsql'], 2, 0, 0, '');

        try {
            $pool->acquire();
        } catch (\RuntimeException) {
        }

        self::assertSame(0, $pool->stats()['open'], 'a refused connection must not count against the limit forever');
    }

    /**
     * A waiter woken by a release must let its timeout go with it.
     *
     * The timeout used to be `Ignis\sleep($remaining)` followed by a check: a waiter woken after two
     * milliseconds still held a parked fiber for the rest of the five-second budget, one per
     * contended acquire, on exactly the load that makes a pool contend. The assertion is `ignis_inflight()` read **at the moment the waiter wakes**, not at the end:
     * the fake reactor's clock is free, so a timer left running still settles by the time the loop
     * stops and an end-of-test count sees nothing wrong.
     */
    public function testAWaiterWokenByAReleaseDoesNotLeaveItsTimeoutRunning(): void
    {
        // As LoopTestCase does: skip boot() (it would gc_disable() the process) and tell the loop
        // that ignis_publish_stats() is absent, which only boot() would otherwise ask.
        $booted = new \ReflectionProperty(\Ignis\Loop::class, 'booted');
        $publishes = new \ReflectionProperty(\Ignis\Loop::class, 'canPublishStats');
        $wasBooted = $booted->getValue();
        $couldPublish = $publishes->getValue();
        $booted->setValue(null, true);
        $publishes->setValue(null, false);

        try {
            $pool = $this->pool(limit: 1, warmCount: 1, waitMilliseconds: 5000);
            $order = [];

            \Ignis\async(static function () use ($pool, &$order): void {
                $connection = $pool->acquire();
                $order[] = 'holder took it';
                \Ignis\sleep(1);
                $pool->release($connection);
                $order[] = 'holder gave it back';
            });
            $inflightWhenWoken = null;
            \Ignis\async(static function () use ($pool, &$order, &$inflightWhenWoken): void {
                \Ignis\sleep(0);
                $order[] = 'waiter parks';
                $pool->acquire();
                $inflightWhenWoken = \ignis_inflight();
                $order[] = 'waiter woken';
            });

            try {
                \Ignis\Loop::run();
            } catch (\Ignis\Tests\StopLoop) {
            }

            self::assertSame(['holder took it', 'waiter parks', 'holder gave it back', 'waiter woken'], $order);
            self::assertSame(0, $inflightWhenWoken, 'the five-second timeout is called off the moment the wait ends, not when it lapses');
            self::assertSame(0, $pool->stats()['waiting']);
        } finally {
            $booted->setValue(null, $wasBooted);
            $publishes->setValue(null, $couldPublish);
        }
    }

    private function pool(int $limit, int $warmCount, int $waitMilliseconds = 1000, string $reset = ''): ConnectionPool
    {
        $driver = $this->createMock(Driver::class);
        $driver->method('connect')->willReturnCallback(function (): DriverConnection {
            $connection = $this->createMock(DriverConnection::class);
            $this->connected[] = $connection;

            return $connection;
        });

        return new ConnectionPool($driver, ['driver' => 'pdo_pgsql'], $limit, $waitMilliseconds, $warmCount, $reset);
    }
}
