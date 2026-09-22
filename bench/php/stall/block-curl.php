<?php

/**
 * Research 50 §A `block-curl.php`: blocked in the shim inside a third-party library.
 *
 * `curl_exec()` calls libcurl's own sockets, which the interposer resolves as `libcurl:*`, a
 * separate row from `libphp:*`. `IGNIS_PARK=libpq` names only `libpq`, so `libcurl` is not in the
 * policy and every one of its calls blocks the thread -- the "blocked inside a library the table
 * has not been told about" case S-8 exercises.
 *
 * Research 50 names `10.255.255.1` (RFC 1918) as the blackhole; see `stallFixtureBlackhole()` in
 * `common.php` for why this box uses a different default and how to override it.
 */

declare(strict_types=1);

require __DIR__ . '/common.php';

use Ignis\Http\Request;
use Ignis\Http\Response;

serveStallFixture(static function (Request $request): Response {
    $timeoutSeconds = (int) ($request->query('s') ?? 30);
    [$host, $port] = explode(':', stallFixtureBlackhole());
    $handle = curl_init("http://$host:$port/");
    curl_setopt($handle, CURLOPT_CONNECTTIMEOUT, $timeoutSeconds);
    curl_setopt($handle, CURLOPT_TIMEOUT, $timeoutSeconds);
    curl_setopt($handle, CURLOPT_RETURNTRANSFER, true);
    $result = curl_exec($handle);
    $errorNumber = curl_errno($handle);
    curl_close($handle);
    return Response::json(['result' => $result, 'curl_errno' => $errorNumber]);
});
