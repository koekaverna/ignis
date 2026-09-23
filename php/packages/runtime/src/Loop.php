<?php

declare(strict_types=1);

namespace Ignis;

/**
 * @phpstan-type Job array{0: callable, 1: array<array-key, mixed>, 2: Future, 3: int|null}
 */
final class Loop
{
    /** @var array<int,\Fiber<mixed,mixed,mixed,mixed>> request id => fiber running the handler */
    private static array $requestFibers = [];
    /** @var array<int,array<int,\Fiber<mixed,mixed,mixed,mixed>>> request id => the child fibers it spawned, keyed by object id so a finished one leaves in O(1). */
    private static array $children = [];
    /** @var array<int,int> op id => request id for deadline timers */
    private static array $deadlines = [];
    /** @var array<int,int> request id => the deadline op it armed, so the timer can be called off */
    private static array $deadlineOf = [];
    /** @var array<int,int> request id => the L0 fiber-timeout milliseconds armFiberTimeout() armed for it (ADR-0043 §7, L0). */
    private static array $fiberTimeoutMilliseconds = [];
    /** @var array<int,true> ids sitting in the request queue, so a cancel for anything else is not remembered */
    private static array $queued = [];
    /** @var array<int,int> fiber object id => op id it is parked on (userland parks) */
    private static array $parkedOn = [];
    public static int $cancelled = 0;
    public static int $cancelAgeUsMax = 0;
    public static int $cancelLatencyUsMax = 0;
    /** @var array<int,\Fiber<mixed,mixed,mixed,mixed>> op id => fiber waiting for it */
    private static array $waiting = [];
    /** @var list<array{0:\Fiber<mixed,mixed,mixed,mixed>,1:mixed}> fibers to resume with a value */
    private static array $ready = [];
    /** @var list<array{0:\Fiber<mixed,mixed,mixed,mixed>,1:Job}> new pool fibers to start with a job */
    private static array $pending = [];
    /** @var list<\Fiber<mixed,mixed,mixed,mixed>> parked pool fibers */
    private static array $idle = [];
    /** @var array<int,\Fiber<mixed,mixed,mixed,mixed>> fiber object id => fiber to force-close at its next park (ADR-0043 L2) */
    private static array $killPending = [];
    /** @var array<int,array{0:\Fiber<mixed,mixed,mixed,mixed>,1:int}> fiber object id => [fiber, request id], the `log` mode's watch */
    private static array $logSwallowedPending = [];
    /** IGNIS_ON_SWALLOWED_CANCEL (ADR-0043 §8): force-close a fiber that parks again after a cancellation, or only log it. */
    private static bool $forceCloseOnSwallowedCancel = true;
    private static bool $running = false;
    /** @var null|callable(Http\Request):mixed the return is checked at run time, since PHP does not */
    private static $requestHandler = null;
    public static int $resumes = 0;
    public static int $fibersCreated = 0;
    /** B1 admission control (ADR-0019): max fibers holding a request; 0 = unlimited, the default. */
    public static int $fiberBudget = 0;
    public static int $queueDepth = 0;
    public static int $inflightRequests = 0;
    /** Requests this loop has finished answering (M4-4). */
    public static int $handled = 0;
    public static int $queuedPeak = 0;
    public static int $rejected = 0;
    public static int $admittedAfterQueue = 0;
    /** @var list<string> path prefixes admitted regardless of the budget (IGNIS_BUDGET_EXEMPT). */
    private static array $budgetExempt = [];
    /** @var list<array{int, IgnisRequest}> FIFO of requests waiting for a slot; read through $queueHead. */
    private static array $requestQueue = [];
    private static int $queueHead = 0;
    /** @var array<int, true> ids whose client went away while queued. */
    private static array $queueCancelled = [];
    /** @var array{start: int, ready: int, poll: int, resume: int} Nanoseconds spent in each phase (for VALIDATION.md; cheap: one hrtime per batch). */
    public static array $phaseNs = ['start' => 0, 'ready' => 0, 'poll' => 0, 'resume' => 0];

    /** Suspend the current fiber until reactor op $id completes; returns its payload. */
    public static function awaitOp(int $id): mixed
    {
        $fiber = \Fiber::getCurrent();
        if ($fiber === null) {
            return self::awaitOpFromMain($id);
        }
        self::boot();
        $payload = self::parkOn($fiber, $id);
        if (Chaos::$on && Chaos::fires()) {
            self::parkOn($fiber, \ignis_submit_sleep(0));
        }
        return $payload;
    }

    /**
     * Registers $fiber against $op and hands the thread to the loop; unregisters however the park ends.
     * @param \Fiber<mixed,mixed,mixed,mixed> $fiber
     */
    private static function parkOn(\Fiber $fiber, int $op): mixed
    {
        self::$waiting[$op] = $fiber;
        self::$parkedOn[\spl_object_id($fiber)] = $op;
        try {
            return \Fiber::suspend();
        } finally {
            unset(self::$parkedOn[\spl_object_id($fiber)]);
        }
    }

