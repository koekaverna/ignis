<?php

/**
 * Caller side of the offload pool (E16, ADR-0016): Ignis\offload(function, ...arguments) runs a NAMED function
 * on a synchronous worker thread; scalar/array arguments are copied in (serialize), the result is
 * copied back; the calling fiber parks meanwhile. Closures among the arguments become callbacks
 * that run on this thread when the worker invokes them.
 */
declare(strict_types=1);

namespace Ignis\Offload {
    use Ignis\Loop;

    /**
     * `CallbackRef` and `RemoteException` are declared identically in `worker.php`, guarded the
     * same way. They cannot be factored into a file both sides `require`: `worker.php` is
     * `include_str!`ed into the binary and `zend_eval_string`d under a synthetic name, where
     * `__DIR__` does not resolve to this directory (`offload/tests/WorkerRuntimeTest.php` states
     * the same bound). `offload/tests/EnvelopeTest.php` pins the two declarations to each other so
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


    /**
     * Auto-routing (ADR-0016 §3). The runtime redirects configured internal functions and `new` of
     * configured classes here while inside a fiber. Real handles live on one offload worker;
     * the fiber holds a proxy that remembers the worker (affinity) and the remote id.
     */
    final class Router
    {
        private static int $roundRobin = 0;
        private static bool $enabled = false;
        public static int $routed = 0;

        public static function enable(): void
        {
            if (self::$enabled) {
                return;
            }
            self::$enabled = true;
            self::proxyConfiguredClasses();
            \ignis_route_enable(true);
        }

        /**
         * Mirrors the runtime's IGNIS_OFFLOAD_CLASSES (route.rs): proxy only what it routes, or a
         * `new PDO('pgsql:…')` would become a proxy for a class the runtime lets through.
         */
        private static function proxyConfiguredClasses(): void
        {
            foreach (explode(',', getenv('IGNIS_OFFLOAD_CLASSES') ?: 'SQLite3') as $class) {
                $class = trim($class);
                if ($class !== '' && class_exists($class, false)) {
                    self::proxyClass($class);
                }
            }
        }

        /**
         * Called by the runtime trampoline for a routed function. A call with no proxy argument is
         * not one of ours, and `ignis_route_pass()` runs the original instead.
         * @param list<mixed> $arguments
         */
        public static function dispatch(string $function, array $arguments): mixed
        {
            $affinity = self::affinityOf($arguments);
            if ($affinity === null && !\in_array($function, ['curl_init', 'curl_multi_init', 'curl_share_init'], true)) {
                \ignis_route_pass();
                return null;
            }
            self::$routed++;
            return self::wrap(Client::call('Ignis\Offload\WorkerRuntime::routed', ['fn:' . $function, self::unwrap($arguments)], $affinity ?? self::pick()));
        }

        /**
         * `new Class(...)` inside a fiber (from the proxy constructor). A temporary proxy carrying
         * the handle must give it up, or its destructor releases the handle we are returning.
         * @param list<mixed> $arguments
         */
        public static function construct(string $class, array $arguments): Handle
        {
            self::$routed++;
            $constructed = self::wrap(Client::call('Ignis\Offload\WorkerRuntime::routed', ['new:' . $class, self::unwrap($arguments)], self::pick()));
            if ($constructed instanceof Handle) {
                return $constructed;
            }
            if (\is_object($constructed) && isset($constructed->__ignisHandle)) {
                $handle = $constructed->__ignisHandle;
                $constructed->__ignisHandle = null;
                return $handle;
            }
            throw new \RuntimeException("offload: constructing $class did not return a handle");
        }

        /** @param list<mixed> $arguments */
        public static function method(Handle $handle, string $method, array $arguments): mixed
        {
            self::$routed++;
            return self::wrap(Client::call('Ignis\Offload\WorkerRuntime::routed', ['method:' . $method, self::unwrap(array_merge([$handle], $arguments))], $handle->worker));
        }

        /**
         * Fire-and-forget: nobody waits for the release, and the loop drops its completion. A proxy
         * and the `Handle` it holds are two destructors over one remote object, so the handle is
         * marked here — every caller goes through this method, and the second one is a no-op.
         */
        public static function release(Handle $handle): void
        {
            if ($handle->released) {
                return;
            }
            $handle->released = true;
            \ignis_offload_submit('Ignis\Offload\WorkerRuntime::routed', serialize(['free', [['__ref' => [$handle->worker, $handle->id, $handle->class]]]]), $handle->worker);
        }

