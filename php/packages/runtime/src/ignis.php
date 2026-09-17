<?php

/**
 * Ignis userland scheduler — the entry point for everything that is not composer.
 *
 * The classes live one per file under PSR-4 (`Ignis\` → `src/`). An application that installs
 * `ignis/runtime` with composer never needs this file: the generated autoloader finds the classes
 * and `autoload.files` pulls in the free functions, which PSR-4 cannot do.
 *
 * It exists for everything else — the examples, the benches, `scripts/phpt-harness.php`, the entry
 * script an application points `ignis.toml` at — which `require` it by path and have no vendor
 * directory. Seventy files in this repository do.
 *
 * It registers an autoloader rather than listing the classes: a hand-kept list of `require_once`
 * silently drifts the first time someone adds a class and tests it under composer, where it works.
 *
 * Rust primitives this is built on: `ignis_submit_sleep(int $ms): int`, `ignis_poll(int $timeout_ms):
 * array`, `ignis_inflight(): int`, `ignis_serve(string $addr): bool`, `ignis_respond(int $id, int
 * $status, array $headers, string $body): bool`. Everything else — fibers, futures, `all()`, the
 * pool, `serve()` — is PHP, shaped so it can become a Revolt driver
 * (activate/dispatch/deactivate/now).
 */

declare(strict_types=1);

(static function (): void {
    static $registered = false;
    if ($registered) {
        return;
    }
    $registered = true;

    $src = __DIR__;
    \spl_autoload_register(static function (string $class) use ($src): void {
        if (!\str_starts_with($class, 'Ignis\\')) {
            return;
        }
        $path = $src . '/' . \str_replace('\\', '/', \substr($class, 6)) . '.php';
        if (\is_file($path)) {
            require $path;
        }
    });
})();

// Functions are not autoloadable; this is the one thing the file has to do eagerly.
require_once __DIR__ . '/functions.php';