    /** {main} has no fiber to park, so park a throwaway one on the op and drive the loop. */
    private static function awaitOpFromMain(int $id): mixed
    {
        $result = null;
        $done = false;
        $fiber = new \Fiber(function () use (&$result, &$done): void {
            $result = \Fiber::suspend();
            $done = true;
        });
        $fiber->start();
        self::$waiting[$id] = $fiber;
        self::runUntil(static fn(): bool => $done);
        return $result;
    }

    /**
     * @internal
     * @param \Fiber<mixed,mixed,mixed,mixed> $fiber
     */
    public static function markReady(\Fiber $fiber, mixed $value): void
    {
        self::$ready[] = [$fiber, $value];
    }

    /**
     * Body of a pooled fiber: runs jobs forever, parking between them.
     * @param Job $job
     */
    private static function poolBody(array $job): never
    {
        $self = self::currentFiber();
        for (;;) {
            [$function, $arguments, $future, $requestId] = $job;
            \ignis_fiber_request($requestId ?? 0);
            try {
                $future->resolve($function(...$arguments));
            } catch (\Throwable $e) {
                $future->reject($e);
            } finally {
                self::rejectIfForceClosedMidJob($future);
            }
            self::forgetChild($requestId, $self);
            self::$idle[] = $self;
            $job = self::nextJob();
        }
    }

    /** ADR-0043 §7, L2/L3: a graceful force-close unwinds through `finally`, never `catch`, leaving the Future unsettled unless this rejects it. */
    private static function rejectIfForceClosedMidJob(Future $future): void
    {
        if (!$future->isDone()) {
            $future->reject(new KilledException('fiber force-closed'));
        }
    }

    /**
     * Drops a finished child from the request that spawned it, before the pool hands the fiber out.
     * @param \Fiber<mixed,mixed,mixed,mixed> $fiber
     */
    private static function forgetChild(?int $requestId, \Fiber $fiber): void
    {
        if ($requestId === null) {
            return;
        }
        unset(self::$children[$requestId][\spl_object_id($fiber)]);
    }

    /**
     * The Job resumeReady() handed this pool fiber; that guarantee lives outside the type system.
     * @return Job
     */
    private static function nextJob(): array
    {
        $job = \Fiber::suspend();
        if (!\is_array($job) || !\array_key_exists(0, $job) || !\array_key_exists(1, $job) || !\array_key_exists(2, $job)
            || !\array_key_exists(3, $job) || !\is_callable($job[0]) || !\is_array($job[1]) || !$job[2] instanceof Future
            || !(\is_int($job[3]) || $job[3] === null)) {
            throw new \LogicException('Ignis\\Loop: a pool fiber was resumed with something other than a Job');
        }

        return [$job[0], $job[1], $job[2], $job[3]];
    }

    /** Run $function concurrently; a parked pool fiber is reused when available. */
    public static function spawn(callable $function, mixed ...$arguments): Future
    {
        $future = new Future();
        $requestId = self::currentRequestId();
        if ($requestId !== null) {
            $function = self::attributedToRequest($function, $arguments, $requestId);
            $arguments = [];
        }
        $job = [$function, $arguments, $future, $requestId];
        $fiber = array_pop(self::$idle);
        if ($fiber !== null) {
            self::$ready[] = [$fiber, $job];
        } else {
            ++self::$fibersCreated;
            $fiber = new \Fiber(self::poolBody(...));
            self::$pending[] = [$fiber, $job];
        }
        if ($requestId !== null) {
            self::$children[$requestId][\spl_object_id($fiber)] = $fiber;
        }
        return $future;
    }

    /**
     * The fiber the current job or request runs on, never the main stack.
     * @return \Fiber<mixed,mixed,mixed,mixed>
     */
    private static function currentFiber(): \Fiber
    {
        $fiber = \Fiber::getCurrent();
        if ($fiber === null) {
            throw new \LogicException('Ignis\\Loop: this runs inside a fiber, never on the main stack');
        }
        return $fiber;
    }

    /** Scope is a generic key-value bag, but this key only ever holds the request id it was set to. */
    private static function currentRequestId(): ?int
    {
        $requestId = Scope::get('ignis.request');
        if ($requestId !== null && !\is_int($requestId)) {
            throw new \LogicException('Ignis\\Loop: the ignis.request scope holds something other than an int');
        }

        return $requestId;
    }

    /**
     * The request id is fiber-scoped, so a child fiber has to be handed it explicitly (E11).
     * @param array<array-key, mixed> $arguments named arguments keep their names through the spread
     */
    private static function attributedToRequest(callable $function, array $arguments, mixed $requestId): \Closure
    {
        return static function () use ($function, $requestId, $arguments) {
            Scope::set('ignis.request', $requestId);
            return $function(...$arguments);
        };
    }

    public static function idleFibers(): int
    {
        return \count(self::$idle);
    }

    /** True while this thread's loop is the one draining `ignis_poll()`. */
    public static function isRunning(): bool
    {
        return self::$running;
    }

    /** Run until no fiber is waiting on anything (and no server is listening). */
    public static function run(): void
    {
        self::runUntil(static fn() => false);
    }

