<?php

declare(strict_types=1);

/**
 * A fake of the five reactor primitives the userland scheduler cannot run without, so that
 * Ignis\Loop can be unit-tested under a plain PHP CLI with no ignis binary and no sockets.
 *
 * What it bounds: the E-suites (E1/E2/E5/E7/E11/E23) are the contract. The fake only exercises
 * userland bookkeeping — fiber reuse, waiter maps, the settle order — against a simulated clock.
 * If a fake-based test and an E-suite ever disagree, the E-suite is right and the fake is the bug.
 *
 * Every function is guarded with function_exists(), so requiring this file inside the real binary
 * (or after packages/runtime/stubs/ignis.php) changes nothing. That is also why tests/bootstrap.php
 * requires it *before* the composer autoloader: the stubs are autoload-dev files whose bodies throw,
 * and whoever declares a name first wins.
 *
 * ignis_set_superglobals() and ignis_cancel_parked_any() are faked for that reason and no other:
 * the runtime calls them behind function_exists(), the stub makes that guard true, and the stub
 * body then throws inside a request fiber — where Loop::poolBody() rejects a Future nobody reads,
 * so the request just disappears. Any stub function the runtime guards on has to be faked here.
 *
 * ignis_scope_allocate() and ignis_scope_rows_clear() are faked too, but for the opposite reason:
 * Ignis\Scope::create() and Ignis\Scope::clear() call them with no function_exists() guard at all
 * (S-SCOPED-CLASS, BACKLOG.md), because under the real binary they always exist. Loop::releaseRequest()
 * calls Scope::clear() at the end of every request LoopTest.php drives, so without a fake here every
 * Loop test would hit the throwing stub in packages/runtime/stubs/ignis.php, not just ScopeTest.
 * The fake cannot reproduce per-scope property storage — that is engine territory — so it only
 * approximates the one behaviour userland tests can observe from outside: ignis_scope_allocate()
 * never runs $class's constructor, which ReflectionClass::newInstanceWithoutConstructor() gives for
 * free, and it records call order so a test can prove allocate happens before construct.
 */

namespace Ignis\Tests {
    /** Raised by the fake ignis_poll() when nothing is left in flight, to end Ignis\Loop::serve(). */
    final class StopLoop extends \Error {}

    final class FakeReactor
    {
        private static int $nextOperationId = 1;
        private static int $clockMilliseconds = 0;

        /** @var array<int, int> operation id => the simulated millisecond it comes due */
        private static array $timers = [];

        /** @var array<int, mixed> operation id => payload, injected by a test */
        private static array $injected = [];

        /** @var list<array{id: int, status: int, headers: array<string, string>, body: string}> */
        private static array $responses = [];

        /** @var list<string> 'allocate:'/'construct:' markers, in call order, for scope tests */
        private static array $scopeEvents = [];
        private static int $scopeRowsCleared = 0;

        public static function reset(): void
        {
            self::$nextOperationId = 1;
            self::$clockMilliseconds = 0;
            self::$timers = [];
            self::$injected = [];
            self::$responses = [];
            self::$scopeEvents = [];
            self::$scopeRowsCleared = 0;
        }

        public static function clockMilliseconds(): int
        {
            return self::$clockMilliseconds;
        }

        public static function submitTimer(int $milliseconds): int
        {
            $operationId = self::$nextOperationId++;
            self::$timers[$operationId] = self::$clockMilliseconds + $milliseconds;

            return $operationId;
        }

        /**
         * Calls off a pending timer, as `Op::CancelWatch` does, and returns the cancel's own id —
         * the real one completes both, which is why the caller must forget the target first.
         */
        public static function cancelTimer(int $operationId): int
        {
            unset(self::$timers[$operationId], self::$injected[$operationId]);

            return self::$nextOperationId++;
        }

        /** Make ignis_poll() hand $payload back for $operationId on its next call. */
        public static function inject(int $operationId, mixed $payload): void
        {
            self::$injected[$operationId] = $payload;
        }

        /** @return array<int, mixed> */
        public static function poll(): array
        {
            if (self::$injected !== []) {
                $completions = self::$injected;
                self::$injected = [];

                return $completions;
            }
            if (self::$timers === []) {
                throw new StopLoop('fake reactor: nothing in flight');
            }
            self::$clockMilliseconds = min(self::$timers);

            return self::dueTimers();
        }

