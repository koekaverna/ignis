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

    /**
     * Builds a fiber-scoped instance of $class (BACKLOG.md S-SCOPED-CLASS, DECISIONS.md 2026-09-20):
     * `ignis_scope_allocate()` hands back an object whose declared property slots are `IS_UNDEF` and
     * whose handlers already address this fiber's row, so the constructor call that follows writes
     * through those handlers instead of through the object's own slots. Allocating first is the whole
     * point — a plain `new $class(...)` here would construct through the VM's ordinary fast path and
     * arm its property-offset cache before the handlers are ever in the picture, which nothing after
     * that point can undo.
     */
    public static function create(string $class, mixed ...$arguments): object
    {
        $instance = \ignis_scope_allocate($class);
        (new \ReflectionMethod($instance, '__construct'))->invoke($instance, ...$arguments);

        return $instance;
    }

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
     *
     * The guard is not style. This call sits in `releaseRequest()`'s `finally`, whose throw lands on a
     * Future `spawn()` discards, and unobserved rejections are only reported when the loop ends —
     * which on a server is never. So an absent function here is silent, and what it silently skips is
     * `--$inflightRequests`: the budget fills and the server refuses every request after the
     * `IGNIS_FIBER_BUDGET`-th one, for ever. Measured 2026-09-20 at budget 4: four 200s, then 503 to
     * everything. It comes out when the engine half defines the function.
     *
     * Also drops this fiber's `Scope::create()`-allocated property rows (`ignis_scope_rows_clear()`),
     * for the identical reason: a scoped object built through `create()` lives in the same per-fiber
     * storage the key-value bag above does, and without this a pooled fiber's next request would read
     * the previous request's property values through the class's own handlers.
     */
    public static function clear(): void
    {
        $fiber = \Fiber::getCurrent();
        if ($fiber === null) {
            self::$main = [];
        } elseif (self::$map?->offsetExists($fiber)) {
            self::$map[$fiber] = [];
        }
        if (\function_exists('ignis_scope_rows_clear')) {
            \ignis_scope_rows_clear();
        }
    }
}
