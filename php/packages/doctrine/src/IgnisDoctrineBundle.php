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
 * (V-85). Connection pooling is off unless `ignis_doctrine.pool.size` says otherwise.
 */
final class IgnisDoctrineBundle extends Bundle
{
    public function build(ContainerBuilder $container): void
    {
        // Priority 1: DoctrineBundle's MiddlewaresPass reads every `doctrine.middleware` tag at 0 and
        // never looks again, so the pools have to be registered before it. The scope pass is the
        // opposite case — it needs DoctrineBundle to have finished building the managers.
        $container->addCompilerPass(new DoctrinePoolPass(), PassConfig::TYPE_BEFORE_OPTIMIZATION, 1);
        $container->addCompilerPass(new DoctrineFiberScopePass(), PassConfig::TYPE_BEFORE_OPTIMIZATION, -512);
    }

    /**
     * Cold start for pool mode: opening one connection per configured connection creates its pool,
     * and a pool fills itself to the configured warm count the moment it exists. The thread
     * therefore reaches its first request with the handshakes already paid for — and a database
     * that is unreachable says so at boot instead of in the first request that needs it.
     *
     * In per-fiber mode this does nothing at all: no pool, no connection opened here.
     */
    public function boot(): void
    {
        $container = $this->container;
        if ($container === null || !$container->hasParameter('doctrine.connections')) {
            return;
        }

        /** @var array<string,string> $connections */
        $connections = $container->getParameter('doctrine.connections');
        foreach ($connections as $name => $id) {
            $middleware = $container->has(DoctrinePoolPass::SERVICE_PREFIX . $name)
                ? $container->get(DoctrinePoolPass::SERVICE_PREFIX . $name)
                : null;
            if (!$middleware instanceof PoolingMiddleware || !$middleware->isPooling()) {
                continue;   // this connection is in per-fiber mode; nothing to warm
            }
            $connection = $container->get($id);
            if ($connection instanceof \Doctrine\DBAL\Connection) {
                $connection->getNativeConnection();
                $connection->close();
            }
        }
    }
}
