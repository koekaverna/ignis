<?php

declare(strict_types=1);

namespace Ignis\Doctrine\DependencyInjection;

use Ignis\Doctrine\Pool\PoolingMiddleware;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Extension\Extension;

/**
 * Registers the pooling middleware with the numbers the application configured.
 *
 * An extension, not a compiler pass, and for a reason that bit once already: DoctrineBundle's
 * `MiddlewaresPass` reads every `doctrine.middleware` tag at priority 0 and turns it into a child
 * definition per connection, so a tag added by a later pass is never seen. Extensions all run before
 * any pass, which puts this out of the ordering question entirely.
 */
final class IgnisDoctrineExtension extends Extension
{
    public const POOL_SERVICE_ID = 'ignis.doctrine.connection_pool';

    /**
     * @param array<array-key, mixed> $configs
     */
    public function load(array $configs, ContainerBuilder $container): void
    {
        /** @var array{pool: array{size: int|string, warm: int|string|null, wait_ms: int|string}} $config */
        $config = $this->processConfiguration(new Configuration(), $configs);
        $pool = $config['pool'];

        $container->setDefinition(
            self::POOL_SERVICE_ID,
            (new Definition(PoolingMiddleware::class))
                ->setArguments([$pool['size'], $pool['wait_ms'], $pool['warm'] ?? $pool['size']])
                ->addTag('doctrine.middleware')
                ->setPublic(true),
        );
    }

    public function getAlias(): string
    {
        return 'ignis_doctrine';
    }
}
