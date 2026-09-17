<?php
/**
 * Swoole userland shim on top of Ignis (E15c / H22c). MIT License.
 * Copyright (c) 2026 the Ignis authors. Permission is hereby granted, free of charge, to any
 * person obtaining a copy of this software, to deal in it without restriction, subject to this
 * notice being included in all copies. THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY.
 *
 * The smallest subset of the Swoole API that lets tests/swoole_runtime start: Runtime,
 * Coroutine (Co), Coroutine\{Channel,WaitGroup,System}, Event::wait, Timer, Co\run, go() and
 * the SWOOLE_HOOK_* constants. A coroutine is an Ignis pool fiber; nothing here changes what
 * is hooked (only tcp:// created inside a fiber suspends; sleep/file/udp/... still block).
 */
declare(strict_types=1);

namespace {
    require_once __DIR__ . '/../../runtime/src/ignis.php';

    const SWOOLE_HOOK_TCP = 1 << 1; const SWOOLE_HOOK_UDP = 1 << 2; const SWOOLE_HOOK_UNIX = 1 << 3;
    const SWOOLE_HOOK_UDG = 1 << 4; const SWOOLE_HOOK_SSL = 1 << 5; const SWOOLE_HOOK_TLS = 1 << 6;
    const SWOOLE_HOOK_STREAM_FUNCTION = 1 << 7; const SWOOLE_HOOK_STREAM_SELECT = 1 << 7;
    const SWOOLE_HOOK_FILE = 1 << 8; const SWOOLE_HOOK_SLEEP = 1 << 9; const SWOOLE_HOOK_PROC = 1 << 10;
    const SWOOLE_HOOK_CURL = 1 << 11; const SWOOLE_HOOK_NATIVE_CURL = 1 << 12; const SWOOLE_HOOK_BLOCKING_FUNCTION = 1 << 13;
    const SWOOLE_HOOK_SOCKETS = 1 << 14; const SWOOLE_HOOK_STDIO = 1 << 15; const SWOOLE_HOOK_PDO_PGSQL = 1 << 16;
    const SWOOLE_HOOK_PDO_ODBC = 1 << 17; const SWOOLE_HOOK_PDO_ORACLE = 1 << 18; const SWOOLE_HOOK_PDO_SQLITE = 1 << 19;
    const SWOOLE_HOOK_PDO_FIREBIRD = 1 << 20; const SWOOLE_HOOK_NET_FUNCTION = 1 << 21; const SWOOLE_HOOK_MONGODB = 1 << 22;
    const SWOOLE_HOOK_ALL = 0x7fffffff & ~SWOOLE_HOOK_NATIVE_CURL & ~SWOOLE_HOOK_MONGODB;
    const SWOOLE_BASE = 1; const SWOOLE_PROCESS = 2; const SWOOLE_LOG_INFO = 2;
    const SWOOLE_USE_SHORTNAME = true; const SWOOLE_VERSION = '0.0-ignis-shim';

    /** Swoole short name: `go()` creates a coroutine and returns its id. */
    function go(callable $fn, mixed ...$args): int { return \Swoole\Coroutine::create($fn, ...$args); }
    function swoole_async_set(array $settings): void {}
    function swoole_cpu_num(): int { return max(1, (int) shell_exec('nproc 2>/dev/null')); }
    /** Swoole's `defer()` runs $fn when the current coroutine ends. */
    function defer(callable $fn): void { \Swoole\Coroutine::defer($fn); }
}

namespace Swoole {
    final class Runtime
    {
        private static int $flags = 0;
        /** Co::set(['hook_flags' => …]); applied by Co\run() like Swoole does (default: all). */
        public static int $configured = SWOOLE_HOOK_ALL;

        /** enableCoroutine(true|false|int $flags): the hook set is recorded, not applied. */
        public static function enableCoroutine(bool|int $enable = true, int $flags = SWOOLE_HOOK_ALL): bool
        {
            return self::setHookFlags(\is_int($enable) ? $enable : ($enable ? $flags : 0));
        }
        public static function getHookFlags(): int { return self::$flags; }
        public static function setHookFlags(int $flags): bool { self::$flags = self::$configured = $flags; return true; }
    }

    /** A Swoole coroutine = one Ignis pool fiber wrapped with an id, defers and a parking slot. */
    class Coroutine
    {
        private static int $next = 0;
        /** @var array<int,\Ignis\Future> live coroutines */
        private static array $live = [];
        /** @var array<int,\Fiber> parked by yield() */
        private static array $parked = [];
        /** @var array<int,list<callable>> */
        private static array $defers = [];
        /** @var array<int,bool> */
        private static array $cancelled = [];

