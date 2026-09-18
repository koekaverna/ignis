<?php

declare(strict_types=1);

namespace Ignis\Symfony;

use Ignis\Scope;
use Symfony\Component\HttpFoundation\Exception\SessionNotFoundException;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Session\SessionInterface;

/**
 * One request stack per fiber (ADR-0011): interleaved requests never see each other's Request.
 *
 * Every method that reads the stack must be overridden — `RequestStack` keeps its own private array,
 * and an inherited method reads that one, which is always empty here (V-88). `resetRequestFormats()`
 * is the exception that stays inherited: it clears `Request::$formats`, a static that is thread-wide
 * for every fiber and cannot be scoped from here (`S-REQUEST-FORMATS`).
 */
final class FiberRequestStack extends RequestStack
{
    private const KEY = 'symfony.request_stack';

    /** @return list<Request> */
    private function stack(): array
    {
        $stack = Scope::get(self::KEY, []);
        if (!\is_array($stack)) {
            throw new \LogicException('Ignis\\Symfony\\FiberRequestStack: scope key "' . self::KEY . '" holds something other than an array');
        }
        $requests = [];
        foreach ($stack as $item) {
            if (!$item instanceof Request) {
                throw new \LogicException('Ignis\\Symfony\\FiberRequestStack: scope key "' . self::KEY . '" holds something other than a Request');
            }
            $requests[] = $item;
        }

        return $requests;
    }

    public function push(Request $request): void
    {
        $s = $this->stack();
        $s[] = $request;
        Scope::set(self::KEY, $s);
    }

    public function pop(): ?Request
    {
        $s = $this->stack();
        if ($s === []) {
            return null;
        }
        $r = array_pop($s);
        Scope::set(self::KEY, $s);
        return $r;
    }

    public function getCurrentRequest(): ?Request
    {
        $s = $this->stack();
        return $s === [] ? null : end($s);
    }

    public function getMainRequest(): ?Request
    {
        $s = $this->stack();
        return $s[0] ?? null;
    }

    public function getParentRequest(): ?Request
    {
        $s = $this->stack();
        return count($s) < 2 ? null : $s[count($s) - 2];
    }

    /** The session of this fiber's current request; `AbstractController::addFlash()` calls it. */
    public function getSession(): SessionInterface
    {
        $request = $this->getCurrentRequest();
        if ($request !== null && $request->hasSession()) {
            return $request->getSession();
        }

        throw new SessionNotFoundException();
    }
}
