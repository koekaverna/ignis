<?php

/**
 * Ignis classic mode: serve a document root of ordinary PHP scripts, one `include` per request.
 *
 * The pieces live one per file under PSR-4 (`Ignis\Classic\` → `src/Classic/`); this file loads them
 * for the entry scripts and benches that `require` it directly. Free functions and the CGI-era
 * polyfills cannot be autoloaded, so they are required here and listed in composer's
 * `autoload.files`.
 *
 * Assumptions (embed SAPI, resident script, one fiber per request):
 * - `header()`/`headers_list()`/`http_response_code()` work because `php_embed_init()` sets
 *   `no_headers=1`. The header table (`SG(sapi_headers)`) and the output buffers are per OS thread
 *   and never re-activated between requests: `reset()` clears them, and a classic script must not
 *   suspend (no `Ignis\sleep`/async I/O inside the include).
 * - `exit()` is an unwind_exit that escapes the fiber and ends the resident script, so scripts call
 *   `finish()` instead.
 * - Sessions need a startup php.ini with `session.use_cookies=0` and `session.cache_limiter=`
 *   because `php_embed_init()` pins `SG(headers_sent)=1`; the adapter then adopts and emits the
 *   session cookie itself.
 * - `php://input` is empty under embed (no read_post); `InputStream` backs it with the request body.
 */

declare(strict_types=1);

require_once __DIR__ . '/ignis.php';
require_once __DIR__ . '/Classic/Finished.php';
require_once __DIR__ . '/Classic/InputStream.php';
require_once __DIR__ . '/Classic/Runner.php';
require_once __DIR__ . '/Classic/functions.php';
require_once __DIR__ . '/Classic/polyfills.php';
