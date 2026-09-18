<?php

declare(strict_types=1);

namespace Ignis\Symfony;

use Ignis\Scope;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

/** One request stack per fiber (ADR-0011): interleaved requests never see each other's Request. */
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
}
