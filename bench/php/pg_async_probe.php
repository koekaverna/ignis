<?php
// Probe: ext/pgsql in async mode parked on ignis_watch (research 24). No Rust change — libpq's own
// socket is handed to the reactor, the fiber parks on the one poll point like any other op.
// Env: PG_DSN, FIBERS (default 20), SLEEP (seconds per query, default 0.2), N (SELECT 1 count, default 2000).
declare(strict_types=1);
require __DIR__ . '/../../php/ignis.php';

use Ignis\Loop;
use function Ignis\all;
use function Ignis\async;

$dsn = getenv('PG_DSN');
if ($dsn === false || $dsn === '') {
    fwrite(STDERR, 'PG_DSN is required, e.g. PG_DSN="host=/tmp/ignis-pgsock user=ignis password=ignis dbname=ignis"' . "\n");
    exit(2);
}
$fibers = (int) (getenv('FIBERS') ?: 20);
$sleep  = (float) (getenv('SLEEP') ?: 0.2);
$n      = (int) (getenv('N') ?: 2000);

/** Park the fiber until libpq's socket is readable (1) or writable (2). */
function pg_park($c, int $mode): void
{
    Loop::awaitOp(\ignis_watch(\pg_socket($c), $mode));
}

/** Async connect: PQconnectPoll driven by the reactor. */
function pg_aconnect(string $dsn)
{
    $c = \pg_connect($dsn, PGSQL_CONNECT_ASYNC | PGSQL_CONNECT_FORCE_NEW);
    if ($c === false) {
        throw new RuntimeException('pg_connect failed');
    }
    while (true) {
        $s = \pg_connect_poll($c);
        if ($s === PGSQL_POLLING_OK) {
            return $c;
        }
        if ($s === PGSQL_POLLING_FAILED) {
            throw new RuntimeException('connect_poll failed: ' . \pg_last_error($c));
        }
        pg_park($c, $s === PGSQL_POLLING_WRITING ? 2 : 1);
    }
}

/** One statement, fiber parked for the whole round trip. Returns the last result's rows. */
function pg_aquery($c, string $sql, array $params = []): array
{
    if (!\pg_send_query_params($c, $sql, $params)) {
        throw new RuntimeException('send failed: ' . \pg_last_error($c));
    }
    // Flush the send buffer; 0 means "more to write".
    while (($f = \pg_flush($c)) === 0) {
        pg_park($c, 2);
    }
    if ($f === false) {
        throw new RuntimeException('flush failed: ' . \pg_last_error($c));
    }
    $rows = [];
    // Drain every result. pg_connection_busy() = PQconsumeInput + PQisBusy, so once it is
    // false PQgetResult cannot block — checking it BEFORE arming the watch is what stops
    // us parking on an fd that will never fire again (the A4 can_block() lesson).
    while (true) {
        while (\pg_connection_busy($c)) {
            pg_park($c, 1);
        }
        $r = \pg_get_result($c);
        if ($r === false) {
            break;
        }
        if (\pg_result_status($r) === PGSQL_FATAL_ERROR) {
            throw new RuntimeException('query failed: ' . \pg_result_error($r));
        }
        $rows = \pg_fetch_all($r, PGSQL_ASSOC) ?: [];
    }
    return $rows;
}

$mode = $argv[1] ?? 'all';

if ($mode === 'all' || $mode === 'concurrency') {
    // Claim: N fibers each waiting `$sleep` s on its own connection finish in ~$sleep s, not N*$sleep.
    $conns = [];
    for ($i = 0; $i < $fibers; $i++) {
        $conns[] = pg_aconnect($dsn);
    }
    $t = hrtime(true);
    $futures = [];
    foreach ($conns as $c) {
        $futures[] = async(fn () => pg_aquery($c, 'SELECT pg_sleep($1::float8) AS s', [$sleep]));
    }
    all($futures);
    $async = (hrtime(true) - $t) / 1e6;

    // Control: the same work through the stock synchronous call. If the async path were not
    // parking, the two numbers would match.
    $t = hrtime(true);
    foreach ($conns as $c) {
        \pg_query_params($c, 'SELECT pg_sleep($1::float8) AS s', [$sleep]);
    }
    $sync = (hrtime(true) - $t) / 1e6;

    printf("concurrency: %d fibers x %.0f ms  async=%.1f ms  sync-control=%.1f ms  speedup=%.1fx\n",
        $fibers, $sleep * 1000, $async, $sync, $sync / $async);
    foreach ($conns as $c) {
        \pg_close($c);
    }
}

if ($mode === 'all' || $mode === 'overhead') {
    // Per-query cost against V-21's tokio pool number (112 us/query, 7.3k q/s on one thread).
    $c = pg_aconnect($dsn);
    pg_aquery($c, 'SELECT 1');                       // warm
    $t = hrtime(true);
    for ($i = 0; $i < $n; $i++) {
        pg_aquery($c, 'SELECT 1 AS one');
    }
    $us = (hrtime(true) - $t) / 1e3 / $n;
    printf("overhead(async+watch): %d x SELECT 1 -> %.1f us/query, %.0f q/s\n", $n, $us, 1e6 / $us);

    $t = hrtime(true);
    for ($i = 0; $i < $n; $i++) {
        \pg_query($c, 'SELECT 1 AS one');
    }
    $us2 = (hrtime(true) - $t) / 1e3 / $n;
    printf("overhead(stock sync)  : %d x SELECT 1 -> %.1f us/query, %.0f q/s  (ratio %.2fx)\n",
        $n, $us2, 1e6 / $us2, $us / $us2);
    \pg_close($c);
}