        public static function create(callable $fn, mixed ...$args): int
        {
            $cid = ++self::$next;
            $pcid = self::getCid();
            self::$live[$cid] = \Ignis\Loop::spawn(static function () use ($fn, $args, $cid, $pcid): void {
                \Ignis\Scope::set('swoole.cid', $cid);
                \Ignis\Scope::set('swoole.pcid', $pcid);
                try {
                    $fn(...$args);
                } catch (\Throwable $e) {
                    // Swoole aborts the process on an uncaught coroutine exception; tests rely on the message.
                    echo "PHP Fatal error:  Uncaught ", $e, "\n  thrown in ", $e->getFile(), " on line ", $e->getLine(), "\n";
                    Event::$aborted = true;
                    exit(255);
                } finally {
                    foreach (\array_reverse(self::$defers[$cid] ?? []) as $d) { $d(); }
                    unset(self::$live[$cid], self::$defers[$cid], self::$cancelled[$cid]);
                }
            });
            return $cid;
        }

        public static function getCid(): int { return (int) \Ignis\Scope::get('swoole.cid', -1); }
        public static function getPcid(): int { return (int) \Ignis\Scope::get('swoole.pcid', -1); }
        public static function exists(int $cid): bool { return isset(self::$live[$cid]); }
        public static function defer(callable $fn): void { self::$defers[self::getCid()][] = $fn; }
        public static function set(array $options): void { if (isset($options['hook_flags'])) { Runtime::$configured = (int) $options['hook_flags']; } }
        public static function stats(): array { return ['coroutine_num' => \count(self::$live), 'coroutine_peak_num' => self::$next]; }

        /** Non-blocking sleep in seconds; false when the coroutine was cancelled meanwhile. */
        public static function sleep(float $seconds): bool
        {
            \Ignis\sleep(\max(0, (int) \round($seconds * 1000)));
            $cid = self::getCid();
            if (isset(self::$cancelled[$cid])) { unset(self::$cancelled[$cid]); return false; }
            return true;
        }

        /** Best effort: marks the target so its next sleep() reports cancellation. */
        public static function cancel(int $cid): bool { if (!isset(self::$live[$cid])) { return false; } self::$cancelled[$cid] = true; return true; }

        /** @param list<int> $cids */
        public static function join(array $cids, float $timeout = -1): bool
        {
            foreach ($cids as $cid) { if (isset(self::$live[$cid])) { self::$live[$cid]->await(); } }
            return true;
        }

        public static function yield(): void
        {
            $fiber = \Fiber::getCurrent() ?? throw new \Error('Coroutine::yield() outside a coroutine');
            self::$parked[self::getCid()] = $fiber;
            \Fiber::suspend();
        }

        public static function resume(int $cid): bool
        {
            $fiber = self::$parked[$cid] ?? null;
            if ($fiber === null) { return false; }
            unset(self::$parked[$cid]);
            \Ignis\Loop::markReady($fiber, null);
            return true;
        }

        /**
         * Drives the Ignis loop until $stop() holds. A ticker fiber keeps one userland timer
         * pending so the loop keeps polling while fibers are parked in C stream ops
         * (Ignis\Loop::run() returns as soon as no userland op is awaited).
         */
        public static function drive(callable $stop): void
        {
            if (\Fiber::getCurrent() !== null) { // nested: only await, the outer driver polls
                while (!$stop()) { \Ignis\sleep(1); }
                return;
            }
            \Ignis\Loop::spawn(static function () use ($stop): void { while (!$stop()) { \Ignis\sleep(5); } });
            \Ignis\Loop::runUntil($stop);
        }

        /** Co\run(): runs $fn as a coroutine and drives the loop until every coroutine and timer is done. */
        public static function run(callable $fn, mixed ...$args): bool
        {
            Runtime::setHookFlags(Runtime::$configured);
            self::create($fn, ...$args);
            Event::wait();
            return true;
        }
    }

    final class Event
    {
        public static bool $aborted = false;

        /** Swoole\Event::wait(): like Co\run() without a body; used by go()-style tests. */
        public static function wait(): void { Coroutine::drive(static fn (): bool => Coroutine::stats()['coroutine_num'] === 0); }

        /** Swoole runs the event loop at request shutdown when coroutines are still pending. */
        public static function shutdown(): void
        {
            if (self::$aborted) { return; }
            try { self::wait(); } catch (\LogicException) { /* loop already running: exit() inside a coroutine */ }
        }
    }

