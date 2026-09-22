<?php

// A3 soak: examples/app.php's routes (streams via /upstream, per-fiber superglobals via
// /whoami, multi-await concurrency via /dashboard), plus a
// /stats route (rss_kb + counters) so the driving script can read RSS from the process
// itself as a cross-check against `ps`/`/proc`. Listens on IGNIS_LISTEN (default differs
// from app.php's 127.0.0.1:8080 so this can run without colliding with anything else on
// the box).
declare(strict_types=1);

require __DIR__ . '/../../php/packages/runtime/src/ignis.php';

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
        Ignis\async(static fn(): array => ['sku-1', 'sku-2']),
    ]);
    return compact('profile', 'orders', 'recommendations');
}

function upstreamJson(string $url): array
{
    return json_decode(file_get_contents($url), true, 512, JSON_THROW_ON_ERROR);
}


Ignis\serve(static function (Request $req): Response {
    return match ($req->path()) {
        '/'          => Response::text("hello from fiber\n"),
        '/dashboard' => Response::json(fetchDashboard((int) ($req->query('user') ?? 1))),
        '/upstream'  => Response::json(upstreamJson('http://' . (getenv('IGNIS_LISTEN') ?: '127.0.0.1:8099') . '/dashboard')),
        '/whoami'    => Response::json(['uri' => $_SERVER['REQUEST_URI'], 'get' => $_GET]),
        '/sleep'     => (static function () use ($req): Response {
            Ignis\sleep((int) ($req->query('ms') ?? 1000));
            return Response::text("slept\n");
        })(),
        '/stats' => Response::json([
            'resumes'  => Ignis\Loop::$resumes,
            'fibers'   => Ignis\Loop::$fibersCreated,
            'idle'     => Ignis\Loop::idleFibers(),
            'runtime'  => function_exists('ignis_stats') ? ignis_stats() : null,
            'mem_real_this_thread' => memory_get_usage(true),
            'rss_kb'   => (int) (preg_match('/^VmRSS:\s+(\d+)/m', (string) file_get_contents('/proc/self/status'), $m) ? $m[1] : -1),
        ]),
        default      => Response::text("not found\n", 404),
    };
}, getenv('IGNIS_LISTEN') ?: '127.0.0.1:8099');
