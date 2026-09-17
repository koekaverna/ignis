<?php

/**
 * Ignis PostgreSQL client (E14, ADR-0015): connections belong to the runtime; PHP holds leases.
 * Module functions: ignis_pg_open(dsn, max): int; ignis_pg_acquire(pool): op; ignis_pg_query(lease, sql, paramsJson): op;
 * ignis_pg_release(lease, reset): op; ignis_pg_stats(pool): ?array.
 */
declare(strict_types=1);

namespace Ignis\Pg;

use Ignis\Loop;
use Ignis\Scope;

final class LeaseError extends \LogicException {}

final class QueryError extends \RuntimeException {}

/** A process-wide pool; `max` connections shared by every PHP thread and fiber. */
final class Pool
{
    public readonly int $id;

    public function __construct(public readonly string $dsn, public readonly int $max = 10)
    {
        $this->id = \ignis_pg_open($dsn, $max);
    }

    /** Lease a connection for this fiber. A second acquire in the same fiber is an error, never a wait. */
    public function acquire(): Lease
    {
        $key = 'ignis.pg.lease.' . $this->id;
        if (Scope::get($key) !== null) {
            throw new LeaseError('this fiber already holds a lease from pool ' . $this->id);
        }
        $r = \ignis_pg_acquire($this->id);
        $leaseId = \is_array($r)
            ? (int) $r['lease'] // idle connection: no reactor hop
            : (int) json_decode(self::result(Loop::awaitOp($r)), true, 8, JSON_THROW_ON_ERROR)['lease'];
        $lease = new Lease($this, $leaseId, $key);
        Scope::set($key, $lease);
        return $lease;
    }

    /** One statement on a fresh lease: acquire → query → release. @return list<array<string,mixed>> */
    public function query(string $sql, array $params = []): array
    {
        $l = $this->acquire();
        try {
            return $l->query($sql, $params);
        } finally {
            $l->release();
        }
    }

    public function exec(string $sql, array $params = []): int
    {
        $l = $this->acquire();
        try {
            return $l->exec($sql, $params);
        } finally {
            $l->release();
        }
    }

    /** Runs $fn(Lease) inside BEGIN/COMMIT on one leased connection; any throwable rolls back. */
    public function transaction(callable $fn): mixed
    {
        $l = $this->acquire();
        try {
            $l->exec('BEGIN');
            try {
                $r = $fn($l);
                $l->exec('COMMIT');
                return $r;
            } catch (\Throwable $e) {
                try {
                    $l->exec('ROLLBACK');
                } catch (\Throwable) { /* reset on release rolls back anyway */
                }
                throw $e;
            }
        } finally {
            $l->release();
        }
    }

    public function stats(): ?array
    {
        return \ignis_pg_stats($this->id);
    }

    /** @internal */
    public static function result(mixed $payload): mixed
    {
        if (\is_array($payload) && ($payload['kind'] ?? '') === 'error') {
            throw new QueryError((string) $payload['message']);
        }
        return $payload;
    }
}

/** One connection, held by one fiber. Release (or let it go out of scope) to return it reset to the pool. */
final class Lease
{
    private bool $released = false;

    /** @internal */
    public function __construct(private readonly Pool $pool, public readonly int $id, private readonly string $scopeKey) {}

    /** @return list<array<string,mixed>> */
    public function query(string $sql, array $params = []): array
    {
        return $this->run($sql, $params)['rows'];
    }

    public function exec(string $sql, array $params = []): int
    {
        return (int) $this->run($sql, $params)['affected'];
    }

    public function backendPid(): int
    {
        return (int) $this->query('SELECT pg_backend_pid() AS pid')[0]['pid'];
    }

    /** Return the connection; `reset` runs ROLLBACK; DISCARD ALL on the runtime side first. */
    public function release(bool $reset = true): void
    {
        if ($this->released) {
            return;
        }
        $this->released = true;
        Scope::set($this->scopeKey, null);
        Pool::result(Loop::awaitOp(\ignis_pg_release($this->id, $reset)));
    }

    public function __destruct()
    {
        if (!$this->released) {
            // Fire-and-forget: nobody waits for the op; the loop drops its completion.
            $this->released = true;
            Scope::set($this->scopeKey, null);
            \ignis_pg_release($this->id, true);
        }
    }

    private function run(string $sql, array $params): array
    {
        if ($this->released) {
            throw new LeaseError('lease already released');
        }
        $json = json_encode(array_values($params), JSON_THROW_ON_ERROR);
        $r = Pool::result(Loop::awaitOp(\ignis_pg_query($this->id, $sql, $json)));
        return json_decode($r, true, 512, JSON_THROW_ON_ERROR);
    }
}
