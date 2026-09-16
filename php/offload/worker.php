<?php
/**
 * Offload worker loop (E16, ADR-0016). Runs on a synchronous PHP thread with its own TSRM context;
 * embedded into the ignis binary and evaluated on every offload thread. IGNIS_OFFLOAD_PRELUDE
 * (a file) is required first so the functions named in jobs exist here.
 */
declare(strict_types=1);

namespace Ignis\Offload;

/** Serializable stand-in for a caller-side closure; the worker turns it into a stub that calls back. */
final class CallbackRef
{
    public function __construct(public readonly int $id)
    {
    }
}

final class RemoteException extends \RuntimeException
{
    public function __construct(public readonly string $remoteClass, string $message, int $code, public readonly string $remoteTrace = '')
    {
        parent::__construct($message, $code);
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
                // Handles among the callback arguments (curl gives the CurlHandle) travel as refs.
                $r = \ignis_offload_callback(self::$job, $id, serialize(self::registerObjects($args)));
                if ($r === false) {
                    throw new \RuntimeException('offload callback failed (caller gone)');
                }
                $u = unserialize($r);
                if (isset($u['err'])) {
                    throw new RemoteException($u['err'][0], 'callback threw: ' . $u['err'][1], (int) $u['err'][2]);
                }
                return $u['ok'] ?? null;
            };
        }
        if (\is_array($v)) {
            foreach ($v as $k => $x) {
                $v[$k] = self::bindCallbacks($x);
            }
        }
        return $v;
    }

    /** @var array<int, object> remote objects held for proxies on the fiber threads */
    private static array $handles = [];
    private static int $nextHandle = 1;
    private const ROUTABLE = ['CurlHandle', 'CurlMultiHandle', 'CurlShareHandle', 'PDO', 'PDOStatement', 'SQLite3', 'SQLite3Stmt', 'SQLite3Result'];

    /** Auto-routed call from a fiber thread: "fn:name" / "new:Class" / "method:name" / "free". */
    public static function routed(string $what, array $args): mixed
    {
        if ($what === 'free') {
            unset(self::$handles[(int) ($args[0]['__ref'][1] ?? 0)]);
            return null;
        }
        $args = self::resolveRefs($args);
        [$kind, $name] = explode(':', $what, 2);
        $result = match ($kind) {
            'fn' => $name(...$args),
            'new' => new $name(...$args),
            'method' => self::callMethod(array_shift($args), $name, $args),
            default => throw new \InvalidArgumentException("bad routed call $what"),
        };
        return self::registerObjects($result);
    }

    private static function callMethod(mixed $obj, string $method, array $args): mixed
    {
        if (!\is_object($obj)) {
            throw new \RuntimeException("routed method $method on a non-object");
        }
        return $obj->$method(...$args);
    }

    /** ['__ref' => [worker, id, class]] → the real object held here; unknown ids are an error. */
    private static function resolveRefs(mixed $v): mixed
    {
        if (\is_array($v)) {
            if (isset($v['__ref']) && \count($v) === 1) {
                $id = (int) $v['__ref'][1];
                if (!isset(self::$handles[$id])) {
                    throw new \RuntimeException("offload: unknown handle $id on this worker");
                }
                return self::$handles[$id];
            }
            foreach ($v as $k => $x) {
                $v[$k] = self::resolveRefs($x);
            }
        }
        return $v;
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

WorkerRuntime::run();
