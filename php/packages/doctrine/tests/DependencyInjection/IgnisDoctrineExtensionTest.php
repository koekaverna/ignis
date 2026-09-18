<?php

declare(strict_types=1);

namespace Ignis\Tests\Doctrine\DependencyInjection;

use Ignis\Doctrine\DependencyInjection\Configuration;
use Ignis\Doctrine\DependencyInjection\IgnisDoctrineExtension;
use Ignis\Doctrine\Pool\PoolingMiddleware;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/** The pool is configured in code, as service arguments — not from the environment. */
#[CoversClass(IgnisDoctrineExtension::class)]
#[CoversClass(Configuration::class)]
final class IgnisDoctrineExtensionTest extends TestCase
{
    public function testWithNoConfigurationThePoolIsOff(): void
    {
        $container = $this->load([]);

        self::assertSame([0, 5000, 0], $container->getDefinition(IgnisDoctrineExtension::POOL_SERVICE_ID)->getArguments());
    }

    public function testTheSizeIsTheOnlyRequiredNumber(): void
    {
        $container = $this->load([['pool' => ['size' => 10]]]);

        self::assertSame([10, 5000, 10], $container->getDefinition(IgnisDoctrineExtension::POOL_SERVICE_ID)->getArguments(), 'warm defaults to the size');
    }

    public function testWarmingCanBeTurnedOffWithoutTurningThePoolOff(): void
    {
        $container = $this->load([['pool' => ['size' => 8, 'warm' => 0, 'wait_ms' => 250]]]);

        self::assertSame([8, 250, 0], $container->getDefinition(IgnisDoctrineExtension::POOL_SERVICE_ID)->getArguments());
    }

    public function testAnEnvironmentPlaceholderIsPassedThroughForTheContainerToResolve(): void
    {
        $container = $this->load([['pool' => ['size' => '%env(int:DB_POOL)%']]]);

        self::assertSame('%env(int:DB_POOL)%', $container->getDefinition(IgnisDoctrineExtension::POOL_SERVICE_ID)->getArgument(0));
    }

    public function testTheMiddlewareIsTaggedForDoctrineToPickUp(): void
    {
        $definition = $this->load([])->getDefinition(IgnisDoctrineExtension::POOL_SERVICE_ID);

        self::assertSame(PoolingMiddleware::class, $definition->getClass());
        self::assertArrayHasKey('doctrine.middleware', $definition->getTags());
    }

    /** @param list<array<string,mixed>> $configs */
    private function load(array $configs): ContainerBuilder
    {
        $container = new ContainerBuilder();
        (new IgnisDoctrineExtension())->load($configs, $container);

        return $container;
    }
}
