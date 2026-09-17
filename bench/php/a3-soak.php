<?php
// A3 soak: examples/app.php's routes (streams via /upstream, per-fiber superglobals via
// /whoami, offload pool via /offload, multi-await concurrency via /dashboard), plus a
// /stats route (rss_kb + counters) so the driving script can read RSS from the process
// itself as a cross-check against `ps`/`/proc`. Listens on IGNIS_LISTEN (default differs
// from app.php's 127.0.0.1:8080 so this can run without colliding with anything else on
// the box). /db (E14, PG_DSN) is left in but unexercised by the driver: no PG server on
// this box -- it just returns its "set PG_DSN..." info message, never an error.
declare(strict_types=1);

require __DIR__ . '/../../php/packages/runtime/src/ignis.php';
require __DIR__ . '/../../php/packages/pg/src/ignis-pg.php';
require __DIR__ . '/../../php/packages/offload/src/ignis-offload.php';

use Ignis\Http\Request;
use Ignis\Http\Response;

function fetchDashboard(int $userId): array
{
    [$profile, $orders, $recommendations] = Ignis\all([
        Ignis\async(static function () use ($userId): array {
            Ignis\sleep(200);
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

function upstreamJson(string $url): array
{
    return json_decode(file_get_contents($url), true, 512, JSON_THROW_ON_ERROR);
}

function offloadDemo(): array
{
    if ((Ignis\Offload\Client::stats()['workers'] ?? 0) === 0) {
        return ['offload' => 'start ignis with --offload N to enable'];
    }
    $upper = Ignis\offload('strtoupper', 'hello from a worker thread');
    return ['result' => $upper, 'pool' => Ignis\Offload\Client::stats()];
}

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
    ]);
}

Ignis\serve(static function (Request $req): Response {
    return match ($req->path()) {
        '/'          => Response::text("hello from fiber\n"),
        '/dashboard' => Response::json(fetchDashboard((int) ($req->query('user') ?? 1))),
        '/upstream'  => Response::json(upstreamJson('http://' . (getenv('IGNIS_LISTEN') ?: '127.0.0.1:8099') . '/dashboard')),
        '/whoami'    => Response::json(['uri' => $_SERVER['REQUEST_URI'], 'get' => $_GET]),
        '/db'        => Response::json(dbDemo()),
        '/offload'   => Response::json(offloadDemo()),
        '/sleep'     => (static function () use ($req): Response {
            Ignis\sleep((int) ($req->query('ms') ?? 1000));
            return Response::text("slept\n");
        })(),
        '/stats' => Response::json([
            'resumes'  => Ignis\Loop::$resumes,
            'fibers'   => Ignis\Loop::$fibersCreated,
            'idle'     => Ignis\Loop::idleFibers(),
            'runtime'  => function_exists('ignis_stats') ? ignis_stats() : null,
            'mem_real' => memory_get_usage(true),
            'rss_kb'   => (int) (preg_match('/^VmRSS:\s+(\d+)/m', (string) file_get_contents('/proc/self/status'), $m) ? $m[1] : -1),
        ]),
        default      => Response::text("not found\n", 404),
    };
}, getenv('IGNIS_LISTEN') ?: '127.0.0.1:8099');
