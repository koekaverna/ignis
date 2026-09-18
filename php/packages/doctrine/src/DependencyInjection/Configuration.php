<?php

declare(strict_types=1);

namespace Ignis\Doctrine\DependencyInjection;

use Symfony\Component\Config\Definition\Builder\TreeBuilder;
use Symfony\Component\Config\Definition\ConfigurationInterface;

/**
 * `config/packages/ignis_doctrine.yaml`:
 *
 *     ignis_doctrine:
 *         pool:
 *             size: 10        # connections per thread; 0 (default) is a connection per fiber
 *             warm: 10        # opened at boot; defaults to size, 0 fills the pool lazily
 *             wait_ms: 5000   # how long a fiber waits for a free connection before it is refused
 *
 * A deployment that would rather keep the number outside the code writes
 * `size: '%env(int:DB_POOL)%'` — that is Symfony's own mechanism and it resolves at runtime, so the
 * container cache does not pin it.
 */
final class Configuration implements ConfigurationInterface
{
    public function getConfigTreeBuilder(): TreeBuilder
    {
        $tree = new TreeBuilder('ignis_doctrine');

        $tree->getRootNode()
            ->children()
                ->arrayNode('pool')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->scalarNode('size')
                            ->defaultValue(0)
                            ->info('Connections per PHP thread. 0 keeps a connection per fiber, opened and closed per request.')
                        ->end()
                        ->scalarNode('warm')
                            ->defaultNull()
                            ->info('Connections opened while the thread boots. Null means "as many as size".')
                        ->end()
                        ->scalarNode('wait_ms')
                            ->defaultValue(5000)
                            ->info('Milliseconds a fiber waits for a free connection before PoolTimeoutException.')
                        ->end()
                    ->end()
                ->end()
            ->end();

        return $tree;
    }
}
