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
 *
 * The two declarations cannot be merged into a file both sides `require`: this file is
 * `include_str!`ed into the binary and run under a synthetic filename, where `__DIR__` resolves to
 * the process's working directory, not to this one, so a `require __DIR__ . '/...'` here would not
 * find anything real. `offload/tests/EnvelopeTest.php` pins the two declarations to each other so
 * they cannot drift apart the way their error envelopes already had.
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
    public static function bindCallbacks(mixed $value): mixed
    {
        if ($value instanceof CallbackRef) {
            $id = $value->id;
            return static function (mixed ...$arguments) use ($id): mixed {
                $answer = \ignis_offload_callback(self::$job, $id, serialize(self::registerObjects($arguments)));
                if ($answer === false) {
                    throw new \RuntimeException('offload callback failed (caller gone)');
                }
                return self::unpackCallbackAnswer($answer);
            };
        }
        if (\is_array($value)) {
            foreach ($value as $key => $element) {
                $value[$key] = self::bindCallbacks($element);
            }
        }
        return $value;
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
            $trace = $error[3] ?? null;
            throw new RemoteException($remoteClass, 'callback threw: ' . $message, \is_scalar($code) ? (int) $code : 0, \is_string($trace) ? $trace : '');
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
     * @param array<int|string, mixed> $arguments
     */
    public static function routed(string $what, array $arguments): mixed
    {
        if ($what === 'free') {
            self::free($arguments[0] ?? null);
            return null;
        }
        $arguments = self::resolveArgumentRefs($arguments);
        [$kind, $name] = explode(':', $what, 2);
        $result = match ($kind) {
            'fn' => self::callFunction($name, $arguments),
            'new' => self::construct($name, $arguments),
            'method' => self::callMethod(array_shift($arguments), $name, $arguments),
            default => throw new \InvalidArgumentException("bad routed call $what"),
        };
        return self::registerObjects($result);
    }

    /** @param array<int|string, mixed> $arguments */
    private static function callFunction(string $function, array $arguments): mixed
    {
        if (!\in_array($function, self::routedNames('IGNIS_OFFLOAD_FUNCTIONS', ''), true) || !\is_callable($function)) {
            throw new \InvalidArgumentException("offload: $function is not a routed function");
        }
        return $function(...$arguments);
    }

    /** @param array<int|string, mixed> $arguments */
    private static function construct(string $class, array $arguments): object
    {
        if (!\in_array($class, self::routedNames('IGNIS_OFFLOAD_CLASSES', 'SQLite3'), true) || !class_exists($class)) {
            throw new \InvalidArgumentException("offload: $class is not a routed class");
        }
        return new $class(...$arguments);
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

    /** @param array<int|string, mixed> $arguments */
    private static function callMethod(mixed $object, string $method, array $arguments): mixed
    {
        if (!\is_object($object)) {
            throw new \RuntimeException("routed method $method on a non-object");
        }
        return $object->$method(...$arguments);
    }

    /**
     * @param array<int|string, mixed> $arguments
     * @return array<int|string, mixed>
     */
    private static function resolveArgumentRefs(array $arguments): array
    {
        foreach ($arguments as $key => $value) {
            $arguments[$key] = self::resolveRefs($value);
        }
        return $arguments;
    }

    /** ['__ref' => [worker, id, class]] → the real object held here; unknown ids are an error. */
    private static function resolveRefs(mixed $value): mixed
    {
        if (\is_array($value)) {
            if (isset($value['__ref']) && \count($value) === 1) {
                return self::resolveHandle($value['__ref']);
            }
            foreach ($value as $key => $element) {
                $value[$key] = self::resolveRefs($element);
            }
        }
        return $value;
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
    private static function registerObjects(mixed $value): mixed
    {
        if (\is_object($value)) {
            $class = $value::class;
            if (\in_array($class, self::ROUTABLE, true) || $value instanceof \PDO || $value instanceof \PDOStatement) {
                $id = self::$nextHandle++;
                self::$handles[$id] = $value;
                return ['__ref' => [self::$worker, $id, $value instanceof \PDOStatement ? 'PDOStatement' : ($value instanceof \PDO ? 'PDO' : $class)]];
            }
        }
        if (\is_array($value)) {
            foreach ($value as $key => $element) {
                $value[$key] = self::registerObjects($element);
            }
        }
        return $value;
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
            [$id, $function, $serializedArguments] = $job;
            self::$job = $id;
            try {
                $arguments = self::bindCallbacks(unserialize($serializedArguments, ['allowed_classes' => true]));
                if (!is_array($arguments)) {
                    throw new \RuntimeException('offload: argument list is ' . get_debug_type($arguments) . ', expected an array');
                }
                $callable = str_contains($function, '::') ? explode('::', $function, 2) : $function;
                if (!is_callable($callable)) {
                    throw new \RuntimeException("offload: {$function} is not callable in this worker");
                }
                $result = $callable(...$arguments);
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
