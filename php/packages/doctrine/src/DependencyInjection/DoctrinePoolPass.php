<?php

declare(strict_types=1);

namespace Ignis\Doctrine\DependencyInjection;

use Ignis\Doctrine\Pool\PoolingMiddleware;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;

/**
 * Registers the pooling middleware, which is what makes the two connection modes one switch.
 *
 * It must run **before** DoctrineBundle's `MiddlewaresPass` (priority 0), because that pass reads
 * the `doctrine.middleware` tags once and turns each into a child definition per connection — a tag
 * added after it is simply never seen. Hence priority 1 in `IgnisDoctrineBundle::build()`, while
 * `DoctrineFiberScopePass` stays at -512 where it needs DoctrineBundle to have finished.
 *
 * The middleware takes no arguments on purpose: it reads `IGNIS_DOCTRINE_POOL` itself at runtime, so
 * a container cache warmed with the pool off does not pin the setting for a process that starts with
 * it on.
 */
final class DoctrinePoolPass implements CompilerPassInterface
{
    public const SERVICE_ID = 'ignis.doctrine.connection_pool';

    public function process(ContainerBuilder $container): void
    {
        if (!$container->hasParameter('doctrine.connections') || $container->hasDefinition(self::SERVICE_ID)) {
            return;
        }

        $container->setDefinition(
            self::SERVICE_ID,
            (new Definition(PoolingMiddleware::class))->addTag('doctrine.middleware')->setPublic(true),
        );
    }
}
