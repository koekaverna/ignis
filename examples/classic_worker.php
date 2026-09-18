<?php

/**
 * Classic mode, top-level worker loop — for apps that keep state in globals.
 *
 * The `include` below runs at the top level of THIS script, which is the only place where an
 * entry script's top-level assignments become real globals: `$GLOBALS['wpdb']` is set and
 * `global $wpdb` inside a function sees it (V-53 measured every alternative — a function, a
 * closure, a fiber and `extract($GLOBALS, EXTR_REFS)` all leave it empty). WordPress, Drupal and
 * any procedural docroot need this shape; a Symfony front controller does not and can use
 * `Ignis\Classic\serve()` (examples/classic_server.php), which handles requests in fibers. Laravel
 * cannot yet: `Container::$instance` is a process-global static that interleaved fibers clobber
 * (research 25, M3-5a/M3-5b).
 *
 *     ignis --threads 4 examples/classic_worker.php /var/www/html/public
 *
 * One request at a time per thread, by construction: the loop is this `while`, so nothing else on
 * the thread runs while the script does. Scripts must declare functions and classes with
 * `require_once` or behind `function_exists()`: a top-level `function foo() {}` survives into the
 * next request and PHP's "Cannot redeclare" is a fatal that cannot be caught (the supervisor
 * respawns the thread, but the request is lost). This is the rule in every worker runtime.
 */
declare(strict_types=1);

require __DIR__ . '/../php/packages/runtime/src/ignis.php';
require __DIR__ . '/../php/packages/runtime/src/classic.php';

$docroot = $argv[1] ?? getenv('IGNIS_DOCROOT') ?: __DIR__ . '/public';
$addr = getenv('IGNIS_LISTEN') ?: '127.0.0.1:8080';

Ignis\Classic\listen($docroot, $addr);
fwrite(STDERR, "classic worker: $docroot on $addr\n");

while ($script = Ignis\Classic\accept()) {
    include $script;
    Ignis\Classic\respond();
}
