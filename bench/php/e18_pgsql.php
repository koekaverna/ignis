<?php
// E18-B / H33 control: N fibers, each `new PDO(pgsql DSN)` + `SELECT pg_sleep(0.2)`. Offload
// routing must be off (the driver sets IGNIS_NO_OFFLOAD_ROUTE=1) so this measures blocking
// pdo_pgsql, not ADR-0016's offload pool. Today (no universal park) every query serializes on the
// single PHP thread: wall ~= N * 200 ms is the control baseline (H33's falsifier: after E18-I,
// wall ~= 200 ms).
//
// Usage: ignis bench/php/e18_pgsql.php <pdo_dsn> [n]   (env fallback: PG_PDO_DSN, N; default n=100)
declare(strict_types=1);
require __DIR__ . '/../../php/packages/runtime/src/ignis.php';

$dsn = $argv[1] ?? getenv('PG_PDO_DSN') ?: null;
$n = (int) ($argv[2] ?? getenv('N') ?: 100);
if (!$dsn) {
    fwrite(STDERR, "usage: e18_pgsql.php <pdo_dsn> [n]\n");
    exit(2);
}

$ok = 0;

$main = Ignis\async(static function () use ($dsn, $n, &$ok): void {
    $t0 = hrtime(true);
    $fs = [];
    for ($i = 0; $i < $n; $i++) {
        $fs[] = Ignis\async(static function () use ($dsn, &$ok): void {
            try {
                $pdo = new PDO($dsn);
                $pdo->query('SELECT pg_sleep(0.2)');
                $ok++;
            } catch (\Throwable $e) {
                // left uncounted; $ok reports successes only
            }
        });
    }
    Ignis\all($fs);
    $wall = (hrtime(true) - $t0) / 1e6;
    printf("e18: pgsql_100x200ms wall_ms=%.0f ok=%d\n", $wall, $ok);
});
Ignis\Loop::run();
