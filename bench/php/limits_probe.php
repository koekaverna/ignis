<?php
// One-route worker for bench/limits.sh: the http.rs limits (body cap, header timeout,
// idle timeout) are all enforced before a request ever reaches PHP, so the route itself
// only needs to prove the server is up.
declare(strict_types=1);
require __DIR__ . '/../../php/ignis.php';

use Ignis\Http\Request;
use Ignis\Http\Response;

$listen = getenv('IGNIS_LISTEN') ?: '127.0.0.1:8081';

Ignis\serve(static function (Request $req): Response {
    return Response::text("ok\n");
}, $listen);
