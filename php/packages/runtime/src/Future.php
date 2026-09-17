<?php

declare(strict_types=1);

namespace Ignis;

final class Future
{
    private bool $done = false;
    private mixed $value = null;
    private ?\Throwable $error = null;
    /** @var list<\Fiber<mixed,mixed,mixed,mixed>> */
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
        if ($e !== null && $this->waiters === []) {
            // Nobody is waiting: remember it so the loop can report it (an await() later un-registers it).
            Loop::$unobserved[] = $e;
            $this->unobservedError = $e;
        }
        foreach ($this->waiters as $fiber) {
            Loop::markReady($fiber, null);
        }
        $this->waiters = [];
    }

    private ?\Throwable $unobservedError = null;

    /** Suspends the current fiber until settled; rethrows on rejection. */
    public function await(): mixed
    {
        if (!$this->done) {
            $fiber = \Fiber::getCurrent();
            if ($fiber === null) {
                $this->driveLoopUntilSettled();
            } else {
                $this->waiters[] = $fiber;
                \Fiber::suspend();
            }
        }
        if ($this->error !== null) {
            if ($this->unobservedError !== null) {
                $k = array_search($this->unobservedError, Loop::$unobserved, true);
                if ($k !== false) {
                    unset(Loop::$unobserved[$k]);
                    Loop::$unobserved = array_values(Loop::$unobserved);
                }
                $this->unobservedError = null;
            }
            throw $this->error;
        }
        return $this->value;
    }

    /**
     * {main} has no fiber to park, so it drives the loop instead. A loop that goes idle — nothing
     * in flight, nothing waiting — with this future still unsettled means a fiber is stuck on
     * something the loop does not know about; say so instead of returning null.
     */
    private function driveLoopUntilSettled(): void
    {
        Loop::runUntil(fn() => $this->done);
        if (!$this->done) {
            throw new \LogicException('Ignis\Future::await(): the loop stopped with this future unsettled (a fiber is parked on an op the loop never completes)');
        }
    }
}