    final class Timer
    {
        /** @var array<int,bool> id => alive */
        private static array $timers = [];

        public static function tick(int $ms, callable $fn, mixed ...$args): int { return self::start($ms, $fn, $args, true); }
        public static function after(int $ms, callable $fn, mixed ...$args): int { return self::start($ms, $fn, $args, false); }
        public static function clear(int $id): bool { if (!isset(self::$timers[$id])) { return false; } unset(self::$timers[$id]); return true; }

        private static function start(int $ms, callable $fn, array $args, bool $repeat): int
        {
            static $next = 0;
            $id = ++$next;
            self::$timers[$id] = true;
            Coroutine::create(static function () use ($id, $ms, $fn, $args, $repeat): void { // a timer is a live coroutine
                do {
                    \Ignis\sleep(\max(1, $ms));
                    if (!isset(self::$timers[$id])) { return; }
                    $fn($id, ...$args);
                } while ($repeat && isset(self::$timers[$id]));
                unset(self::$timers[$id]);
            });
            return $id;
        }
    }
}

namespace Swoole\Coroutine {
    function run(callable $fn, mixed ...$args): bool { return \Swoole\Coroutine::run($fn, ...$args); }
    function go(callable $fn, mixed ...$args): int { return \Swoole\Coroutine::create($fn, ...$args); }

    final class System
    {
        public static function sleep(float $seconds): bool { return \Swoole\Coroutine::sleep($seconds); }
    }

    /** Parks the current fiber until $ready(); from {main} drives the loop instead. False on timeout. */
    trait Parking
    {
        /** @var list<\Fiber> */
        private array $waiters = [];

        private function park(callable $ready, float $timeout): bool
        {
            if ($ready()) { return true; }
            $fiber = \Fiber::getCurrent();
            if ($fiber === null) { \Swoole\Coroutine::drive($ready); return $ready(); }
            $this->waiters[] = $fiber;
            if ($timeout > 0) {
                \Ignis\Loop::spawn(function () use ($fiber, $timeout): void {
                    \Ignis\sleep((int) ($timeout * 1000));
                    $i = \array_search($fiber, $this->waiters, true);
                    if ($i !== false) { unset($this->waiters[$i]); \Ignis\Loop::markReady($fiber, 'timeout'); }
                });
            }
            return \Fiber::suspend() !== 'timeout';
        }

        private function wake(): void
        {
            foreach ($this->waiters as $f) { \Ignis\Loop::markReady($f, null); }
            $this->waiters = [];
        }
    }

    final class Channel
    {
        use Parking;
        private array $queue = [];
        private bool $closed = false;
        public int $errCode = 0;

        public function __construct(public readonly int $capacity = 1) {}

        public function push(mixed $data, float $timeout = -1): bool
        {
            if (!$this->park(fn (): bool => $this->closed || \count($this->queue) < $this->capacity, $timeout) || $this->closed) { return false; }
            $this->queue[] = $data;
            $this->wake();
            return true;
        }

        public function pop(float $timeout = -1): mixed
        {
            if (!$this->park(fn (): bool => $this->closed || $this->queue !== [], $timeout) || $this->queue === []) { return false; }
            $v = \array_shift($this->queue);
            $this->wake();
            return $v;
        }

        public function close(): bool { $this->closed = true; $this->wake(); return true; }
        public function length(): int { return \count($this->queue); }
        public function isEmpty(): bool { return $this->queue === []; }
        public function isFull(): bool { return \count($this->queue) >= $this->capacity; }
    }

    final class WaitGroup
    {
        use Parking;
        private int $count = 0;

        public function __construct(int $delta = 0) { $this->add($delta); }
        public function add(int $delta = 1): void { $this->count += $delta; if ($this->count < 0) { throw new \InvalidArgumentException('WaitGroup misuse: negative counter'); } }
        public function done(): void { $this->add(-1); if ($this->count === 0) { $this->wake(); } }
        public function count(): int { return $this->count; }
        public function wait(float $timeout = -1): bool { return $this->park(fn (): bool => $this->count === 0, $timeout); }
    }
}

namespace Co {
    function run(callable $fn, mixed ...$args): bool { return \Swoole\Coroutine::run($fn, ...$args); }
}

namespace {
    class_alias(\Swoole\Coroutine::class, 'Co');
    class_alias(\Swoole\Coroutine\Channel::class, 'Co\Channel');
    class_alias(\Swoole\Coroutine\WaitGroup::class, 'Co\WaitGroup');
    class_alias(\Swoole\Coroutine\System::class, 'Co\System');
    register_shutdown_function(\Swoole\Event::shutdown(...));
}
