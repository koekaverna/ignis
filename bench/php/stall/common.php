<?php

/**
 * Shared wiring for every research-50 §A fixture (`bench/stall-ladder.sh`): each requires this
 * file and calls `serveStallFixture()` with the `/stuck` handler that produces the stuck state under test.
 */

declare(strict_types=1);

require __DIR__ . '/../../../php/packages/runtime/src/ignis.php';

use Ignis\Http\Request;
use Ignis\Http\Response;

function stallFixtureListen(): string
{
    return getenv('IGNIS_LISTEN') ?: '127.0.0.1:18090';
}

/** @param callable(Request): Response $stuckHandler */
function serveStallFixture(callable $stuckHandler): void
{
    $listen = stallFixtureListen();
    Ignis\serve(static function (Request $request) use ($stuckHandler): Response {
        return match ($request->path()) {
            '/hello' => Response::text("hello\n"),
            '/sleep' => sleepRoute($request),
            '/stuck' => $stuckHandler($request),
            default  => Response::text("not found\n", 404),
        };
    }, $listen);
}

function sleepRoute(Request $request): Response
{
    $milliseconds = (int) ($request->query('ms') ?? 5000);
    Ignis\sleep($milliseconds);
    return Response::text("slept $milliseconds\n");
}

/** A host:port whose TCP connect blocks instead of answering, for `block-curl.php` and `finally-io.php`. */
function stallFixtureBlackhole(): string
{
    return getenv('IGNIS_STALL_BLACKHOLE') ?: '93.184.216.34:81';
}
