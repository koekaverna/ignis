<?php

// M4-8 (ADR-0015, ADR-0012): does the runtime-owned PG pool survive a PHP worker
// thread's death while it holds a lease? Routes:
//   /lease-hold?ms=N  acquire a lease, dirty the session (search_path + a temp
//                     table), sleep N ms while holding it, release, respond.
//   /fatal            trigger_error(E_USER_ERROR) — same bailout as hello_server's.
//   /pool             ignis_pg_stats(pool) + ignis_stats() as JSON.
//   /probe            acquire a fresh lease, SHOW search_path and read pg_temp.t
//                     (expect the default search_path and "does not exist" —
//                     i.e. the session was reset before the connection went back
//                     to idle).
declare(strict_types=1);
require __DIR__ . '/../../php/packages/runtime/src/ignis.php';
require __DIR__ . '/../../php/packages/pg/src/ignis-pg.php';

use Ignis\Http\Request;
use Ignis\Http\Response;
use Ignis\Loop;
use Ignis\Pg\Pool;
use Ignis\Pg\QueryError;

$dsn = getenv('PG_DSN');
if ($dsn === false || $dsn === '') {
    fwrite(STDERR, 'PG_DSN is required, e.g. PG_DSN="host=/tmp/ignis-pgsock user=ignis password=ignis dbname=ignis"' . "\n");
    exit(2);
}
$max = (int) (getenv('POOL_MAX') ?: 2);
$listen = getenv('IGNIS_LISTEN') ?: '127.0.0.1:8150';

// Every --threads worker runs this whole script from scratch, and ignis_pg_open()
// mints a brand-new runtime pool on every call (crates/ignis/src/pg.rs has no
// dsn dedup) — so without coordination each worker would get its own separate
// pool instead of the one process-wide pool ADR-0015 describes. Elect the opener
// with a flock on a tmp file: first thread in writes the id, the rest read it.
// ponytail: file + flock, not a real leader-election service — good enough for
// N=threads at process start, not a general primitive.
$idFile = getenv('IGNIS_M4_POOL_IDFILE') ?: '/tmp/ignis-m4-pool-id';
$lock = fopen($idFile . '.lock', 'c');
flock($lock, LOCK_EX);
$existing = trim((string) @file_get_contents($idFile));
if ($existing !== '' && ctype_digit($existing)) {
    $poolId = (int) $existing;
} else {
    $poolId = \ignis_pg_open($dsn, $max);
    file_put_contents($idFile, (string) $poolId);
}
flock($lock, LOCK_UN);
fclose($lock);

/** @internal raw pg helpers: the Pool/Lease classes in ignis-pg.php always call
 * ignis_pg_open() in their constructor, so they can't wrap an id shared this way. */
function pg_lease(int $poolId): int
{
    $r = \ignis_pg_acquire($poolId);
    return is_array($r)
        ? (int) $r['lease']
        : (int) json_decode(Pool::result(Loop::awaitOp($r)), true, 8, JSON_THROW_ON_ERROR)['lease'];
}
function pg_run(int $lease, string $sql, array $params = []): array
{
    $json = json_encode(array_values($params), JSON_THROW_ON_ERROR);
    $r = Pool::result(Loop::awaitOp(\ignis_pg_query($lease, $sql, $json)));
    return json_decode($r, true, 512, JSON_THROW_ON_ERROR);
}
function pg_rows(int $lease, string $sql, array $params = []): array
{
    return pg_run($lease, $sql, $params)['rows'];
}
function pg_release(int $lease, bool $reset = true): void
{
    Pool::result(Loop::awaitOp(\ignis_pg_release($lease, $reset)));
}

Ignis\serve(static function (Request $req) use ($poolId, $listen): Response {
    return match ($req->path()) {
        '/lease-hold' => (static function () use ($req, $poolId): Response {
            $ms = (int) ($req->query('ms') ?? 1000);
            $lease = pg_lease($poolId);
            pg_run($lease, 'SET search_path TO leaked');
            pg_run($lease, 'CREATE TEMP TABLE t(x int)');
            try {
                Ignis\sleep($ms);
            } finally {
                // Only reached if this fiber's thread is still alive; a
                // trigger_error(E_USER_ERROR) bailout on this thread skips this
                // entirely — that's the failure mode M4-8 measures.
                pg_release($lease, true);
            }
            return Response::text("held $ms ms\n");
        })(),
        '/fatal' => (static function (): Response {
            // Same bailout E12 uses (V-17): must kill only this thread.
            trigger_error('deliberate fatal for M4-8', E_USER_ERROR);
            return Response::text("unreachable\n");
        })(),
        '/pool' => (static function () use ($poolId): Response {
            return Response::json([
                'pool_id' => $poolId,
                'pool'    => \ignis_pg_stats($poolId),
                'runtime' => \ignis_stats(),
            ]);
        })(),
        '/probe' => (static function () use ($poolId): Response {
            $lease = pg_lease($poolId);
            $searchPath = pg_rows($lease, 'SHOW search_path')[0]['search_path'] ?? null;
            try {
                $rows = pg_rows($lease, 'SELECT count(*) FROM pg_temp.t');
                $tempProbe = 'NO ERROR: ' . json_encode($rows);
            } catch (QueryError $e) {
                $tempProbe = 'error: ' . $e->getMessage();
            }
            pg_release($lease, true);
            return Response::json(['search_path' => $searchPath, 'temp_probe' => $tempProbe]);
        })(),
        default => Response::text("not found\n", 404),
    };
}, $listen);
