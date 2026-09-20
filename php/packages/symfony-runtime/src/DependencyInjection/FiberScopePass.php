<?php

declare(strict_types=1);

namespace Ignis\Symfony\DependencyInjection;

use Ignis\Scope;
use Ignis\Symfony\FiberServicesResetter;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;

/**
 * Marks the container definitions that hold per-request state to build through `Ignis\Scope::create()`
 * instead of the container's ordinary `new` (BACKLOG.md S-SCOPED-CLASS, DECISIONS.md 2026-09-20).
 *
 * This is the table, not a mechanism: each row is a service id whose state belongs to one request,
 * and the mechanism under all of them is `Ignis\Scope`. Symfony's own inventory of such services is
 * the `kernel.reset` tag — a service tagged there is, by its maintainer's admission, stateful between
 * requests, and between *overlapping* requests resetting cannot help (research 36: `Kernel::boot()`
 * only resets when nothing else is in flight, which under load is never).
 *
 * Only public Symfony API is used to mark a definition: its class and its own arguments are kept,
 * prepended with the class itself, and `Scope::create()` becomes the factory — nothing here replaces
 * the definition, so whatever else it already carries (visibility, tags, decorators) is untouched.
 *
 * Ids come from two sources, combined: the `%ignis.scoped_vendor_ids%` container parameter, and every
 * service tagged `ignis.scoped`. Neither is the application's door — the parameter is how
 * `IgnisBundle` hands this pass the framework ids plus whatever the application put in the bundle's
 * `scoped_ids` setting, and the tag is what `#[FiberScoped]` produces on application code. An
 * application writes one of those two, and either way the row needs a test that fails without it.
 */
final class FiberScopePass implements CompilerPassInterface
{
    public const VENDOR_IDS_PARAMETER = 'ignis.scoped_vendor_ids';
    public const SCOPED_TAG = 'ignis.scoped';

    public function process(ContainerBuilder $container): void
    {
        foreach ($this->scopedServiceIds($container) as $id) {
            $this->scope($container, $id);
        }

        $this->disableTheServiceReset($container);
    }

    /** @return list<string> */
    private function scopedServiceIds(ContainerBuilder $container): array
    {
        $ids = [...$this->vendorIds($container), ...array_keys($container->findTaggedServiceIds(self::SCOPED_TAG))];

        return array_values(array_unique($ids));
    }

    /** @return list<string> */
    private function vendorIds(ContainerBuilder $container): array
    {
        if (!$container->hasParameter(self::VENDOR_IDS_PARAMETER)) {
            return [];
        }
        $parameter = $container->getParameter(self::VENDOR_IDS_PARAMETER);
        if (!is_array($parameter)) {
            throw new \LogicException('the "' . self::VENDOR_IDS_PARAMETER . '" container parameter must be an array of service ids');
        }
        $ids = [];
        foreach ($parameter as $id) {
            if (!is_string($id)) {
                throw new \LogicException('the "' . self::VENDOR_IDS_PARAMETER . '" container parameter must be an array of service ids');
            }
            $ids[] = $id;
        }

        return $ids;
    }

    private function scope(ContainerBuilder $container, string $id): void
    {
        if (!$container->hasDefinition($id)) {
            return;
        }
        $definition = $container->getDefinition($id);
        $this->refuseAnAlreadyConfiguredDefinition($definition, $id);
        $definition->setArguments([$definition->getClass(), ...$definition->getArguments()]);
        $definition->setFactory([Scope::class, 'create']);
        $definition->setConfigurator([Scope::class, 'seal']);
    }

    /**
     * The configurator is the only hook Symfony runs after a definition's method calls and property
     * assignments, and the seal has to be there rather than in the factory: the compiled container
     * emits `$instance->someCall(...)` *after* `Scope::create()` returned, so anything configured
     * that way would otherwise belong to the one request that built the service. This is not a corner
     * case — `security.logout_url_generator` registers one listener per firewall exactly like that.
     *
     * A definition that already carries a configurator of its own cannot have both, so it is refused
     * by name at compile time instead of being scoped with its configuration silently dropped. None
     * of the framework ids is such a definition; an application that hits this should scope the state
     * rather than the service, and the message says so.
     */
    private function refuseAnAlreadyConfiguredDefinition(Definition $definition, string $id): void
    {
        if ($definition->getConfigurator() === null) {
            return;
        }

        throw new \LogicException(\sprintf('the service "%s" cannot be fiber-scoped: it already has a configurator, and Ignis needs that slot to seal the object once the container has finished configuring it. Scope the per-request state it holds instead of the service.', $id));
    }

    /**
     * Takes `services_resetter` out of the request path, because under fibers it reaches across
     * requests rather than between them.
     *
     * `Kernel::handle()` arms the reset and the **next** `handle()` performs it from `boot()`, guarded
     * by `requestStackSize == 0`. That guard covers a request still inside `handle()` and nothing
     * after it: a `StreamedResponse` body is produced by the loop once `handle()` has returned, and
     * `kernel.terminate` listeners park on their own I/O. Measured on the E21 fixture — a streaming
     * request lost its service state to a concurrent one in 3 of 3 rounds, `/reset` arm.
     *
     * The list of ids is read here rather than in the resetter because it exists at compile time and
     * not at run time: `FiberServicesResetter` only reports it, in debug.
     */
    private function disableTheServiceReset(ContainerBuilder $container): void
    {
        if (!$container->hasDefinition('services_resetter')) {
            return;
        }
        $declined = array_keys($container->findTaggedServiceIds('kernel.reset'));
        sort($declined);
        $container->setDefinition(
            'services_resetter',
            (new Definition(FiberServicesResetter::class))
                ->setArguments([$declined, '%kernel.debug%'])
                ->setPublic(true),
        );
    }
}
