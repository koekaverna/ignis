<?php

declare(strict_types=1);

namespace Ignis\Symfony;

use Ignis\Symfony\Attribute\FiberScoped;
use Ignis\Symfony\DependencyInjection\FiberScopePass;
use Symfony\Component\Config\Definition\Configurator\DefinitionConfigurator;
use Symfony\Component\DependencyInjection\ChildDefinition;
use Symfony\Component\DependencyInjection\Compiler\PassConfig;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpKernel\Bundle\AbstractBundle;

/**
 * Register in `config/bundles.php` — without it the container's request-scoped singletons are
 * shared by every fiber, which is a correctness bug for Doctrine and a security bug for the
 * security token (V-68).
 *
 *     Ignis\Symfony\IgnisBundle::class => ['all' => true],
 *
 * An application scopes a vendor service it owns no class for through the bundle's own setting,
 * which is added to the framework defaults below rather than replacing them:
 *
 *     # config/packages/ignis.yaml
 *     ignis:
 *         scoped_ids: ['acme.tenant_context']
 *
 * For a class the application owns, the `#[FiberScoped]` attribute says the same thing at the class
 * and needs no configuration at all.
 *
 * The pass runs at `BEFORE_OPTIMIZATION` with a low priority so it sees the definitions the
 * security bundle has finished creating, and before optimisation inlines any of them.
 */
final class IgnisBundle extends AbstractBundle
{
    /**
     * Framework services whose state belongs to one request.
     *
     * The list is deliberately short. `kernel.reset` is not the criterion — research 36 read all
     * fifteen services carrying that tag and found **nine are caches shared between requests on
     * purpose**, where scoping would give each request its own cache and quietly destroy what it is
     * for. The criterion is "this state belongs to one request", which no container tag expresses
     * and a person has to decide.
     *
     * `security.logout_url_generator` is here for the anonymous request alone. Its `getListener()`
     * asks `security.token_storage` for the firewall name first and only falls back to its own
     * `currentFirewallName`, so an authenticated request is already correct through a service that
     * is already scoped — measured, 0/3 leaks with the id unscoped. Without a token the fallback is
     * reached, and a neighbour's `onKernelFinishRequest` nulls that property under a request that is
     * still awaiting: measured as `InvalidArgumentException: This request is not behind a firewall`,
     * 2 of 2 rounds.
     *
     * @var list<string>
     */
    private const FRAMEWORK_SCOPED_IDS = [
        'request_stack',
        'security.token_storage',
        'security.untracked_token_storage',
        'security.logout_url_generator',
    ];

    public function configure(DefinitionConfigurator $definition): void
    {
        $definition->rootNode()
            ->children()
                ->arrayNode('scoped_ids')
                    ->info('Service ids whose state belongs to one request, added to the framework ids the bundle already scopes.')
                    ->scalarPrototype()->end()
                ->end()
            ->end();
    }

    /**
     * @param array{scoped_ids?: list<string>} $config
     */
    public function loadExtension(array $config, ContainerConfigurator $configurator, ContainerBuilder $container): void
    {
        $container->setParameter(
            FiberScopePass::VENDOR_IDS_PARAMETER,
            [...self::FRAMEWORK_SCOPED_IDS, ...($config['scoped_ids'] ?? [])],
        );
    }

    public function build(ContainerBuilder $container): void
    {
        $container->registerAttributeForAutoconfiguration(
            FiberScoped::class,
            static function (ChildDefinition $definition): void {
                $definition->addTag(FiberScopePass::SCOPED_TAG);
            },
        );
        $container->addCompilerPass(new FiberScopePass(), PassConfig::TYPE_BEFORE_OPTIMIZATION, -256);
    }
}
