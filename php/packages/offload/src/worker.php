<?php

/**
 * Offload worker loop (E16, ADR-0016). Runs on a synchronous PHP thread with its own TSRM context;
 * embedded into the ignis binary and evaluated on every offload thread. IGNIS_OFFLOAD_PRELUDE
 * (a file) is required first so the functions named in jobs exist here.
 */
declare(strict_types=1);

namespace Ignis\Offload;

/**
 * Serializable stand-in for a caller-side closure; the worker turns it into a stub that calls back.
 *
 * Guarded like the identical pair in `ignis-offload.php`, and for the same reason: a worker thread
 * evaluates this file with nothing else loaded, but anything that has already required the caller
 * side (a prelude, a test) would otherwise hit "cannot redeclare".
 */
if (!class_exists(CallbackRef::class, false)) {
    final class CallbackRef
    {
        public function __construct(public readonly int $id) {}
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

final class WorkerRuntime
{
    public static int $job = 0;

    /** Replace CallbackRef values (any depth) by closures that call back into the calling thread. */
    public static function bindCallbacks(mixed $v): mixed
    {
        if ($v instanceof CallbackRef) {
            $id = $v->id;
            return static function (mixed ...$args) use ($id): mixed {
                $answer = \ignis_offload_callback(self::$job, $id, serialize(self::registerObjects($args)));
                if ($answer === false) {
                    throw new \RuntimeException('offload callback failed (caller gone)');
                }
                return self::unpackCallbackAnswer($answer);
            };
        }
        if (\is_array($v)) {
            foreach ($v as $k => $x) {
                $v[$k] = self::bindCallbacks($x);
            }
        }
        return $v;
    }

    /** Rebuilds the caller's answer to a callback, after validating the envelope it serialized. */
    private static function unpackCallbackAnswer(string $answer): mixed
    {
        $unpacked = unserialize($answer, ['allowed_classes' => true]);
        if (!\is_array($unpacked)) {
            throw new \RuntimeException('offload: malformed callback answer');
        }
        if (isset($unpacked['err'])) {
            $error = $unpacked['err'];
            if (!\is_array($error)) {
                throw new \RuntimeException('offload: malformed callback error');
            }
            $remoteClass = $error[0] ?? null;
            $message = $error[1] ?? null;
            if (!\is_string($remoteClass) || !\is_string($message)) {
                throw new \RuntimeException('offload: malformed callback error');
            }
            $code = $error[2] ?? 0;
            throw new RemoteException($remoteClass, 'callback threw: ' . $message, \is_scalar($code) ? (int) $code : 0);
        }
        return $unpacked['ok'] ?? null;
    }

    /** @var array<int, object> remote objects held for proxies on the fiber threads */
    private static array $handles = [];
    private static int $nextHandle = 1;
    private const ROUTABLE = ['CurlHandle', 'CurlMultiHandle', 'CurlShareHandle', 'PDO', 'PDOStatement', 'SQLite3', 'SQLite3Stmt', 'SQLite3Result'];

    /**
     * Auto-routed call from a fiber thread: "fn:name" / "new:Class" / "method:name" / "free".
     *
     * The name is data that arrived from another thread, so `fn:` and `new:` are answered only for
     * the names the runtime itself routes — anything else reaching this channel is a bug or worse.
     *
     * @param array<int|string, mixed> $args
     */
    public static function routed(string $what, array $args): mixed
    {
        if ($what === 'free') {
            self::free($args[0] ?? null);
            return null;
        }
        $args = self::resolveArgumentRefs($args);
        [$kind, $name] = explode(':', $what, 2);
        $result = match ($kind) {
            'fn' => self::callFunction($name, $args),
            'new' => self::construct($name, $args),
            'method' => self::callMethod(array_shift($args), $name, $args),
            default => throw new \InvalidArgumentException("bad routed call $what"),
        };
        return self::registerObjects($result);
    }

    /** @param array<int|string, mixed> $args */
    private static function callFunction(string $function, array $args): mixed
    {
        if (!\in_array($function, self::routedNames('IGNIS_OFFLOAD_FUNCTIONS', ''), true) || !\is_callable($function)) {
            throw new \InvalidArgumentException("offload: $function is not a routed function");
        }
        return $function(...$args);
    }

    /** @param array<int|string, mixed> $args */
    private static function construct(string $class, array $args): object
    {
        if (!\in_array($class, self::routedNames('IGNIS_OFFLOAD_CLASSES', 'SQLite3'), true) || !class_exists($class)) {
            throw new \InvalidArgumentException("offload: $class is not a routed class");
        }
        return new $class(...$args);
    }

    /**
     * What `route.rs` installed its trampolines for: same variables, same defaults, read here so a
     * worker accepts exactly the names the runtime is configured to send it.
     *
     * @return list<string>
     */
    private static function routedNames(string $variable, string $fallback): array
    {
        $configured = getenv($variable);
        return array_values(array_filter(array_map('trim', explode(',', $configured ?: $fallback))));
    }

    /** @param array<int|string, mixed> $args */
    private static function callMethod(mixed $obj, string $method, array $args): mixed
    {
        if (!\is_object($obj)) {
            throw new \RuntimeException("routed method $method on a non-object");
        }
        return $obj->$method(...$args);
    }

    /**
     * @param array<int|string, mixed> $args
     * @return array<int|string, mixed>
     */
    private static function resolveArgumentRefs(array $args): array
    {
        foreach ($args as $key => $value) {
            $args[$key] = self::resolveRefs($value);
        }
        return $args;
    }

    /** ['__ref' => [worker, id, class]] → the real object held here; unknown ids are an error. */
    private static function resolveRefs(mixed $v): mixed
    {
        if (\is_array($v)) {
            if (isset($v['__ref']) && \count($v) === 1) {
                return self::resolveHandle($v['__ref']);
            }
            foreach ($v as $k => $x) {
                $v[$k] = self::resolveRefs($x);
            }
        }
        return $v;
    }

    /** @param mixed $reference the wire tuple `[worker, id, class]` naming a handle held here */
    private static function resolveHandle(mixed $reference): object
    {
        $id = \is_array($reference) ? ($reference[1] ?? null) : null;
        if (!\is_int($id)) {
            throw new \RuntimeException('offload: malformed handle reference');
        }
        if (!isset(self::$handles[$id])) {
            throw new \RuntimeException("offload: unknown handle $id on this worker");
        }
        return self::$handles[$id];
    }

    /** @param mixed $reference the wire tuple `['__ref' => [worker, id, class]]` naming the handle to drop */
    private static function free(mixed $reference): void
    {
        $ref = \is_array($reference) ? ($reference['__ref'] ?? null) : null;
        $id = \is_array($ref) ? ($ref[1] ?? null) : null;
        if (!\is_int($id)) {
            throw new \RuntimeException('offload: malformed free request');
        }
        unset(self::$handles[$id]);
    }

    /** Objects of routable classes stay here; the caller gets a reference. */
    private static function registerObjects(mixed $v): mixed
    {
        if (\is_object($v)) {
            $class = $v::class;
            if (\in_array($class, self::ROUTABLE, true) || $v instanceof \PDO || $v instanceof \PDOStatement) {
                $id = self::$nextHandle++;
                self::$handles[$id] = $v;
                return ['__ref' => [self::$worker, $id, $v instanceof \PDOStatement ? 'PDOStatement' : ($v instanceof \PDO ? 'PDO' : $class)]];
            }
        }
        if (\is_array($v)) {
            foreach ($v as $k => $x) {
                $v[$k] = self::registerObjects($x);
            }
        }
        return $v;
    }

    public static int $worker = 0;

    public static function run(): void
    {
        $prelude = getenv('IGNIS_OFFLOAD_PRELUDE');
        if ($prelude !== false && $prelude !== '') {
            require_once $prelude;
        }
        self::$worker = (int) (\ignis_offload_stats()['this'] ?? 0);
        while (($job = \ignis_offload_next()) !== null) {
            [$id, $fn, $argsSer] = $job;
            self::$job = $id;
            try {
                $args = self::bindCallbacks(unserialize($argsSer, ['allowed_classes' => true]));
                $callable = str_contains($fn, '::') ? explode('::', $fn, 2) : $fn;
                $result = $callable(...$args);
                $out = serialize(['ok' => $result]);
            } catch (\Throwable $e) {
                $out = serialize(['err' => [$e::class, $e->getMessage(), $e->getCode(), $e->getTraceAsString()]]);
            }
            \ignis_offload_done($id, $out);
        }
    }
}

// The job channel only exists inside the ignis binary, which is also the only place that evaluates
// this file (crates/ignis/src/main.rs `include_str!`s it onto every offload thread). Guarding the
// call is what lets a test require the file for WorkerRuntime alone.
if (\function_exists('ignis_offload_next')) {
    WorkerRuntime::run();
}