        public static function inflight(): int
        {
            return \count(self::$timers) + \count(self::$injected);
        }

        /** @param array<string, string> $headers */
        public static function recordResponse(int $operationId, int $status, array $headers, string $body): void
        {
            self::$responses[] = ['id' => $operationId, 'status' => $status, 'headers' => $headers, 'body' => $body];
        }

        /** @return list<array{id: int, status: int, headers: array<string, string>, body: string}> */
        public static function responses(): array
        {
            return self::$responses;
        }

        /**
     * The real one promotes what the constructor wrote into row zero, wherever it ran. A pure-PHP
     * fake has no per-scope storage to promote into, so it records the call: what userland tests
     * can observe is that `Scope::create()` seals **after** constructing, never before.
     */
    public static function sealScope(object $instance): void
    {
        self::$scopeEvents[] = 'seal:' . $instance::class;
    }

    public static function allocateScope(string $class): object
        {
            self::$scopeEvents[] = 'allocate:' . $class;

            return (new \ReflectionClass($class))->newInstanceWithoutConstructor();
        }

        /** A test's own subject class records its own 'construct:...' marker; this is that hook. */
        public static function recordScopeEvent(string $event): void
        {
            self::$scopeEvents[] = $event;
        }

        /** @return list<string> */
        public static function scopeEvents(): array
        {
            return self::$scopeEvents;
        }

        public static function clearScopeRows(): void
        {
            ++self::$scopeRowsCleared;
        }

        public static function scopeRowsCleared(): int
        {
            return self::$scopeRowsCleared;
        }

        /** @return array<int, null> */
        private static function dueTimers(): array
        {
            $due = [];
            foreach (self::$timers as $operationId => $dueAtMilliseconds) {
                if ($dueAtMilliseconds <= self::$clockMilliseconds) {
                    $due[$operationId] = null;
                    unset(self::$timers[$operationId]);
                }
            }

            return $due;
        }
    }
}

namespace {
    if (!function_exists('ignis_submit_sleep')) {
        function ignis_submit_sleep(int $ms): int
        {
            return Ignis\Tests\FakeReactor::submitTimer($ms);
        }
    }

    if (!function_exists('ignis_poll')) {
        /** @return array<int, mixed> */
        function ignis_poll(int $timeout_ms): array
        {
            return Ignis\Tests\FakeReactor::poll();
        }
    }

    if (!function_exists('ignis_cancel')) {
        function ignis_cancel(int $target): int
        {
            return Ignis\Tests\FakeReactor::cancelTimer($target);
        }
    }

    if (!function_exists('ignis_inflight')) {
        function ignis_inflight(): int
        {
            return Ignis\Tests\FakeReactor::inflight();
        }
    }

    if (!function_exists('ignis_serve')) {
        function ignis_serve(string $addr): bool
        {
            return true;
        }
    }

    if (!function_exists('ignis_set_superglobals')) {
        /**
         * @param array<string, string> $server
         * @param array<string, string> $get
         * @param array<string, string> $post
         * @param array<string, string> $cookie
         */
        function ignis_set_superglobals(array $server, array $get, array $post, array $cookie): void {}
    }

    if (!function_exists('ignis_cancel_parked_any')) {
        /** @param \Fiber<mixed, mixed, mixed, mixed> $fiber */
        function ignis_cancel_parked_any(\Fiber $fiber, \Throwable $exception): bool
        {
            return false;
        }
    }

    if (!function_exists('ignis_respond')) {
        /** @param array<string, string> $headers */
        function ignis_respond(int $id, int $status, array $headers, string $body): bool
        {
            Ignis\Tests\FakeReactor::recordResponse($id, $status, $headers, $body);

            return true;
        }
    }

    if (!function_exists('ignis_scope_allocate')) {
        function ignis_scope_allocate(string $class): object
        {
            return Ignis\Tests\FakeReactor::allocateScope($class);
        }
    }

    if (!function_exists('ignis_scope_seal')) {
        function ignis_scope_seal(object $instance): void
        {
            Ignis\Tests\FakeReactor::sealScope($instance);
        }
    }

    if (!function_exists('ignis_scope_rows_clear')) {
        function ignis_scope_rows_clear(): void
        {
            Ignis\Tests\FakeReactor::clearScopeRows();
        }
    }
}
