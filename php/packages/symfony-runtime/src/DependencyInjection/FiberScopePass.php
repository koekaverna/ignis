<?php

declare(strict_types=1);

namespace Ignis\Symfony\DependencyInjection;

use Ignis\Symfony\FiberRequestStack;
use Ignis\Symfony\FiberServicesResetter;
use Ignis\Symfony\FiberTokenStorage;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;

/**
 * Replaces the container singletons that hold per-request state with fiber-scoped ones.
 *
 * This is the table, not a mechanism: each row is a service id whose state belongs to one request,
 * and the mechanism under all of them is `Ignis\Scope` (ADR-0006). Symfony's own inventory of such
 * services is the `kernel.reset` tag — a service tagged there is, by its maintainer's admission,
 * stateful between requests, and between *overlapping* requests resetting cannot help (research 36:
 * `Kernel::boot()` only resets when nothing else is in flight, which under load is never).
 *
 * Only rows that are measured are listed. Adding one is a line here plus a decorator, and it needs
 * a test that fails without it.
 */
final class FiberScopePass implements CompilerPassInterface
{
    public function process(ContainerBuilder $container): void
    {
        // ADR-0011, V-16. An application may already do this in services.yaml; setting it again is
        // harmless and means the bundle alone is enough.
        $this->replace($container, 'request_stack', new Definition(FiberRequestStack::class));

        // V-68. `security.token_storage` is the one the firewall writes and every voter reads;
        // `security.untracked_token_storage` is the undecorated one behind UsageTrackingTokenStorage
        // when sessions are enabled, and holds the same token.
        foreach (['security.token_storage', 'security.untracked_token_storage'] as $id) {
            $this->replace($container, $id, (new Definition(FiberTokenStorage::class))->setArguments(['security.token']));
        }

        $this->disableTheServiceReset($container);
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

    private function replace(ContainerBuilder $container, string $id, Definition $definition): void
    {
        if (!$container->hasDefinition($id) && !$container->hasAlias($id)) {
            return;
        }
        if ($container->hasDefinition($id)) {
            $old = $container->getDefinition($id);
            $definition->setPublic($old->isPublic());
            // Keep whatever tags the original carried, `kernel.reset` among them: research 36 found
            // an override in the wild that silently dropped the tag and stopped a reset happening.
            foreach ($old->getTags() as $tag => $attributes) {
                foreach ($attributes as $attribute) {
                    $definition->addTag($tag, $attribute);
                }
            }
        } else {
            $container->removeAlias($id);
            $definition->setPublic(true);
        }
        $container->setDefinition($id, $definition);
    }
}
