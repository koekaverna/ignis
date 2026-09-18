<?php

declare(strict_types=1);

namespace Ignis\Doctrine\Pool;

use Doctrine\DBAL\Driver;
use Doctrine\DBAL\Driver\Connection as DriverConnection;
use Doctrine\DBAL\DriverManager;
use Ignis\Future;

/**
 * A fixed set of database connections, leased to one fiber at a time (E24/V-85).
 *
 * One pool per thread and per connection parameters, because a PHP thread has its own container and
 * its own statics: the process opens `threads × limit` connections at most, and that number is
 * knowable in advance instead of tracking how many requests happen to be in flight.
 *
 * The lease is what makes it safe. libpq is not reentrant per connection, so a connection that two
 * fibers can reach at once hands one of them the other's rows (V-85); here a connection is either
 * idle in this pool or held by exactly one fiber, never both.
 *
 * @phpstan-import-type Params from DriverManager
 */
final class ConnectionPool
{
    /** @var list<DriverConnection> connections nobody is using */
    private array $idle = [];
    /** @var list<Future> fibers waiting for capacity; each is resolved with null, meaning "look again" */
    private array $waiting = [];
    private int $open = 0;

    /**
     * @param Params $params the DBAL connection parameters this pool connects with
     * @param int $limit connections this pool may open, total
     * @param int $waitMilliseconds how long a fiber waits for a free connection before giving up
     * @param int $warmCount connections opened immediately, so the first requests skip the handshake
     * @param string $resetStatement SQL that returns a connection to a clean session, `''` for none
     */
    public function __construct(
        private readonly Driver $driver,
        private readonly array $params,
        private readonly int $limit,
        private readonly int $waitMilliseconds,
        int $warmCount,
        private readonly string $resetStatement,
    ) {
        $this->warm($warmCount);
    }

    /** Opens connections up front, so the first requests do not pay for the handshake. */
    public function warm(int $count): void
    {
        while ($this->open < \min($count, $this->limit)) {
            $this->idle[] = $this->connect();
        }
    }

    /**
     * This fiber's connection. Parks while the pool is at its limit and everything is leased out;
     * a fiber that waits longer than the configured time gets a `PoolTimeoutException` rather than
     * waiting forever behind a stuck request.
     */
    public function acquire(): DriverConnection
    {
        $deadline = \microtime(true) + $this->waitMilliseconds / 1000;
        while (true) {
            $connection = \array_pop($this->idle);
            if ($connection !== null) {
                return $connection;
            }
            if ($this->open < $this->limit) {
                return $this->connect();
            }
            $this->waitForCapacity($deadline);
        }
    }

    /**
     * Gives the connection back, in a state the next request can trust: whatever session settings,
     * temporary tables or advisory locks the last request left are cleared first (the expansion of
     * `DISCARD ALL` that works inside an implicit transaction block, V-21). A connection that cannot
     * be reset is dropped instead of handed on — which also makes the reset a liveness check.
     */
    public function release(DriverConnection $connection): void
    {
        if ($this->reset($connection)) {
            $this->idle[] = $connection;
        } else {
            $this->open--;
        }
        $this->wakeOneWaiter();
    }

    /** @return array{limit:int, open:int, idle:int, waiting:int} */
    public function stats(): array
    {
        return ['limit' => $this->limit, 'open' => $this->open, 'idle' => \count($this->idle), 'waiting' => \count($this->waiting)];
    }

    private function connect(): DriverConnection
    {
        $this->open++;
        try {
            return $this->driver->connect($this->params);
        } catch (\Throwable $failure) {
            $this->open--;
            $this->wakeOneWaiter();   // the slot this attempt held is free again, and someone is queued for it
            throw $failure;
        }
    }

    private function waitForCapacity(float $deadline): void
    {
        $remaining = $deadline - \microtime(true);
        if ($remaining <= 0) {
            throw new PoolTimeoutException(\sprintf(
                'no database connection became free within %d ms (pool limit %d, %d waiting)',
                $this->waitMilliseconds,
                $this->limit,
                \count($this->waiting),
            ));
        }

        $future = new Future();
        $this->waiting[] = $future;
        $timer = \ignis_submit_sleep((int) \ceil($remaining * 1000));
        $this->rejectWhenTheWaitIsOver($future, $timer);

        try {
            $future->await();
        } finally {
            \ignis_cancel($timer);   // woken early: end the timer's fiber now, do not leave it asleep
        }
    }

    /**
     * A fiber of its own, because the runtime's timers are fibers: nothing here blocks the thread.
     *
     * It parks on the op rather than on `Ignis\sleep()` so the waiter can call it off. Sleeping the
     * full budget and *then* checking `isDone()` meant a waiter woken after 2 ms still held a parked
     * fiber for the remaining five seconds — one per contended acquire, at ~14.7 kB of marginal RSS
     * each (V-37), on exactly the workload that made the pool contend in the first place.
     */
    private function rejectWhenTheWaitIsOver(Future $future, int $timer): void
    {
        \Ignis\async(function () use ($future, $timer): void {
            \Ignis\Loop::awaitOp($timer);
            if ($future->isDone()) {
                return;
            }
            $this->waiting = \array_values(\array_filter($this->waiting, static fn(Future $waiter): bool => $waiter !== $future));
            $future->reject(new PoolTimeoutException(\sprintf(
                'no database connection became free within %d ms (pool limit %d, %d waiting)',
                $this->waitMilliseconds,
                $this->limit,
                \count($this->waiting),
            )));
        });
    }

    private function wakeOneWaiter(): void
    {
        while ($this->waiting !== []) {
            $waiter = \array_shift($this->waiting);
            if (!$waiter->isDone()) {
                $waiter->resolve(null);
                return;
            }
        }
    }

    private function reset(DriverConnection $connection): bool
    {
        if ($this->resetStatement === '') {
            return true;
        }
        try {
            $connection->exec($this->resetStatement);

            return true;
        } catch (\Throwable) {
            return false;
        }
    }
}
