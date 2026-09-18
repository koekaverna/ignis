<?php

declare(strict_types=1);

namespace Ignis\Doctrine;

use Ignis\Doctrine\DependencyInjection\DoctrineFiberScopePass;
use Ignis\Doctrine\DependencyInjection\DoctrinePoolPass;
use Ignis\Doctrine\Pool\PoolingMiddleware;
use Symfony\Component\DependencyInjection\Compiler\PassConfig;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\HttpKernel\Bundle\Bundle;

/**
 * Register after DoctrineBundle in `config/bundles.php`:
 *
 *     Ignis\Doctrine\IgnisDoctrineBundle::class => ['all' => true],
 *
 * Without it Doctrine's EntityManager is one shared object for every fiber on the thread, and so is
 * its database connection — which under PostgreSQL means two overlapping requests inside one socket
 * (V-85).
 */
final class IgnisDoctrineBundle extends Bundle
{
    public function build(ContainerBuilder $container): void
    {
        $container->addCompilerPass(new DoctrinePoolPass(), PassConfig::TYPE_BEFORE_OPTIMIZATION, 1);
        $container->addCompilerPass(new DoctrineFiberScopePass(), PassConfig::TYPE_BEFORE_OPTIMIZATION, -512);
    }

    /**
     * Cold start for pool mode: opening one connection per configured connection creates its pool,
     * and a pool fills itself to `IGNIS_DOCTRINE_POOL_WARM` the moment it exists. The thread
     * therefore reaches its first request with the handshakes already paid for — and a database
     * that is unreachable says so at boot instead of in the first request that needs it.
     *
     * In per-fiber mode this does nothing at all: no pool, no connection opened here.
     */
    public function boot(): void
    {
        $container = $this->container;
        if (PoolingMiddleware::limitFromEnvironment() < 1 || $container === null || !$container->hasParameter('doctrine.connections')) {
            return;
        }

        /** @var array<string,string> $connections */
        $connections = $container->getParameter('doctrine.connections');
        foreach ($connections as $id) {
            $connection = $container->get($id);
            if (!$connection instanceof \Doctrine\DBAL\Connection) {
                continue;
            }
            $connection->getNativeConnection();
            $connection->close();
        }
    }
}
