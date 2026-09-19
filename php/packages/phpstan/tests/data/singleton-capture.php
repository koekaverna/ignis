<?php

declare(strict_types=1);

namespace Ignis\PHPStan\Tests\Data;

use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

/** The shape the rule exists for: a singleton that captures the request it was first built in. */
final class CapturesTheRequest
{
    private ?Request $request;
    private object $connection;

    public function __construct(private readonly RequestStack $requests, private readonly EntityManagerInterface $manager)
    {
        $this->request = $this->requests->getCurrentRequest();
        $this->connection = $this->manager->getConnection();
    }

    public function later(): void
    {
        $this->request = $this->requests->getMainRequest();
    }
}

/** The supported shape: keep the façade, call it every time. Nothing here is flagged. */
final class KeepsTheFacade
{
    public function __construct(private readonly RequestStack $requests) {}

    public function tag(): ?string
    {
        return $this->requests->getCurrentRequest()?->query->get('tag');
    }

    /** A local is fine: it dies with the call. */
    public function alsoFine(): ?string
    {
        $request = $this->requests->getCurrentRequest();

        return $request?->getPathInfo();
    }
}

/** Worse than an instance property: a static outlives every fiber on the thread. */
final class PutsItInAStatic
{
    private static ?Request $shared = null;

    public static function remember(RequestStack $requests): void
    {
        self::$shared = $requests->getCurrentRequest();
    }
}

/** The shape that provoked the rule: what gets stored is derived from the request, not the request. */
final class CapturesSomethingDerived
{
    private ?string $locale = null;
    private string $connectionHash = '';

    public function __construct(private readonly RequestStack $requests, private readonly EntityManagerInterface $manager) {}

    public function capture(): void
    {
        $this->locale = $this->requests->getCurrentRequest()?->getLocale();
        $this->connectionHash = spl_object_hash($this->manager->getConnection());
    }
}
