<?php

declare(strict_types=1);

namespace Ignis\Symfony;

use Ignis\Symfony\Attribute\FiberScoped;
use Ignis\Symfony\DependencyInjection\FiberScopePass;
use Symfony\Component\DependencyInjection\ChildDefinition;
use Symfony\Component\DependencyInjection\Compiler\PassConfig;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\HttpKernel\Bundle\Bundle;

/**
 * Register in `config/bundles.php` — without it the container's request-scoped singletons are
 * shared by every fiber, which is a correctness bug for Doctrine and a security bug for the
 * security token (V-68).
 *
 *     Ignis\Symfony\IgnisBundle::class => ['all' => true],
 *
 * The pass runs at `BEFORE_OPTIMIZATION` with a low priority so it sees the definitions the
 * security bundle has finished creating, and before optimisation inlines any of them.
 */
final class IgnisBundle extends Bundle
{
    /**
     * Framework services whose state belongs to one request. An application adds its own through the
     * same parameter — `parameters: { ignis.scoped_vendor_ids: [...] }` in `services.yaml`, or the
     * `#[FiberScoped]` attribute for a class it owns — and what it lists is **added** to these
     * rather than replacing them. It used to replace them: the bundle set the defaults only when
     * the parameter was absent, so an application scoping one service of its own silently lost
     * `request_stack` and the token storages, and the loss showed up as another request's user.
     *
     * The list is deliberately short. `kernel.reset` is not the criterion — research 36 read all
     * fifteen services carrying that tag and found **nine are caches shared between requests on
     * purpose**, where scoping would give each request its own cache and quietly destroy what it is
     * for. The criterion is "this state belongs to one request", which no container tag expresses
     * and a person has to decide.
     */
    private const FRAMEWORK_SCOPED_IDS = [
        'request_stack',
        'security.token_storage',
        'security.untracked_token_storage',
    ];

    public function build(ContainerBuilder $container): void
    {
        $container->registerAttributeForAutoconfiguration(
            FiberScoped::class,
            static function (ChildDefinition $definition): void {
                $definition->addTag(FiberScopePass::SCOPED_TAG);
            },
        );
        $container->setParameter(
            FiberScopePass::VENDOR_IDS_PARAMETER,
            [...self::FRAMEWORK_SCOPED_IDS, ...self::configured($container)],
        );
        $container->addCompilerPass(new FiberScopePass(), PassConfig::TYPE_BEFORE_OPTIMIZATION, -256);
    }

    /**
     * What the application put in the parameter before the bundle was built, if anything.
     *
     * @return list<string>
     */
    private static function configured(ContainerBuilder $container): array
    {
        if (!$container->hasParameter(FiberScopePass::VENDOR_IDS_PARAMETER)) {
            return [];
        }
        $configured = $container->getParameter(FiberScopePass::VENDOR_IDS_PARAMETER);
        if (!is_array($configured)) {
            throw new \LogicException('the "' . FiberScopePass::VENDOR_IDS_PARAMETER . '" container parameter must be an array of service ids');
        }

        return array_values(array_filter($configured, 'is_string'));
    }
}
