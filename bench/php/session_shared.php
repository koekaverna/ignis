<?php

/**
 * Two questions about sessions under fibers, answered in one run (V-66):
 *
 * 1. Does `session_start()` work under the embed SAPI at all? V-58 stopped at "headers already
 *    sent" and never got past it; this says so from a real request.
 * 2. Is `$_SESSION` shared between two overlapping requests on one thread? It is NOT one of the
 *    four superglobals the fiber-switch observer swaps (`superglobals.rs:22` — `_SERVER`, `_GET`,
 *    `_POST`, `_COOKIE`), so it is a plain thread-global array.
 *
 *   IGNIS_LISTEN=127.0.0.1:8191 ignis --threads 1 bench/php/session_shared.php
 *   for v in alice bob; do curl -s "http://127.0.0.1:8191/?v=$v" & sleep 0.1; done; wait
 *
 * `after` != `before` means the other request overwrote this one's value mid-flight.
 */
require __DIR__ . '/../../php/packages/runtime/src/ignis.php';

Ignis\serve(function (Ignis\Http\Request $r): Ignis\Http\Response {
    $v = $r->query('v') ?? '?';
    $started = @session_start();
    $err = error_get_last()['message'] ?? null;
    $_SESSION['v'] = $v;
    $before = $_SESSION['v'];
    Ignis\sleep(300);                       // park: let the other request run
    return Ignis\Http\Response::json([
        'v' => $v,
        'started' => $started,
        'status' => session_status(),       // 1 = none, 2 = active
        'id' => session_id(),
        'before' => $before,
        'after' => $_SESSION['v'] ?? null,  // != before  =>  the other request overwrote it
        'error' => $err,
    ]);
}, getenv('IGNIS_LISTEN') ?: '127.0.0.1:8191');
