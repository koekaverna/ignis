<?php

declare(strict_types=1);

namespace Ignis\Tests\Doctrine;

use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManager;
use Ignis\Doctrine\DependencyInjection\DoctrineFiberScopePass;
use Ignis\Doctrine\FiberEntityManager;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;

/**
 * The container wiring that keeps two overlapping requests apart: a per-fiber manager (V-69) and,
 * since E24, a per-fiber connection — a shared one is a single PostgreSQL socket for every fiber on
 * the thread, and libpq is not reentrant per connection.
 */
#[CoversClass(DoctrineFiberScopePass::class)]
final class DoctrineFiberScopePassTest extends TestCase
{
    public function testTheManagerBecomesAFiberScopedDecoratorOverANonSharedInner(): void
    {
        $container = $this->containerWithDoctrine();

        (new DoctrineFiberScopePass())->process($container);

        $public = $container->getDefinition('doctrine.orm.default_entity_manager');
        self::assertSame(FiberEntityManager::class, $public->getClass());
        self::assertTrue($public->isShared(), 'the id everyone injects has to stay one object');
        self::assertArrayHasKey('kernel.reset', $public->getTags());

        $inner = $container->getDefinition('doctrine.orm.default_entity_manager.ignis_inner');
        self::assertSame(EntityManager::class, $inner->getClass());
        self::assertFalse($inner->isShared(), 'a new manager per get() is what "per fiber" needs');
        self::assertFalse($inner->isLazy(), 'a proxy per fiber buys nothing on a non-shared definition');
    }

    public function testEveryConnectionBecomesNonShared(): void
    {
        $container = $this->containerWithDoctrine();

        (new DoctrineFiberScopePass())->process($container);

        self::assertFalse($container->getDefinition('doctrine.dbal.default_connection')->isShared());
        self::assertFalse($container->getDefinition('doctrine.dbal.reporting_connection')->isShared());
    }

    public function testAConnectionThatDoctrineDoesNotDeclareIsLeftAlone(): void
    {
        $container = $this->containerWithDoctrine();
        $container->setDefinition('app.legacy_connection', new Definition(Connection::class));

        (new DoctrineFiberScopePass())->process($container);

        self::assertTrue($container->getDefinition('app.legacy_connection')->isShared());
    }

    public function testRunningTwiceDoesNotWrapTheWrapper(): void
    {
        $container = $this->containerWithDoctrine();
        $pass = new DoctrineFiberScopePass();

        $pass->process($container);
        $pass->process($container);

        self::assertFalse($container->hasDefinition('doctrine.orm.default_entity_manager.ignis_inner.ignis_inner'));
        self::assertSame(
            FiberEntityManager::class,
            $container->getDefinition('doctrine.orm.default_entity_manager')->getClass(),
        );
    }

    public function testAContainerWithoutDoctrineIsUntouched(): void
    {
        $container = new ContainerBuilder();
        $container->setDefinition('app.service', new Definition(self::class));

        (new DoctrineFiberScopePass())->process($container);

        self::assertSame(self::class, $container->getDefinition('app.service')->getClass());
        self::assertTrue($container->getDefinition('app.service')->isShared());
    }

    /** Two managers and two connections, named the way DoctrineBundle names them. */
    private function containerWithDoctrine(): ContainerBuilder
    {
        $container = new ContainerBuilder();
        $container->setParameter('doctrine.connections', [
            'default' => 'doctrine.dbal.default_connection',
            'reporting' => 'doctrine.dbal.reporting_connection',
        ]);
        foreach (['default', 'reporting'] as $name) {
            $container->setDefinition("doctrine.dbal.{$name}_connection", new Definition(Connection::class));
            $container->setDefinition(
                "doctrine.orm.{$name}_entity_manager",
                (new Definition(EntityManager::class))->setPublic(true),
            );
        }

        return $container;
    }
}
