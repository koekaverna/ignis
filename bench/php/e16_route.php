<?php
// E16 part 2: config-driven auto-routing with no code changes — SQLite3 / PDO / curl used the normal way inside fibers.
declare(strict_types=1);
require __DIR__ . '/../../php/ignis.php';
require __DIR__ . '/../../php/offload/ignis-offload.php';

use Ignis\Offload\Router;

$fibers = (int) (getenv('FIBERS') ?: 100);
$ms = (int) (getenv('MS') ?: 200);
printf("pool: %s\n", json_encode(Ignis\Offload\Client::stats()));

$main = Ignis\async(static function () use ($fibers, $ms): void {
    // 1. SQLite3 (always built): `new SQLite3` in a fiber yields a proxy; results come back by copy.
    $db = new SQLite3(':memory:');
    printf("sqlite3: object is %s, instanceof SQLite3: %s\n", $db::class, var_export($db instanceof SQLite3, true));
    $db->exec('CREATE TABLE t (id INTEGER, name TEXT)');
    $db->exec("INSERT INTO t VALUES (1, 'ada'), (2, 'grace')");
    $res = $db->query('SELECT id, name FROM t ORDER BY id');
    $rows = [];
    while (($row = $res->fetchArray(SQLITE3_ASSOC)) !== false) { $rows[] = $row; }
    printf("sqlite3: %s (result proxy %s), routed calls so far: %d\n", json_encode($rows), $res::class, Router::$routed);

    // 2. PDO pgsql: FIBERS concurrent 200 ms queries, plain `new PDO` + query in each fiber; the pool bounds the wall time.
    if (extension_loaded('pdo_pgsql')) {
        $dsn = getenv('PG_DSN') ?: 'pgsql:host=127.0.0.1;dbname=ignis;user=ignis;password=ignis';
        $t0 = hrtime(true);
        $fs = [];
        for ($i = 0; $i < $fibers; $i++) {
            $fs[] = Ignis\async(static function () use ($dsn, $ms): int {
                $pdo = new PDO($dsn);
                $st = $pdo->query(sprintf('SELECT pg_backend_pid() AS pid, pg_sleep(%.3f)', $ms / 1000));
                return (int) $st->fetch(PDO::FETCH_ASSOC)['pid'];
            });
        }
        $pids = Ignis\all($fs);
        $wall = (hrtime(true) - $t0) / 1e6;
        $w = (int) Ignis\Offload\Client::stats()['workers'];
        printf("pdo_pgsql auto-routed: %d x %d ms `new PDO` + query in fibers, %d workers: %.0f ms wall (bound ceil(%d/%d) x %d = %d ms); distinct backend pids: %d\n",
            $fibers, $ms, $w, $wall, $fibers, $w, $ms, (int) ceil($fibers / $w) * $ms, count(array_unique($pids)));
    } else {
        echo "pdo_pgsql: not built\n";
    }

    // 3. curl_exec with CURLOPT_WRITEFUNCTION: the closure runs on this thread while curl runs on a worker.
    if (function_exists('curl_init')) {
        $url = getenv('CURL_URL') ?: 'http://127.0.0.1:8080/';
        $chunks = 0; $bytes = 0;
        $ch = curl_init($url);
        printf("curl: handle is %s\n", is_object($ch) ? $ch::class : gettype($ch));
        curl_setopt($ch, CURLOPT_WRITEFUNCTION, static function ($h, string $data) use (&$chunks, &$bytes): int { $chunks++; $bytes += strlen($data); return strlen($data); });
        curl_setopt($ch, CURLOPT_TIMEOUT, 5);
        $ok = curl_exec($ch);
        printf("curl_exec: %s, http %d, WRITEFUNCTION called %d time(s) on the caller for %d bytes (errno %d %s)\n", var_export($ok, true), curl_getinfo($ch, CURLINFO_RESPONSE_CODE), $chunks, $bytes, curl_errno($ch), curl_error($ch));
        unset($ch); // curl_close() is deprecated in 8.5; the proxy handle frees the worker-side handle
        // Copy overhead of a routed call: curl_getinfo round trips.
        $t = hrtime(true); $n = 1000; $ch = curl_init($url);
        for ($i = 0; $i < $n; $i++) { curl_getinfo($ch, CURLINFO_EFFECTIVE_URL); }
        printf("routed call overhead: curl_getinfo x %d = %.1f us per call\n", $n, (hrtime(true) - $t) / 1e3 / $n);
    } else {
        echo "curl: not built\n";
    }
    printf("stats: %s routed=%d\n", json_encode(Ignis\Offload\Client::stats()), Router::$routed);
});
Ignis\Loop::run();
