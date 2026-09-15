<?php
/**
 * Ignis userland scheduler (Cycle 0).
 *
 * The Rust side exposes three primitives: ignis_submit_sleep(int $ms): int,
 * ignis_poll(int $timeout_ms): array<int,int>, ignis_inflight(): int.
 * Everything else — fibers, futures, all() — lives here, shaped so that it
 * can become a Revolt driver (activate/dispatch/deactivate/now) later.
 */
declare(strict_types=1);

namespace Ignis;

final class Future
{
    private bool $done = false;
    private mixed $value = null;
    private ?\Throwable $error = null;
    /** @var list<\Fiber> */
    private array $waiters = [];

    public function isDone(): bool
    {
        return $this->done;
    }

    public function resolve(mixed $value): void
    {
        $this->settle($value, null);
    }

    public function reject(\Throwable $e): void
    {
        $this->settle(null, $e);
    }

    private function settle(mixed $value, ?\Throwable $e): void
    {
        if ($this->done) {
            throw new \LogicException('Future already settled');
        }
        $this->done = true;
        $this->value = $value;
        $this->error = $e;
        foreach ($this->waiters as $fiber) {
            Loop::markReady($fiber, $this);
        }
        $this->waiters = [];
    }

    /** Suspends the current fiber until settled; rethrows on rejection. */
    public function await(): mixed
    {
        if (!$this->done) {
            $fiber = \Fiber::getCurrent();
            if ($fiber === null) {
                Loop::runUntil(fn () => $this->done);
            } else {
                $this->waiters[] = $fiber;
                \Fiber::suspend();
            }
        }
        if ($this->error !== null) {
            throw $this->error;
        }
        return $this->value;
    }
}

final class Loop
{
    /** @var array<int,\Fiber> op id => fiber waiting for it */
    private static array $waiting = [];
    /** @var list<array{0:\Fiber,1:mixed}> fibers to (re)start with a value */
    private static array $ready = [];
    /** @var list<\Fiber> fibers created but not yet started */
    private static array $pending = [];
    private static bool $running = false;
    public static int $resumes = 0;
    /** Nanoseconds spent in each phase (for VALIDATION.md; cheap: one hrtime per batch). */
    public static array $phaseNs = ['start' => 0, 'ready' => 0, 'poll' => 0, 'resume' => 0];

    /** Suspend the current fiber until reactor op $id completes; returns its payload. */
    public static function awaitOp(int $id): mixed
    {
        $fiber = \Fiber::getCurrent();
        if ($fiber === null) {
            // Called from {main}: drive the loop until this op is done.
            $result = null;
            $done = false;
            self::$waiting[$id] = new \Fiber(function () use (&$result, &$done) {
                $result = \Fiber::suspend();
                $done = true;
            });
            self::$waiting[$id]->start();
            self::runUntil(fn () => $done);
            return $result;
        }
        self::$waiting[$id] = $fiber;
        return \Fiber::suspend();
    }

    /** @internal */
    public static function markReady(\Fiber $fiber, mixed $value): void
    {
        self::$ready[] = [$fiber, $value];
    }

    /** Start $fn in a new fiber on the next loop turn. */
    public static function spawn(callable $fn, mixed ...$args): Future
    {
        $future = new Future();
        self::$pending[] = new \Fiber(static function () use ($fn, $args, $future): void {
            try {
                $future->resolve($fn(...$args));
            } catch (\Throwable $e) {
                $future->reject($e);
            }
        });
        return $future;
    }

    /** Run until no fiber is waiting on anything. */
    public static function run(): void
    {
        self::runUntil(static fn () => false);
    }

    /** @param callable():bool $stop */
    public static function runUntil(callable $stop): void
    {
        if (self::$running) {
            throw new \LogicException('Loop already running');
        }
        self::$running = true;
        try {
            while (!$stop()) {
                // 1. start new fibers
                while (self::$pending !== []) {
                    $batch = self::$pending;
                    self::$pending = [];
                    $t = hrtime(true);
                    foreach ($batch as $fiber) {
                        ++self::$resumes;
                        $fiber->start();
                    }
                    self::$phaseNs['start'] += hrtime(true) - $t;
                }
                // 2. resume fibers whose future settled
                while (self::$ready !== []) {
                    $batch = self::$ready;
                    self::$ready = [];
                    $t = hrtime(true);
                    foreach ($batch as [$fiber, $value]) {
                        ++self::$resumes;
                        $fiber->resume($value);
                    }
                    self::$phaseNs['ready'] += hrtime(true) - $t;
                }
                if (self::$pending !== []) {
                    continue;
                }
                // 3. nothing runnable: block on the reactor
                if (self::$waiting === []) {
                    if (self::$ready === [] && self::$pending === []) {
                        break;
                    }
                    continue;
                }
                $t = hrtime(true);
                $events = \ignis_poll(-1);
                $t2 = hrtime(true);
                self::$phaseNs['poll'] += $t2 - $t;
                foreach ($events as $id => $payload) {
                    $fiber = self::$waiting[$id] ?? null;
                    if ($fiber === null) {
                        continue; // cancelled
                    }
                    unset(self::$waiting[$id]);
                    ++self::$resumes;
                    $fiber->resume($payload);
                }
                self::$phaseNs['resume'] += hrtime(true) - $t2;
            }
        } finally {
            self::$running = false;
        }
    }
}

/** Non-blocking sleep: the fiber suspends, tokio owns the timer. */
function sleep(int $ms): void
{
    Loop::awaitOp(\ignis_submit_sleep($ms));
}

/** Run $fn concurrently in a fiber. */
function async(callable $fn, mixed ...$args): Future
{
    return Loop::spawn($fn, ...$args);
}

/**
 * Await all futures; returns their values in the same order.
 * @param iterable<Future> $futures
 */
function all(iterable $futures): array
{
    $out = [];
    foreach ($futures as $k => $f) {
        $out[$k] = $f->await();
    }
    return $out;
}
