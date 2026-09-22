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
    /**
     * request id => the child fibers it spawned, keyed by object id so a finished one leaves in O(1).
     * @var array<int,array<int,\Fiber<mixed,mixed,mixed,mixed>>>
     */
    private static array $children = [];
    /** @var array<int,int> op id => request id for deadline timers */
    private static array $deadlines = [];
    /** @var array<int,int> request id => the deadline op it armed, so the timer can be called off */
    private static array $deadlineOf = [];
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
    private static bool $running = false;
    /**
     * A `callable` return type is not enforced at run time, so the loop checks what came back
     * rather than trusting it; the contract handlers are written to is `?Http\Response`.
     * @var null|callable(Http\Request):mixed
     */
    private static $requestHandler = null;
    public static int $resumes = 0;
    public static int $fibersCreated = 0;
    /** B1 admission control (ADR-0019): max fibers holding a request; 0 = unlimited, the default. */
    public static int $fiberBudget = 0;
    public static int $queueDepth = 0;
    public static int $inflightRequests = 0;
    /** Requests this loop has finished answering (M4-4). */
    public static int $handled = 0;
    /** False when the runtime is too old to have ignis_publish_stats() (a script run, a test). */
    private static bool $canPublishStats = true;
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
    /**
     * Nanoseconds spent in each phase (for VALIDATION.md; cheap: one hrtime per batch).
     * @var array{start: int, ready: int, poll: int, resume: int}
     */
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
     * Registers $fiber against $op, hands the thread back to the loop, and unregisters on the way
     * out however that happens — a resume, or a cancellation thrown into the parked fiber.
     *
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
     * Body of a pooled fiber: runs jobs forever, parking between them. Never returns — the fiber
     * either stays parked for the life of the thread or unwinds on a thrown cancellation.
     * @param Job $job
     */
    private static function poolBody(array $job): never
    {
        $self = self::currentFiber();
        for (;;) {
            [$function, $arguments, $future, $requestId] = $job;
            try {
                $future->resolve($function(...$arguments));
            } catch (\Throwable $e) {
                $future->reject($e);
            }
            self::forgetChild($requestId, $self);
            self::$idle[] = $self;
            $job = self::nextJob();
        }
    }

    /**
     * Drops a finished child from the request that spawned it, before the pool hands the fiber out.
     *
     * Without this the fiber stays in `$children` for as long as its parent lives, while the pool has
     * already re-issued it: a disconnect on request A then threw `CancelledException` into whatever
     * request B was doing on the same fiber. The list is keyed by object id so this is O(1) and the
     * spawn order `cancelRequest()` walks in reverse is the insertion order either way.
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
     * A pool fiber is only ever resumed by resumeReady(), which only ever hands it a Job — but
     * that guarantee lives outside the type system, on the other side of Fiber::suspend()'s erased
     * generic, so the boundary is checked rather than assumed.
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
     * The fiber every pooled job and every request runs on. Nothing here is reachable from the main
     * stack, and a null would silently corrupt the pool and the cancellation maps rather than fail.
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

    /**
     * True while this thread's loop is the one draining `ignis_poll()`.
     *
     * `ignis_poll()` consumes the completion channel, so a second consumer on the same thread takes
     * completions the fibers of this loop are parked on and those fibers never wake. Anything that
     * wants to drive the reactor itself — `Ignis\Revolt\IgnisDriver` is the one in the tree — asks
     * first and refuses rather than producing a hang nobody can read.
     */
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
        self::$canPublishStats = \function_exists('ignis_publish_stats');
        // boot() runs on the first turn of every mode, including classic `listen()`, which never
        // calls serve() and would otherwise watch nothing at all.
        self::$watching = Env::flag('IGNIS_WATCH') && \function_exists('ignis_watch_generation');
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

    /**
     * Nothing left for this loop to do. `$ready` and `$pending` count because ignis_poll() resumes
     * C-parked fibers itself: a fiber they settled sits in `$ready` with nothing in flight, and
     * seeing that is what keeps a nested all() alive (E18-I1). A fiber parked inside a C hook is
     * not in `$waiting` either, but its op is in flight (E15c fix).
     */
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

    /**
     * M4-4: hand this loop's counters to the runtime so /_ignis/metrics can answer them while PHP
     * is busy. A wedged loop stops publishing, which the endpoint reports as an ageing sample.
     *
     * A-DUPES(c): this key set is not `budgetStats()`'s by another name — it is
     * `crate::metrics::Published::set()`'s fixed field list, sent whole every loop turn because
     * `ignis_publish_stats()` takes one array, not incremental fields. `inflight` is missing on
     * purpose: the Rust side already tracks in-flight requests itself (see the module doc at the
     * top of `metrics.rs`), and `fibers_idle`/`fibers_created`/`resumes`/`handled` are missing from
     * `budgetStats()` because callers building a `/stats` endpoint already have them as
     * `Loop::$resumes`, `Loop::$fibersCreated` and `Loop::idleFibers()` (`examples/app.php`,
     * `examples/hello_server.php`) and would otherwise get them twice.
     */
    private static function publishStats(): void
    {
        if (!self::$canPublishStats) {
            return;
        }
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
                self::cancelRequest($requestId, new DeadlineExceededException('deadline exceeded'), 0);
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
     * A completion no fiber is waiting for, as the reactor's tagged union: a cancelled request, or
     * a new request — which carries no tag, only a method. It arrives off the reactor as
     * `array<array-key, mixed>`, so every field is checked before use.
     *
     * A payload matching neither tag is dropped with no log, on purpose: a fire-and-forget op
     * nobody awaits can fail at the reactor level, and there is nobody left to tell.
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


    /**
     * A fiber failed and nobody awaited its Future: surface it instead of losing it (E15c fix).
     * Only one can be rethrown, so the rest are logged rather than dropped on the floor — the loop
     * stops on the first crash of a batch, and the ones behind it are usually how it is explained.
     */
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
     *
     * A-DUPES(c): overlaps `publishStats()` on six keys and is not the same shape as it — see the
     * note there for why both exist. This one is the public admission-control subset a caller
     * composes its own `/stats` response from, `inflight` included because nothing else exposes it.
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

    /**
     * Asks the loop to stop serving: no new requests are accepted, the ones in flight finish, and
     * `serve()` returns. Under `--supervise` the supervisor then respawns this thread with a fresh
     * engine, which is what makes it a reload rather than an exit (research 40).
     */
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

    /**
     * Development reload: the files PHP has loaded are the dependency graph, so they are what the
     * watcher watches (research 40). Off unless `IGNIS_WATCH` is set; a delta after every request,
     * which is almost always empty.
     */
    private static function watchLoadedFiles(): void
    {
        if (!self::$watching || !\function_exists('ignis_watch_files')) {
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

    /**
     * Begins a graceful stop the first time it is asked for, then waits for everything in flight.
     *
     * "In flight" is deliberately the same set `isIdle()` uses. Counting only `$inflightRequests`
     * lost two kinds of work: a request the front door had already handed this reactor but that
     * `ignis_poll()` had not delivered yet — measured at 1,472 of 383,181 requests answered 500
     * across a reload under `wrk -t4 -c32` — and a fiber parked on an op, which is how a
     * fire-and-forget `Ignis\async()` would have been dropped.
     */
    private static function windingDown(): bool
    {
        if (!self::$stopping) {
            return false;
        }
        if (!self::$leftDispatch) {
            self::$leftDispatch = true;
            if (\function_exists('ignis_stop_accepting')) {
                \ignis_stop_accepting();
            }
        }

        return \ignis_inflight() === 0          // the runtime's count, which includes a request delivered
            && self::$inflightRequests === 0    // to this thread but not yet drained by ignis_poll()
            && self::$waiting === []            // and a fiber parked on an op is still work in flight
            && self::$ready === []
            && self::$pending === [];
    }

    /**
     * Hands a request to the loop's caller instead of to a fiber. Set by `Ignis\Classic\listen()`
     * for the top-level worker loop; see docs/classic-mode.md for why that mode exists (V-53).
     * @var null|callable(int,IgnisRequest):void
     */
    public static $rawRequestHandler = null;

    /**
     * B1 (ADR-0019): admit, queue, or shed. Queueing holds the request as data, so a queued
     * request costs a few hundred bytes instead of the fiber's ~14.7 kB of marginal RSS (V-37).
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

    /**
     * Takes waiting requests while there is room. O(1) per request: the FIFO is read by index, and
     * the slot keeps only the id once it is read, so a body is freed now and not at compaction.
     * A client that went away while queued has nothing to answer.
     */
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
            try {
                self::answer($id, self::runHandler($handler, $request, $id));
            } catch (\Throwable $e) {
                self::answerFailed($id, $e);
            } finally {
                self::releaseRequest($id);
            }
        });
    }

    /**
     * Sending the answer happens outside every `catch` in runHandler(), and the Future spawn()
     * returns is discarded — so without this a failing `ignis_stream_bind` answers nobody, logs
     * nothing, and surfaces later as an unobserved rejection that kills the loop, not the request.
     */
    private static function answerFailed(int $id, \Throwable $exception): void
    {
        self::logFailure('answering the request failed', $exception);
        try {
            self::respondTo($id, 500, ['content-type' => 'text/plain'], '500 ' . $exception::class . ': ' . $exception->getMessage() . "\n");
        } catch (\Throwable $second) {
            self::logFailure('and so did answering it with a 500', $second);
        }
    }

    /**
     * Answers $id and says so when the answer was refused, because a refused answer is a request
     * nobody will ever answer — the client waits until it gives up.
     *
     * `ignis_respond()` returns false when the id is unknown, already answered, or in a state this
     * shape of answer cannot satisfy. Every call site here used to discard that, and the cost was
     * measured: a gRPC call rejected by admission control was "answered" with HTTP 503, the reactor
     * refused it because a gRPC id is not a whole-body id, and three of five concurrent calls hung
     * for ever with nothing logged (V-107). The transport now maps a refusal onto its own wire, so
     * that particular false is gone; this is here so the next one is a line in the log and not a
     * hang.
     *
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
     * Runs the handler with this request's fiber-scoped state. Every throw becomes a status,
     * the ones from entering the request included: an exception that escaped here would be
     * answered by nobody, because the Future spawn() returns is discarded.
     * @param null|callable(Http\Request):mixed $handler
     */
    private static function runHandler(?callable $handler, Http\Request $request, int $id): mixed
    {
        try {
            self::enterRequest($request, $id);
            if ($handler === null) {
                throw new \LogicException('no request handler is set: serve through Ignis\\serve()');
            }
            return $handler($request);
        } catch (DeadlineExceededException) {
            return Http\Response::text("504 deadline exceeded\n", 504);
        } catch (CancelledException) {
            return Http\Response::text("499 cancelled\n", 499);
        } catch (\Throwable $e) {
            return Http\Response::text('500 ' . $e::class . ': ' . $e->getMessage() . "\n", 500);
        } finally {
            self::leaveRequestScope();
        }
    }

    /**
     * The fiber stays mapped: a StreamedResponse is produced *after* runHandler() returns, and
     * cancelling it needs to find this fiber. releaseRequest() drops the mapping once the answer
     * is actually complete (R-STREAM-CANCEL).
     */
    private static function leaveRequestScope(): void
    {
        Scope::set('ignis.request', null);
    }

    /**
     * Gives this fiber the request id, its own $_SERVER/$_GET/$_POST/$_COOKIE (E13, ADR-0006) and
     * its own `php://input`.
     *
     * The body belongs here for the same reason the superglobals do: the embed SAPI has no
     * `read_post`, so unmodified code reading `php://input` gets nothing unless the runtime backs
     * it. Leaving that to classic mode alone cost a measured defect — the runtime fills `$_POST`
     * for `POST` only, and a framework re-parsing `php://input` for `PUT`/`PATCH` therefore saw an
     * empty body.
     */
    private static function enterRequest(Http\Request $request, int $id): void
    {
        self::$requestFibers[$id] = self::currentFiber();
        Scope::set('ignis.request', $id);
        if (\function_exists('ignis_set_superglobals')) {
            \ignis_set_superglobals(...$request->superglobals());
        }
        InputStream::register();
        InputStream::setBody($request->body);
        // And where the SAPI keeps it, which is a different place and the one PHP 8.4's
        // request_parse_body() reads -- Symfony 8 calls that for PUT/PATCH/DELETE form bodies.
        \ignis_set_request_info($request->method, $request->header('content-type') ?? '', $request->body);
    }

    /**
     * What the handler returned IS the contract: a StreamedResponse has its producer driven by the
     * loop, a Response is sent, and null means another channel answered already (gRPC, E10).
     */
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

    /**
     * Drops the request's fiber-scoped state before the fiber goes back to the pool — without it the
     * next request on the same fiber inherits the last one's token, EntityManager and lease (V-67,
     * V-68) — then releases the slot and admits the next waiting request from here, so the loop
     * needs no extra wait point for it.
     */
    private static function releaseRequest(int $id): void
    {
        self::disarmDeadline($id);
        unset(self::$requestFibers[$id], self::$children[$id]);
        Scope::clear();
        \ignis_clear_request_info();
        Output::reset();
        self::watchLoadedFiles();
        --self::$inflightRequests;
        ++self::$handled;
        self::drainQueue();
    }

    /**
     * Runs a streaming producer and ends its body.
     *
     * Binding does not send anything: the status line goes out with the first byte, whatever wrote
     * it. That is what lets a producer which fails early still answer `500` — once the headers are
     * out, an error can only truncate, which is all HTTP allows.
     */
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

    /**
     * Sends whatever the producer left behind and closes the body. A producer that wrote nothing
     * at all gets an ordinary empty response, not a chunked one; a non-empty tail is only what did
     * not fit, because the unbind already pushed the rest and opened the response.
     */
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

    /**
     * Throw $exception into the request's children and only then into its own fiber, at their suspension
     * points (ADR-0009; research 08: "cancel walks children first"). The parent goes last because
     * its unwinding answers 499 and hands its fiber back to the pool, which the next request may
     * take while a child is still in its `finally`. Children unwind in reverse spawn order.
     * A request that is still only queued (B1) has no fiber to throw into: it is never admitted.
     */
    private static function cancelRequest(int $requestId, CancelledException $exception, int $ageUs): void
    {
        $cancelStart = hrtime(true);
        if (isset(self::$queued[$requestId])) {
            self::$queueCancelled[$requestId] = true;   // it has no fiber to throw into; drainQueue drops it
        }
        $parent = self::$requestFibers[$requestId] ?? null;
        foreach (array_reverse(self::$children[$requestId] ?? []) as $child) {
            self::throwInto($child, $exception);
        }
        if ($parent !== null) {
            self::throwInto($parent, $exception);
        }
        ++self::$cancelled;
        self::$cancelAgeUsMax = max(self::$cancelAgeUsMax, $ageUs);
        self::$cancelLatencyUsMax = max(self::$cancelLatencyUsMax, $ageUs + (int) ((hrtime(true) - $cancelStart) / 1000));
    }

    /**
     * Throws into $fiber and absorbs the injected exception on its way back out; anything else is
     * a genuine user error and is kept for the unobserved-error report. An unguarded `Fiber::throw`
     * killed the worker thread — see "Absorbing the injected exception" in ADR-0009.
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
     * `$parkedOn` holds the op of a fiber parked in userland (Ignis\sleep, await); `$waiting` is
     * scanned for one parked in a C stream op; anything else is a park only Rust can find.
     * @param \Fiber<mixed,mixed,mixed,mixed> $fiber
     */
    private static function throwInto(\Fiber $fiber, \Throwable $exception): void
    {
        if ($fiber->isTerminated() || !$fiber->isSuspended()) {
            return;
        }
        $opId = self::$parkedOn[\spl_object_id($fiber)] ?? null;
        if ($opId !== null) {
            unset(self::$waiting[$opId]);
            ++self::$resumes;
            self::throwAndAbsorb($fiber, $exception);
            return;
        }
        foreach (self::$waiting as $op => $waiter) {
            if ($waiter === $fiber) {
                unset(self::$waiting[$op]);
                ++self::$resumes;
                self::throwAndAbsorb($fiber, $exception);
                return;
            }
        }
        self::cancelCPark($fiber, $exception);
    }

    /**
     * A fiber parked inside a C hook has an op id only Rust knows, so Rust searches its own table.
     * Guarded like throwAndAbsorb, and this is the path that actually killed worker threads:
     * zend_fiber_resume_exception leaves the throwable pending in C when the fiber has no handler,
     * so it surfaces on return here with no throwInto frame in the trace (ADR-0009).
     * @param \Fiber<mixed,mixed,mixed,mixed> $fiber
     */
    private static function cancelCPark(\Fiber $fiber, \Throwable $exception): void
    {
        if (!\function_exists('ignis_cancel_parked_any')) {
            return;
        }
        try {
            \ignis_cancel_parked_any($fiber, $exception);
        } catch (\Throwable $caught) {
            if ($caught !== $exception) {
                self::$unobserved[] = $caught;
            }
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

    /**
     * Calls off a request's deadline timer: one wall-clock deadline per request is the contract, and
     * a request that answered in time has no use for one.
     *
     * A timer nobody cancelled still fired, and then `cancelRequest()` ran for an id that was already
     * answered — inflating `$cancelled` and `$cancelAgeUsMax`, the counters `/stats` reports. Worse,
     * `ignis_inflight()` counted the pending op, and both `isIdle()` and `windingDown()` wait for that
     * to reach zero: a graceful drain held for as long as the longest deadline anyone had armed.
     * The map is cleared before the cancel, because the cancelled op completes under its own id.
     */
    private static function disarmDeadline(int $requestId): void
    {
        $op = self::$deadlineOf[$requestId] ?? null;
        if ($op === null) {
            return;
        }
        unset(self::$deadlineOf[$requestId], self::$deadlines[$op]);
        \ignis_cancel($op);
    }
}
