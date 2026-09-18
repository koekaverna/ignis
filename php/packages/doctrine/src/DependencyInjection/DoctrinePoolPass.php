<?php

declare(strict_types=1);

namespace Ignis\Doctrine\DependencyInjection;

use Ignis\Doctrine\Pool\PoolingMiddleware;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Exception\InvalidArgumentException;

/**
 * One pooling middleware per Doctrine connection, each with that connection's own settings.
 *
 * Two constraints decide the shape. The connection names come from DoctrineBundle's
 * `doctrine.connections` parameter, which only exists once every extension has loaded — so this is a
 * pass, not an extension. And DoctrineBundle's own `MiddlewaresPass` reads the `doctrine.middleware`
 * tags **once**, at priority 0, so this has to run before it: hence priority 1 in the bundle.
 *
 * A middleware is registered for every connection, including those configured with `size: 0`,
 * because `size` may be an environment placeholder that has no value until runtime. Deciding is
 * therefore `PoolingMiddleware`'s job: with a size below 1 it hands the driver straight back.
 */
final class DoctrinePoolPass implements CompilerPassInterface
{
    public const SERVICE_PREFIX = 'ignis.doctrine.pool.';

    public function process(ContainerBuilder $container): void
    {
        if (!$container->hasParameter('doctrine.connections') || !$container->hasParameter(IgnisDoctrineExtension::CONFIG_PARAMETER)) {
            return;
        }

        /** @var array{pool: array<string, mixed>, connections: array<string, array{pool: array<string, mixed>}>} $config */
        $config = $container->getParameter(IgnisDoctrineExtension::CONFIG_PARAMETER);
        /** @var array<string, string> $connections */
        $connections = $container->getParameter('doctrine.connections');
        $this->refuseUnknownConnections(array_keys($config['connections']), array_keys($connections));

        foreach (array_keys($connections) as $name) {
            [$size, $waitMilliseconds, $warmCount] = $this->settingsFor($config, $name);
            $container->setDefinition(
                self::SERVICE_PREFIX . $name,
                (new Definition(PoolingMiddleware::class))
                    ->setArguments([$size, $waitMilliseconds, $warmCount])
                    ->addTag('doctrine.middleware', ['connection' => $name])
                    ->setPublic(true),
            );
        }
    }

    /**
     * @param array{pool: array<string, mixed>, connections: array<string, array{pool: array<string, mixed>}>} $config
     *
     * @return array{0: mixed, 1: mixed, 2: mixed} size, wait, warm — each possibly an env placeholder
     */
    private function settingsFor(array $config, string $name): array
    {
        $defaults = $config['pool'];
        $own = $config['connections'][$name]['pool'] ?? [];

        $size = $own['size'] ?? $defaults['size'];

        return [$size, $own['wait_ms'] ?? $defaults['wait_ms'], $own['warm'] ?? $defaults['warm'] ?? $size];
    }

    /**
     * @param list<string> $configured
     * @param list<string> $known
     */
    private function refuseUnknownConnections(array $configured, array $known): void
    {
        $unknown = array_diff($configured, $known);
        if ($unknown !== []) {
            throw new InvalidArgumentException(sprintf(
                'ignis_doctrine.connections names %s, which Doctrine does not have. Known connections: %s.',
                implode(', ', $unknown),
                implode(', ', $known),
            ));
        }
    }
}
