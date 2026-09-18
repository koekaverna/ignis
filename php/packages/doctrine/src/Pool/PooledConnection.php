<?php

declare(strict_types=1);

namespace Ignis\Doctrine\Pool;

use Doctrine\DBAL\Driver\Connection as DriverConnection;
use Doctrine\DBAL\Driver\Middleware\AbstractConnectionMiddleware;

/**
 * The lease itself: a driver connection that goes back to its pool when the request lets go of it.
 *
 * `Doctrine\DBAL\Connection::close()` sets its driver connection to null, and `FiberManager` calls
 * that at request end, so this object's last reference disappears exactly there — which is why the
 * destructor is enough and no framework has to remember to return anything.
 */
final class PooledConnection extends AbstractConnectionMiddleware
{
    private bool $returned = false;

    public function __construct(
        private readonly ConnectionPool $pool,
        private readonly DriverConnection $connection,
    ) {
        parent::__construct($connection);
    }

    public function __destruct()
    {
        $this->returnToPool();
    }

    /** Idempotent: a caller may return the lease early, and the destructor still runs afterwards. */
    public function returnToPool(): void
    {
        if ($this->returned) {
            return;
        }
        $this->returned = true;
        $this->pool->release($this->connection);
    }
}
