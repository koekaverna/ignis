<?php

declare(strict_types=1);

namespace Ignis\Symfony;

use Symfony\Component\DependencyInjection\ServicesResetterInterface;

/**
 * The service reset, in fiber mode: nothing, because a fiber's state dies with its scope.
 *
 * Symfony's `ServicesResetter` empties every resettable service at once, and `Kernel::handle()`
 * drives it: `handle()` arms `resetServices` and counts itself in `requestStackSize`, and the **next**
 * `handle()` calls `boot()`, which resets — but only while that counter is zero. A request inside
 * `handle()` is therefore safe, and everything a request does *after* `handle()` returns is not: a
 * `StreamedResponse` body is produced by the loop later, and `kernel.terminate` listeners park on
 * their own I/O. Measured on the E21 fixture: a streaming request lost its service state to a
 * concurrent one in **3 of 3** rounds.
 *
 * So the resetter is not merely unnecessary here, it is wrong — it reaches across requests. What
 * replaces it is `Ignis\Scope`: per-request state lives on the fiber and `Scope::clear()` drops it at
 * request end (V-67, V-68, V-69, V-85), which is per request rather than per lull in the traffic.
 *
 * A service that accumulates state and is **not** fiber-scoped loses its cleanup by this change, and
 * that is a real cost rather than a free win. `reset()` says so once, in debug, naming the services,
 * because the alternative is a leak nobody is told about. Classic mode keeps Symfony's own resetter:
 * there requests do not overlap and the reset is correct as written.
 */
final class FiberServicesResetter implements ServicesResetterInterface
{
    private bool $reported = false;

    /** @param list<string> $declined the resettable service ids this will not reset */
    public function __construct(private readonly array $declined, private readonly bool $debug) {}

    public function reset(): void
    {
        if (!$this->debug || $this->reported || $this->declined === []) {
            return;
        }
        $this->reported = true;
        error_log(\sprintf(
            'ignis: service reset is disabled in fiber mode (a reset crosses requests: it would clear services a '
            . 'streaming or terminating fiber is still using). %d service(s) keep whatever state they accumulate '
            . 'unless they are fiber-scoped: %s',
            \count($this->declined),
            implode(', ', $this->declined),
        ));
    }
}
