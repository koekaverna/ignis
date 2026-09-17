<?php

declare(strict_types=1);

namespace Ignis;

final class Loop
{
    /** @var array<int,\Fiber> request id => fiber running the handler */
    private static array $requestFibers = [];
    /** @var array<int,list<\Fiber>> request id => child fibers spawned by it */
    private static array $children = [];
    /** @var array<int,int> op id => request id for deadline timers */
    private static array $deadlines = [];
    /** @var array<int,int> fiber object id => op id it is parked on (userland parks) */
    private static array $parkedOn = [];
    public static int $cancelled = 0;
    public static int $cancelAgeUsMax = 0;
    public static int $cancelLatencyUsMax = 0;
    /** @var array<int,\Fiber> op id => fiber waiting for it */
    private static array $waiting = [];
    /** @var list<array{0:\Fiber,1:mixed}> fibers to resume with a value */
    private static array $ready = [];
    /** @var list<array{0:\Fiber,1:array}> new pool fibers to start with a job */
    private static array $pending = [];
    /** @var list<\Fiber> parked pool fibers */
    private static array $idle = [];
    private static bool $running = false;
    /** @var null|callable(Http\Request):Http\Response */
    private static $requestHandler = null;
    public static int $resumes = 0;
    public static int $fibersCreated = 0;
    /**
     * B1 (ADR-0019) admission control. A request that cannot be admitted waits as *data* — the raw
     * array the reactor delivered — never as a Fiber: V-37 measured 47.7 kB per held request with a
     * fiber against 33.0 kB queued as data, i.e. the fiber itself is ~14.7 kB of RSS
     * (V-5) and that is the whole point of the budget. 0 = unlimited, which is the pre-B1
     * behaviour and stays the default.
     */
    public static int $fiberBudget = 0;
    public static int $queueDepth = 0;
    public static int $inflightRequests = 0;
    /** Requests this loop has finished answering (M4-4). */
    public static int $handled = 0;
    /** False when the runtime is too old to have ignis_publish_stats() (a script run, a test). */
    private static bool $publishStats = true;
    public static int $queuedPeak = 0;
    public static int $rejected = 0;
    public static int $admittedAfterQueue = 0;
    private static bool $budgetInit = false;
    /** @var list<string> path prefixes admitted regardless of the budget (IGNIS_BUDGET_EXEMPT). */
    private static array $budgetExempt = [];
    /** @var list<array{int, array}> FIFO of requests waiting for a slot; read through $queueHead. */
    private static array $requestQueue = [];
    private static int $queueHead = 0;
    /** @var array<int, true> ids whose client went away while queued. */
    private static array $queueCancelled = [];
    /** Nanoseconds spent in each phase (for VALIDATION.md; cheap: one hrtime per batch). */
    public static array $phaseNs = ['start' => 0, 'ready' => 0, 'poll' => 0, 'resume' => 0];

    /** Suspend the current fiber until reactor op $id completes; returns its payload. */
    public static function awaitOp(int $id): mixed
    {
        $fiber = \Fiber::getCurrent();
        if ($fiber === null) {
            // Called from {main}: park a throwaway fiber on the op and drive the loop.
            $result = null;
            $done = false;
            $f = new \Fiber(function () use (&$result, &$done) {
                $result = \Fiber::suspend();
                $done = true;
            });
            $f->start();
            self::$waiting[$id] = $f;
            self::runUntil(fn () => $done);
            return $result;
        }
        if (!self::$chaosInit) {
            self::chaosInit();
        }
        self::$waiting[$id] = $fiber;
        self::$parkedOn[\spl_object_id($fiber)] = $id;
        try {
            $payload = \Fiber::suspend();
        } finally {
            unset(self::$parkedOn[\spl_object_id($fiber)]);
        }
        if (self::$chaos && mt_rand() / mt_getrandmax() < self::$chaosP) {
            // Extra switch point after the completion: park on a 0 ms timer so other fibers run
            // before this one continues (the op itself is already consumed, nothing is lost).
            self::$chaosYields++;
            $yid = \ignis_submit_sleep(0);
            self::$waiting[$yid] = $fiber;
            self::$parkedOn[\spl_object_id($fiber)] = $yid;
            try {
                \Fiber::suspend();
            } finally {
                unset(self::$parkedOn[\spl_object_id($fiber)]);
            }
        }
        return $payload;
    }

