<?php

/**
 * examples/app.php — THE API SPEC.
 *
 * This file is what an application developer should be able to write. Every
 * construct here is either implemented (✓) or a target with the expectation
 * id that will validate it (→ E-n). It is executed by scripts/smoke.sh with
 * the parts that are not implemented yet guarded by feature checks, so the
 * file always runs; unimplemented parts are reported, never faked.
 *
 * The runtime answers `/_ignis/health` itself, never PHP: 200 while a worker is alive and not
 * stalled, 503 otherwise (V-38). Admission control is ADR-0019: `budget.fibers` / `budget.queue`
 * cap in-flight request fibers; past the queue depth the server answers 503 with `retry-after: 1`.
 */
declare(strict_types=1);

require __DIR__ . '/../php/packages/runtime/src/ignis.php';

use Ignis\Http\Request;   // → E4 (hyper transport)
use Ignis\Http\Response;  // → E4

// ---------------------------------------------------------------------------
// 1. Structured concurrency inside one request: ✓ (E1/E2, V-2/V-3)
// ---------------------------------------------------------------------------
/**
 * The three upstreams, fetched concurrently. Keyed `mixed` because `Ignis\all()` over futures of
 * different shapes can only say `mixed` per value -- a precise shape here would be a promise this
 * function does not keep (V-84).
 *
 * @return array<string, mixed>
 */
function fetchDashboard(int $userId): array
{
    // Three independent waits run concurrently on one OS thread; the fiber
    // suspends, tokio owns the timers/sockets, the thread serves other requests.
    [$profile, $orders, $recommendations] = Ignis\all([
        Ignis\async(static function () use ($userId): array {
            Ignis\sleep(200);                              // stands in for a slow upstream
            return ['id' => $userId, 'name' => 'Ada'];
        }),
        Ignis\async(static function (): array {
            Ignis\sleep(200);
            return [['id' => 1, 'total' => 42.0]];
        }),
        Ignis\async(static fn(): array => ['sku-1', 'sku-2']),
    ]);
    return compact('profile', 'orders', 'recommendations');
}

// ---------------------------------------------------------------------------
// 2. Unmodified synchronous I/O becomes non-blocking: ✓ (E6, V-12; E18, V-45/V-46).
//    file_get_contents('http://…') suspends the fiber because the blocking libc call
//    inside it is interposed and parks (universal park, ADR-0020/0037) — the tcp://
//    transport factory this used to go through was deleted in V-49. PDO sqlite is the
//    exception: a regular file is not epoll-able, so it still blocks the thread
//    (V-59 addendum) — short queries only.
// ---------------------------------------------------------------------------
/**
 * PDO's own stub promises no more than `array` for fetchAll(), so that is what this claims. Saying
 * `list<array<string, mixed>>` would be a promise nothing here can keep.
 *
 * @return array<array-key, mixed>
 */
function usersFromDb(\PDO $pdo): array
{
    $rows = $pdo->query('SELECT id, name FROM users ORDER BY id');
    if ($rows === false) {
        throw new \RuntimeException('users query failed: ' . implode(' ', $pdo->errorInfo()));
    }

    return $rows->fetchAll(\PDO::FETCH_ASSOC);
}

/**
 * One integer column of a row. A column value is `mixed` because SQL says so, and an example that
 * casts it teaches the wrong habit.
 *
 * @param array<string, mixed> $row
 */
function intColumn(array $row, string $column): int
{
    $value = $row[$column] ?? null;
    if (!is_numeric($value)) {
        throw new \RuntimeException("column {$column} is " . get_debug_type($value) . ', expected a number');
    }

    return (int) $value;
}

/**
 * The decoded upstream document. Keyed `mixed` because that is what JSON gives back: the only
 * caller hands it straight to `Response::json()`, so promising string keys was a lie that bought
 * nothing.
 *
 * @return array<mixed, mixed>
 */
function upstreamJson(string $url): array
{
    $body = file_get_contents($url);
    if ($body === false) {
        throw new \RuntimeException("upstream {$url} could not be read");
    }

    $decoded = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
    if (!is_array($decoded)) {
        throw new \RuntimeException("upstream {$url} returned " . get_debug_type($decoded) . ', expected a JSON object');
    }

    return $decoded;
}

// ---------------------------------------------------------------------------
// 3. Worker mode: the script stays resident; each request runs in its own
//    fiber (✓ E4, V-5/V-6); $_SERVER/$_GET/$_POST/$_COOKIE and Ignis\Scope are
//    fiber-scoped (✓ E13, V-11); Symfony via symfony/runtime (✓ E8, V-16, php/packages/symfony-runtime);
//    client disconnect cancels the fiber and its children, Ignis\deadline() (✓ E11, V-14).
// ---------------------------------------------------------------------------
// Worker mode: boots once, then serves; each request runs in its own pooled fiber.
$pdo = new \PDO('sqlite::memory:');
$pdo->exec('CREATE TABLE users (id INTEGER PRIMARY KEY, name TEXT)');
$pdo->exec("INSERT INTO users (name) VALUES ('ada'), ('grace')");

// The listen address is one value: the self-call below must follow it, or a box with something
// else on :8080 silently exercises that stranger instead of this process (smoke.sh, 2026-09-16).
$listen = getenv('IGNIS_LISTEN') ?: '127.0.0.1:8080';

Ignis\serve(static function (Request $req) use ($pdo, $listen): Response {
    return match ($req->path()) {
        '/'          => Response::text("hello from fiber\n"),
        '/deadline'  => (static function (): Response {
            Ignis\deadline(100);
            Ignis\sleep(1000);
            return Response::text("never\n");
        })(),
        '/dashboard' => Response::json(fetchDashboard((int) ($req->query('user') ?? 1))),
        '/users'     => Response::json(usersFromDb($pdo)),
        '/upstream'  => Response::json(upstreamJson("http://$listen/dashboard")), // self-call, suspends (E6)
        '/whoami'    => Response::json(['uri' => $_SERVER['REQUEST_URI'], 'get' => $_GET]),  // per-fiber superglobals (E13)
        '/sleep'     => (static function () use ($req): Response {
            Ignis\sleep((int) ($req->query('ms') ?? 1000));
            return Response::text("slept\n");
        })(),
        '/stats'     => Response::json(array_merge(Ignis\Loop::budgetStats(), [                // admission control + fiber counters (✓ ADR-0019, V-37/V-38);
            'resumes' => Ignis\Loop::$resumes,                                                  // set IGNIS_BUDGET_EXEMPT=/stats to keep it answering under load
            'idle'    => Ignis\Loop::idleFibers(),
            'runtime' => function_exists('ignis_stats') ? ignis_stats() : null,
        ])),
        default      => Response::text("not found\n", 404),
    };
}, $listen);
