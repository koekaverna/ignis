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

/**
 * The pool could not hand out a connection: the wait hit `IGNIS_PG_ACQUIRE_TIMEOUT_MS`, or the
 * breaker is open and we have stopped calling PostgreSQL for a cooldown.
 *
 * Separate from `QueryError` because no query happened, and separate from `LeaseError` because
 * nothing was used wrongly -- this is the database being unreachable, which is a runtime condition
 * an operator acts on, not a programming mistake. The message says which of the two it was.
 */
final class PoolError extends \RuntimeException {}

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
            ? self::leaseIdFromCompletion($r) // idle connection: no reactor hop
            : self::leaseIdFromCompletion(self::decodedJson(self::orThrow(Loop::awaitOp($r), PoolError::class), PoolError::class));
        $lease = new Lease($leaseId, $key);
        Scope::set($key, $lease);
        return $lease;
    }

    /**
     * One statement on a fresh lease: acquire → query → release.
     * @param  array<array-key, mixed> $params
     * @return list<array<string,mixed>>
     */
    public function query(string $sql, array $params = []): array
    {
        $lease = $this->acquire();
        try {
            return $lease->query($sql, $params);
        } finally {
            $lease->release();
        }
    }

    /** @param array<array-key, mixed> $params */
    public function exec(string $sql, array $params = []): int
    {
        $lease = $this->acquire();
        try {
            return $lease->exec($sql, $params);
        } finally {
            $lease->release();
        }
    }

    /** Runs $fn(Lease) inside BEGIN/COMMIT on one leased connection; any throwable rolls back. */
    public function transaction(callable $fn): mixed
    {
        $lease = $this->acquire();
        try {
            $lease->exec('BEGIN');
            try {
                $result = $fn($lease);
                $lease->exec('COMMIT');
                return $result;
            } catch (\Throwable $e) {
                try {
                    $lease->exec('ROLLBACK');
                } catch (\Throwable) { /* reset on release rolls back anyway */
                }
                throw $e;
            }
        } finally {
            $lease->release();
        }
    }

    /** @return null|array<string, mixed> [idle, created, available]; null when the pool id is unknown. */
    public function stats(): ?array
    {
        return \ignis_pg_stats($this->id);
    }

    /** @internal */
    public static function result(mixed $payload): mixed
    {
        return self::orThrow($payload, QueryError::class);
    }

    /**
     * @param class-string<\Throwable> $error
     *
     * @internal
     */
    public static function orThrow(mixed $payload, string $error): mixed
    {
        if (\is_array($payload) && ($payload['kind'] ?? '') === 'error') {
            $message = $payload['message'] ?? null;
            throw new $error(\is_string($message) ? $message : 'the reactor reported an error with no message');
        }

        return $payload;
    }

    /**
     * A completion that is not an error is JSON off the wire, never a fact about its shape.
     * @param class-string<\Throwable> $error
     * @return array<string, mixed>
     * @internal
     */
    public static function decodedJson(mixed $payload, string $error): array
    {
        if (!\is_string($payload)) {
            throw new $error('expected a JSON string from the reactor, got ' . get_debug_type($payload));
        }
        $decoded = json_decode($payload, true, 512, JSON_THROW_ON_ERROR);
        if (!\is_array($decoded)) {
            throw new $error('expected a JSON object from the reactor, got ' . get_debug_type($decoded));
        }

        return self::withStringKeys($decoded, $error);
    }

    /**
     * A JSON object always decodes to string keys, except the one PHP itself breaks: a key that
     * looks like an integer is silently rekeyed as one. Rebuilding the array is what turns
     * "every key happened to be a string" into a fact PHPStan can carry forward.
     * @param array<array-key, mixed> $object
     * @param class-string<\Throwable> $error
     * @return array<string, mixed>
     *
     * @internal
     */
    public static function withStringKeys(array $object, string $error): array
    {
        $out = [];
        foreach ($object as $field => $value) {
            if (!\is_string($field)) {
                throw new $error('expected a JSON object with string keys, found ' . get_debug_type($field) . ' ' . $field);
            }
            $out[$field] = $value;
        }

        return $out;
    }

    /** @param array<string, mixed> $completion */
    private static function leaseIdFromCompletion(array $completion): int
    {
        $lease = $completion['lease'] ?? null;
        if (!\is_int($lease)) {
            throw new PoolError('malformed pg_acquire completion: missing or non-int "lease"');
        }

        return $lease;
    }
}

/** One connection, held by one fiber. Release (or let it go out of scope) to return it reset to the pool. */
final class Lease
{
    private bool $released = false;

    /** @internal */
    public function __construct(public readonly int $id, private readonly string $scopeKey) {}

    /**
     * @param  array<array-key, mixed> $params
     * @return list<array<string,mixed>>
     */
    public function query(string $sql, array $params = []): array
    {
        return $this->run($sql, $params)['rows'];
    }

    /** @param array<array-key, mixed> $params */
    public function exec(string $sql, array $params = []): int
    {
        return (int) $this->run($sql, $params)['affected'];
    }

    public function backendPid(): int
    {
        $pid = $this->query('SELECT pg_backend_pid() AS pid')[0]['pid'];
        if (!\is_int($pid)) {
            throw new QueryError('pg_backend_pid() did not return an integer');
        }

        return $pid;
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

    /**
     * `array_values` because the wire format is positional: an associative array would encode as a
     * JSON object and PostgreSQL would see no parameters at all.
     * @param  array<array-key, mixed> $params
     * @return array{rows: list<array<string,mixed>>, affected: int}
     */
    private function run(string $sql, array $params): array
    {
        if ($this->released) {
            throw new LeaseError('lease already released');
        }
        $json = json_encode(array_values($params), JSON_THROW_ON_ERROR);
        $decoded = Pool::decodedJson(Pool::result(Loop::awaitOp(\ignis_pg_query($this->id, $sql, $json))), QueryError::class);

        return self::asQueryResult($decoded);
    }

    /**
     * @param array<string, mixed> $decoded
     * @return array{rows: list<array<string, mixed>>, affected: int}
     */
    private static function asQueryResult(array $decoded): array
    {
        $rows = $decoded['rows'] ?? null;
        $affected = $decoded['affected'] ?? null;
        if (!\is_array($rows) || !\is_int($affected)) {
            throw new QueryError('malformed query completion: expected {rows: array, affected: int}');
        }

        return ['rows' => array_values(array_map(self::asRow(...), $rows)), 'affected' => $affected];
    }

    /** @return array<string, mixed> */
    private static function asRow(mixed $row): array
    {
        if (!\is_array($row)) {
            throw new QueryError('malformed query completion: a row is not an array');
        }

        return Pool::withStringKeys($row, QueryError::class);
    }
}