        private static function pick(): int
        {
            $workers = max(1, (int) (\ignis_offload_stats()['workers'] ?? 1));
            return self::$roundRobin++ % $workers;
        }

        /**
         * The worker a proxy argument already lives on, so a handle never crosses workers.
         * @param list<mixed> $arguments
         */
        private static function affinityOf(array $arguments): ?int
        {
            foreach ($arguments as $argument) {
                if ($argument instanceof Handle) {
                    return $argument->worker;
                }
                if (\is_object($argument) && isset($argument->__ignisHandle)) {
                    return $argument->__ignisHandle->worker;
                }
            }
            return null;
        }

        /** Proxies → ['__ref' => ...] for the wire (closures are handled by Client). */
        private static function unwrap(mixed $value): mixed
        {
            if ($value instanceof Handle) {
                return ['__ref' => [$value->worker, $value->id, $value->class]];
            }
            if (\is_object($value) && isset($value->__ignisHandle)) {
                return self::unwrap($value->__ignisHandle);
            }
            if (\is_array($value)) {
                foreach ($value as $key => $element) {
                    $value[$key] = self::unwrap($element);
                }
            }
            return $value;
        }

        /** @internal refs in callback arguments → handles/proxies */
        public static function wrapRefs(mixed $value): mixed
        {
            return self::wrap($value);
        }

        /**
         * ['__ref' => [worker, id, class]] → a class proxy, or a bare Handle with `__call`
         * forwarding for a final class (CurlHandle) that cannot be subclassed. A class seen for the
         * first time here — SQLite3Result, PDOStatement — gets its proxy declared now.
         */
        private static function wrap(mixed $value): mixed
        {
            if (\is_array($value)) {
                if (isset($value['__ref']) && \count($value) === 1) {
                    return self::wrapReference($value['__ref']);
                }
                foreach ($value as $key => $element) {
                    $value[$key] = self::wrap($element);
                }
            }
            return $value;
        }

        /** Turns the `[worker, id, class]` wire tuple behind a `__ref` key into a handle or class proxy. */
        private static function wrapReference(mixed $reference): object
        {
            if (!\is_array($reference) || \count($reference) !== 3) {
                throw new \RuntimeException('offload: malformed handle reference on the wire');
            }
            [$worker, $id, $class] = $reference;
            if (!\is_int($worker) || !\is_int($id) || !\is_string($class)) {
                throw new \RuntimeException('offload: malformed handle reference on the wire');
            }
            $handle = new Handle($worker, $id, $class);
            $proxy = 'Ignis\Offload\Proxy\\' . $class;
            if (!class_exists($proxy, false) && class_exists($class, false) && !(new \ReflectionClass($class))->isFinal()) {
                self::proxyClass($class);
            }
            return class_exists($proxy, false) ? $proxy::fromHandle($handle) : $handle;
        }

        /** One reflection type back to source, with class names absolute and self/static resolved. */
        private static function typeString(\ReflectionType $type, string $class): string
        {
            if ($type instanceof \ReflectionUnionType) {
                return implode('|', array_map(static fn($member) => self::typeString($member, $class), $type->getTypes()));
            }
            if ($type instanceof \ReflectionIntersectionType) {
                return implode('&', array_map(static fn($member) => self::typeString($member, $class), $type->getTypes()));
            }
            /** @var \ReflectionNamedType $type */
            $name = $type->getName();
            $resolvedName = match (true) {
                $name === 'self' => '\\' . $class,
                $name === 'static' => 'static',
                $type->isBuiltin() => $name,
                default => '\\' . $name,
            };
            return $type->allowsNull() && !\in_array($name, ['mixed', 'null'], true) && !str_contains($resolvedName, '|') ? '?' . $resolvedName : $resolvedName;
        }