    /** @param callable():bool $stop */
    public static function runUntil(callable $stop): void
    {
        if (self::$running) {
            throw new \LogicException('Loop already running');
        }
        self::$running = true;
        self::boot();
        try {
            while (!$stop()) {
                self::startPending();
                self::resumeReady();
                self::forceClosePending();
                if (self::$pending !== []) {
                    continue;
                }
                if (self::$watching && !self::$stopping && \ignis_watch_generation() > self::$watchGeneration
                    && \ignis_watch_begin_reload()) {
                    self::$stopping = true;      // one worker goes down at a time; the rest keep serving
                }
                if (self::windingDown()) {
                    break;
                }
                if (self::isIdle()) {
                    break;
                }
                self::collectGarbage();
                self::publishStats();
                $events = self::poll();
                if ($events === [] && self::isIdle()) {
                    break;
                }
                self::dispatchEvents($events);
            }
        } finally {
            self::$running = false;
        }
        self::reportUnobserved();
    }

    /** Every one-time read of the environment, so no path can reach the loop with half of them done. */
    private static function boot(): void
    {
        if (self::$booted) {
            return;
        }
        self::$booted = true;
        Chaos::init();
        self::gcInit();
        self::budgetInit();
        self::$forceCloseOnSwallowedCancel = \trim(Env::text('IGNIS_ON_SWALLOWED_CANCEL', 'force-close')) !== 'log';
        // boot() runs on the first turn of every mode, including classic `listen()`, which never
        // calls serve() and would otherwise watch nothing at all.
        self::$watching = Env::flag('IGNIS_WATCH');
        self::$watchGeneration = self::$watching ? \ignis_watch_generation() : 0;
        if (self::$watching) {
            \ignis_watch_end_reload();   // this incarnation is up; whoever is waiting to reload may go
        }
        self::watchLoadedFiles();
    }

    /** Starts the fibers spawn() created since the last turn; starting one can create more. */
    private static function startPending(): void
    {
        while (self::$pending !== []) {
            $batch = self::$pending;
            self::$pending = [];
            $phaseStart = hrtime(true);
            foreach ($batch as [$fiber, $job]) {
                ++self::$resumes;
                $fiber->start($job);
            }
            self::$phaseNs['start'] += hrtime(true) - $phaseStart;
        }
    }

    /** Resumes what became runnable: a settled future, or a parked pool fiber given a new job. */
    private static function resumeReady(): void
    {
        while (self::$ready !== []) {
            $batch = self::$ready;
            self::$ready = [];
            if (Chaos::$on) {
                $batch = Chaos::shuffled($batch);
            }
            $phaseStart = hrtime(true);
            foreach ($batch as [$fiber, $value]) {
                ++self::$resumes;
                $fiber->resume($value);
            }
            self::$phaseNs['ready'] += hrtime(true) - $phaseStart;
        }
    }

    /** Nothing left for this loop to do: no ready, pending, parked or in-flight work. */
    private static function isIdle(): bool
    {
        return self::$waiting === []
            && self::$requestHandler === null
            && self::$rawRequestHandler === null
            && self::$ready === []
            && self::$pending === []
            && \ignis_inflight() === 0;
    }

    /** E2'/E13': one collection at an idle point, never mid-request; every 256th poll, because gc_status() allocates. */
    private static function collectGarbage(): void
    {
        if (self::$loopGc && (++self::$gcTick & 255) === 0 && gc_status()['roots'] >= self::$gcRoots) {
            gc_collect_cycles();
            ++self::$gcRuns;
        }
    }

    /** M4-4: hands this loop's counters to the runtime for /_ignis/metrics. */
    private static function publishStats(): void
    {
        \ignis_publish_stats([
            'budget' => self::$fiberBudget,
            'queue_depth' => self::$queueDepth,
            'queued' => \count(self::$requestQueue) - self::$queueHead,
            'queued_peak' => self::$queuedPeak,
            'queued_admitted' => self::$admittedAfterQueue,
            'rejected' => self::$rejected,
            'fibers_idle' => \count(self::$idle),
            'fibers_created' => self::$fibersCreated,
            'resumes' => self::$resumes,
            'handled' => self::$handled,
        ]);
    }

    /**
     * The loop's single wait point: blocks on the reactor until something completes.
     * @return array<int, mixed> op id => payload
     */
    private static function poll(): array
    {
        $phaseStart = hrtime(true);
        $events = \ignis_poll(-1);
        self::$phaseNs['poll'] += hrtime(true) - $phaseStart;
        return $events;
    }

