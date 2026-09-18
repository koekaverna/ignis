<?php

declare(strict_types=1);

/**
 * R-HEADERS-MULTI: a response may carry the same header name more than once, and `Set-Cookie` is
 * the case that must not be comma-joined (RFC 7230 names it as the exception). Until 2026-09-18 the
 * boundary was a flat array<string, string>, so only the last value survived.
 *
 * Serves one route that sets three cookies and a repeated Vary. smoke.sh counts the lines.
 */

require __DIR__ . '/../../php/packages/runtime/src/ignis.php';

use Ignis\Http\Request;
use Ignis\Http\Response;

$listen = getenv('IGNIS_LISTEN') ?: '127.0.0.1:8183';

Ignis\serve(static function (Request $request): Response {
    return new Response('ok', 200, [
        'content-type' => 'text/plain',
        'set-cookie' => ['session=abc; path=/', 'csrf=xyz; path=/', 'locale=en; path=/'],
        'vary' => ['accept-encoding', 'origin'],
    ]);
}, $listen);
