<?php

/**
 * Research 50 §A `block-curl.php`: S-8, blocked in the shim inside a third-party library.
 * `curl_exec()` resolves as `libcurl:*`, a row the default `IGNIS_PARK` policy does not cover, so it blocks the thread.
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
