<?php
/**
 * Does per-fiber state survive into the NEXT request on a pooled fiber?
 *
 * `Ignis\Scope` is a WeakMap keyed by the Fiber object, and the loop reuses parked fibers (V-4), so
 * "per fiber" and "per request" are not the same thing. Which one it is decides the shape of every
 * fiber-scoped service — Symfony's token storage, Doctrine's EntityManager. `inherited` is what the
 * PREVIOUS request left on this fiber; `fiber` is its object id.
 *
 *   IGNIS_LISTEN=127.0.0.1:8191 ignis --threads 1 bench/php/scope_reuse.php
 *   for n in 1 2 3 4; do curl -s "http://127.0.0.1:8191/?n=$n" & done; wait   # then repeat
 *
 * Measured: within one wave every request gets its own fiber and `inherited` is null; in the next
 * wave each fiber comes back from the pool carrying the previous request's value, id for id.
 */
require __DIR__ . '/../../php/packages/runtime/src/ignis.php';

Ignis\serve(function (Ignis\Http\Request $r): Ignis\Http\Response {
    $seen = Ignis\Scope::get('probe');            // what a previous request left on THIS fiber
    Ignis\Scope::set('probe', $r->query('n') ?? '?');
    // park, so several requests are in flight at once and the pool has to grow
    Ignis\sleep(50);
    return Ignis\Http\Response::json([
        'n' => $r->query('n') ?? '?',
        'inherited' => $seen,                     // null = clean fiber, otherwise a previous request's n
        'fiber' => spl_object_id(Fiber::getCurrent()),
    ]);
}, getenv('IGNIS_LISTEN') ?: '127.0.0.1:8187');