        /**
         * Declare Ignis\Offload\Proxy\<Class> extends <Class>: every public method forwards to the
         * worker (signature copied by reflection so the override is LSP-compatible); constants and
         * `instanceof` come from the parent for free.
         */
        public static function proxyClass(string $class): void
        {
            if (!class_exists($class, false) || class_exists('Ignis\Offload\Proxy\\' . $class, false)) {
                return;
            }
            $reflectionClass = new \ReflectionClass($class);
            $methods = '';
            foreach ($reflectionClass->getMethods(\ReflectionMethod::IS_PUBLIC) as $method) {
                if ($method->isStatic() || $method->isFinal() || $method->getName() === '__construct') {
                    continue;
                }
                $parameters = [];
                $forwardedArguments = [];
                foreach ($method->getParameters() as $parameter) {
                    $declaration = ($parameter->isVariadic() ? '...' : '') . ($parameter->isPassedByReference() ? '&' : '') . '$' . $parameter->getName();
                    if ($parameter->isOptional() && !$parameter->isVariadic()) {
                        $declaration .= ' = ' . ($parameter->isDefaultValueAvailable() ? var_export($parameter->getDefaultValue(), true) : 'null');
                    }
                    $parameters[] = $declaration;
                    $forwardedArguments[] = ($parameter->isVariadic() ? '...' : '') . '$' . $parameter->getName();
                }
                $returnType = $method->getReturnType() ?? $method->getTentativeReturnType();
                $returnTypeDeclaration = $returnType === null ? '' : ': ' . self::typeString($returnType, $class);
                $body = $returnType instanceof \ReflectionNamedType && $returnType->getName() === 'void'
                    ? sprintf("\\Ignis\\Offload\\Router::method(\$this->__ignisHandle, %s, [%s]);", var_export($method->getName(), true), implode(', ', $forwardedArguments))
                    : sprintf("return \\Ignis\\Offload\\Router::method(\$this->__ignisHandle, %s, [%s]);", var_export($method->getName(), true), implode(', ', $forwardedArguments));
                $methods .= sprintf("    public function %s(%s)%s { %s }\n", $method->getName(), implode(', ', $parameters), $returnTypeDeclaration, $body);
            }
            $code = sprintf('namespace Ignis\Offload\Proxy; #[\AllowDynamicProperties] class %1$s extends \%1$s { public $__ignisHandle; public function __construct(...$arguments) { $this->__ignisHandle = \Ignis\Offload\Router::construct(%2$s, $arguments); } public static function fromHandle(\Ignis\Offload\Handle $handle): static { $proxy = (new \ReflectionClass(static::class))->newInstanceWithoutConstructor(); $proxy->__ignisHandle = $handle; return $proxy; } public function __destruct() { if ($this->__ignisHandle !== null) { \Ignis\Offload\Router::release($this->__ignisHandle); } }
%3$s }', $class, var_export($class, true), $methods);
            eval($code);
        }
    }

    final class Handle
    {
        /** @internal owned by `Router::release()`, which is the only thing that may set it. */
        public bool $released = false;

        public function __construct(public readonly int $worker, public readonly int $id, public readonly string $class) {}

        /**
         * Method calls on a handle of a final class (no proxy subclass possible) forward as well.
         * @param list<mixed> $arguments
         */
        public function __call(string $method, array $arguments): mixed
        {
            return Router::method($this, $method, $arguments);
        }

        public function __destruct()
        {
            Router::release($this);
        }
    }

    final class Client
    {
        /** @var array<int, \Closure> callback id => closure, for the calls that are in flight */
        private static array $pending = [];
        private static int $nextCallbackId = 1;
        private static bool $hooked = false;
        public static int $callbacksRun = 0;

        /**
         * Runs a named function on a worker and parks this fiber until its answer comes back.
         *
         * A variadic `Ignis\offload()` call carries named arguments through as string keys, which
         * is why this is not a list.
         *
         * @param array<int|string, mixed> $arguments
         */
        public static function call(string $function, array $arguments, ?int $affinity = null): mixed
        {
            self::hook();
            $callbacks = [];
            $arguments = self::extractCallbacks($arguments, $callbacks);
            $opId = \ignis_offload_submit($function, serialize($arguments), $affinity ?? -1);
            if (!\is_int($opId)) {
                throw new \RuntimeException('offload: no pool (start ignis with --offload N)');
            }
            self::$pending += $callbacks;
            try {
                $answer = Loop::awaitOp($opId);
            } finally {
                foreach (array_keys($callbacks) as $id) {
                    unset(self::$pending[$id]);
                }
            }
            if (\is_array($answer) && ($answer['kind'] ?? '') === 'error') {
                $message = $answer['message'] ?? null;
                throw new \RuntimeException('offload: ' . (\is_string($message) ? $message : 'unknown error'));
            }
            if (!\is_string($answer)) {
                throw new \RuntimeException('offload: malformed completion payload');
            }
            $result = unserialize($answer, ['allowed_classes' => true]);
            if (!\is_array($result)) {
                throw new \RuntimeException('offload: malformed result payload');
            }
            if (isset($result['err'])) {
                throw self::remoteExceptionFrom($result['err']);
            }
            return $result['ok'] ?? null;
        }

