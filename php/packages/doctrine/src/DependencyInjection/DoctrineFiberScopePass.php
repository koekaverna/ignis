<?php

declare(strict_types=1);

namespace Ignis\Doctrine\DependencyInjection;

use Ignis\Doctrine\FiberEntityManager;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Reference;
use Symfony\Component\DependencyInjection\ServiceLocator;

/**
 * Turns every Doctrine entity manager into "one per fiber", without moving the id anyone injects.
 *
 * For each `doctrine.orm.<name>_entity_manager` (found by tag, so multiple managers and non-default
 * names work) the original definition is moved aside to `<id>.ignis_inner` and marked **non-shared**
 * so the container builds a fresh manager on every `get()`. The public id is then a shared
 * `FiberEntityManager` that resolves the current fiber's instance through a locator.
 *
 * Aliases (`doctrine.orm.entity_manager`, `Doctrine\ORM\EntityManagerInterface`, the
 * `$defaultEntityManager` named arguments) keep pointing at the public id and need no change.
 *
 * Priority: this runs late in BEFORE_OPTIMIZATION, after DoctrineBundle has finished building the
 * managers, and before optimisation would inline them into their consumers.
 */
final class DoctrineFiberScopePass implements CompilerPassInterface
{
    public function process(ContainerBuilder $container): void
    {
        foreach ($this->managerIds($container) as $id) {
            $inner = $id . '.ignis_inner';
            if ($container->hasDefinition($inner)) {
                continue;
            }

            $original = $container->getDefinition($id);
            // Non-shared: every get() builds a new manager, which is what "per fiber" needs. Lazy
            // is pointless on a non-shared definition and only adds a proxy per fiber.
            $original->setShared(false)->setLazy(false)->setPublic(false);
            $container->setDefinition($inner, $original);

            $locator = (new Definition(ServiceLocator::class))
                ->setArguments([[$inner => new Reference($inner)]])
                ->addTag('container.service_locator');

            $scoped = (new Definition(FiberEntityManager::class))
                ->setArguments([$locator, $inner, 'doctrine.em.' . $id])
                ->setPublic(true)
                // Symfony's own inventory of per-request state; keeping the tag means the framework
                // resetter also clears this fiber's manager when it does run (research 36).
                ->addTag('kernel.reset', ['method' => 'reset']);

            $container->setDefinition($id, $scoped);
        }
    }

    /** @return list<string> */
    private function managerIds(ContainerBuilder $container): array
    {
        $ids = [];
        foreach ($container->getDefinitions() as $id => $definition) {
            if (\preg_match('/^doctrine\.orm\.[a-z0-9_]+_entity_manager$/', $id) === 1 && $definition->getClass() !== FiberEntityManager::class) {
                $ids[] = $id;
            }
        }

        return $ids;
    }
}
