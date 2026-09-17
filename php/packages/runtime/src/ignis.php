<?php

/**
 * Ignis userland scheduler — the entry point that loads it.
 *
 * The classes live one per file under PSR-4 (`Ignis\` → `src/`), so an application that installs
 * `ignis/runtime` with composer needs nothing from this file: the autoloader finds them, and
 * `autoload.files` pulls in the free functions, which PSR-4 cannot do.
 *
 * This file stays because not everything goes through composer — examples, benches and entry
 * scripts `require` it directly, and the embed SAPI has no autoloader of its own until one is
 * registered. It requires the pieces in dependency order and nothing else.
 *
 * Rust primitives it is built on: `ignis_submit_sleep(int $ms): int`, `ignis_poll(int $timeout_ms):
 * array`, `ignis_inflight(): int`, `ignis_serve(string $addr): bool`, `ignis_respond(int $id, int
 * $status, array $headers, string $body): bool`. Everything else — fibers, futures, `all()`, the
 * pool, `serve()` — is PHP, shaped so it can become a Revolt driver
 * (activate/dispatch/deactivate/now).
 */

declare(strict_types=1);

require_once __DIR__ . '/CancelledException.php';
require_once __DIR__ . '/DeadlineExceededException.php';
require_once __DIR__ . '/Future.php';
require_once __DIR__ . '/Scope.php';
require_once __DIR__ . '/Http/Request.php';
require_once __DIR__ . '/Http/Response.php';
require_once __DIR__ . '/Loop.php';
require_once __DIR__ . '/functions.php';
