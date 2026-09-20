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
    public function build(ContainerBuilder $container): void
    {
        $container->registerAttributeForAutoconfiguration(
            FiberScoped::class,
            static function (ChildDefinition $definition): void {
                $definition->addTag(FiberScopePass::SCOPED_TAG);
            },
        );
        if (!$container->hasParameter(FiberScopePass::VENDOR_IDS_PARAMETER)) {
            $container->setParameter(FiberScopePass::VENDOR_IDS_PARAMETER, [
                'request_stack',
                'security.token_storage',
                'security.untracked_token_storage',
            ]);
        }
        $container->addCompilerPass(new FiberScopePass(), PassConfig::TYPE_BEFORE_OPTIMIZATION, -256);
    }
}
