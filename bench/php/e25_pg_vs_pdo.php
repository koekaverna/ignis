<?php

declare(strict_types=1);

/*
 * E25: the number V-21 said was "not measured yet" and still was — what a query costs through the
 * runtime's own PostgreSQL client against the same query through `pdo_pgsql`, parked.
 *
 * It decides whether `Ignis\Pg` (crates/ignis/src/pg.rs, 697 lines of Rust) still earns its place
 * now that `ignis/doctrine` pools `pdo_pgsql` connections in userland. Same box, same database, same
 * process, same statement.
 *
 * Usage: ignis bench/php/e25_pg_vs_pdo.php [queries] [fibers]
 *   env: PG_DSN (postgres://…), PG_PDO_DSN (pgsql:host=…), PG_USER, PG_PASSWORD
 */

require __DIR__ . '/../../php/packages/runtime/src/ignis.php';
require __DIR__ . '/../../php/packages/pg/src/ignis-pg.php';

$queries = (int) ($argv[1] ?? 2000);
$fibers = (int) ($argv[2] ?? 20);
$dsn = getenv('PG_DSN') ?: 'postgres://ignis:ignis@e24-pg/ignis';
$pdoDsn = getenv('PG_PDO_DSN') ?: 'pgsql:host=e24-pg;dbname=ignis';
$user = getenv('PG_USER') ?: 'ignis';
$password = getenv('PG_PASSWORD') ?: 'ignis';

$report = static function (string $arm, int $operations, float $seconds): void {
    printf("e25 %-34s %8.1f us/query  %9.0f q/s\n", $arm, $seconds / $operations * 1e6, $operations / $seconds);
};

Ignis\async(static function () use ($dsn, $pdoDsn, $user, $password, $queries, $fibers, $report): void {
    $pool = new Ignis\Pg\Pool($dsn, 20);

    $pool->transaction(static function (Ignis\Pg\Lease $lease) use ($queries, $report): void {
        $lease->query('SELECT 1');                                   // warm the statement cache
        $started = microtime(true);
        for ($i = 0; $i < $queries; $i++) {
            $lease->query('SELECT 1');
        }
        $report('Ignis\Pg, one held lease', $queries, microtime(true) - $started);
    });

    $pool->query('SELECT 1');
    $started = microtime(true);
    for ($i = 0; $i < $queries; $i++) {
        $pool->query('SELECT 1');
    }
    $report('Ignis\Pg, acquire+query+release', $queries, microtime(true) - $started);

    $pdo = new PDO($pdoDsn, $user, $password, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $pdo->query('SELECT 1')->fetchAll();
    $started = microtime(true);
    for ($i = 0; $i < $queries; $i++) {
        $pdo->query('SELECT 1')->fetchAll();
    }
    $report('pdo_pgsql, one held connection', $queries, microtime(true) - $started);

    $statement = $pdo->prepare('SELECT 1');
    $started = microtime(true);
    for ($i = 0; $i < $queries; $i++) {
        $statement->execute();
        $statement->fetchAll();
    }
    $report('pdo_pgsql, prepared once', $queries, microtime(true) - $started);

    // Concurrency: the shape a server actually has — many fibers, one thread.
    $each = max(1, intdiv($queries, $fibers));
    $started = microtime(true);
    Ignis\all(array_map(static fn(): Ignis\Future => Ignis\async(static function () use ($pool, $each): void {
        for ($i = 0; $i < $each; $i++) {
            $pool->query('SELECT 1');
        }
    }), range(1, $fibers)));
    $report("Ignis\\Pg, $fibers fibers", $each * $fibers, microtime(true) - $started);

    // Prepared, because Ignis\Pg caches the statement per connection and comparing it against
    // pdo_pgsql re-parsing every time would measure the parser, not the two paths.
    $statements = array_map(static function () use ($pdoDsn, $user, $password): PDOStatement {
        $own = new PDO($pdoDsn, $user, $password, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $prepared = $own->prepare('SELECT 1');
        $prepared->execute();
        $prepared->fetchAll();

        return $prepared;
    }, range(1, $fibers));
    $started = microtime(true);
    Ignis\all(array_map(static fn(PDOStatement $own): Ignis\Future => Ignis\async(static function () use ($own, $each): void {
        for ($i = 0; $i < $each; $i++) {
            $own->execute();
            $own->fetchAll();
        }
    }), $statements));
    $report("pdo_pgsql, $fibers fibers, prepared", $each * $fibers, microtime(true) - $started);
});
Ignis\Loop::run();
