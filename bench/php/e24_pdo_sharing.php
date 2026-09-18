<?php

declare(strict_types=1);

/*
 * E24: what happens when two fibers use one PostgreSQL connection at the same time?
 *
 * That is not a hypothetical shape — it is the one Symfony's container produces today. The
 * EntityManager is per fiber (V-69) but `doctrine.dbal.default_connection` is a plain shared
 * service, so every fiber's manager gets the same `Doctrine\DBAL\Connection` and the same PDO
 * handle underneath it. A query parks the fiber inside libpq, the next fiber runs, and both are
 * inside one connection.
 *
 * Arms (`own` is the control; it must pass, or the harness is measuring nothing):
 *   own     one handle per fiber  — concurrent and correct
 *   shared  one handle, N fibers  — the shape under test
 *
 * Usage: ignis bench/php/e24_pdo_sharing.php <own|shared> [fibers]   env: PG_PDO_DSN, PG_USER, PG_PASSWORD
 */

require __DIR__ . '/../../php/packages/runtime/src/ignis.php';

$mode = $argv[1] ?? 'shared';
$fibers = (int) ($argv[2] ?? 2);
$dsn = getenv('PG_PDO_DSN') ?: 'pgsql:host=e24-pg;dbname=ignis';
$user = getenv('PG_USER') ?: 'ignis';
$password = getenv('PG_PASSWORD') ?: 'ignis';

$connect = static fn(): PDO => new PDO($dsn, $user, $password, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);

$ok = 0;
$wrong = 0;
$errors = 0;
$firstError = '';

$started = microtime(true);
Ignis\async(static function () use ($connect, $mode, $fibers, &$ok, &$wrong, &$errors, &$firstError): void {
    $shared = $mode === 'shared' ? $connect() : null;
    $running = [];
    for ($i = 1; $i <= $fibers; $i++) {
        $running[] = Ignis\async(static function () use ($connect, $shared, $i, $fibers, &$ok, &$wrong, &$errors, &$firstError): void {
            $sleep = $i === 1 ? 0.3 : 0.05;                  // the first fiber parks long enough for every other to enter
            $pdo = $shared ?? $connect();
            try {
                $row = $pdo->query("SELECT pg_sleep($sleep), $i AS marker")->fetch(PDO::FETCH_ASSOC);
                if ((int) ($row['marker'] ?? 0) === $i) {
                    $ok++;
                } else {
                    $wrong++;                                 // this fiber was handed another fiber's result set
                }
            } catch (\Throwable $e) {
                $errors++;
                $firstError = $firstError ?: $e->getMessage();
            }
        });
    }
    Ignis\all($running);
});
Ignis\Loop::run();

printf(
    "e24 %s fibers=%d ok=%d wrong=%d errors=%d wall_ms=%d first_error=%s\n",
    $mode,
    $fibers,
    $ok,
    $wrong,
    $errors,
    (int) round((microtime(true) - $started) * 1000),
    $firstError === '' ? '-' : '"' . $firstError . '"',
);
