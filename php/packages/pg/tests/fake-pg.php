<?php

declare(strict_types=1);

/**
 * The five `ignis_pg_*` module functions, faked over `tests/fake-reactor.php`'s injectable
 * completion queue so `Ignis\Pg\Pool` and `Lease` can be unit-tested with no PostgreSQL.
 *
 * The bound is the fake reactor's own: E14 against a real server is the contract. What this covers
 * is the userland half — the lease-per-fiber rule, release idempotence, and the statement order a
 * transaction produces.
 */

namespace Ignis\Tests\Pg {
    use Ignis\Tests\FakeReactor;

    final class FakePg
    {
        private static int $nextOp = 5000;
        private static int $nextLease = 1;
        public static int $nextPool = 1;
        /** Acquire answers straight away (an idle connection) instead of through the reactor. */
        public static bool $acquireIsIdle = false;
        /** @var list<string> every statement this pool was asked to run, in order */
        public static array $statements = [];
        /** @var list<array{lease: int, reset: bool}> */
        public static array $releases = [];
        /** sql => the error message it answers with instead of rows */
        public static ?string $failSql = null;
        /** @var array<string, mixed>|null overrides the default {sql, params} row when set */
        public static ?array $queryRow = null;
        /** @var array<string, mixed>|null */
        public static ?array $stats = null;

        public static function reset(): void
        {
            self::$nextOp = 5000;
            self::$nextLease = 1;
            self::$nextPool = 1;
            self::$acquireIsIdle = false;
            self::$statements = [];
            self::$releases = [];
            self::$failSql = null;
            self::$queryRow = null;
            self::$stats = ['idle' => 4, 'leased' => 0];
        }

        /** An op whose completion is already waiting, so awaitOp() settles on the first poll. */
        public static function completed(mixed $payload): int
        {
            $op = self::$nextOp++;
            FakeReactor::inject($op, $payload);

            return $op;
        }

        public static function nextLease(): int
        {
            return self::$nextLease++;
        }
    }
}

namespace {
    use Ignis\Tests\Pg\FakePg;

    if (!function_exists('ignis_pg_open')) {
        function ignis_pg_open(string $dsn, int $max): int
        {
            return FakePg::$nextPool++;
        }
    }

    if (!function_exists('ignis_pg_acquire')) {
        /** @return int|array{lease: int} */
        function ignis_pg_acquire(int $pool): int|array
        {
            $lease = FakePg::nextLease();

            return FakePg::$acquireIsIdle ? ['lease' => $lease] : FakePg::completed(json_encode(['lease' => $lease]));
        }
    }

    if (!function_exists('ignis_pg_query')) {
        function ignis_pg_query(int $lease, string $sql, string $paramsJson): int
        {
            FakePg::$statements[] = $sql;
            if (FakePg::$failSql === $sql) {
                return FakePg::completed(['kind' => 'error', 'message' => 'ERROR: ' . $sql]);
            }

            $row = FakePg::$queryRow ?? ['sql' => $sql, 'params' => $paramsJson];

            return FakePg::completed(json_encode(['rows' => [$row], 'affected' => 1]));
        }
    }

    if (!function_exists('ignis_pg_release')) {
        function ignis_pg_release(int $lease, bool $reset): int
        {
            FakePg::$releases[] = ['lease' => $lease, 'reset' => $reset];

            return FakePg::completed('released');
        }
    }

    if (!function_exists('ignis_pg_stats')) {
        /** @return array<string, mixed>|null */
        function ignis_pg_stats(int $pool): ?array
        {
            return FakePg::$stats;
        }
    }
}