    /** @param array<int, mixed> $events */
    private static function dispatchEvents(array $events): void
    {
        $phaseStart = hrtime(true);
        if (Chaos::$on && \count($events) > 1) {
            $events = Chaos::shuffled($events);
        }
        foreach ($events as $id => $payload) {
            if (\is_array($payload) && !isset(self::$waiting[$id])) {
                self::dispatchUnawaited($id, $payload);
                continue;
            }
            if (isset(self::$deadlines[$id])) {
                $requestId = self::$deadlines[$id];
                unset(self::$deadlines[$id], self::$deadlineOf[$requestId]);
                $fiberTimeoutMilliseconds = self::$fiberTimeoutMilliseconds[$requestId] ?? null;
                unset(self::$fiberTimeoutMilliseconds[$requestId]);
                $message = $fiberTimeoutMilliseconds === null
                    ? 'deadline exceeded'
                    : self::fiberTimeoutMessage($fiberTimeoutMilliseconds, $requestId);
                self::cancelRequest($requestId, new DeadlineExceededException($message), 0);
                continue;
            }
            $fiber = self::$waiting[$id] ?? null;
            if ($fiber === null) {
                continue;
            }
            unset(self::$waiting[$id]);
            ++self::$resumes;
            $fiber->resume($payload);
        }
        self::$phaseNs['resume'] += hrtime(true) - $phaseStart;
    }

    /**
     * A completion no fiber waits for: a cancelled request or a new one, as the reactor's tagged union.
     * @param array<array-key, mixed> $payload
     */
    private static function dispatchUnawaited(int $id, array $payload): void
    {
        if (($payload['method'] ?? null) !== null) {
            self::dispatchRequest($id, self::asIgnisRequest($payload));
            return;
        }
        if (($payload['kind'] ?? null) === 'cancel') {
            self::cancelRequest($id, new CancelledException('client disconnected'), self::asAgeUs($payload));
        }
    }

    /** @param array<array-key, mixed> $payload */
    private static function asAgeUs(array $payload): int
    {
        $ageUs = $payload['age_us'] ?? null;
        if (!\is_int($ageUs)) {
            throw new \UnexpectedValueException('Ignis\\Loop: a cancel completion is missing an int age_us');
        }

        return $ageUs;
    }

    /**
     * The request handed over by the loop is data off the reactor, not a fact about its shape.
     * @param array<array-key, mixed> $payload
     * @return IgnisRequest
     */
    private static function asIgnisRequest(array $payload): array
    {
        return Http\Request::validateRaw(
            $payload,
            'Ignis\\Loop: a malformed request completion',
            'Ignis\\Loop: a malformed request completion header',
        );
    }


    /** A fiber failed and nobody awaited its Future: surface it instead of losing it (E15c fix). */
    private static function reportUnobserved(): void
    {
        $errors = self::$unobserved;
        self::$unobserved = [];
        if ($errors === []) {
            return;
        }
        foreach (array_slice($errors, 1) as $alsoUnobserved) {
            self::logFailure('a further unobserved rejection, not the one rethrown', $alsoUnobserved);
        }
        throw $errors[0];
    }

    /** @var list<\Throwable> rejected futures nobody has awaited (reported when the loop stops) */
    public static array $unobserved = [];

    private static bool $booted = false;

    /** Loop-scheduled GC (E2'/E13', ADR-0034): see IGNIS_LOOP_GC in docs/reference/configuration.md. */
    public static int $gcRoots = 5000;
    public static int $gcRuns = 0;
    private static int $gcTick = 0;
    private static bool $loopGc = false;

    /** Reads the budget from the environment once; `IGNIS_FIBER_BUDGET=0` (default) means unlimited. */
    private static function budgetInit(): void
    {
        self::$fiberBudget = Env::integer('IGNIS_FIBER_BUDGET', self::$fiberBudget);
        self::$queueDepth = Env::integer('IGNIS_QUEUE_DEPTH', self::$queueDepth);
        self::$budgetExempt = Env::commaList('IGNIS_BUDGET_EXEMPT', self::$budgetExempt);
    }

    /**
     * Counters for /stats and for VALIDATION: see ADR-0019.
     * @return array<string, int>
     */
    public static function budgetStats(): array
    {
        return [
            'budget' => self::$fiberBudget,
            'queue_depth' => self::$queueDepth,
            'inflight' => self::$inflightRequests,
            'queued' => \count(self::$requestQueue) - self::$queueHead,
            'queued_peak' => self::$queuedPeak,
            'queued_admitted' => self::$admittedAfterQueue,
            'rejected' => self::$rejected,
        ];
    }

    private static function gcInit(): void
    {
        self::$loopGc = Env::flag('IGNIS_LOOP_GC', true);
        self::$gcRoots = Env::integer('IGNIS_LOOP_GC_ROOTS', self::$gcRoots, 100);
        if (self::$loopGc) {
            gc_disable();
        }
    }

    /** @param callable(Http\Request):?Http\Response $handler */
    public static function serve(callable $handler, string $address): void
    {
        \ignis_serve($address);
        self::$requestHandler = $handler;
        self::watchLoadedFiles();
        self::run();
    }

    /** Stops serving: no new requests, the ones in flight finish, `serve()` returns. */
    public static function stop(): void
    {
        self::$stopping = true;
    }

    private static bool $watching = false;
    /** The number of settled changes this thread booted with; it reloads when the runtime's is higher. */
    private static int $watchGeneration = 0;
    private static bool $stopping = false;
    private static bool $leftDispatch = false;
    /** @var array<string, true> files already handed to the watcher, so each turn sends a delta */
    private static array $watched = [];

