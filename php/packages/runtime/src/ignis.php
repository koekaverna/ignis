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
 * It walks `src/` rather than listing the classes: a hand-kept list of `require_once` silently
 * drifts the first time someone adds a class and tests it under composer, where it works. An
 * autoloader is registered for the walk and **unregistered again afterwards**, because a runtime
 * has no business appearing in the application's `spl_autoload_functions()`. FrankenPHP's
 * `autoloader.php` testdata asserts exactly that, and a registered closure made it fatal:
 * `implode(',', spl_autoload_functions())` cannot stringify one (E15d, 2026-09-18).
 *
 * Rust primitives this is built on: `ignis_submit_sleep(int $ms): int`, `ignis_poll(int $timeout_ms):
 * array`, `ignis_inflight(): int`, `ignis_serve(string $addr): bool`, `ignis_respond(int $id, int
 * $status, array $headers, string $body): bool`. Everything else — fibers, futures, `all()`, the
 * pool, `serve()` — is PHP, shaped so it can become a Revolt driver
 * (activate/dispatch/deactivate/now).
 */

declare(strict_types=1);

(static function (): void {
    static $loaded = false;
    if ($loaded) {
        return;
    }
    $loaded = true;

    $src = __DIR__;
    $autoload = static function (string $class) use ($src): void {
        if (!\str_starts_with($class, 'Ignis\\')) {
            return;
        }
        $path = $src . '/' . \str_replace('\\', '/', \substr($class, 6)) . '.php';
        if (\is_file($path)) {
            require $path;
        }
    };

    // A class file is the one whose basename starts with a capital -- the convention this tree
    // already follows. functions.php, classic.php and polyfills.php are not classes and are
    // required deliberately elsewhere, not swept up here.
    $classFiles = static function (string $dir): iterable {
        $entries = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS));
        foreach ($entries as $entry) {
            /** @var \SplFileInfo $entry */
            if ($entry->getExtension() === 'php' && \ctype_upper($entry->getBasename()[0])) {
                yield $entry->getPathname();
            }
        }
    };

    // The autoloader is registered only for the walk. Declaring a subclass resolves its parent
    // through it, so the directory order does not matter -- DeadlineExceededException extends
    // CancelledException and StreamedResponse extends Response.
    \spl_autoload_register($autoload);
    foreach ($classFiles($src) as $file) {
        require_once $file;
    }
    \spl_autoload_unregister($autoload);
})();

// Functions are not autoloadable; this is the one thing the file has to do eagerly.
require_once __DIR__ . '/functions.php';
