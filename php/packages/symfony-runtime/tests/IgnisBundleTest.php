<?php

declare(strict_types=1);

namespace Ignis\Tests\Symfony;

use Ignis\Scope;
use Ignis\Symfony\Attribute\FiberScoped;
use Ignis\Symfony\DependencyInjection\FiberScopePass;
use Ignis\Symfony\IgnisBundle;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerBuilder;

#[CoversClass(IgnisBundle::class)]
final class IgnisBundleTest extends TestCase
{
    public function testBuildDefaultsTheVendorIdsParameterWhenTheApplicationHasNotSetOne(): void
    {
        $container = new ContainerBuilder();

        (new IgnisBundle())->build($container);

        self::assertSame(
            ['request_stack', 'security.token_storage', 'security.untracked_token_storage'],
            $container->getParameter(FiberScopePass::VENDOR_IDS_PARAMETER),
        );
    }

    /**
     * What the application lists is **added** to the framework services, never substituted for them.
     * This used to be the other way round -- the bundle set its defaults only when the parameter was
     * absent -- so an application scoping one service of its own silently lost `request_stack` and
     * the token storages, and the loss showed up as another request's user.
     */
    public function testAnApplicationsOwnIdsAreAddedToTheFrameworksNotSubstitutedForThem(): void
    {
        $container = new ContainerBuilder();
        $container->setParameter(FiberScopePass::VENDOR_IDS_PARAMETER, ['app.only']);

        (new IgnisBundle())->build($container);

        $ids = $container->getParameter(FiberScopePass::VENDOR_IDS_PARAMETER);
        self::assertIsArray($ids);
        self::assertContains('app.only', $ids, "the application's own id survives");
        self::assertContains('request_stack', $ids, 'and so does every framework one');
        self::assertContains('security.token_storage', $ids);
        self::assertContains('security.untracked_token_storage', $ids);
    }

    public function testAnApplicationThatConfiguresNothingStillGetsTheFrameworkIds(): void
    {
        $container = new ContainerBuilder();

        (new IgnisBundle())->build($container);

        self::assertSame(
            ['request_stack', 'security.token_storage', 'security.untracked_token_storage'],
            $container->getParameter(FiberScopePass::VENDOR_IDS_PARAMETER),
        );
    }

    /**
     * The full pipeline, not a unit stand-in: `build()` registers `#[FiberScoped]` with Symfony's own
     * `registerAttributeForAutoconfiguration()`, and this proves that registration actually reaches an
     * application class carrying the attribute, through Symfony's own compiler passes, into the tag
     * `FiberScopePass` reads and the `Scope::create()` factory it sets.
     */
    public function testACompiledContainerScopesAFiberScopedApplicationClass(): void
    {
        $container = new ContainerBuilder();
        (new IgnisBundle())->build($container);
        $container->register(FiberScopedExampleService::class, FiberScopedExampleService::class)
            ->setAutoconfigured(true)
            ->setPublic(true);

        $container->compile();

        $definition = $container->getDefinition(FiberScopedExampleService::class);
        self::assertSame([Scope::class, 'create'], $definition->getFactory());
        self::assertSame([FiberScopedExampleService::class], $definition->getArguments());
    }
}

#[FiberScoped]
final class FiberScopedExampleService {}
