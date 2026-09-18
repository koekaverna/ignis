<?php

// Worker-mode hello world: the script stays resident, every request runs in a pooled fiber.
declare(strict_types=1);
require __DIR__ . '/../php/packages/runtime/src/ignis.php';

// One pooled fiber per in-flight request, 16 KiB VM stack each: 10k concurrent requests need > 160 MiB.
ini_set('memory_limit', '1G');

use Ignis\Http\Request;
use Ignis\Http\Response;

// One address for the listener and the /fetch self-call below: with the two hardcoded apart,
// a stranger holding :8080 answered the self-call and E6 reported ok=0 (2026-09-16).
$listen = getenv('IGNIS_LISTEN') ?: '127.0.0.1:8080';

Ignis\serve(static function (Request $req) use ($listen): Response {
    return match ($req->path()) {
        '/'      => Response::text("Hello, World!\n"),
        '/sleep' => (static function () use ($req): Response {
            Ignis\sleep((int) ($req->query('ms') ?? 1000));
            return Response::text("slept\n");
        })(),
        '/fatal' => (static function (): Response {
            // E12: a real fatal (bailout) — must kill only this thread's script, and never return.
            trigger_error('deliberate fatal for E12', E_USER_ERROR);
        })(),
        '/spin'  => (static function () use ($req): Response {
            // E12: CPU loop with no suspension point; stalls only this thread.
            $until = hrtime(true) + (int) ($req->query('s') ?? 5) * 1_000_000_000;
            $n = 0;
            while (hrtime(true) < $until) {
                $n++;
            }
            return Response::text("spun $n\n");
        })(),
        '/slow'  => (static function (): Response {
            // E11: 5 s of work in this fiber plus a child; a client disconnect must cancel both.
            $child = Ignis\async(static function (): string {
                Ignis\sleep(5000);
                return 'child done';
            });
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
        '/fetch' => (static function () use ($listen): Response {
            // E6: unmodified file_get_contents() over http:// suspends this fiber; the server serves
            // its own /sleep endpoints meanwhile — on ONE thread this can only work if it suspends.
            $t0 = hrtime(true);
            $ms = (int) ($_GET['ms'] ?? 200);
            $bodies = Ignis\all([
                Ignis\async(static fn() => file_get_contents("http://$listen/sleep?ms=$ms")),
                Ignis\async(static fn() => file_get_contents("http://$listen/sleep?ms=$ms")),
                Ignis\async(static fn() => file_get_contents("http://$listen/sleep?ms=$ms")),
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
            'budget'   => Ignis\Loop::budgetStats(), // B1 (ADR-0019)
            'resumes'  => Ignis\Loop::$resumes,
            'fibers'   => Ignis\Loop::$fibersCreated,
            'idle'     => Ignis\Loop::idleFibers(),
            'cancelled' => Ignis\Loop::$cancelled,
            'cancel_age_us_max' => Ignis\Loop::$cancelAgeUsMax,
            'cancel_latency_us_max' => Ignis\Loop::$cancelLatencyUsMax,
            'slow_finally_ran' => $GLOBALS['slow_finally_ran'] ?? 0,
            'runtime'  => function_exists('ignis_stats') ? ignis_stats() : null,
            'mem_this_thread'      => memory_get_usage(),
            'mem_real_this_thread' => memory_get_usage(true),
            'rss_kb'   => (int) (preg_match('/^VmRSS:\s+(\d+)/m', (string) file_get_contents('/proc/self/status'), $m) ? $m[1] : -1),
        ]),
        default  => Response::text("not found\n", 404),
    };
}, $listen);
