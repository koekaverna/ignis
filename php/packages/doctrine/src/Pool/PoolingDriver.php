<?php

declare(strict_types=1);

namespace Ignis\Doctrine\Pool;

use Doctrine\DBAL\Driver;
use Doctrine\DBAL\Driver\Connection as DriverConnection;
use Doctrine\DBAL\Driver\Middleware\AbstractDriverMiddleware;
use SensitiveParameter;

/** The driver seam: `connect()` takes a lease from the pool for these parameters. */
final class PoolingDriver extends AbstractDriverMiddleware
{
    public function __construct(
        private readonly Driver $driver,
        private readonly PoolingMiddleware $pools,
    ) {
        parent::__construct($driver);
    }

    /**
     * {@inheritDoc}
     */
    public function connect(
        #[SensitiveParameter]
        array $params,
    ): DriverConnection {
        $pool = $this->pools->poolFor($this->driver, $params);

        return new PooledConnection($pool, $pool->acquire());
    }
}
