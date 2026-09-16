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


    /**
     * Auto-routing (ADR-0016 §3). The runtime redirects configured internal functions and `new` of
     * configured classes here while inside a fiber. Real handles live on one offload worker;
     * the fiber holds a proxy that remembers the worker (affinity) and the remote id.
     */
    final class Router
    {
        private static int $rr = 0;
        private static bool $enabled = false;
        public static int $routed = 0;

        public static function enable(): void
        {
            if (self::$enabled) {
                return;
            }
            self::$enabled = true;
            self::proxyClass('PDO');
            self::proxyClass('SQLite3');
            \ignis_route_enable(true);
        }

        /** Called by the runtime trampoline for a routed function. */
        public static function dispatch(string $fn, array $args): mixed
        {
            $affinity = self::affinityOf($args);
            if ($affinity === null && !\in_array($fn, ['curl_init', 'curl_multi_init', 'curl_share_init'], true)) {
                \ignis_route_pass(); // not one of ours (no proxy argument): run the original
                return null;
            }
            self::$routed++;
            return self::wrap(Client::call('Ignis\Offload\WorkerRuntime::routed', ['fn:' . $fn, self::unwrap($args)], $affinity ?? self::pick()));
        }

        /** `new Class(...)` inside a fiber (from the proxy constructor). */
        public static function construct(string $class, array $args): Handle
        {
            self::$routed++;
            $r = self::wrap(Client::call('Ignis\Offload\WorkerRuntime::routed', ['new:' . $class, self::unwrap($args)], self::pick()));
            if ($r instanceof Handle) {
                return $r;
            }
            if (\is_object($r) && isset($r->__ignisHandle)) {
                $h = $r->__ignisHandle;
                $r->__ignisHandle = null; // the temporary proxy must not release it
                return $h;
            }
            throw new \RuntimeException("offload: constructing $class did not return a handle");
        }

        public static function method(Handle $h, string $method, array $args): mixed
        {
            self::$routed++;
            return self::wrap(Client::call('Ignis\Offload\WorkerRuntime::routed', ['method:' . $method, self::unwrap(array_merge([$h], $args))], $h->worker));
        }

        public static function release(Handle $h): void
        {
            // Fire-and-forget: nobody waits; the loop drops the completion.
            \ignis_offload_submit('Ignis\Offload\WorkerRuntime::routed', serialize(['free', [['__ref' => [$h->worker, $h->id, $h->class]]]]), $h->worker);
        }

        private static function pick(): int
        {
            $n = max(1, (int) (\ignis_offload_stats()['workers'] ?? 1));
            return self::$rr++ % $n;
        }

        private static function affinityOf(array $args): ?int
        {
            foreach ($args as $a) {
                if ($a instanceof Handle) {
                    return $a->worker;
                }
                if (\is_object($a) && isset($a->__ignisHandle)) {
                    return $a->__ignisHandle->worker;
                }
            }
            return null;
        }

        /** Proxies → ['__ref' => ...] for the wire (closures are handled by Client). */
        private static function unwrap(mixed $v): mixed
        {
            if ($v instanceof Handle) {
                return ['__ref' => [$v->worker, $v->id, $v->class]];
            }
            if (\is_object($v) && isset($v->__ignisHandle)) {
                return self::unwrap($v->__ignisHandle);
            }
            if (\is_array($v)) {
                foreach ($v as $k => $x) {
                    $v[$k] = self::unwrap($x);
                }
            }
            return $v;
        }

        /** @internal refs in callback arguments → handles/proxies */
        public static function wrapRefs(mixed $v): mixed
        {
            return self::wrap($v);
        }

        /** ['__ref' => [worker, id, class]] → Handle or a class proxy. */
        private static function wrap(mixed $v): mixed
        {
            if (\is_array($v)) {
                if (isset($v['__ref']) && \count($v) === 1) {
                    [$w, $id, $class] = $v['__ref'];
                    $h = new Handle($w, $id, $class);
                    $proxy = 'Ignis\Offload\Proxy\\' . $class;
                    if (!class_exists($proxy, false) && class_exists($class, false) && !(new \ReflectionClass($class))->isFinal()) {
                        self::proxyClass($class); // e.g. SQLite3Result, PDOStatement: proxied on first sight
                    }
                    if (class_exists($proxy, false)) {
                        return $proxy::fromHandle($h);
                    }
                    return $h; // final classes (CurlHandle): a Handle with __call forwarding
                }
                foreach ($v as $k => $x) {
                    $v[$k] = self::wrap($x);
                }
            }
            return $v;
        }

        /**
         * Declare Ignis\Offload\Proxy\<Class> extends <Class>: every public method forwards to the
         * worker (signature copied by reflection so the override is LSP-compatible); constants and
         * `instanceof` come from the parent for free.
         */
        private static function typeString(\ReflectionType $t, string $class): string
        {
            if ($t instanceof \ReflectionUnionType) {
                return implode('|', array_map(static fn ($x) => self::typeString($x, $class), $t->getTypes()));
            }
            if ($t instanceof \ReflectionIntersectionType) {
                return implode('&', array_map(static fn ($x) => self::typeString($x, $class), $t->getTypes()));
            }
            /** @var \ReflectionNamedType $t */
            $n = $t->getName();
            $s = match (true) {
                $n === 'self' => '\\' . $class,
                $n === 'static' => 'static',
                $t->isBuiltin() => $n,
                default => '\\' . $n,
            };
            return $t->allowsNull() && !\in_array($n, ['mixed', 'null'], true) && !str_contains($s, '|') ? '?' . $s : $s;
        }

        public static function proxyClass(string $class): void
        {
            if (!class_exists($class, false) || class_exists('Ignis\Offload\Proxy\\' . $class, false)) {
                return;
            }
            $rc = new \ReflectionClass($class);
            $methods = '';
            foreach ($rc->getMethods(\ReflectionMethod::IS_PUBLIC) as $m) {
                if ($m->isStatic() || $m->isFinal() || $m->getName() === '__construct') {
                    continue;
                }
                $params = [];
                $pass = [];
                foreach ($m->getParameters() as $p) {
                    $decl = ($p->isVariadic() ? '...' : '') . ($p->isPassedByReference() ? '&' : '') . '$' . $p->getName();
                    if ($p->isOptional() && !$p->isVariadic()) {
                        $decl .= ' = ' . ($p->isDefaultValueAvailable() ? var_export($p->getDefaultValue(), true) : 'null');
                    }
                    $params[] = $decl;
                    $pass[] = ($p->isVariadic() ? '...' : '') . '$' . $p->getName();
                }
                $rt = $m->getReturnType() ?? $m->getTentativeReturnType();
                $ret = $rt === null ? '' : ': ' . self::typeString($rt, $class);
                $body = $rt instanceof \ReflectionNamedType && $rt->getName() === 'void'
                    ? sprintf("\\Ignis\\Offload\\Router::method(\$this->__ignisHandle, %s, [%s]);", var_export($m->getName(), true), implode(', ', $pass))
                    : sprintf("return \\Ignis\\Offload\\Router::method(\$this->__ignisHandle, %s, [%s]);", var_export($m->getName(), true), implode(', ', $pass));
                $methods .= sprintf("    public function %s(%s)%s { %s }\n", $m->getName(), implode(', ', $params), $ret, $body);
            }
            $code = sprintf('namespace Ignis\Offload\Proxy; #[\AllowDynamicProperties] class %1$s extends \%1$s { public $__ignisHandle; public function __construct(...$args) { $this->__ignisHandle = \Ignis\Offload\Router::construct(%2$s, $args); } public static function fromHandle(\Ignis\Offload\Handle $h): static { $o = (new \ReflectionClass(static::class))->newInstanceWithoutConstructor(); $o->__ignisHandle = $h; return $o; } public function __destruct() { if ($this->__ignisHandle !== null) { \Ignis\Offload\Router::release($this->__ignisHandle); } }
%3$s }', $class, var_export($class, true), $methods);
            eval($code);
        }
    }

    // (Router helper) reflection type → source, with class names absolute and self/static resolved.
    // Declared as a Router method below via closure binding to keep the class body compact.

    final class Handle
    {
        private bool $released = false;

        public function __construct(public readonly int $worker, public readonly int $id, public readonly string $class)
        {
        }

        /** Method calls on a handle of a final class (no proxy subclass possible) forward as well. */
        public function __call(string $method, array $args): mixed
        {
            return Router::method($this, $method, $args);
        }

        public function __destruct()
        {
            if (!$this->released) {
                $this->released = true;
                Router::release($this);
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
                        $args = Router::wrapRefs(unserialize($p['args'], ['allowed_classes' => true]));
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

namespace Ignis\Offload {
    if (\function_exists('ignis_route_enable') && (\ignis_offload_stats()['workers'] ?? 0) > 0) {
        Router::enable();
    }
}

namespace Ignis {
    /** Run a named function (or "Class::method") on the offload pool; the current fiber parks. */
    function offload(string $fn, mixed ...$args): mixed
    {
        return Offload\Client::call($fn, $args);
    }
}
