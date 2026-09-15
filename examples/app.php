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
// 2. Unmodified synchronous I/O becomes non-blocking: → E6 (php_stream hooks)
//    No Ignis\* call in sight: plain PDO and file_get_contents() suspend the
//    fiber because the tcp:// transport factory is replaced by Ignis.
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
//    fiber; RequestStack-style state is fiber-scoped: → E4, E8
// ---------------------------------------------------------------------------
if (function_exists('Ignis\serve')) {
    $pdo = new \PDO('sqlite::memory:');
    $pdo->exec('CREATE TABLE users (id INTEGER PRIMARY KEY, name TEXT)');
    $pdo->exec("INSERT INTO users (name) VALUES ('ada'), ('grace')");

    Ignis\serve(static function (Request $req) use ($pdo): Response {
        return match ($req->path()) {
            '/'          => Response::text("hello from fiber\n"),
            '/dashboard' => Response::json(fetchDashboard((int) ($req->query('user') ?? 1))),
            '/users'     => Response::json(usersFromDb($pdo)),
            '/sleep'     => (static function () use ($req): Response {
                Ignis\sleep((int) ($req->query('ms') ?? 1000));
                return Response::text("slept\n");
            })(),
            default      => Response::text("not found\n", 404),
        };
    });
} else {
    // Transport not implemented yet: run section 1 directly so the file is
    // still a working program.
    $t0 = hrtime(true);
    $dashboard = fetchDashboard(1);
    printf("dashboard for %s in %.1f ms (3 x 200 ms in parallel)\n", $dashboard['profile']['name'], (hrtime(true) - $t0) / 1e6);
    echo "Ignis\\serve() not available yet (→ E4)\n";
}
