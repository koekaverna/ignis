<?php

/**
 * Research 50 §A `sqlite.php`: blocking forward inside an extension under `block`, the documented
 * "cannot park" case (MVP scope, ADR-0043 §9). Measured: a 6,000,000-row `WITH RECURSIVE` query.
 */

declare(strict_types=1);

require __DIR__ . '/common.php';

use Ignis\Http\Request;
use Ignis\Http\Response;

function countByRecursion(int $upperBound): int
{
    $database = new SQLite3(':memory:');
    $result = $database->query(
        "WITH RECURSIVE counter(value) AS (SELECT 1 UNION ALL SELECT value + 1 FROM counter WHERE value < $upperBound) "
        . "SELECT count(*) AS total FROM counter",
    );
    $row = $result->fetchArray(SQLITE3_ASSOC);
    $database->close();
    return (int) $row['total'];
}

serveStallFixture(static function (Request $request): Response {
    $upperBound = (int) ($request->query('rows') ?? 6_000_000);
    $startedAt = hrtime(true);
    $total = countByRecursion($upperBound);
    $elapsedMilliseconds = (int) ((hrtime(true) - $startedAt) / 1_000_000);
    return Response::json(['rows' => $total, 'elapsed_ms' => $elapsedMilliseconds]);
});