    /** @internal */
    public static function markReady(\Fiber $fiber, mixed $value): void
    {
        self::$ready[] = [$fiber, $value];
    }

    /** Body of a pooled fiber: runs jobs forever, parking between them. */
    private static function poolBody(array $job): void
    {
        $self = \Fiber::getCurrent();
        while (true) {
            [$fn, $args, $future] = $job;
            try {
                $future->resolve($fn(...$args));
            } catch (\Throwable $e) {
                $future->reject($e);
            }
            self::$idle[] = $self;
            $job = \Fiber::suspend();
        }
    }

    /** Run $fn concurrently; a parked pool fiber is reused when available. */
    public static function spawn(callable $fn, mixed ...$args): Future
    {
        $future = new Future();
        // Attribute the child to the current request (E11): the request id is fiber-scoped.
        $requestId = Scope::get('ignis.request');
        if ($requestId !== null) {
            $fn = static function () use ($fn, $requestId, $args) {
                Scope::set('ignis.request', $requestId);
                return $fn(...$args);
            };
            $args = [];
        }
        $job = [$fn, $args, $future];
        $fiber = array_pop(self::$idle);
        if ($fiber !== null) {
            self::$ready[] = [$fiber, $job];
        } else {
            ++self::$fibersCreated;
            $fiber = new \Fiber(self::poolBody(...));
            self::$pending[] = [$fiber, $job];
        }
        if ($requestId !== null) {
            self::$children[$requestId][] = $fiber;
        }
        return $future;
    }

    public static function idleFibers(): int
    {
        return \count(self::$idle);
    }