        /** Rebuilds the exception a worker reported, after validating the envelope it serialized. */
        private static function remoteExceptionFrom(mixed $error): RemoteException
        {
            if (!\is_array($error)) {
                throw new \RuntimeException('offload: malformed error payload');
            }
            $remoteClass = $error[0] ?? null;
            $message = $error[1] ?? null;
            if (!\is_string($remoteClass) || !\is_string($message)) {
                throw new \RuntimeException('offload: malformed error payload');
            }
            $code = $error[2] ?? 0;
            $trace = $error[3] ?? null;
            return new RemoteException($remoteClass, $message, \is_scalar($code) ? (int) $code : 0, \is_string($trace) ? $trace : '');
        }

        /**
         * Closures anywhere in $arguments become CallbackRef; `call()` owns how long the real ones live.
         * @param array<int, \Closure> $callbacks
         */
        private static function extractCallbacks(mixed $value, array &$callbacks): mixed
        {
            if ($value instanceof \Closure) {
                $id = self::$nextCallbackId++;
                $callbacks[$id] = $value;
                return new CallbackRef($id);
            }
            if (\is_array($value)) {
                foreach ($value as $key => $element) {
                    $value[$key] = self::extractCallbacks($element, $callbacks);
                }
            }
            return $value;
        }

        private static function hook(): void
        {
            if (self::$hooked) {
                return;
            }
            self::$hooked = true;
            Loop::$offloadCallbackHandler = static function (array $payload): void {
                Loop::spawn(self::runCallback(...), $payload);
            };
        }

        /**
         * Runs one worker callback in its own fiber, so it may itself await, and answers when it returns.
         * @param array<string, mixed> $payload
         */
        private static function runCallback(array $payload): void
        {
            $jobId = $payload['job'] ?? null;
            $sequence = $payload['seq'] ?? null;
            if (!\is_int($jobId) || !\is_int($sequence)) {
                throw new \RuntimeException('offload: malformed callback envelope');
            }
            try {
                $callbackId = $payload['cb'] ?? null;
                $serializedArguments = $payload['args'] ?? null;
                if (!\is_int($callbackId) || !\is_string($serializedArguments)) {
                    throw new \RuntimeException('offload: malformed callback payload');
                }
                $callback = self::$pending[$callbackId] ?? null;
                if ($callback === null) {
                    throw new \RuntimeException('unknown callback ' . $callbackId);
                }
                $arguments = Router::wrapRefs(unserialize($serializedArguments, ['allowed_classes' => true]));
                if (!\is_array($arguments)) {
                    throw new \RuntimeException('offload: malformed callback arguments');
                }
                $out = serialize(['ok' => $callback(...$arguments)]);
            } catch (\Throwable $e) {
                $out = serialize(['err' => [$e::class, $e->getMessage(), $e->getCode(), $e->getTraceAsString()]]);
            }
            self::$callbacksRun++;
            \ignis_offload_cb_result($jobId, $sequence, $out);
        }

        /** @return array<string, mixed> */
        public static function stats(): array
        {
            return \ignis_offload_stats();
        }
    }
}

namespace Ignis\Offload {
    if (\function_exists('ignis_route_enable') && (\ignis_offload_stats()['workers'] ?? 0) > 0) {
        Router::enable();
    }
}

namespace Ignis {
    /** Run a named function (or "Class::method") on the offload pool; the current fiber parks. */
    function offload(string $function, mixed ...$arguments): mixed
    {
        return Offload\Client::call($function, $arguments);
    }
}
