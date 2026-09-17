<?php

declare(strict_types=1);

namespace Ignis\Doctrine;

use Ignis\Doctrine\DependencyInjection\DoctrineFiberScopePass;
use Symfony\Component\DependencyInjection\Compiler\PassConfig;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\HttpKernel\Bundle\Bundle;

/**
 * Register after DoctrineBundle in `config/bundles.php`:
 *
 *     Ignis\Doctrine\IgnisDoctrineBundle::class => ['all' => true],
 *
 * Without it Doctrine's EntityManager is one shared object for every fiber on the thread.
 */
final class IgnisDoctrineBundle extends Bundle
{
    public function build(ContainerBuilder $container): void
    {
        $container->addCompilerPass(new DoctrineFiberScopePass(), PassConfig::TYPE_BEFORE_OPTIMIZATION, -512);
    }
}