    /** `Ignis\Classic` drives the loop itself, so it reports what its per-request include added. */
    public static function reportLoadedFiles(): void
    {
        self::watchLoadedFiles();
    }

    /** Development reload: watches the files PHP has loaded, a delta after every request (research 40). */
    private static function watchLoadedFiles(): void
    {
        if (!self::$watching) {
            return;
        }
        $new = [];
        foreach (\get_included_files() as $file) {
            if (!isset(self::$watched[$file])) {
                self::$watched[$file] = true;
                $new[] = $file;
            }
        }
        if ($new !== []) {
            \ignis_watch_files($new);
        }
    }

    /** Begins a graceful stop the first time it is asked for, then waits for everything in flight. */
    private static function windingDown(): bool
    {
        if (!self::$stopping) {
            return false;
        }
        if (!self::$leftDispatch) {
            self::$leftDispatch = true;
            \ignis_stop_accepting();
        }

        return \ignis_inflight() === 0          // the runtime's count, which includes a request delivered
            && self::$inflightRequests === 0    // to this thread but not yet drained by ignis_poll()
            && self::$waiting === []            // and a fiber parked on an op is still work in flight
            && self::$ready === []
            && self::$pending === [];
    }

    /** @var null|callable(int,IgnisRequest):void classic mode's handler, set by `Ignis\Classic\listen()` (V-53) */
    public static $rawRequestHandler = null;

    /**
     * B1 (ADR-0019): admit, queue, or shed; a queued request is data, not a fiber (V-37).
     * @param IgnisRequest $raw
     */
    private static function dispatchRequest(int $id, array $raw): void
    {
        if (self::$rawRequestHandler !== null) {
            (self::$rawRequestHandler)($id, $raw);
            return;
        }
        if (self::$fiberBudget > 0 && self::$inflightRequests >= self::$fiberBudget
            && !self::isExempt($raw['uri'])) {
            self::queueRequest($id, $raw);
            return;
        }
        self::admitRequest($id, $raw);
    }

    /** @param IgnisRequest $raw */
    private static function queueRequest(int $id, array $raw): void
    {
        $queued = \count(self::$requestQueue) - self::$queueHead;
        if (self::$queueDepth > 0 && $queued >= self::$queueDepth) {
            ++self::$rejected;
            self::respondTo($id, 503, ['retry-after' => '1'], "503 busy\n");
            return;
        }
        self::$requestQueue[] = [$id, $raw];
        self::$queued[$id] = true;
        if ($queued + 1 > self::$queuedPeak) {
            self::$queuedPeak = $queued + 1;
        }
    }

    /** A saturated server is when its metrics matter most, so health and stats must not queue behind the load they report. */
    private static function isExempt(string $uri): bool
    {
        foreach (self::$budgetExempt as $prefix) {
            if (str_starts_with($uri, $prefix)) {
                return true;
            }
        }
        return false;
    }

    /** Takes waiting requests while there is room, O(1) per request. */
    private static function drainQueue(): void
    {
        while (self::$queueHead < \count(self::$requestQueue)
            && (self::$fiberBudget <= 0 || self::$inflightRequests < self::$fiberBudget)) {
            [$id, $raw] = self::$requestQueue[self::$queueHead];
            self::$requestQueue[self::$queueHead] = [$id, ['method' => '', 'uri' => '', 'headers' => [], 'body' => '']];
            ++self::$queueHead;
            unset(self::$queued[$id]);
            if (isset(self::$queueCancelled[$id])) {
                unset(self::$queueCancelled[$id]);
                continue;
            }
            ++self::$admittedAfterQueue;
            self::admitRequest($id, $raw);
        }
        if (self::$queueHead > 0 && self::$queueHead === \count(self::$requestQueue)) {
            self::$requestQueue = [];
            self::$queueHead = 0;
        }
    }

    /** @param IgnisRequest $raw */
    private static function admitRequest(int $id, array $raw): void
    {
        ++self::$inflightRequests;
        $handler = self::$requestHandler;
        $request = new Http\Request(
            $raw['method'],
            $raw['uri'],
            $raw['headers'],
            $raw['body'],
            $id,
        );
        self::spawn(static function () use ($handler, $request, $id): void {
            $answered = false;
            try {
                self::answer($id, self::runHandler($handler, $request, $id));
                $answered = true;
            } catch (\Throwable $e) {
                self::answerFailed($id, $e);
                $answered = true;
            } finally {
                if (!$answered) {
                    self::answerKilled($id);
                }
                self::releaseRequest($id);
            }
        });
    }

    /** Answers 500 when sending the answer itself failed, so no request is left unanswered. */
    private static function answerFailed(int $id, \Throwable $exception): void
    {
        self::logFailure('answering the request failed', $exception);
        try {
            self::respondTo($id, 500, ['content-type' => 'text/plain'], '500 ' . $exception::class . ': ' . $exception->getMessage() . "\n");
        } catch (\Throwable $second) {
            self::logFailure('and so did answering it with a 500', $second);
        }
    }

    /** ADR-0043 L2/L3: the 504 for a fiber force-closed before it answered, sent without a park. */
    private static function answerKilled(int $id): void
    {
        \ignis_respond($id, 504, ['content-type' => 'text/plain'], "504 fiber killed\n");
    }

