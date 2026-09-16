<?php
/**
 * Caller side of the offload pool (E16, ADR-0016): Ignis\offload(fn, ...args) runs a NAMED function
 * on a synchronous worker thread; scalar/array arguments are copied in (serialize), the result is
 * copied back; the calling fiber parks meanwhile. Closures among the arguments become callbacks
 * that run on this thread when the worker invokes them.
 */
declare(strict_types=1);

namespace Ignis\Offload {
    use Ignis\Loop;

    if (!class_exists(CallbackRef::class, false)) {
        final class CallbackRef
        {
            public function __construct(public readonly int $id)
            {
            }
        }
    }
    if (!class_exists(RemoteException::class, false)) {
        final class RemoteException extends \RuntimeException
        {
            public function __construct(public readonly string $remoteClass, string $message, int $code, public readonly string $remoteTrace = '')
            {
                parent::__construct($message, $code);
            }
        }
    }

    final class Client
    {
        /** @var array<int, array<int, \Closure>> job id => callback id => closure */
        private static array $callbacks = [];
        private static int $nextCb = 1;
        private static bool $hooked = false;
        public static int $callbacksRun = 0;

        public static function call(string $fn, array $args, ?int $affinity = null): mixed
        {
            self::hook();
            $cbs = [];
            $args = self::extractCallbacks($args, $cbs);
            $ser = serialize($args);
            $op = \ignis_offload_submit($fn, $ser, $affinity ?? -1);
            if (!\is_int($op)) {
                throw new \RuntimeException('offload: no pool (start ignis with --offload N)');
            }
            if ($cbs !== []) {
                self::$callbacks[$op] = $cbs; // keyed by op: the callback payload carries the job's op? no: the job id
            }
            $r = Loop::awaitOp($op);
            unset(self::$callbacks[$op]);
            if (\is_array($r) && ($r['kind'] ?? '') === 'error') {
                throw new \RuntimeException('offload: ' . $r['message']);
            }
            $u = unserialize($r, ['allowed_classes' => true]);
            if (isset($u['err'])) {
                throw new RemoteException($u['err'][0], $u['err'][1], (int) $u['err'][2], $u['err'][3] ?? '');
            }
            return $u['ok'] ?? null;
        }

        /** Closures anywhere in $args become CallbackRef; the closures are kept per call. */
        private static function extractCallbacks(mixed $v, array &$cbs): mixed
        {
            if ($v instanceof \Closure) {
                $id = self::$nextCb++;
                $cbs[$id] = $v;
                self::$pending[$id] = $v;
                return new CallbackRef($id);
            }
            if (\is_array($v)) {
                foreach ($v as $k => $x) {
                    $v[$k] = self::extractCallbacks($x, $cbs);
                }
            }
            return $v;
        }

        /** @var array<int, \Closure> callback id => closure (ids are unique per process) */
        private static array $pending = [];

        private static function hook(): void
        {
            if (self::$hooked) {
                return;
            }
            self::$hooked = true;
            Loop::$offloadCallbackHandler = static function (array $p): void {
                // Run the closure in its own fiber so it may itself await; answer when it returns.
                Loop::spawn(static function () use ($p): void {
                    $cb = self::$pending[(int) $p['cb']] ?? null;
                    try {
                        if ($cb === null) {
                            throw new \RuntimeException('unknown callback ' . $p['cb']);
                        }
                        $args = unserialize($p['args'], ['allowed_classes' => true]);
                        $out = serialize(['ok' => $cb(...$args)]);
                    } catch (\Throwable $e) {
                        $out = serialize(['err' => [$e::class, $e->getMessage(), $e->getCode()]]);
                    }
                    self::$callbacksRun++;
                    \ignis_offload_cb_result((int) $p['job'], (int) $p['seq'], $out);
                });
            };
        }

        public static function stats(): array
        {
            return \ignis_offload_stats();
        }
    }
}

namespace Ignis {
    /** Run a named function (or "Class::method") on the offload pool; the current fiber parks. */
    function offload(string $fn, mixed ...$args): mixed
    {
        return Offload\Client::call($fn, $args);
    }
}
