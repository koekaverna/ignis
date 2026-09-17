<?php

declare(strict_types=1);

namespace Ignis\Doctrine;

use Doctrine\ORM\EntityManagerInterface;
use Ignis\Scope;
use Psr\Container\ContainerInterface;
use Symfony\Contracts\Service\ResetInterface;

/**
 * One EntityManager per fiber, so two overlapping requests never share an identity map.
 *
 * Doctrine's manager is a container singleton holding a UnitOfWork — a per-request object by
 * nature. Under Ignis several requests run on one thread at once, so sharing it means one
 * request's entities are visible to another, and one request's `flush()` writes another's changes.
 *
 * **Why a shared decorator over a non-shared inner, and not just `shared: false`.** Marking the
 * manager non-shared does nothing after boot: dozens of application services take it in their
 * constructor and keep it in a property (38 of them in the app research 38 surveyed). The id
 * everyone injects therefore has to stay one shared object — this one — and the per-fiber instance
 * has to be resolved on every call, from a locator pointing at a non-shared inner definition.
 *
 * The forwarders below are generated from `EntityManagerInterface` (35 methods) rather than written
 * by hand, and `Doctrine\ORM\Decorator\EntityManagerDecorator` is deliberately not extended: it
 * reads `$this->wrapped` directly in every method, which is exactly the thing that must not be
 * fixed at construction time.
 *
 * Lifetime: `Ignis\Scope` is keyed by the fiber and fibers are reused, so `Loop` clears the scope at
 * request end (V-67). That is what makes the manager per *request* while its database connection is
 * still opened once per pooled fiber instead of once per request.
 */
final class FiberEntityManager implements EntityManagerInterface, ResetInterface
{
    public function __construct(
        private readonly ContainerInterface $locator,
        private readonly string $innerId,
        private readonly string $key = 'doctrine.em',
    ) {
    }

    /** This fiber's manager, built on first use and replaced once Doctrine has closed it. */
    private function em(): EntityManagerInterface
    {
        $em = Scope::get($this->key);
        if (!$em instanceof EntityManagerInterface || !$em->isOpen()) {
            /** @var EntityManagerInterface $em */
            $em = $this->locator->get($this->innerId);
            Scope::set($this->key, $em);
        }

        return $em;
    }

    /**
     * Request end. `clear()` alone is not enough: `EntityManager::clear()` is `UnitOfWork::clear()`
     * and nothing more (`EntityManager.php:425-428` in orm 3.7.1) — it does **not** roll back, and
     * nothing in DoctrineBundle does either, so a request that dies between `beginTransaction()` and
     * `commit()` would hand its open transaction to the next request on the same pooled fiber.
     */
    public function reset(): void
    {
        $em = Scope::get($this->key);
        if (!$em instanceof EntityManagerInterface) {
            return;
        }
        try {
            $connection = $em->getConnection();
            while ($connection->isTransactionActive()) {
                $connection->rollBack();
            }
        } catch (\Throwable) {
            // A connection that is already gone needs no rollback.
        }
        if ($em->isOpen()) {
            $em->clear();
        }
        Scope::set($this->key, null);
    }

    public function getRepository(string $className): \Doctrine\ORM\EntityRepository
    {
        return $this->em()->getRepository($className);
    }

    public function getCache(): ?\Doctrine\ORM\Cache
    {
        return $this->em()->getCache();
    }

    public function getConnection(): \Doctrine\DBAL\Connection
    {
        return $this->em()->getConnection();
    }

    public function getMetadataFactory(): \Doctrine\ORM\Mapping\ClassMetadataFactory
    {
        return $this->em()->getMetadataFactory();
    }

    public function getExpressionBuilder(): \Doctrine\ORM\Query\Expr
    {
        return $this->em()->getExpressionBuilder();
    }

    public function beginTransaction(): void
    {
        $this->em()->beginTransaction();
    }

    public function wrapInTransaction(callable $func): mixed
    {
        return $this->em()->wrapInTransaction($func);
    }

    public function commit(): void
    {
        $this->em()->commit();
    }

    public function rollback(): void
    {
        $this->em()->rollback();
    }

    public function createQuery(string $dql = ''): \Doctrine\ORM\Query
    {
        return $this->em()->createQuery($dql);
    }

    public function createNativeQuery(string $sql, \Doctrine\ORM\Query\ResultSetMapping $rsm): \Doctrine\ORM\NativeQuery
    {
        return $this->em()->createNativeQuery($sql, $rsm);
    }

    public function createQueryBuilder(): \Doctrine\ORM\QueryBuilder
    {
        return $this->em()->createQueryBuilder();
    }

    public function find(string $className, mixed $id, \Doctrine\DBAL\LockMode|int|null $lockMode = \Doctrine\DBAL\LockMode::NONE, ?int $lockVersion = NULL): ?object
    {
        return $this->em()->find($className, $id, $lockMode, $lockVersion);
    }

    public function refresh(object $object, \Doctrine\DBAL\LockMode|int|null $lockMode = \Doctrine\DBAL\LockMode::NONE): void
    {
        $this->em()->refresh($object, $lockMode);
    }

    public function getReference(string $entityName, mixed $id): ?object
    {
        return $this->em()->getReference($entityName, $id);
    }

    public function close(): void
    {
        $this->em()->close();
    }

    public function lock(object $entity, \Doctrine\DBAL\LockMode|int $lockMode, \DateTimeInterface|int|null $lockVersion = NULL): void
    {
        $this->em()->lock($entity, $lockMode, $lockVersion);
    }

    public function getEventManager(): \Doctrine\Common\EventManagerInterface
    {
        return $this->em()->getEventManager();
    }

    public function getConfiguration(): \Doctrine\ORM\Configuration
    {
        return $this->em()->getConfiguration();
    }

    public function isOpen(): bool
    {
        return $this->em()->isOpen();
    }

    public function getUnitOfWork(): \Doctrine\ORM\UnitOfWork
    {
        return $this->em()->getUnitOfWork();
    }

    public function newHydrator(string|int $hydrationMode): \Doctrine\ORM\Internal\Hydration\AbstractHydrator
    {
        return $this->em()->newHydrator($hydrationMode);
    }

    public function getProxyFactory(): \Doctrine\ORM\Proxy\ProxyFactory
    {
        return $this->em()->getProxyFactory();
    }

    public function getFilters(): \Doctrine\ORM\Query\FilterCollection
    {
        return $this->em()->getFilters();
    }

    public function isFiltersStateClean(): bool
    {
        return $this->em()->isFiltersStateClean();
    }

    public function hasFilters(): bool
    {
        return $this->em()->hasFilters();
    }

    public function getClassMetadata(string $className): \Doctrine\ORM\Mapping\ClassMetadata
    {
        return $this->em()->getClassMetadata($className);
    }

    public function persist(object $object): void
    {
        $this->em()->persist($object);
    }

    public function remove(object $object): void
    {
        $this->em()->remove($object);
    }

    public function clear(): void
    {
        $this->em()->clear();
    }

    public function detach(object $object): void
    {
        $this->em()->detach($object);
    }

    public function flush(): void
    {
        $this->em()->flush();
    }

    public function initializeObject(object $obj): void
    {
        $this->em()->initializeObject($obj);
    }

    public function isUninitializedObject(mixed $value): bool
    {
        return $this->em()->isUninitializedObject($value);
    }

    public function contains(object $object): bool
    {
        return $this->em()->contains($object);
    }
}
