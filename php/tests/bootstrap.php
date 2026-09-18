<?php

declare(strict_types=1);

/**
 * The fake reactor goes first, before the autoloader: packages/runtime/stubs/ignis.php is an
 * autoload-dev file whose bodies throw, and the first declaration of a name wins. Loading the
 * fake first leaves every stub guard a no-op and every ignis_*() call answerable.
 */

require __DIR__ . '/fake-reactor.php';
require dirname(__DIR__) . '/vendor/autoload.php';
