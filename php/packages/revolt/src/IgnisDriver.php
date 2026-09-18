<?php

/**
 * Revolt event-loop driver over the Ignis reactor (ADR-0008, E7).
 *
 * Select it with REVOLT_DRIVER=Ignis\Revolt\IgnisDriver — no application change.
 * dispatch() blocks in ignis_poll(); readiness on real fds comes from
 * ignis_watch() (tokio AsyncFd), timers from Revolt's own TimerQueue.
 *
 * **It is the thread's only scheduler, or it is a hang.** `ignis_poll()` drains the completion
 * channel, so this driver and `Ignis\Loop` cannot both run on one PHP thread: whichever polls first
 * takes the other's completions and the fibers waiting on them never wake. That was true from the
 * first version and said nowhere; the constructor refuses now instead. An AMPHP application is the
 * supported shape — the driver drives, `Ignis\serve()` is not used — and a request handler that
 * wants AMPHP inside `Ignis\serve()` is not something this driver can give.
 */
declare(strict_types=1);

namespace Ignis\Revolt;

use Revolt\EventLoop\Internal\AbstractDriver;
use Revolt\EventLoop\Internal\DriverCallback;
use Revolt\EventLoop\Internal\SignalCallback;
use Revolt\EventLoop\Internal\StreamReadableCallback;
use Revolt\EventLoop\Internal\StreamWritableCallback;
use Revolt\EventLoop\Internal\TimerCallback;
use Revolt\EventLoop\Internal\TimerQueue;
use Revolt\EventLoop\UnsupportedFeatureException;

final class IgnisDriver extends AbstractDriver
{
    private readonly TimerQueue $timerQueue;
    /** @var array<string, StreamReadableCallback|StreamWritableCallback> enabled stream callbacks by id */
    private array $streamCallbacks = [];
    /** @var array<int, string> pending watch op id => callback id */
    private array $pendingWatch = [];
    /** @var array<string, int> callback id => pending watch op id */
    private array $watchOf = [];

    public function __construct()
    {
        if (!\function_exists('ignis_poll')) {
            throw new UnsupportedFeatureException('IgnisDriver requires the ignis runtime (ignis_poll)');
        }
        if (\class_exists(\Ignis\Loop::class, false) && \Ignis\Loop::isRunning()) {
            throw new \LogicException(
                'IgnisDriver and Ignis\Loop cannot both drive one PHP thread: ignis_poll() drains the '
                . 'completion channel, so whichever polls first takes the other\'s completions and its '
                . 'fibers never wake. Run an AMPHP application on this driver, or Ignis\serve() on the loop.',
            );
        }
        parent::__construct();
        $this->timerQueue = new TimerQueue();
    }

    public function getHandle(): mixed
    {
        return null;
    }

    /**
     * Signals are not supported yet, and Revolt's contract is that saying so happens here.
     *
     * Its own DriverTest::checkForSignalCapability() calls onSignal() and skips the signal tests
     * when this throws; leaving the throw in activate() let that probe pass and turned five skips
     * into five errors inside run() the moment ext-posix appeared in the build.
     */
    public function onSignal(int $signal, \Closure $closure): string
    {
        throw new UnsupportedFeatureException('Signals are not supported by IgnisDriver yet');
    }

    protected function now(): float
    {
        return (float) \hrtime(true) / 1_000_000_000;
    }

    /** @param array<string, DriverCallback> $callbacks */
    protected function activate(array $callbacks): void
    {
        foreach ($callbacks as $callback) {
            if ($callback instanceof StreamReadableCallback || $callback instanceof StreamWritableCallback) {
                $this->streamCallbacks[$callback->id] = $callback;
                $this->arm($callback);
            } elseif ($callback instanceof TimerCallback) {
                $this->timerQueue->insert($callback);
            } elseif ($callback instanceof SignalCallback) {
                throw new UnsupportedFeatureException('Signals are not supported by IgnisDriver yet');
            } else {
                throw new \Error('Unknown callback type');
            }
        }
    }

    protected function deactivate(DriverCallback $callback): void
    {
        if ($callback instanceof StreamReadableCallback || $callback instanceof StreamWritableCallback) {
            unset($this->streamCallbacks[$callback->id]);
            if (isset($this->watchOf[$callback->id])) {
                // Cancel the one-shot watch on the reactor (closes its dup'd fd); a late completion is ignored.
                $op = $this->watchOf[$callback->id];
                unset($this->pendingWatch[$op], $this->watchOf[$callback->id]);
                if (\function_exists('ignis_cancel')) {
                    \ignis_cancel($op);
                }
            }
        } elseif ($callback instanceof TimerCallback) {
            $this->timerQueue->remove($callback);
        }
    }

    private function arm(StreamReadableCallback|StreamWritableCallback $callback): void
    {
        if (isset($this->watchOf[$callback->id]) || !\is_resource($callback->stream)) {
            return;
        }
        $id = \ignis_watch($callback->stream, $callback instanceof StreamWritableCallback ? 2 : 1);
        $this->pendingWatch[$id] = $callback->id;
        $this->watchOf[$callback->id] = $id;
    }

    protected function dispatch(bool $blocking): void
    {
        // Re-arm every enabled stream callback that has no pending watch (one-shot watches).
        foreach ($this->streamCallbacks as $callback) {
            $this->arm($callback);
        }

        $timeoutMs = 0;
        if ($blocking) {
            $expiration = $this->timerQueue->peek();
            $timeoutMs = $expiration === null ? -1 : (int) \max(0, \ceil(($expiration - $this->now()) * 1000));
        }
        if ($timeoutMs !== 0 || $this->pendingWatch !== []) {
            $events = \ignis_poll($timeoutMs);
            foreach ($events as $opId => $payload) {
                $callbackId = $this->pendingWatch[$opId] ?? null;
                if ($callbackId === null) {
                    continue; // deactivated meanwhile, or not ours
                }
                unset($this->pendingWatch[$opId], $this->watchOf[$callbackId]);
                $callback = $this->streamCallbacks[$callbackId] ?? null;
                if ($callback !== null) {
                    $this->enqueueCallback($callback);
                }
            }
        }

        $now = $this->now();
        while ($callback = $this->timerQueue->extract($now)) {
            $this->enqueueCallback($callback);
        }
    }
}
