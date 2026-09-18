<?php

// E14 / H23: runtime-owned PostgreSQL pool with per-fiber leases. Env: PG_DSN, POOL (default 20), FIBERS (default 200), N (SELECT 1 count).
declare(strict_types=1);
require __DIR__ . '/../../php/packages/runtime/src/ignis.php';
require __DIR__ . '/../../php/packages/pg/src/ignis-pg.php';

use Ignis\Pg\LeaseError;
use Ignis\Pg\Pool;

$dsn = getenv('PG_DSN');
if ($dsn === false || $dsn === '') {
    fwrite(STDERR, 'PG_DSN is required, e.g. PG_DSN="host=/tmp/ignis-pgsock user=ignis password=ignis dbname=ignis"' . "\n");
    exit(2);
}
$poolSize = (int) (getenv('POOL') ?: 20);
$fibers = (int) (getenv('FIBERS') ?: 200);
$n = (int) (getenv('N') ?: 2000);
$pool = new Pool($dsn, $poolSize);

$main = Ignis\async(static function () use ($pool, $poolSize, $fibers, $n): void {
    try {
        // 1. Concurrency: FIBERS × pg_sleep(0.1) through POOL connections (cold: includes connects; warm: pool already filled).
        foreach (['cold', 'warm'] as $phase) {
            $t0 = hrtime(true);
            $futures = [];
            for ($i = 0; $i < $fibers; $i++) {
                $futures[] = Ignis\async(static fn() => $pool->query('SELECT pg_sleep(0.1)'));
            }
            Ignis\all($futures);
            $ms = (hrtime(true) - $t0) / 1e6;
            printf("concurrency %s: %d fibers x pg_sleep(0.1) through pool=%d: %.0f ms (ideal %.0f ms) stats=%s\n", $phase, $fibers, $poolSize, $ms, ceil($fibers / $poolSize) * 100, json_encode($pool->stats()));
        }

        // 2. Transaction pins the lease: both statements on one backend pid; second acquire throws without an op.
        $pids = $pool->transaction(static function (Ignis\Pg\Lease $l) use ($pool): array {
            $a = $l->backendPid();
            $l->exec('CREATE TEMP TABLE t14 (id int)');
            $l->exec('INSERT INTO t14 VALUES ($1), ($2)', [1, 2]);
            $b = $l->backendPid();
            $before = Ignis\Loop::$resumes;
            $t = hrtime(true);
            try {
                $pool->acquire();
                $err = 'NO ERROR';
            } catch (LeaseError $e) {
                $err = 'LeaseError in ' . round((hrtime(true) - $t) / 1e3, 1) . ' us, ops submitted: ' . (Ignis\Loop::$resumes - $before);
            }
            $rows = $l->query('SELECT count(*)::int AS c FROM t14');
            return [$a, $b, $err, $rows[0]['c']];
        });
        printf("transaction: pid %d == %d (%s); second acquire: %s; rows in temp table: %d\n", $pids[0], $pids[1], $pids[0] === $pids[1] ? 'same' : 'DIFFERENT', $pids[2], $pids[3]);

        // 3. Session reset: SET inside a lease must not leak; the temp table must be gone.
        $l = $pool->acquire();
        $l->exec("SET search_path TO leaked_schema, public");
        $pidA = $l->backendPid();
        $l->release();
        $seen = 0;
        for ($i = 0; $i < 50; $i++) { // find the same backend again
            $l = $pool->acquire();
            if ($l->backendPid() === $pidA) {
                $sp = $l->query('SHOW search_path')[0]['search_path'];
                $tmp = $l->query("SELECT count(*)::int AS c FROM pg_tables WHERE tablename = 't14'")[0]['c'];
                $l->release();
                printf("reset: same backend %d: search_path=%s (leaked? %s), temp table rows visible: %d\n", $pidA, $sp, str_contains($sp, 'leaked') ? 'YES' : 'no', $tmp);
                $seen = 1;
                break;
            }
            $l->release();
        }
        if (!$seen) {
            echo "reset: backend $pidA not re-leased in 50 tries\n";
        }

        // 4. Types round trip.
        $r = $pool->query('SELECT $1::int AS i, $2::text AS s, $3::bool AS b, $4::float8 AS f, $5::jsonb AS j, NULL::int AS z, $6::bytea AS y', [42, 'héllo', true, 1.5, ['k' => [1, 2]], 'AQID']);
        printf("types: %s\n", json_encode($r[0]));
        try {
            $pool->query('SELECT $1::numeric AS n', [1]);
        } catch (Ignis\Pg\QueryError $e) {
            printf("unsupported type is an error: %s\n", substr($e->getMessage(), 0, 90));
        }

        // 5. Per-query cost: N sequential SELECT 1 on one lease, then N on pool->query (acquire+release each), then N concurrent (pool size).
        $l = $pool->acquire();
        $t = hrtime(true);
        for ($i = 0; $i < $n; $i++) {
            $l->query('SELECT 1 AS x');
        }
        $seq = (hrtime(true) - $t) / 1e3 / $n;
        $l->release();
        $t = hrtime(true);
        for ($i = 0; $i < $n; $i++) {
            $pool->query('SELECT 1 AS x');
        }
        $pq = (hrtime(true) - $t) / 1e3 / $n;
        $t = hrtime(true);
        $fs = [];
        for ($i = 0; $i < $n; $i++) {
            $fs[] = Ignis\async(static fn() => $pool->query('SELECT 1 AS x'));
        }
        Ignis\all($fs);
        $conc = (hrtime(true) - $t) / 1e3;
        printf("per query: %.1f us sequential on one lease; %.1f us per pool->query (acquire+query+ROLLBACK;DISCARD ALL+release); %d concurrent queries in %.1f ms = %.0f q/s on one PHP thread\n", $seq, $pq, $n, $conc / 1e3, $n / ($conc / 1e6));
    } catch (\Throwable $e) {
        fwrite(STDERR, "FAILED: " . $e::class . ': ' . $e->getMessage() . "\n");
        exit(1);
    }
});
Ignis\Loop::run();
