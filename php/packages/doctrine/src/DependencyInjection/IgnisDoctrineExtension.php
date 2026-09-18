<?php

declare(strict_types=1);

namespace Ignis\Doctrine\DependencyInjection;

use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Extension\Extension;

/**
 * Parses `ignis_doctrine` and leaves the result for `DoctrinePoolPass` to wire.
 *
 * It cannot wire the middleware itself: the settings are per Doctrine connection, and the list of
 * connections is DoctrineBundle's `doctrine.connections` parameter, which does not exist yet while
 * extensions are loading — extensions run in registration order, not in dependency order.
 */
final class IgnisDoctrineExtension extends Extension
{
    public const CONFIG_PARAMETER = 'ignis_doctrine.pools';

    /**
     * @param array<array-key, mixed> $configs
     */
    public function load(array $configs, ContainerBuilder $container): void
    {
        $container->setParameter(self::CONFIG_PARAMETER, $this->processConfiguration(new Configuration(), $configs));
    }

    public function getAlias(): string
    {
        return 'ignis_doctrine';
    }
}