    /** Run until no fiber is waiting on anything (and no server is listening). */
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
        if (!self::$chaosInit) {
            self::chaosInit();
            self::gcInit();
            self::$publishStats = \function_exists('ignis_publish_stats');
        }
        try {
            while (!$stop()) {
                // 1. start new pool fibers
                while (self::$pending !== []) {
                    $batch = self::$pending;
                    self::$pending = [];
                    $t = hrtime(true);
                    foreach ($batch as [$fiber, $job]) {
                        ++self::$resumes;
                        $fiber->start($job);
                    }
                    self::$phaseNs['start'] += hrtime(true) - $t;
                }
                // 2. resume fibers that became runnable (settled future or new job)
                while (self::$ready !== []) {
                    $batch = self::$ready;
                    self::$ready = [];
                    if (self::$chaos) {
                        shuffle($batch);
                    }
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
                // 3. nothing runnable: block on the reactor. A fiber parked inside a C hook
                // (stream op, sleep) is not in $waiting but its op is in flight (E15c fix).
                if (self::$waiting === [] && self::$requestHandler === null && self::$rawRequestHandler === null && \ignis_inflight() === 0 && self::$ready === [] && self::$pending === []) {
                    break;
                }
                if (self::$loopGc && (++self::$gcTick & 255) === 0 && gc_status()['roots'] >= self::$gcRoots) {
                    gc_collect_cycles(); // idle point: no fiber is mid-request here (checked every 256 polls: gc_status() allocates)
                    ++self::$gcRuns;
                }
                // M4-4: hand this loop's counters to the runtime so /_ignis/metrics can answer
                // them while PHP is busy. One call per loop turn, right before the poll that is
                // about to block — a handful of integer stores, and a wedged loop simply stops
                // publishing, which the endpoint reports as an ageing sample rather than a lie.
                if (self::$publishStats) {
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
                $t = hrtime(true);
                $events = \ignis_poll(-1);
                $t2 = hrtime(true);
                self::$phaseNs['poll'] += $t2 - $t;
                if (self::$chaos && \count($events) > 1) {
                    $keys = array_keys($events);
                    shuffle($keys);
                    $shuffled = [];
                    foreach ($keys as $k) {
                        $shuffled[$k] = $events[$k];
                    }
                    $events = $shuffled;
                }
                // ignis_poll() resumes C-parked fibers itself; a fiber they settled sits in $ready
                // with nothing in flight — checking $ready here is what keeps a nested all() alive (E18-I1).
                if ($events === [] && self::$waiting === [] && self::$requestHandler === null && self::$rawRequestHandler === null && \ignis_inflight() === 0 && self::$ready === [] && self::$pending === []) {
                    break;
                }
                foreach ($events as $id => $payload) {
                    // Array payloads: a fiber waiting on this op id wins (Custom ops return
                    // JSON strings or ['kind' => 'error']); otherwise a cancel or a new request.
                    if (\is_array($payload) && !isset(self::$waiting[$id])) {
                        if (($payload['kind'] ?? null) === 'cancel') {
                            self::cancelRequest($id, new CancelledException('client disconnected'), (int) $payload['age_us']);
                        } elseif (($payload['kind'] ?? null) === 'offload_cb') {
                            (self::$offloadCallbackHandler ?? static fn () => null)($payload); // E16
                        } elseif (isset($payload['method'])) {
                            self::dispatchRequest($id, $payload);
                        }
                        continue;
                    }
                    if (isset(self::$deadlines[$id])) {
                        $req = self::$deadlines[$id];
                        unset(self::$deadlines[$id]);
                        self::cancelRequest($req, new DeadlineExceededException('deadline exceeded'), 0);
                        continue;
                    }
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
        // A fiber failed and nobody awaited its Future: surface it instead of losing it (E15c fix).
        if (self::$unobserved !== []) {
            $e = array_shift(self::$unobserved);
            self::$unobserved = [];
            throw $e;
        }
    }

    /** @var list<\Throwable> rejected futures nobody has awaited (reported when the loop stops) */
    public static array $unobserved = [];
    /** @var null|callable(array):void set by Ignis\Offload\Client (E16) */
    public static $offloadCallbackHandler = null;

    /**
     * Chaos mode (E15e): IGNIS_CHAOS=1 shuffles the order in which ready fibers and completed ops
     * are resumed and adds an extra yield (a 0 ms timer) before every awaited op with probability
     * IGNIS_CHAOS_P (default 0.5). IGNIS_CHAOS_SEED makes a run reproducible. Correct code must not
     * notice; code that depends on resume order or on "no switch here" fails loudly.
     */
    public static bool $chaos = false;
    public static float $chaosP = 0.5;
    public static int $chaosYields = 0;
    private static bool $chaosInit = false;

    /**
     * E2'/E13': GC off the hot path. With IGNIS_LOOP_GC (default on) the engine's automatic cycle
     * collection is disabled and the loop runs gc_collect_cycles() itself right before blocking
     * on the reactor once the root buffer holds IGNIS_LOOP_GC_ROOTS (default 5000) entries —
     * never in the middle of a request's fiber.
     */
    public static int $gcRoots = 5000;
    public static int $gcRuns = 0;
    private static int $gcTick = 0;
    private static bool $loopGc = false;

    /** Reads the budget from the environment once; `IGNIS_FIBER_BUDGET=0` (default) means unlimited. */
    private static function budgetInit(): void
    {
        self::$budgetInit = true;
        $b = getenv('IGNIS_FIBER_BUDGET');
        if ($b !== false && is_numeric($b)) {
            self::$fiberBudget = max(0, (int) $b);
        }
        $q = getenv('IGNIS_QUEUE_DEPTH');
        if ($q !== false && is_numeric($q)) {
            self::$queueDepth = max(0, (int) $q);
        }
        // A saturated server is exactly when its metrics matter, so health and stats endpoints
        // must not queue behind the load they are there to report.
        $e = getenv('IGNIS_BUDGET_EXEMPT');
        if ($e !== false && $e !== '') {
            self::$budgetExempt = array_values(array_filter(array_map('trim', explode(',', $e))));
        }
    }

    /** Counters for /stats and for VALIDATION: see ADR-0019. */
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
        $env = getenv('IGNIS_LOOP_GC');
        self::$loopGc = $env === false || ($env !== '' && $env !== '0');
        $n = getenv('IGNIS_LOOP_GC_ROOTS');
        if ($n !== false && is_numeric($n)) {
            self::$gcRoots = max(100, (int) $n);
        }
        if (self::$loopGc) {
            gc_disable();
        }
    }

    private static function chaosInit(): void
    {
        self::$chaosInit = true;
        $env = getenv('IGNIS_CHAOS');
        self::$chaos = $env !== false && $env !== '' && $env !== '0';
        if (!self::$chaos) {
            return;
        }
        $p = getenv('IGNIS_CHAOS_P');
        if ($p !== false && is_numeric($p)) {
            self::$chaosP = max(0.0, min(1.0, (float) $p));
        }
        $seed = getenv('IGNIS_CHAOS_SEED');
        mt_srand($seed !== false && $seed !== '' ? (int) $seed : (int) (hrtime(true) % 2147483647));
    }

    /** @param callable(Http\Request):Http\Response $handler */
    public static function serve(callable $handler, string $addr): void
    {
        \ignis_serve($addr);
        self::$requestHandler = $handler;
        self::run();
    }

    /**
     * B1 (ADR-0019): admit, queue, or shed. Queueing holds the request as data, so a queued
     * request costs a few hundred bytes instead of the fiber's ~14.7 kB of marginal RSS (V-37).
     */
    /**
     * Hands a request to the loop's caller instead of to a fiber. Set by `Ignis\Classic\listen()`
     * for the top-level worker loop: an entry script `include`d from a fiber (or from any function)
     * has its top-level variables as *locals*, so `$GLOBALS['x']` stays empty and `global $x` in a
     * function sees null — measured in V-53. Only an include at the top level of the main script
     * gets real globals, which is why that mode exists and why this hook does not spawn anything.
     * @var null|callable(int,array):void
     */
    public static $rawRequestHandler = null;

    private static function dispatchRequest(int $id, array $raw): void
    {
        if (self::$rawRequestHandler !== null) {
            (self::$rawRequestHandler)($id, $raw);
            return;
        }
        if (!self::$budgetInit) {
            self::budgetInit();
        }
        if (self::$fiberBudget > 0 && self::$inflightRequests >= self::$fiberBudget
            && !self::isExempt($raw['uri'] ?? '')) {
            $queued = \count(self::$requestQueue) - self::$queueHead;
            if (self::$queueDepth > 0 && $queued >= self::$queueDepth) {
                ++self::$rejected;
                \ignis_respond($id, 503, ['retry-after' => '1'], "503 busy\n");
                return;
            }
            self::$requestQueue[] = [$id, $raw];
            if ($queued + 1 > self::$queuedPeak) {
                self::$queuedPeak = $queued + 1;
            }
            return;
        }
        self::admitRequest($id, $raw);
    }

    private static function isExempt(string $uri): bool
    {
        foreach (self::$budgetExempt as $prefix) {
            if (str_starts_with($uri, $prefix)) {
                return true;
            }
        }
        return false;
    }

    /** Takes waiting requests while there is room. O(1) per request: the FIFO is read by index. */
    private static function drainQueue(): void
    {
        while (self::$queueHead < \count(self::$requestQueue)
            && (self::$fiberBudget <= 0 || self::$inflightRequests < self::$fiberBudget)) {
            [$id, $raw] = self::$requestQueue[self::$queueHead];
            self::$requestQueue[self::$queueHead] = null; // drop the body now, not at compaction
            ++self::$queueHead;
            if (isset(self::$queueCancelled[$id])) {
                unset(self::$queueCancelled[$id]); // client gone while queued: nothing to answer
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

    private static function admitRequest(int $id, array $raw): void
    {
        ++self::$inflightRequests;
        $handler = self::$requestHandler;
        $request = new Http\Request($raw['method'], $raw['uri'], $raw['headers'], $raw['body'], $id);
        self::spawn(static function () use ($handler, $request, $id): void {
            try {
            self::$requestFibers[$id] = \Fiber::getCurrent();
            Scope::set('ignis.request', $id);
            // E13: this fiber gets its own $_SERVER/$_GET/$_POST/$_COOKIE (ADR-0006).
            if (\function_exists('ignis_set_superglobals')) {
                \ignis_set_superglobals(...$request->superglobals());
            }
            try {
                $response = $handler($request);
                if (!$response instanceof Http\Response) {
                    $response = Http\Response::text("handler must return Ignis\\Http\\Response\n", 500);
                }
            } catch (DeadlineExceededException $e) {
                $response = Http\Response::text("504 deadline exceeded\n", 504);
            } catch (CancelledException $e) {
                $response = Http\Response::text("499 cancelled\n", 499);
            } catch (\Throwable $e) {
                $response = Http\Response::text('500 ' . $e::class . ': ' . $e->getMessage() . "\n", 500);
            } finally {
                unset(self::$requestFibers[$id], self::$children[$id]);
                Scope::set('ignis.request', null);
            }
            if ($response->status !== 0) { // 0 = detached: the handler answered through another channel (gRPC, E10)
                \ignis_respond($id, $response->status, $response->headers, $response->body);
            }
            } finally {
                // The request is over: drop its fiber-scoped state before this fiber goes back to
                // the pool. Without it the next request on the same fiber inherits the last one's
                // security token, EntityManager and database lease (V-67, V-68).
                Scope::clear();
                // The slot is released after the answer is on its way, and the next waiting
                // request is admitted from here — the loop needs no extra wait point for it.
                --self::$inflightRequests;
                ++self::$handled;
                self::drainQueue();
            }
        });
    }

    /** Throw $e into the request's fiber and its children at their suspension points (ADR-0009). */
    private static function cancelRequest(int $requestId, CancelledException $e, int $ageUs): void
    {
        $t0 = hrtime(true);
        if (self::$queueHead < \count(self::$requestQueue) && !isset(self::$requestFibers[$requestId])) {
            // Still only queued (B1): no fiber to throw into, just never admit it.
            self::$queueCancelled[$requestId] = true;
        }
        $targets = self::$children[$requestId] ?? [];
        if (isset(self::$requestFibers[$requestId])) {
            $targets[] = self::$requestFibers[$requestId];
        }
        foreach (array_reverse($targets) as $fiber) {
            self::throwInto($fiber, $e);
        }
        ++self::$cancelled;
        self::$cancelAgeUsMax = max(self::$cancelAgeUsMax, $ageUs);
        self::$cancelLatencyUsMax = max(self::$cancelLatencyUsMax, $ageUs + (int) ((hrtime(true) - $t0) / 1000));
    }

    /**
     * `Fiber::throw()` on a fiber that has no handler for `$e` re-throws it straight back out at
     * the caller. Unguarded user code is the normal case, so without this the cancellation walked
     * back up through cancelRequest() into the event loop and killed the whole worker thread's
     * script — one disconnected client took out a quarter of the server's capacity. Found by the
     * A3 soak: 6 dead threads in 10.1M requests, and without --supervise the process itself died.
     * Anything OTHER than the exception we injected (a `finally` that throws while unwinding, say)
     * is a genuine user error and is kept for the unobserved-error report rather than swallowed.
     */
    private static function throwAndAbsorb(\Fiber $fiber, \Throwable $e): void
    {
        try {
            $fiber->throw($e);
        } catch (\Throwable $t) {
            if ($t !== $e) {
                self::$unobserved[] = $t;
            }
        }
    }

    private static function throwInto(\Fiber $fiber, \Throwable $e): void
    {
        if ($fiber->isTerminated() || !$fiber->isSuspended()) {
            return;
        }
        $opId = self::$parkedOn[\spl_object_id($fiber)] ?? null;
        if ($opId !== null) {
            unset(self::$waiting[$opId]); // parked in userland (Ignis\sleep / await)
            ++self::$resumes;
            self::throwAndAbsorb($fiber, $e);
            return;
        }
        // Parked in a C stream op? Let Rust resume it with the exception.
        foreach (self::$waiting as $op => $f) {
            if ($f === $fiber) {
                unset(self::$waiting[$op]);
                ++self::$resumes;
                self::throwAndAbsorb($fiber, $e);
                return;
            }
        }
        if (\function_exists('ignis_cancel_parked_any')) {
            // We do not know the op id of a C park; the Rust side searches its table.
            // Guarded for the same reason as throwAndAbsorb, and this is the path that actually
            // killed worker threads: zend_fiber_resume_exception leaves the throwable pending in
            // C when the fiber has no handler, so it surfaces on return here — with no throwInto
            // frame in the trace, which is why guarding Fiber::throw() alone did not help.
            try {
                \ignis_cancel_parked_any($fiber, $e);
            } catch (\Throwable $t) {
                if ($t !== $e) {
                    self::$unobserved[] = $t;
                }
            }
        }
    }

    /** Wall-clock deadline for the current request (E11): after $ms the request fiber and its children get DeadlineExceededException. */
    public static function deadline(int $ms): void
    {
        $requestId = Scope::get('ignis.request');
        if ($requestId === null) {
            throw new \LogicException('Ignis\\deadline() must be called inside a request');
        }
        self::$deadlines[\ignis_submit_sleep($ms)] = $requestId;
    }
}
