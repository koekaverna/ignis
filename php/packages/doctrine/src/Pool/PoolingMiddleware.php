<?php

declare(strict_types=1);

namespace Ignis\Doctrine\Pool;

use Doctrine\DBAL\Driver;
use Doctrine\DBAL\Driver\Middleware;
use Doctrine\DBAL\DriverManager;

/**
 * The switch between the two connection modes, and the home of the pools in pool mode.
 *
 * **Per fiber** (`IGNIS_DOCTRINE_POOL` unset or below 1, the default): `wrap()` hands the driver
 * back untouched, every request opens its own connection and closes it at request end (V-85). No
 * shared state, no limit, and the bill is one connect per request that touches the database.
 *
 * **Pool** (`IGNIS_DOCTRINE_POOL=N`): `connect()` leases from a per-thread pool of at most N
 * connections, opened when the thread boots rather than when the first request needs them, and
 * returned — session state cleared — at request end. The process therefore opens `threads × N`
 * connections and no more, which is the number a database administrator can plan for.
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
     * Arguments are for tests; in an application every value comes from the environment, so the
     * compiled container never bakes in a number that was true when the cache was warmed.
     */
    public function __construct(?int $limit = null, ?int $waitMilliseconds = null, ?int $warmCount = null)
    {
        $this->limit = $limit ?? self::limitFromEnvironment();
        $this->waitMilliseconds = $waitMilliseconds ?? self::environmentInteger('IGNIS_DOCTRINE_POOL_WAIT_MS', 5000);
        $this->warmCount = $warmCount ?? self::environmentInteger('IGNIS_DOCTRINE_POOL_WARM', $this->limit);
    }

    /** How many connections a thread may hold, or 0 for a connection per fiber. */
    public static function limitFromEnvironment(): int
    {
        return \max(0, self::environmentInteger('IGNIS_DOCTRINE_POOL', 0));
    }

    public function wrap(Driver $driver): Driver
    {
        return $this->limit < 1 ? $driver : new PoolingDriver($driver, $this);
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

    private static function environmentInteger(string $name, int $default): int
    {
        $value = \getenv($name);

        return $value === false || !\is_numeric($value) ? $default : (int) $value;
    }
}
