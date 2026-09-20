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
 * Ids come from two sources, combined: the `%ignis.scoped_vendor_ids%` container parameter, which
 * `IgnisBundle` defaults to the three framework services this used to replace outright
 * (`request_stack` and the two token-storage ids), and every service tagged `ignis.scoped` — which
 * `IgnisBundle` also arranges for `#[FiberScoped]` to produce on application code. Adding a row is a
 * parameter entry or a tag, and it needs a test that fails without it.
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
        $definition->setArguments([$definition->getClass(), ...$definition->getArguments()]);
        $definition->setFactory([Scope::class, 'create']);
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
