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

    public function reject(\Throwable $exception): void
    {
        $this->settle(null, $exception);
    }

    private function settle(mixed $value, ?\Throwable $exception): void
    {
        if ($this->done) {
            throw new \LogicException('Future already settled');
        }
        $this->done = true;
        $this->value = $value;
        $this->error = $exception;
        if ($exception !== null && $this->waiters === []) {
            $this->rememberUnobserved($exception);
        }
        foreach ($this->waiters as $fiber) {
            Loop::markReady($fiber, null);
        }
        $this->waiters = [];
    }

    private ?\Throwable $unobservedError = null;

    /** Nobody is waiting yet, so the loop holds the rejection until someone does (V-22). */
    private function rememberUnobserved(\Throwable $exception): void
    {
        Loop::$unobserved[] = $exception;
        $this->unobservedError = $exception;
    }

    /** Awaiting a rejection is observing it, so it leaves the loop's report. */
    private function observeError(): void
    {
        $error = $this->unobservedError;
        if ($error === null) {
            return;
        }
        $this->unobservedError = null;
        Loop::$unobserved = array_values(array_filter(Loop::$unobserved, static fn(\Throwable $exception): bool => $exception !== $error));
    }

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
        $error = $this->error;
        if ($error !== null) {
            $this->observeError();
            throw $error;
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
