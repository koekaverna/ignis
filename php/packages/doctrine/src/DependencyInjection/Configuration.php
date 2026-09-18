<?php

declare(strict_types=1);

namespace Ignis\Doctrine\DependencyInjection;

use Symfony\Component\Config\Definition\Builder\TreeBuilder;
use Symfony\Component\Config\Definition\ConfigurationInterface;

/**
 * `config/packages/ignis_doctrine.yaml`, shaped the way DoctrineBundle shapes `doctrine.dbal`: the
 * common case writes the settings once, and an application with several connections names them.
 *
 *     ignis_doctrine:
 *         pool:
 *             size: 10        # connections per thread; 0 (default) is a connection per fiber
 *             warm: 10        # opened at boot; defaults to size, 0 fills the pool lazily
 *             wait_ms: 5000   # how long a fiber waits for a free connection before it is refused
 *
 *         connections:        # per connection, and every key falls back to the block above
 *             reporting:
 *                 pool: { size: 2, wait_ms: 500 }
 *             archive:
 *                 pool: { size: 0 }        # this one stays a connection per fiber
 *
 * The names are Doctrine's own connection names (`doctrine.dbal.connections`), and a name that
 * matches none of them is a compile-time error rather than a setting that silently does nothing.
 *
 * A deployment that would rather keep a number outside the code writes `size: '%env(int:DB_POOL)%'`
 * — that is Symfony's own mechanism and it resolves at runtime, so the container cache does not pin
 * it.
 */
final class Configuration implements ConfigurationInterface
{
    public function getConfigTreeBuilder(): TreeBuilder
    {
        $tree = new TreeBuilder('ignis_doctrine');

        $tree->getRootNode()
            ->children()
                ->append($this->poolNode('pool', inherits: false))
                ->arrayNode('connections')
                    ->useAttributeAsKey('name')
                    ->normalizeKeys(false)
                    ->arrayPrototype()
                        ->children()
                            ->append($this->poolNode('pool', inherits: true))
                        ->end()
                    ->end()
                ->end()
            ->end();

        return $tree;
    }

    /**
     * The same three settings twice: once as the defaults for every connection, once per connection
     * where a null means "whatever the block above said".
     */
    private function poolNode(string $name, bool $inherits): \Symfony\Component\Config\Definition\Builder\ArrayNodeDefinition
    {
        $builder = new TreeBuilder($name);
        $node = $builder->getRootNode();

        $node
            ->addDefaultsIfNotSet()
            ->children()
                ->scalarNode('size')
                    ->defaultValue($inherits ? null : 0)
                    ->info('Connections per PHP thread. 0 keeps a connection per fiber, opened and closed per request.')
                ->end()
                ->scalarNode('warm')
                    ->defaultNull()
                    ->info('Connections opened while the thread boots. Null means "as many as size".')
                ->end()
                ->scalarNode('wait_ms')
                    ->defaultValue($inherits ? null : 5000)
                    ->info('Milliseconds a fiber waits for a free connection before PoolTimeoutException.')
                ->end()
            ->end();

        return $node;
    }
}
