<?php

declare(strict_types=1);

/**
 * @PER-CS only, and nothing risky: a formatter may not change what the code does.
 *
 * Caching is off: the whole tree formats in half a second, and a cache file in the working tree
 * is one more artefact to keep out of git.
 */

use PhpCsFixer\Config;
use PhpCsFixer\Finder;
use PhpCsFixer\Runner\Parallel\ParallelConfigFactory;

$finder = Finder::create()
    ->in([__DIR__ . '/packages', __DIR__ . '/../examples', __DIR__ . '/../bench/php'])
    ->exclude(['vendor'])
    ->notPath('#/var/cache/#');

return (new Config())
    ->setParallelConfig(ParallelConfigFactory::detect())
    ->setUsingCache(false)
    ->setRiskyAllowed(false)
    ->setRules(['@PER-CS' => true])
    ->setFinder($finder);