    /**
     * Answers $id and logs a refused answer, because a refused answer is a request nobody answers.
     * @param array<string, string|list<string>> $headers
     */
    private static function respondTo(int $id, int $status, array $headers, string $body): bool
    {
        if (\ignis_respond($id, $status, $headers, $body)) {
            return true;
        }
        error_log(\sprintf('Ignis\Loop: request %d refused a %d answer and is now unanswered', $id, $status));

        return false;
    }

    /** Loop-level failures the client may never see. `error_log()` is stderr under the embed SAPI. */
    private static function logFailure(string $what, \Throwable $exception): void
    {
        \error_log('ignis: ' . $what . ': ' . $exception::class . ': ' . $exception->getMessage());
    }

    /**
     * Runs the handler with this request's fiber-scoped state; every throw becomes a status.
     * @param null|callable(Http\Request):mixed $handler
     */
    private static function runHandler(?callable $handler, Http\Request $request, int $id): mixed
    {
        try {
            self::enterRequest($request, $id);
            self::armFiberTimeout($id, $request->uri);
            if ($handler === null) {
                throw new \LogicException('no request handler is set: serve through Ignis\\serve()');
            }
            return $handler($request);
        } catch (DeadlineExceededException $exception) {
            return Http\Response::text('504 ' . $exception->getMessage() . "\n", 504);
        } catch (CancelledException) {
            return Http\Response::text("499 cancelled\n", 499);
        } catch (\Throwable $e) {
            return Http\Response::text('500 ' . $e::class . ': ' . $e->getMessage() . "\n", 500);
        } finally {
            self::leaveRequestScope();
        }
    }

    /** Leaves the request's scope but keeps the fiber mapped, so a StreamedResponse can still be cancelled. */
    private static function leaveRequestScope(): void
    {
        Scope::set('ignis.request', null);
    }

    /** Gives this fiber the request id, its own superglobals (E13, ADR-0006) and its own `php://input`. */
    private static function enterRequest(Http\Request $request, int $id): void
    {
        self::$requestFibers[$id] = self::currentFiber();
        Scope::set('ignis.request', $id);
        \ignis_fiber_request($id);
        \ignis_set_superglobals(...$request->superglobals());
        InputStream::register();
        InputStream::setBody($request->body);
        // And where the SAPI keeps it, which is a different place and the one PHP 8.4's
        // request_parse_body() reads -- Symfony 8 calls that for PUT/PATCH/DELETE form bodies.
        \ignis_set_request_info($request->method, $request->header('content-type') ?? '', $request->body);
    }

    /** What the handler returned is the contract: streamed, sent, or null when another channel answered. */
    private static function answer(int $id, mixed $answer): void
    {
        if ($answer instanceof Http\StreamedResponse) {
            self::produce($id, $answer);
        } elseif ($answer instanceof Http\Response) {
            self::respondTo($id, $answer->status, $answer->headers, $answer->body);
        } elseif ($answer !== null) {
            self::respondTo($id, 500, ['content-type' => 'text/plain'], "handler must return Ignis\\Http\\Response or null\n");
        }
    }

    /** Drops the request's fiber-scoped state before the fiber goes back to the pool (V-67). */
    private static function releaseRequest(int $id): void
    {
        self::disarmDeadline($id);
        unset(self::$requestFibers[$id], self::$children[$id]);
        \ignis_fiber_request(0);
        Scope::clear();
        \ignis_clear_request_info();
        Output::reset();
        self::watchLoadedFiles();
        --self::$inflightRequests;
        ++self::$handled;
        self::drainQueue();
    }

    /** Runs a streaming producer and ends its body. */
    private static function produce(int $id, Http\StreamedResponse $response): void
    {
        \ignis_stream_bind($id, $response->status, $response->headers);
        try {
            ($response->producer)();
        } catch (\Throwable $e) {
            [$tail, $started] = \ignis_stream_unbind();
            if (!$started && $tail === '') {
                self::respondTo($id, 500, ['content-type' => 'text/plain'], '500 ' . $e::class . ': ' . $e->getMessage() . "\n");

                return;
            }
            self::logFailure('streaming handler failed mid-body', $e);
            self::endStream($id, $tail, $started);

            return;
        }
        [$tail, $started] = \ignis_stream_unbind();
        self::endStream($id, $tail, $started);
    }

    /** Sends whatever the producer left behind and closes the body. */
    private static function endStream(int $id, string $tail, bool $started): void
    {
        if (!$started && $tail === '') {
            self::respondTo($id, 204, [], '');

            return;
        }
        if ($tail !== '') {
            $op = \ignis_respond_chunk($id, $tail);
            if ($op > 0) {
                self::awaitOp($op);
            }
        }
        \ignis_respond_end($id);
    }

