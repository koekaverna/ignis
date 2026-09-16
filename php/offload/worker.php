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
                $r = \ignis_offload_callback(self::$job, $id, serialize($args));
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

    public static function run(): void
    {
        $prelude = getenv('IGNIS_OFFLOAD_PRELUDE');
        if ($prelude !== false && $prelude !== '') {
            require_once $prelude;
        }
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
