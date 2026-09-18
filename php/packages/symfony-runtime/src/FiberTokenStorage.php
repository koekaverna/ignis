<?php

declare(strict_types=1);

namespace Ignis\Symfony;

use Ignis\Scope;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Contracts\Service\ResetInterface;

/**
 * One security token per fiber. Without it, two overlapping requests share one.
 *
 * Symfony's `TokenStorage` keeps the authenticated token in a private property of a **shared**
 * service, written once per request by the firewall and never restored. Under Ignis several
 * requests are in flight on one thread, so a request that parks on I/O resumes holding whoever
 * authenticated in the meantime: measured in V-68, 10 of 10, with `isGranted('ROLE_ADMIN')` going
 * from false to true inside a non-admin's request. Every `$request`-shaped check still passes —
 * `Request::getUser()` and `RequestStack::getCurrentRequest()` are already per fiber — which is
 * exactly why it is easy to miss.
 *
 * This is ADR-0011's own kill criterion ("a framework service that keeps request state outside
 * `RequestStack`"), and the answer is the same move ADR-0011 already makes for the request stack:
 * the state moves into `Ignis\Scope`, the fiber-switch slots of ADR-0006. No fourth mechanism.
 *
 * `Scope` is keyed by the fiber and fibers are reused, so the token would otherwise survive into
 * the *next* request on the same fiber; `Loop` clears the scope when a request ends (V-67).
 */
final class FiberTokenStorage implements TokenStorageInterface, ResetInterface
{
    public function __construct(private readonly string $key = 'security.token') {}

    public function getToken(): ?TokenInterface
    {
        $token = Scope::get($this->key);
        if ($token !== null && !$token instanceof TokenInterface) {
            throw new \LogicException('Ignis\\Symfony\\FiberTokenStorage: scope key "' . $this->key . '" holds something other than a security token');
        }

        return $token;
    }

    public function setToken(?TokenInterface $token): void
    {
        Scope::set($this->key, $token);
    }

    public function reset(): void
    {
        Scope::set($this->key, null);
    }
}
