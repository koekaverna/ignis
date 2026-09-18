<?php

declare(strict_types=1);

namespace Ignis\Doctrine\Pool;

use Doctrine\DBAL\Driver;
use Doctrine\DBAL\Driver\Middleware;
use Doctrine\DBAL\DriverManager;

/**
 * The switch between the two connection modes, and the home of the pools in pool mode.
 *
 * **Per fiber** (`pool.size: 0`, the default): `wrap()` hands the driver back untouched, every
 * request opens its own connection and closes it at request end (V-85). No shared state, no limit,
 * and the bill is one connect per request that touches the database.
 *
 * **Pool** (`pool.size: N`): `connect()` leases from a per-thread pool of at most N connections,
 * opened when the thread boots rather than when the first request needs them, and returned — session
 * state cleared — at request end. The process therefore opens `threads × N` connections and no more,
 * which is the number a database administrator can plan for.
 *
 * Configured in `config/packages/ignis_doctrine.yaml` (see `Configuration`), not from the
 * environment: the numbers are service arguments like any other, and a deployment that wants them
 * outside the code writes `%env(int:DB_POOL)%` there.
 *
 * Registered as a `doctrine.middleware`, which DoctrineBundle turns into one instance per connection
 * name, so a pool belongs to one set of connection parameters by construction.
 *
 * @phpstan-import-type Params from DriverManager
 */
final class PoolingMiddleware implements Middleware
{
    /** @var array<string, ConnectionPool> */
    private array $pools = [];
    private readonly int $limit;
    private readonly int $waitMilliseconds;
    private readonly int $warmCount;

    /**
     * @param int $limit connections per thread, or 0 for a connection per fiber
     * @param int $waitMilliseconds how long a fiber waits for a free connection before it is refused
     * @param int|null $warmCount connections opened at boot; null means as many as the limit
     */
    public function __construct(int $limit, int $waitMilliseconds = 5000, ?int $warmCount = null)
    {
        $this->limit = \max(0, $limit);
        $this->waitMilliseconds = $waitMilliseconds;
        $this->warmCount = $warmCount ?? $this->limit;
    }

    /** Whether this is pool mode at all — what `IgnisDoctrineBundle::boot()` asks before warming. */
    public function isPooling(): bool
    {
        return $this->limit >= 1;
    }

    public function wrap(Driver $driver): Driver
    {
        return $this->isPooling() ? new PoolingDriver($driver, $this) : $driver;
    }

    /**
     * The pool for these parameters, filled to the warm count the moment it is created — so a pool
     * asked for at boot (`IgnisDoctrineBundle::boot()`) is cold-started there instead of making the
     * first requests queue behind N handshakes.
     *
     * @param Params $params
     */
    public function poolFor(Driver $driver, array $params): ConnectionPool
    {
        return $this->pools[\md5(\serialize($params))] ??= new ConnectionPool(
            $driver,
            $params,
            $this->limit,
            $this->waitMilliseconds,
            $this->warmCount,
            self::resetStatementFor($params),
        );
    }

    /** @return array<string, array{limit:int, open:int, idle:int, waiting:int}> */
    public function stats(): array
    {
        return \array_map(static fn(ConnectionPool $pool): array => $pool->stats(), $this->pools);
    }

    /**
     * What a connection must run before the next request may have it.
     *
     * PostgreSQL gets the documented expansion of `DISCARD ALL` minus `DEALLOCATE ALL`/`DISCARD
     * PLANS` (V-21): `DISCARD ALL` itself is refused inside the implicit transaction block of a
     * multi-statement string, and keeping the prepared-statement cache is half the point of reusing
     * a connection. Other drivers get nothing and the documentation says so rather than pretending:
     * a reused MySQL or SQLite connection carries its session settings into the next request.
     *
     * @param Params $params
     */
    private static function resetStatementFor(array $params): string
    {
        $driver = $params['driver'] ?? '';
        if ($driver !== 'pdo_pgsql' && $driver !== 'pgsql') {
            return '';
        }

        return 'ROLLBACK; CLOSE ALL; SET SESSION AUTHORIZATION DEFAULT; RESET ALL; UNLISTEN *;'
            . ' SELECT pg_advisory_unlock_all(); DISCARD TEMP; DISCARD SEQUENCES';
    }
}
