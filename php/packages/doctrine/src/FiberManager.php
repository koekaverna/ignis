<?php

declare(strict_types=1);

namespace Ignis\Doctrine;

use Doctrine\ORM\EntityManagerInterface;

/**
 * The fiber's EntityManager, plus the one thing the manager cannot do for itself: give its
 * connection back at a time anyone can predict.
 *
 * `Scope`'s contract is that a resource "releases through its destructor when the reference goes"
 * (`Scope::clear()`), and for Doctrine that never happens: `EntityManager` and `UnitOfWork` hold
 * each other, so dropping the last outside reference leaves a cycle that only a collection can
 * free — and a collection is scheduled by root-buffer pressure, not by the request boundary.
 * Measured with the manager stored directly: 30 sequential requests left 8 PostgreSQL backends open
 * with the loop collector and 31 with PHP's own, against a stock `max_connections` of 100 (V-85).
 *
 * This handle is in no cycle. Nothing but the fiber's scope bag references it, so `Scope::clear()`
 * at request end takes its refcount to zero and `__destruct` runs there and then.
 */
final class FiberManager
{
    private bool $released = false;

    public function __construct(public readonly EntityManagerInterface $manager) {}

    public function __destruct()
    {
        $this->release();
    }

    /**
     * Rolls back whatever the request left open and closes the connection. Idempotent: the Symfony
     * resetter may call it through `FiberEntityManager::reset()` before the destructor does.
     */
    public function release(): void
    {
        if ($this->released) {
            return;
        }
        $this->released = true;

        try {
            $connection = $this->manager->getConnection();
            while ($connection->isTransactionActive()) {
                $connection->rollBack();
            }
            $connection->close();
        } catch (\Throwable) {
            // A connection that is already gone needs no rollback and no closing.
        }
        if ($this->manager->isOpen()) {
            $this->manager->clear();
        }
    }
}