    /** Throws $exception into the request's children first, then into its own fiber (ADR-0009). */
    private static function cancelRequest(int $requestId, CancelledException $exception, int $ageUs): void
    {
        $cancelStart = hrtime(true);
        if (isset(self::$queued[$requestId])) {
            self::$queueCancelled[$requestId] = true;   // it has no fiber to throw into; drainQueue drops it
        }
        $parent = self::$requestFibers[$requestId] ?? null;
        foreach (array_reverse(self::$children[$requestId] ?? []) as $child) {
            self::throwInto($child, $exception, $requestId);
        }
        if ($parent !== null) {
            self::throwInto($parent, $exception, $requestId);
        }
        ++self::$cancelled;
        self::$cancelAgeUsMax = max(self::$cancelAgeUsMax, $ageUs);
        self::$cancelLatencyUsMax = max(self::$cancelLatencyUsMax, $ageUs + (int) ((hrtime(true) - $cancelStart) / 1000));
    }

    /**
     * Throws into $fiber and absorbs the injected exception on its way back; anything else is reported.
     * @param \Fiber<mixed,mixed,mixed,mixed> $fiber
     */
    private static function throwAndAbsorb(\Fiber $fiber, \Throwable $exception): void
    {
        try {
            $fiber->throw($exception);
        } catch (\Throwable $caught) {
            if ($caught !== $exception) {
                self::$unobserved[] = $caught;
            }
        }
    }

    /**
     * Finds where $fiber is parked (userland, a C stream op, or only Rust knows) and throws there.
     * @param \Fiber<mixed,mixed,mixed,mixed> $fiber
     */
    private static function throwInto(\Fiber $fiber, \Throwable $exception, ?int $requestId = null): void
    {
        if ($fiber->isTerminated() || !$fiber->isSuspended()) {
            return;
        }
        $opId = self::$parkedOn[\spl_object_id($fiber)] ?? null;
        if ($opId !== null) {
            unset(self::$waiting[$opId]);
            ++self::$resumes;
            self::throwAndAbsorb($fiber, $exception);
            self::watchForSwallowedCancellation($fiber, $requestId);
            return;
        }
        foreach (self::$waiting as $op => $waiter) {
            if ($waiter === $fiber) {
                unset(self::$waiting[$op]);
                ++self::$resumes;
                self::throwAndAbsorb($fiber, $exception);
                self::watchForSwallowedCancellation($fiber, $requestId);
                return;
            }
        }
        self::cancelCPark($fiber, $exception, $requestId);
    }

    /**
     * A fiber parked inside a C hook has an op id only Rust knows, so Rust searches its own table.
     * @param \Fiber<mixed,mixed,mixed,mixed> $fiber
     */
    private static function cancelCPark(\Fiber $fiber, \Throwable $exception, ?int $requestId = null): void
    {
        self::throwIntoCPark($fiber, $exception);
        self::watchForSwallowedCancellation($fiber, $requestId);
    }

    /**
     * Resumes $fiber out of its C-side park with $exception thrown in; false when it is not C-parked.
     * @param \Fiber<mixed,mixed,mixed,mixed> $fiber
     */
    private static function throwIntoCPark(\Fiber $fiber, \Throwable $exception): bool
    {
        try {
            return \ignis_cancel_parked_any($fiber, $exception);
        } catch (\Throwable $caught) {
            if ($caught !== $exception) {
                self::$unobserved[] = $caught;
            }
        }

        return true;
    }

    /**
     * Watches whether $fiber parks again instead of unwinding after a cancellation was thrown into it.
     * @param \Fiber<mixed,mixed,mixed,mixed> $fiber
     */
    private static function watchForSwallowedCancellation(\Fiber $fiber, ?int $requestId): void
    {
        if ($fiber->isTerminated() || self::hasReturnedToThePoolIdle($fiber)) {
            return;
        }
        $fiberId = \spl_object_id($fiber);
        if (self::$forceCloseOnSwallowedCancel) {
            self::$killPending[$fiberId] = $fiber;
            \ignis_fiber_kill_pending($fiber, true);
            return;
        }
        self::$logSwallowedPending[$fiberId] = [$fiber, $requestId ?? 0];
    }

    /**
     * True for a fiber back in the idle pool: an ordinary re-park, not a swallowed cancellation.
     * @param \Fiber<mixed,mixed,mixed,mixed> $fiber
     */
    private static function hasReturnedToThePoolIdle(\Fiber $fiber): bool
    {
        return \in_array($fiber, self::$idle, true);
    }

    /** Runs every loop turn (ADR-0043 §7, L2): force-closes fibers that parked again after a cancellation instead of unwinding. */
    private static function forceClosePending(): void
    {
        if (self::forceCloseSuspendedFibers()) {
            try {
                self::collectSelfReferencingFiberCycle();
            } catch (\Throwable $exception) {
                self::$unobserved[] = $exception;
            }
        }
        self::logFibersThatSwallowedTheirCancellation();
    }

