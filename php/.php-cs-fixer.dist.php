<?php

declare(strict_types=1);

/**
 * @PER-CS only, and nothing risky: a formatter may not change what the code does.
 *
 * packages/swoole/src/shim.php alone accounts for most of the diff. It is deliberately not
 * excluded — one permanent exception is worse than one noisy formatting commit.
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
    ->setRiskyAllowed(false)
    ->setRules(['@PER-CS' => true])
    ->setFinder($finder);
