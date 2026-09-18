<?php

declare(strict_types=1);

namespace Ignis\Tests\Doctrine;

use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Ignis\Doctrine\FiberEntityManager;
use Ignis\Scope;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;

/**
 * One EntityManager per fiber (V-67, V-68). Doctrine's manager is a container singleton holding a
 * UnitOfWork, which is a per-request object by nature; under Ignis several requests share a thread,
 * so the shared id everyone injects has to resolve a per-fiber instance on every call.
 */
#[CoversClass(FiberEntityManager::class)]
#[AllowMockObjectsWithoutExpectations]
final class FiberEntityManagerTest extends TestCase
{
    protected function setUp(): void
    {
        Scope::clear();
    }

    protected function tearDown(): void
    {
        Scope::clear();
    }

    public function testEachFiberResolvesItsOwnEntityManager(): void
    {
        $inner = [$this->openManager(), $this->openManager()];
        $manager = new FiberEntityManager($this->locatorReturning($inner), 'doctrine.orm.inner');

        $fromMain = self::em($manager);
        $fromFiber = null;
        $fiber = new \Fiber(static function () use ($manager, &$fromFiber): void {
            $fromFiber = self::em($manager);
        });
        $fiber->start();

        self::assertSame($inner[0], $fromMain);
        self::assertSame($inner[1], $fromFiber);
        self::assertNotSame($fromMain, $fromFiber, 'two overlapping requests must never share an identity map');
    }

    public function testTheSameFiberKeepsTheOneItAlreadyBuilt(): void
    {
        $inner = [$this->openManager(), $this->openManager()];
        $locator = $this->locatorReturning($inner);
        $locator->expects($this->once())->method('get');
        $manager = new FiberEntityManager($locator, 'doctrine.orm.inner');

        self::assertSame(self::em($manager), self::em($manager), 'built on first use, then reused for the rest of the request');
    }

    public function testAManagerDoctrineHasClosedIsReplacedOnTheNextCall(): void
    {
        $closed = $this->createMock(EntityManagerInterface::class);
        $closed->method('isOpen')->willReturn(false);
        $manager = new FiberEntityManager($this->locatorReturning([$closed, $fresh = $this->openManager()]), 'doctrine.orm.inner');

        self::em($manager);

        self::assertSame($fresh, self::em($manager), 'a closed manager throws on every call, so it has to be dropped rather than handed out again');
    }

    /**
     * V-68: `EntityManager::clear()` is `UnitOfWork::clear()` and nothing more — it does not roll
     * back — so without this a request that died between `beginTransaction()` and `commit()` would
     * hand its open transaction to the next request on the same pooled fiber.
     */
    public function testResetRollsBackEveryOpenTransactionAndDropsTheManager(): void
    {
        $transactionActive = [true, true, false];
        $connection = $this->createMock(Connection::class);
        $connection->method('isTransactionActive')->willReturnCallback(static function () use (&$transactionActive): bool {
            return array_shift($transactionActive) ?? false;
        });
        $connection->expects($this->exactly(2))->method('rollBack');

        $inner = $this->openManager();
        $inner->method('getConnection')->willReturn($connection);
        $inner->expects($this->once())->method('clear');

        $manager = new FiberEntityManager($this->locatorReturning([$inner, $this->openManager()]), 'doctrine.orm.inner');
        self::em($manager);

        $manager->reset();

        self::assertNull(Scope::get('doctrine.em'), 'the fiber must not carry it into the next request');
        self::assertNotSame($inner, self::em($manager), 'the next use builds a new one');
    }

    public function testResettingSomethingThatWasNeverUsedDoesNothing(): void
    {
        $locator = $this->createMock(ContainerInterface::class);
        $locator->expects($this->never())->method('get');

        (new FiberEntityManager($locator, 'doctrine.orm.inner'))->reset();

        self::assertNull(Scope::get('doctrine.em'));
    }

    public function testAConnectionThatIsAlreadyGoneNeedsNoRollback(): void
    {
        $inner = $this->openManager();
        $inner->method('getConnection')->willThrowException(new \RuntimeException('the server closed the connection'));
        $inner->expects($this->once())->method('clear');

        $manager = new FiberEntityManager($this->locatorReturning([$inner]), 'doctrine.orm.inner');
        self::em($manager);

        $manager->reset();

        self::assertNull(Scope::get('doctrine.em'));
    }

    /** @param list<EntityManagerInterface> $managers */
    private function locatorReturning(array $managers): MockObject&ContainerInterface
    {
        $next = 0;
        $locator = $this->createMock(ContainerInterface::class);
        $locator->method('get')->willReturnCallback(static function () use ($managers, &$next): EntityManagerInterface {
            return $managers[$next++] ?? throw new \LogicException('the locator was asked for one manager too many');
        });

        return $locator;
    }

    private function openManager(): MockObject&EntityManagerInterface
    {
        $manager = $this->createMock(EntityManagerInterface::class);
        $manager->method('isOpen')->willReturn(true);

        return $manager;
    }

    private static function em(FiberEntityManager $manager): EntityManagerInterface
    {
        $inner = (new \ReflectionMethod(FiberEntityManager::class, 'em'))->invoke($manager);
        if (!$inner instanceof EntityManagerInterface) {
            throw new \LogicException('FiberEntityManager::em() returned ' . get_debug_type($inner));
        }

        return $inner;
    }
}
