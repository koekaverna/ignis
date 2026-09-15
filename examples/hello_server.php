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
            'mem'      => memory_get_usage(),
            'mem_real' => memory_get_usage(true),
            'rss_kb'   => (int) (preg_match('/^VmRSS:\s+(\d+)/m', (string) file_get_contents('/proc/self/status'), $m) ? $m[1] : -1),
        ]),
        default  => Response::text("not found\n", 404),
    };
}, getenv('IGNIS_LISTEN') ?: '127.0.0.1:8080');
