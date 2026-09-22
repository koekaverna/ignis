<?php

/**
 * Shared wiring for every research-50 §A fixture (`bench/stall-ladder.sh`).
 *
 * Each fixture requires this file and calls `serveStallFixture()` with the handler for `/stuck` --
 * the one route that produces the stuck state under test. `/hello` and `/sleep` are the same on
 * every fixture: `/hello` is what proves the *other* fibers on the same worker keep answering while
 * `/stuck` is wedged, and `/sleep?ms=N` is how the harness pins a request to every other worker
 * before it fires `/stuck` at the remaining one (the technique `bench/e12-isolation.sh` uses).
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

/**
 * `block-curl.php` and `finally-io.php` both need a host:port that genuinely blocks a TCP connect
 * rather than answering. Research 50 names `10.255.255.1` (RFC 1918); on this box that address is
 * intercepted by the sandbox's egress filter and answers every private/reserved destination with
 * an immediate HTTP 403 (`x-deny-reason: private_dest_ip`), confirmed with `curl -v` before this
 * file was written, so it is not a blackhole here. `93.184.216.34:81` (a public host, a port its
 * upstream firewall drops) gives a genuine ~5 s connect timeout with no answer on this box.
 * Override with `IGNIS_STALL_BLACKHOLE=host:port` where the RFC 1918 address works as intended.
 */
function stallFixtureBlackhole(): string
{
    return getenv('IGNIS_STALL_BLACKHOLE') ?: '93.184.216.34:81';
}
