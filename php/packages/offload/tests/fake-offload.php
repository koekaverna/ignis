<?php

declare(strict_types=1);

/**
 * The offload module functions, faked the way `tests/fake-reactor.php` fakes the reactor: enough
 * bookkeeping to unit-test `Ignis\Offload\Router`, `Client` and `WorkerRuntime` under php-cli.
 *
 * The bound is the same one the fake reactor states — E16 against the real binary is the contract,
 * this only exercises userland wiring (wrap/unwrap, affinity, proxy generation, handle tables).
 * Every declaration is guarded, so requiring it inside the binary changes nothing.
 */

namespace Ignis\Tests\Offload {
    final class FakeOffload
    {
        public static int $workers = 2;
        private static int $nextOp = 1000;
        /** @var list<array{fn: string, args: string, affinity: int, op: int}> */
        public static array $submitted = [];
        /** @var list<array{job: int, cb: int, args: string}> */
        public static array $callbacks = [];
        /** @var list<array{job: int, seq: int, result: string}> */
        public static array $callbackResults = [];
        /** @var list<array{job: int, result: string}> answers the worker loop handed back */
        public static array $done = [];
        /** Serialized payload the next ignis_offload_callback() hands back. */
        public static string $callbackAnswer = 'b:1;';
        public static bool $callbackFails = false;
        /** No `--offload N` behind the runtime: every submission is refused. */
        public static bool $noPool = false;
        public static ?bool $routing = null;
        public static int $passed = 0;

        public static function reset(): void
        {
            self::$workers = 2;
            self::$nextOp = 1000;
            self::$submitted = [];
            self::$callbacks = [];
            self::$callbackResults = [];
            self::$done = [];
            self::$callbackAnswer = serialize(['ok' => null]);
            self::$callbackFails = false;
            self::$noPool = false;
            self::$routing = null;
            self::$passed = 0;
        }

        public static function submit(string $fn, string $args, int $affinity): int
        {
            $op = self::$nextOp++;
            self::$submitted[] = ['fn' => $fn, 'args' => $args, 'affinity' => $affinity, 'op' => $op];

            return $op;
        }
    }
}

namespace {
    use Ignis\Tests\Offload\FakeOffload;

    if (!function_exists('ignis_offload_submit')) {
        function ignis_offload_submit(string $fn, string $serializedArgs, int $affinity = -1): int|false
        {
            return FakeOffload::$noPool ? false : FakeOffload::submit($fn, $serializedArgs, $affinity);
        }
    }

    if (!function_exists('ignis_offload_stats')) {
        /** @return array<string, int> */
        function ignis_offload_stats(): array
        {
            return ['workers' => FakeOffload::$workers, 'this' => 0];
        }
    }

    if (!function_exists('ignis_offload_callback')) {
        function ignis_offload_callback(int $job, int $cb, string $serializedArgs): string|false
        {
            FakeOffload::$callbacks[] = ['job' => $job, 'cb' => $cb, 'args' => $serializedArgs];

            return FakeOffload::$callbackFails ? false : FakeOffload::$callbackAnswer;
        }
    }

    if (!function_exists('ignis_offload_cb_result')) {
        function ignis_offload_cb_result(int $job, int $seq, string $serializedResult): bool
        {
            FakeOffload::$callbackResults[] = ['job' => $job, 'seq' => $seq, 'result' => $serializedResult];

            return true;
        }
    }

    if (!function_exists('ignis_offload_done')) {
        function ignis_offload_done(int $job, string $serializedResult): bool
        {
            FakeOffload::$done[] = ['job' => $job, 'result' => $serializedResult];

            return true;
        }
    }

    if (!function_exists('ignis_route_enable')) {
        function ignis_route_enable(bool $on): void
        {
            FakeOffload::$routing = $on;
        }
    }

    if (!function_exists('ignis_route_pass')) {
        function ignis_route_pass(): void
        {
            ++FakeOffload::$passed;
        }
    }
}
