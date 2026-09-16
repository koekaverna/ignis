<?php
// Classic-mode server: every request includes the matching script under DOCROOT (default: FrankenPHP's testdata).
declare(strict_types=1);
require __DIR__ . '/../php/ignis.php';
require __DIR__ . '/../php/classic.php';

// FrankenPHP's testdata is written against its worker API; these one-shot shims let it run in classic mode.
// _executor.php ends its non-worker branch with exit(0), which would stop the resident Ignis script (an
// unwind_exit escapes the fiber), so FRANKENPHP_WORKER=1 steers it to the worker branch and the shim ends
// the request by throwing Ignis\Classic\Finished after one handler call.
if (!function_exists('frankenphp_handle_request')) {
    function frankenphp_handle_request(callable $handler): bool { $handler(); Ignis\Classic\finish(); }
    function frankenphp_finish_request(): bool { return Ignis\Classic\finish_request(); }
}

Ignis\Classic\serve(
    getenv('DOCROOT') ?: __DIR__ . '/../../frankenphp/testdata',
    getenv('IGNIS_ADDR') ?: '127.0.0.1:8080',
    'index.php',
    ['FRANKENPHP_WORKER' => '1'],
    // _executor.php is require_once'd and Ignis keeps included_files for the thread's lifetime: from the second
    // request on, the script skips the executor and returns its handler closure, which is then called here.
    static function (string $file): void { $r = include $file; if ($r instanceof Closure) { $r(); } },
);
