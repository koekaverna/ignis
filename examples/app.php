<?php
/**
 * examples/app.php — THE API SPEC.
 *
 * This file is what an application developer should be able to write. Every
 * construct here is either implemented (✓) or a target with the expectation
 * id that will validate it (→ E-n). It is executed by scripts/smoke.sh with
 * the parts that are not implemented yet guarded by feature checks, so the
 * file always runs; unimplemented parts are reported, never faked.
 */
declare(strict_types=1);

require __DIR__ . '/../php/ignis.php';
require __DIR__ . '/../php/pg/ignis-pg.php'; // E14: runtime-owned PostgreSQL pool (route /db needs PG_DSN)
require __DIR__ . '/../php/offload/ignis-offload.php'; // E16: offload pool (route /offload needs --offload N)

use Ignis\Future;
use Ignis\Http\Request;   // → E4 (hyper transport)
use Ignis\Http\Response;  // → E4

// ---------------------------------------------------------------------------
// 1. Structured concurrency inside one request: ✓ (E1/E2, V-2/V-3)
// ---------------------------------------------------------------------------
function fetchDashboard(int $userId): array
{
    // Three independent waits run concurrently on one OS thread; the fiber
    // suspends, tokio owns the timers/sockets, the thread serves other requests.
    [$profile, $orders, $recommendations] = Ignis\all([
        Ignis\async(static function () use ($userId): array {
            Ignis\sleep(200);                              // stands in for a slow upstream
            return ['id' => $userId, 'name' => 'Ada'];
        }),
        Ignis\async(static function () use ($userId): array {
            Ignis\sleep(200);
            return [['id' => 1, 'total' => 42.0]];
        }),
        Ignis\async(static fn (): array => ['sku-1', 'sku-2']),
    ]);
    return compact('profile', 'orders', 'recommendations');
}

// ---------------------------------------------------------------------------
// 2. Unmodified synchronous I/O becomes non-blocking: ✓ for tcp streams (E6, V-12):
//    file_get_contents('http://…') suspends the fiber because the tcp:// transport
//    factory is replaced by Ignis (ADR-0007). PDO sqlite does its own file I/O in-process
//    and still blocks the thread (V-12) — use it for short queries only for now.
// ---------------------------------------------------------------------------
function usersFromDb(\PDO $pdo): array
{
    return $pdo->query('SELECT id, name FROM users ORDER BY id')->fetchAll(\PDO::FETCH_ASSOC);
}

function upstreamJson(string $url): array
{
    return json_decode(file_get_contents($url), true, 512, JSON_THROW_ON_ERROR);
}

// ---------------------------------------------------------------------------
// 3. Worker mode: the script stays resident; each request runs in its own
//    fiber (✓ E4, V-5/V-6); $_SERVER/$_GET/$_POST/$_COOKIE and Ignis\Scope are
//    fiber-scoped (✓ E13, V-11); Symfony via symfony/runtime (✓ E8, V-16, php/symfony);
//    client disconnect cancels the fiber and its children, Ignis\deadline() (✓ E11, V-14).
// ---------------------------------------------------------------------------
// Worker mode: boots once, then serves; each request runs in its own pooled fiber.
$pdo = new \PDO('sqlite::memory:');
$pdo->exec('CREATE TABLE users (id INTEGER PRIMARY KEY, name TEXT)');
$pdo->exec("INSERT INTO users (name) VALUES ('ada'), ('grace')");

Ignis\serve(static function (Request $req) use ($pdo): Response {
    return match ($req->path()) {
        '/'          => Response::text("hello from fiber\n"),
        '/deadline'  => (static function (): Response { Ignis\deadline(100); Ignis\sleep(1000); return Response::text("never\n"); })(),
        '/dashboard' => Response::json(fetchDashboard((int) ($req->query('user') ?? 1))),
        '/users'     => Response::json(usersFromDb($pdo)),
        '/upstream'  => Response::json(upstreamJson('http://127.0.0.1:8080/dashboard')), // self-call, suspends (E6)
        '/whoami'    => Response::json(['uri' => $_SERVER['REQUEST_URI'], 'get' => $_GET]),  // per-fiber superglobals (E13)
        '/db'        => Response::json(dbDemo()),                                             // pool lease per fiber, transaction pins it (E14)
        '/offload'   => Response::json(offloadDemo()),                                        // blocking code on a sync worker thread, fiber parks (E16)
        '/sleep'     => (static function () use ($req): Response {
            Ignis\sleep((int) ($req->query('ms') ?? 1000));
            return Response::text("slept\n");
        })(),
        default      => Response::text("not found\n", 404),
    };
});

/** E14: one lease per fiber; a transaction keeps one backend; the query parks the fiber, not the thread. */
function dbDemo(): array
{
    static $pool = null;
    $dsn = getenv('PG_DSN');
    if ($dsn === false) {
        return ['pg' => 'set PG_DSN=host=127.0.0.1 user=ignis password=ignis dbname=ignis to enable'];
    }
    $pool ??= new Ignis\Pg\Pool($dsn, 10);
    return $pool->transaction(static fn (Ignis\Pg\Lease $l) => [
        'backend' => $l->backendPid(),
        'now' => $l->query('SELECT now()::text AS t')[0]['t'],
        'sleep_ms' => (int) $l->query('SELECT extract(milliseconds from clock_timestamp() - now())::int AS d FROM pg_sleep(0.05)')[0]['d'],
        'pool' => $pool->stats(),
    ]);
}

/** E16: a named function runs on a synchronous worker thread with its own PHP context; this fiber parks meanwhile. */
function offloadDemo(): array
{
    if ((Ignis\Offload\Client::stats()['workers'] ?? 0) === 0) {
        return ['offload' => 'start ignis with --offload N to enable'];
    }
    $t = hrtime(true);
    $upper = Ignis\offload('strtoupper', 'hello from a worker thread'); // any function the worker can resolve; closures are not copied
    return ['result' => $upper, 'round_trip_us' => (int) ((hrtime(true) - $t) / 1e3), 'pool' => Ignis\Offload\Client::stats()];
}
