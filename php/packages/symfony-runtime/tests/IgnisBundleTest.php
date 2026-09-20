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
    /**
     * Through the bundle's own extension rather than a direct `loadExtension()` call, because that is
     * the path a kernel takes: it is what runs `configure()`'s schema over the application's yaml
     * before the bundle ever sees an array.
     *
     * @param array<string, mixed> $configuration
     */
    private static function scopedIds(array $configuration): mixed
    {
        $container = new ContainerBuilder();
        $extension = (new IgnisBundle())->getContainerExtension();
        self::assertNotNull($extension);
        $extension->load([$configuration], $container);

        return $container->getParameter(FiberScopePass::VENDOR_IDS_PARAMETER);
    }

    public function testAnApplicationThatConfiguresNothingGetsTheFrameworkIds(): void
    {
        self::assertSame(
            ['request_stack', 'security.token_storage', 'security.untracked_token_storage', 'security.logout_url_generator'],
            self::scopedIds([]),
        );
    }

    /**
     * What the application lists is **added** to the framework services, never substituted for them.
     * This used to be the other way round — the bundle set its defaults only when a container
     * parameter was absent — so an application scoping one service of its own silently lost
     * `request_stack` and the token storages, and the loss showed up as another request's user.
     */
    public function testAnApplicationsOwnIdsAreAddedToTheFrameworksNotSubstitutedForThem(): void
    {
        $ids = self::scopedIds(['scoped_ids' => ['acme.tenant_context']]);

        self::assertIsArray($ids);
        self::assertContains('acme.tenant_context', $ids, "the application's own id survives");
        self::assertContains('request_stack', $ids, 'and so does every framework one');
        self::assertContains('security.token_storage', $ids);
        self::assertContains('security.untracked_token_storage', $ids);
        self::assertContains('security.logout_url_generator', $ids);
    }

    /**
     * The setting is a bundle setting and not a container parameter, which is what makes the line
     * above true in an application rather than only in this test: a parameter written in
     * `services.yaml` is set while the kernel loads its configuration, and the bundle's defaults are
     * merged in later, during compilation — so a parameter could only ever have overwritten them.
     */
    public function testTheSettingIsRejectedWhenItIsNotAListOfIds(): void
    {
        $this->expectException(\Exception::class);

        self::scopedIds(['scoped_ids' => 'acme.tenant_context']);
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
