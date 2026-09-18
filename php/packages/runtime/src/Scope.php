<?php

declare(strict_types=1);

namespace Ignis;

/**
 * Fiber-scoped storage (ADR-0006): values live exactly as long as the fiber that set them.
 * In {main} (no fiber) a plain static array is used.
 */
final class Scope
{
    /** @var null|\WeakMap<object, array<string, mixed>> */
    private static ?\WeakMap $map = null;
    /** @var array<string, mixed> the {main} bag, for code running outside any fiber */
    private static array $main = [];

    public static function set(string $key, mixed $value): void
    {
        $fiber = \Fiber::getCurrent();
        if ($fiber === null) {
            self::$main[$key] = $value;
            return;
        }
        self::$map ??= new \WeakMap();
        $bag = self::$map[$fiber] ?? [];
        $bag[$key] = $value;
        self::$map[$fiber] = $bag;
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        $fiber = \Fiber::getCurrent();
        if ($fiber === null) {
            return self::$main[$key] ?? $default;
        }
        return (self::$map?->offsetExists($fiber) ? self::$map[$fiber] : [])[$key] ?? $default;
    }

    /**
     * Drops everything this fiber holds. Called once per request (`admitRequest`), because a fiber
     * outlives the request that used it: the loop keeps parked fibers and hands them to the next
     * request, so without this "per fiber" silently means "per fiber, forever" — measured in V-67,
     * where request n+1 read request n's value on the same fiber id. Anything holding a resource
     * releases through its destructor when the reference goes — and a resource caught in a reference
     * cycle does not, which is why `ignis/doctrine` keeps its connection behind a handle that is in
     * none (V-85).
     */
    public static function clear(): void
    {
        $fiber = \Fiber::getCurrent();
        if ($fiber === null) {
            self::$main = [];
            return;
        }
        if (self::$map?->offsetExists($fiber)) {
            self::$map[$fiber] = [];
        }
    }
}