    /** Drops loop references to each still-suspended `$killPending` fiber and reports whether it found one, so the caller knows a cycle collection is worth running. */
    private static function forceCloseSuspendedFibers(): bool
    {
        $foundOneToClose = false;
        foreach (\array_keys(self::$killPending) as $fiberId) {
            if (!\array_key_exists($fiberId, self::$killPending)) {
                continue;
            }
            $fiber = self::$killPending[$fiberId];
            if ($fiber->isSuspended()) {
                self::releaseCPark($fiber);
            }
            if ($fiber->isTerminated()) {
                unset(self::$killPending[$fiberId]);
                continue;
            }
            if (!$fiber->isSuspended()) {
                continue;
            }
            unset(self::$killPending[$fiberId]);
            self::dropLoopReferencesTo($fiber);
            error_log(\sprintf('Ignis\\Loop: fiber %d parked again after its cancellation and is force-closed (L2)', $fiberId));
            $foundOneToClose = true;
        }

        return $foundOneToClose;
    }

    /**
     * Ends a fiber's C-side park with the kill thrown in, so the loop can drop its references (research 49 H1).
     * @param \Fiber<mixed,mixed,mixed,mixed> $fiber
     */
    private static function releaseCPark(\Fiber $fiber): void
    {
        self::throwIntoCPark($fiber, new KilledException('fiber force-closed'));
    }

    /** @param \Fiber<mixed,mixed,mixed,mixed> $fiber */
    private static function dropLoopReferencesTo(\Fiber $fiber): void
    {
        $fiberId = \spl_object_id($fiber);
        unset(self::$parkedOn[$fiberId], self::$killPending[$fiberId]);
        foreach (self::$waiting as $op => $waiter) {
            if ($waiter === $fiber) {
                unset(self::$waiting[$op]);
            }
        }
        self::$idle = array_values(array_filter(self::$idle, static fn(\Fiber $idleFiber): bool => $idleFiber !== $fiber));
        foreach (array_keys(self::$children) as $requestId) {
            unset(self::$children[$requestId][$fiberId]);
        }
        foreach (self::$requestFibers as $requestId => $requestFiber) {
            if ($requestFiber === $fiber) {
                unset(self::$requestFibers[$requestId]);
            }
        }
        self::$ready = array_values(array_filter(self::$ready, static fn(array $entry): bool => $entry[0] !== $fiber));
        self::$pending = array_values(array_filter(self::$pending, static fn(array $entry): bool => $entry[0] !== $fiber));
    }

    /** Frees a force-closed fiber's self-reference cycle immediately, rather than on the loop's periodic GC schedule (research 49 H1/H2). */
    private static function collectSelfReferencingFiberCycle(): void
    {
        gc_collect_cycles();
    }

    /** `IGNIS_ON_SWALLOWED_CANCEL=log`: one warn line per swallowed cancellation, then forgotten. */
    private static function logFibersThatSwallowedTheirCancellation(): void
    {
        foreach (self::$logSwallowedPending as $fiberId => [$fiber, $requestId]) {
            if ($fiber->isTerminated()) {
                unset(self::$logSwallowedPending[$fiberId]);
                continue;
            }
            if (!$fiber->isSuspended()) {
                continue;
            }
            unset(self::$logSwallowedPending[$fiberId]);
            error_log(\sprintf('Ignis\Loop: request %d swallowed its cancellation and parked again', $requestId));
        }
    }

    /** Wall-clock deadline for the current request (E11): after $milliseconds the request fiber and its children get DeadlineExceededException. */
    public static function deadline(int $milliseconds): void
    {
        $requestId = self::currentRequestId();
        if ($requestId === null) {
            throw new \LogicException('Ignis\\deadline() must be called inside a request');
        }
        self::disarmDeadline($requestId);
        $op = \ignis_submit_sleep($milliseconds);
        self::$deadlines[$op] = $requestId;
        self::$deadlineOf[$requestId] = $op;
    }

    /** ADR-0043 §7, L0: arms the same deadline machinery as `Ignis\deadline()` from `Recovery::fiberTimeoutFor($uri)`, unless that is 0 (off). */
    private static function armFiberTimeout(int $requestId, string $uri): void
    {
        $milliseconds = Recovery::fiberTimeoutFor($uri);
        if ($milliseconds <= 0) {
            return;
        }
        $op = \ignis_submit_sleep($milliseconds);
        self::$deadlines[$op] = $requestId;
        self::$deadlineOf[$requestId] = $op;
        self::$fiberTimeoutMilliseconds[$requestId] = $milliseconds;
    }

    /** Calls off a request's deadline timer. */
    private static function disarmDeadline(int $requestId): void
    {
        unset(self::$fiberTimeoutMilliseconds[$requestId]);
        $op = self::$deadlineOf[$requestId] ?? null;
        if ($op === null) {
            return;
        }
        unset(self::$deadlineOf[$requestId], self::$deadlines[$op]);
        \ignis_cancel($op);
    }

    /** The L0 504 text, naming the park's file:line when the engine can say (ADR-0043 §7). */
    private static function fiberTimeoutMessage(int $milliseconds, int $requestId): string
    {
        $fiber = self::$requestFibers[$requestId] ?? null;
        $parkedAt = $fiber === null ? null : \ignis_fiber_where($fiber);

        return $parkedAt === null
            ? \sprintf('fiber timeout after %d ms', $milliseconds)
            : \sprintf('fiber timeout after %d ms, parked at %s', $milliseconds, $parkedAt);
    }
}
