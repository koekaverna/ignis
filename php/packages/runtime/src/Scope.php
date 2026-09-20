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
     *
     * Sealing afterwards is what makes "what the constructor stores is process-wide" true wherever the
     * service was built. A container builds services lazily, inside the request that first asks for
     * one, and without the seal that request's scope would keep the dependencies to itself: measured,
     * a second fiber read a `readonly string` as `''`.
     *
     * A class with no constructor is ordinary and must work: `ReflectionMethod` throws on one, so
     * the call is guarded. Arguments passed to such a class are an error rather than a silent
     * discard, because `new` would refuse them too and this must not be quieter than `new`.
     */
    public static function create(string $class, mixed ...$arguments): object
    {
        $instance = \ignis_scope_allocate($class);
        if (\method_exists($instance, '__construct')) {
            (new \ReflectionMethod($instance, '__construct'))->invoke($instance, ...$arguments);
        } elseif ($arguments !== []) {
            throw new \ArgumentCountError(\sprintf('Ignis\Scope::create(): %s has no constructor, so it takes no arguments, %d given', $class, \count($arguments)));
        }
        self::seal($instance);

        return $instance;
    }

    /**
     * Promotes what this fiber currently holds for $instance into the process-wide row every other
     * fiber inherits from. `create()` calls it once, for the constructor; anything that configures an
     * object *after* its constructor has to call it again, because the seal captures a moment and not
     * a phase.
     *
     * That second call is not theoretical: Symfony configures services with `addMethodCall`, which
     * the compiled container emits after the factory, so `security.logout_url_generator` reached its
     * `registerListener()` calls already sealed and filed both firewalls into the scope of whichever
     * request happened to build it. Every other request then read an empty `$listeners` and Symfony
     * threw `Unable to find logout in the current firewall` — measured 6/6 on the E21 fixture before
     * `FiberScopePass` started setting this as the definition's configurator.
     *
     * Sealing twice is sound rather than merely tolerated: the seal reads the object's own slots,
     * which hold the current scope's values, takes its own reference to each, and releases what row
     * zero held before.
     */
    public static function seal(object $instance): void
    {
        \ignis_scope_seal($instance);
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
        \ignis_scope_rows_clear();
    }
}
