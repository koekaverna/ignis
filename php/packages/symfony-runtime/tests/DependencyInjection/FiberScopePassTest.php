<?php

declare(strict_types=1);

namespace Ignis\Tests\Symfony\DependencyInjection;

use Ignis\Scope;
use Ignis\Symfony\DependencyInjection\FiberScopePass;
use Ignis\Symfony\FiberServicesResetter;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;

/**
 * `FiberScopePass` marks definitions rather than replacing them (DECISIONS.md 2026-09-20): a marked
 * definition keeps its own class and arguments, with `Ignis\Scope::create()` as the factory and the
 * class prepended to the argument list `Scope::create(string $class, mixed ...$arguments)` expects.
 */
#[CoversClass(FiberScopePass::class)]
final class FiberScopePassTest extends TestCase
{
    public function testAVendorIdFromTheContainerParameterIsMarkedForScopeCreate(): void
    {
        $container = new ContainerBuilder();
        $container->setParameter(FiberScopePass::VENDOR_IDS_PARAMETER, ['request_stack']);
        $container->setDefinition('request_stack', (new Definition('Vendor\\RequestStack'))->setArguments(['original-argument']));

        (new FiberScopePass())->process($container);

        $definition = $container->getDefinition('request_stack');
        self::assertSame([Scope::class, 'create'], $definition->getFactory());
        self::assertSame(['Vendor\\RequestStack', 'original-argument'], $definition->getArguments());
    }

    /**
     * The seal has to happen after the container has finished configuring the object, not after its
     * constructor, because the compiled container emits `$instance->someCall(...)` *after* the
     * factory returned — and Symfony's configurator is the one hook that runs later still.
     *
     * Without this, `security.logout_url_generator` reached its `registerListener()` calls already
     * sealed and filed both firewalls into the scope of whichever request happened to build it; every
     * other request read an empty `$listeners` and Symfony threw `Unable to find logout in the current
     * firewall`, measured 6 of 6 rounds on the E21 fixture.
     */
    public function testTheSealIsTheConfiguratorSoItRunsAfterTheDefinitionsMethodCalls(): void
    {
        $container = new ContainerBuilder();
        $container->setDefinition(
            'app.configured_after_construction',
            (new Definition('Vendor\\ConfiguredService'))
                ->addTag(FiberScopePass::SCOPED_TAG)
                ->addMethodCall('registerSomething', ['a-value']),
        );

        (new FiberScopePass())->process($container);

        $definition = $container->getDefinition('app.configured_after_construction');
        self::assertSame([Scope::class, 'seal'], $definition->getConfigurator());
        self::assertSame([['registerSomething', ['a-value']]], $definition->getMethodCalls(), 'the calls themselves are untouched');
    }

    /**
     * A definition that already has a configurator cannot also have the seal, and scoping it would
     * drop one of the two silently. It is refused by name instead.
     */
    public function testADefinitionThatAlreadyHasAConfiguratorIsRefusedByName(): void
    {
        $container = new ContainerBuilder();
        $container->setDefinition(
            'app.already_configured',
            (new Definition('Vendor\\AlreadyConfigured'))
                ->addTag(FiberScopePass::SCOPED_TAG)
                ->setConfigurator('Vendor\\Configurator::configure'),
        );

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('app.already_configured');

        (new FiberScopePass())->process($container);
    }

    public function testAServiceTaggedIgnisScopedIsMarkedTheSameWay(): void
    {
        $container = new ContainerBuilder();
        $container->setDefinition(
            'app.tagged_service',
            (new Definition('Vendor\\TaggedService'))->addTag(FiberScopePass::SCOPED_TAG),
        );

        (new FiberScopePass())->process($container);

        $definition = $container->getDefinition('app.tagged_service');
        self::assertSame([Scope::class, 'create'], $definition->getFactory());
        self::assertSame(['Vendor\\TaggedService'], $definition->getArguments());
    }

    public function testAServiceNamedByBothSourcesIsMarkedOnlyOnce(): void
    {
        $container = new ContainerBuilder();
        $container->setParameter(FiberScopePass::VENDOR_IDS_PARAMETER, ['request_stack']);
        $container->setDefinition(
            'request_stack',
            (new Definition('Vendor\\RequestStack'))->addTag(FiberScopePass::SCOPED_TAG),
        );

        (new FiberScopePass())->process($container);

        self::assertSame(['Vendor\\RequestStack'], $container->getDefinition('request_stack')->getArguments());
    }

    public function testAnUnrelatedServiceIsLeftAlone(): void
    {
        $container = new ContainerBuilder();
        $container->setParameter(FiberScopePass::VENDOR_IDS_PARAMETER, ['request_stack']);
        $container->setDefinition('request_stack', new Definition('Vendor\\RequestStack'));
        $container->setDefinition('app.other_service', (new Definition('Vendor\\OtherService'))->setArguments(['keep-me']));

        (new FiberScopePass())->process($container);

        $definition = $container->getDefinition('app.other_service');
        self::assertNull($definition->getFactory());
        self::assertSame(['keep-me'], $definition->getArguments());
    }

    /** A vendor id with no definition (an alias-only or absent service) is skipped, not an error. */
    public function testAVendorIdWithNoDefinitionIsSkipped(): void
    {
        $container = new ContainerBuilder();
        $container->setParameter(FiberScopePass::VENDOR_IDS_PARAMETER, ['request_stack']);

        (new FiberScopePass())->process($container);

        self::assertFalse($container->hasDefinition('request_stack'));
    }

    public function testTheServiceResetterIsStillDisabled(): void
    {
        $container = new ContainerBuilder();
        $container->setDefinition('services_resetter', new Definition('Vendor\\OriginalResetter'));
        $container->setDefinition(
            'app.resettable',
            (new Definition('Vendor\\Resettable'))->addTag('kernel.reset', ['method' => 'reset']),
        );

        (new FiberScopePass())->process($container);

        self::assertSame(FiberServicesResetter::class, $container->getDefinition('services_resetter')->getClass());
        self::assertSame(
            [['app.resettable'], '%kernel.debug%'],
            $container->getDefinition('services_resetter')->getArguments(),
        );
    }
}
