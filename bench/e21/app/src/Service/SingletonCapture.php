<?php
declare(strict_types=1);

namespace App\Service;

use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * A singleton that holds fiber-scoped services, and one that holds values taken out of them.
 *
 * The two are not the same hazard and this exists to tell them apart. `FiberRequestStack` and
 * `FiberEntityManager` are singleton **façades**: every method reads `Ignis\Scope`, so a service
 * holding one resolves per fiber and that is the supported shape (V-16, V-68, V-69). What pins a
 * singleton to one request is taking a *value* out of such a façade and keeping it — here, the
 * `Request` and the `Connection`, captured in the constructor, which runs once for the whole process.
 */
final class SingletonCapture
{
    private ?string $capturedTag;
    private ?string $capturedConnection;

    public function __construct(
        private readonly RequestStack $requests,
        private readonly EntityManagerInterface $manager,
    ) {
        $this->capturedTag = $this->requests->getCurrentRequest()?->query->get('tag');
        $this->capturedConnection = spl_object_hash($this->manager->getConnection());
    }

    /** Through the façade, every call: what this request's stack holds now. */
    public function liveTag(): ?string
    {
        return $this->requests->getCurrentRequest()?->query->get('tag');
    }

    /** Captured once, in the constructor: whichever request happened to build this service. */
    public function capturedTag(): ?string
    {
        return $this->capturedTag;
    }

    public function liveConnection(): string
    {
        return spl_object_hash($this->manager->getConnection());
    }

    public function capturedConnection(): ?string
    {
        return $this->capturedConnection;
    }
}
