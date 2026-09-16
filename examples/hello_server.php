<?php
// Worker-mode hello world: the script stays resident, every request runs in a pooled fiber.
declare(strict_types=1);
require __DIR__ . '/../php/ignis.php';

// One pooled fiber per in-flight request, 16 KiB VM stack each: 10k concurrent requests need > 160 MiB.
ini_set('memory_limit', '1G');

use Ignis\Http\Request;
use Ignis\Http\Response;

Ignis\serve(static function (Request $req): Response {
    return match ($req->path()) {
        '/'      => Response::text("Hello, World!\n"),
        '/sleep' => (static function () use ($req): Response {
            Ignis\sleep((int) ($req->query('ms') ?? 1000));
            return Response::text("slept\n");
        })(),
        '/slow'  => (static function (): Response {
            // E11: 5 s of work in this fiber plus a child; a client disconnect must cancel both.
            $child = Ignis\async(static function (): string { Ignis\sleep(5000); return 'child done'; });
            try {
                Ignis\sleep(5000);
                return Response::text($child->await() . "\n");
            } finally {
                Ignis\Scope::set('slow.finally', true);
                $GLOBALS['slow_finally_ran'] = ($GLOBALS['slow_finally_ran'] ?? 0) + 1;
            }
        })(),
        '/deadline' => (static function () use ($req): Response {
            // E11: one wall-clock deadline for the request; the sleep is longer than it.
            Ignis\deadline((int) ($req->query('ms') ?? 100));
            Ignis\sleep(1000);
            return Response::text("finished\n");
        })(),
        '/fetch' => (static function (): Response {
            // E6: unmodified file_get_contents() over http:// suspends this fiber; the server serves
            // its own /sleep endpoints meanwhile — on ONE thread this can only work if it suspends.
            $t0 = hrtime(true);
            $ms = (int) ($_GET['ms'] ?? 200);
            $bodies = Ignis\all([
                Ignis\async(static fn () => file_get_contents("http://127.0.0.1:8080/sleep?ms=$ms")),
                Ignis\async(static fn () => file_get_contents("http://127.0.0.1:8080/sleep?ms=$ms")),
                Ignis\async(static fn () => file_get_contents("http://127.0.0.1:8080/sleep?ms=$ms")),
            ]);
            return Response::json(['bodies' => $bodies, 'ms' => round((hrtime(true) - $t0) / 1e6, 1)]);
        })(),
        '/echo'  => (static function (): Response {
            // E13: after suspending, this fiber must still see its own superglobals.
            $x = $_GET['x'] ?? '?';
            Ignis\sleep((int) ($_GET['ms'] ?? 20));
            Ignis\Scope::set('x', $x);
            Ignis\sleep(1);
            return Response::text(($_GET['x'] ?? '?') . ' ' . $_SERVER['REQUEST_URI'] . ' ' . Ignis\Scope::get('x') . "\n");
        })(),
        '/cpu'   => (static function (): Response {
            // ~0.3 ms of CPU-bound work per request (E5 over HTTP).
            $acc = 0;
            for ($i = 0; $i < 2000; $i++) {
                $acc += strlen(md5((string) $i));
            }
            return Response::text("cpu $acc\n");
        })(),
        '/stats' => Response::json([
            'resumes'  => Ignis\Loop::$resumes,
            'fibers'   => Ignis\Loop::$fibersCreated,
            'idle'     => Ignis\Loop::idleFibers(),
            'cancelled' => Ignis\Loop::$cancelled,
            'cancel_age_us_max' => Ignis\Loop::$cancelAgeUsMax,
            'cancel_latency_us_max' => Ignis\Loop::$cancelLatencyUsMax,
            'slow_finally_ran' => $GLOBALS['slow_finally_ran'] ?? 0,
            'mem'      => memory_get_usage(),
            'mem_real' => memory_get_usage(true),
            'rss_kb'   => (int) (preg_match('/^VmRSS:\s+(\d+)/m', (string) file_get_contents('/proc/self/status'), $m) ? $m[1] : -1),
        ]),
        default  => Response::text("not found\n", 404),
    };
}, getenv('IGNIS_LISTEN') ?: '127.0.0.1:8080');
