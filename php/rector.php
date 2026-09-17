<?php

declare(strict_types=1);

/**
 * Rector is a local one-shot tool here, never a CI gate: run `composer rector` to see what it
 * would change, then take the changes you agree with by hand.
 *
 * RecastingRemovalRector is skipped because it deletes the (string) cast around array_shift() in
 * Temporal\Worker\Transport\Core\CoreCodec, which changes behaviour on a non-string element.
 */

use Rector\Config\RectorConfig;
use Rector\DeadCode\Rector\Cast\RecastingRemovalRector;
use Rector\ValueObject\PhpVersion;

return RectorConfig::configure()
    ->withPaths([
        __DIR__ . '/packages',
        __DIR__ . '/../examples',
        __DIR__ . '/../bench/php',
    ])
    ->withSkip([
        RecastingRemovalRector::class,
        __DIR__ . '/vendor',
        __DIR__ . '/packages/*/vendor',
    ])
    ->withAutoloadPaths([__DIR__ . '/packages/runtime/stubs/ignis.php'])
    ->withPhpVersion(PhpVersion::PHP_82)
    ->withPreparedSets(deadCode: true, typeDeclarations: true);
