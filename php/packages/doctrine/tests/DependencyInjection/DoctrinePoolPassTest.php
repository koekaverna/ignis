<?php

declare(strict_types=1);

namespace Ignis\Tests\Doctrine\DependencyInjection;

use Ignis\Doctrine\DependencyInjection\Configuration;
use Ignis\Doctrine\DependencyInjection\DoctrinePoolPass;
use Ignis\Doctrine\DependencyInjection\IgnisDoctrineExtension;
use Ignis\Doctrine\Pool\PoolingMiddleware;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Exception\InvalidArgumentException;

/**
 * Every Doctrine connection gets its own pool with its own numbers, the way `doctrine.dbal` lets
 * every connection have its own driver and credentials.
 */
#[CoversClass(DoctrinePoolPass::class)]
#[CoversClass(IgnisDoctrineExtension::class)]
#[CoversClass(Configuration::class)]
final class DoctrinePoolPassTest extends TestCase
{
    public function testOneBlockConfiguresEveryConnection(): void
    {
        $container = $this->compile([['pool' => ['size' => 6, 'wait_ms' => 250]]]);

        self::assertSame([6, 250, 6], $this->argumentsFor($container, 'default'));
        self::assertSame([6, 250, 6], $this->argumentsFor($container, 'reporting'));
    }

    public function testAConnectionOverridesOnlyWhatItNames(): void
    {
        $container = $this->compile([[
            'pool' => ['size' => 6, 'wait_ms' => 250],
            'connections' => ['reporting' => ['pool' => ['size' => 2]]],
        ]]);

        self::assertSame([6, 250, 6], $this->argumentsFor($container, 'default'));
        self::assertSame([2, 250, 2], $this->argumentsFor($container, 'reporting'), 'the wait is inherited, the size and its warm are not');
    }

    public function testOneConnectionCanStayPerFiberWhileAnotherPools(): void
    {
        $container = $this->compile([[
            'pool' => ['size' => 6],
            'connections' => ['reporting' => ['pool' => ['size' => 0]]],
        ]]);

        self::assertSame(6, $this->argumentsFor($container, 'default')[0]);
        self::assertSame(0, $this->argumentsFor($container, 'reporting')[0], 'a size of 0 is per fiber, and the middleware waves the driver through');
    }

    public function testWarmingIsConfiguredPerConnectionToo(): void
    {
        $container = $this->compile([[
            'pool' => ['size' => 6, 'warm' => 0],
            'connections' => ['reporting' => ['pool' => ['size' => 3, 'warm' => 3]]],
        ]]);

        self::assertSame([6, 5000, 0], $this->argumentsFor($container, 'default'), 'fill this one lazily');
        self::assertSame([3, 5000, 3], $this->argumentsFor($container, 'reporting'));
    }

    public function testAnEnvironmentPlaceholderSurvivesToTheService(): void
    {
        $container = $this->compile([['pool' => ['size' => '%env(int:DB_POOL)%']]]);

        self::assertSame('%env(int:DB_POOL)%', $this->argumentsFor($container, 'default')[0]);
    }

    public function testEveryConnectionGetsItsOwnTag(): void
    {
        $container = $this->compile([['pool' => ['size' => 4]]]);

        foreach (['default', 'reporting'] as $name) {
            $definition = $container->getDefinition(DoctrinePoolPass::SERVICE_PREFIX . $name);
            self::assertSame(PoolingMiddleware::class, $definition->getClass());
            self::assertSame([['connection' => $name]], $definition->getTag('doctrine.middleware'));
        }
    }

    public function testANameDoctrineDoesNotHaveIsRefusedAtCompileTime(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('ignis_doctrine.connections names analytics, which Doctrine does not have. Known connections: default, reporting.');

        $this->compile([['connections' => ['analytics' => ['pool' => ['size' => 2]]]]]);
    }

    public function testWithoutDoctrineNothingIsRegistered(): void
    {
        $container = new ContainerBuilder();
        (new IgnisDoctrineExtension())->load([['pool' => ['size' => 4]]], $container);

        (new DoctrinePoolPass())->process($container);

        self::assertFalse($container->hasDefinition(DoctrinePoolPass::SERVICE_PREFIX . 'default'));
    }

    /** @return array{0: mixed, 1: mixed, 2: mixed} */
    private function argumentsFor(ContainerBuilder $container, string $name): array
    {
        /** @var array{0: mixed, 1: mixed, 2: mixed} $arguments */
        $arguments = $container->getDefinition(DoctrinePoolPass::SERVICE_PREFIX . $name)->getArguments();

        return $arguments;
    }

    /** @param list<array<string,mixed>> $configs */
    private function compile(array $configs): ContainerBuilder
    {
        $container = new ContainerBuilder();
        $container->setParameter('doctrine.connections', [
            'default' => 'doctrine.dbal.default_connection',
            'reporting' => 'doctrine.dbal.reporting_connection',
        ]);
        (new IgnisDoctrineExtension())->load($configs, $container);
        (new DoctrinePoolPass())->process($container);

        return $container;
    }
}
