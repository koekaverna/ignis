<?php
/**
 * Ignis userland scheduler (Cycle 1: fiber pool + HTTP).
 *
 * Rust primitives: ignis_submit_sleep(int $ms): int, ignis_poll(int $timeout_ms): array,
 * ignis_inflight(): int, ignis_serve(string $addr): bool,
 * ignis_respond(int $id, int $status, array $headers, string $body): bool.
 * Everything else — fibers, futures, all(), the pool, serve() — lives here,
 * shaped so it can become a Revolt driver (activate/dispatch/deactivate/now).
 */
declare(strict_types=1);

namespace Ignis {

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
                Loop::runUntil(fn () => $this->done);
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
}

class CancelledException extends \RuntimeException
{
}

final class DeadlineExceededException extends CancelledException
{
}

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
                if (self::$waiting === [] && self::$requestHandler === null && \ignis_inflight() === 0) {
                    break;
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
                if ($events === [] && self::$waiting === [] && self::$requestHandler === null && \ignis_inflight() === 0) {
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

    private static function dispatchRequest(int $id, array $raw): void
    {
        $handler = self::$requestHandler;
        $request = new Http\Request($raw['method'], $raw['uri'], $raw['headers'], $raw['body'], $id);
        self::spawn(static function () use ($handler, $request, $id): void {
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
        });
    }

    /** Throw $e into the request's fiber and its children at their suspension points (ADR-0009). */
    private static function cancelRequest(int $requestId, CancelledException $e, int $ageUs): void
    {
        $t0 = hrtime(true);
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

    private static function throwInto(\Fiber $fiber, \Throwable $e): void
    {
        if ($fiber->isTerminated() || !$fiber->isSuspended()) {
            return;
        }
        $opId = self::$parkedOn[\spl_object_id($fiber)] ?? null;
        if ($opId !== null) {
            unset(self::$waiting[$opId]); // parked in userland (Ignis\sleep / await)
            ++self::$resumes;
            $fiber->throw($e);
            return;
        }
        // Parked in a C stream op? Let Rust resume it with the exception.
        foreach (self::$waiting as $op => $f) {
            if ($f === $fiber) {
                unset(self::$waiting[$op]);
                ++self::$resumes;
                $fiber->throw($e);
                return;
            }
        }
        if (\function_exists('ignis_cancel_parked_any')) {
            // We do not know the op id of a C park; the Rust side searches its table.
            \ignis_cancel_parked_any($fiber, $e);
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

/**
 * Fiber-scoped storage (ADR-0006): values live exactly as long as the fiber that set them.
 * In {main} (no fiber) a plain static array is used.
 */
final class Scope
{
    private static ?\WeakMap $map = null;
    private static array $main = [];

    public static function set(string $key, mixed $value): void
    {
        $fiber = \Fiber::getCurrent();
        if ($fiber === null) {
            self::$main[$key] = $value;
            return;
        }
        self::$map ??= new \WeakMap();
        $bag = self::$map[$fiber] ?? [];
        $bag[$key] = $value;
        self::$map[$fiber] = $bag;
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        $fiber = \Fiber::getCurrent();
        if ($fiber === null) {
            return self::$main[$key] ?? $default;
        }
        return (self::$map?->offsetExists($fiber) ? self::$map[$fiber] : [])[$key] ?? $default;
    }
}

/** One wall-clock deadline for the current request, inherited by its Ignis\async children. */
function deadline(int $ms): void
{
    Loop::deadline($ms);
}

/** Non-blocking sleep: the fiber suspends, tokio owns the timer. */
function sleep(int $ms): void
{
    Loop::awaitOp(\ignis_submit_sleep($ms));
}

/** Run $fn concurrently in a (pooled) fiber. */
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

/**
 * Worker mode: serve HTTP forever, one pooled fiber per request.
 * @param callable(Http\Request):Http\Response $handler
 */
function serve(callable $handler, string $addr = '127.0.0.1:8080'): void
{
    Loop::serve($handler, $addr);
}

} // namespace Ignis

namespace Ignis\Http {

final class Request
{
    private ?array $query = null;

    /** @param array<string,string> $headers lower-cased names */
    public function __construct(
        public readonly string $method,
        public readonly string $uri,
        public readonly array $headers,
        public readonly string $body,
        /** Reactor request id (E10: gRPC handlers answer through it; 0 outside a served request). */
        public readonly int $id = 0,
    ) {
    }

    public function path(): string
    {
        $q = strpos($this->uri, '?');
        return $q === false ? $this->uri : substr($this->uri, 0, $q);
    }

    public function query(string $name): ?string
    {
        if ($this->query === null) {
            $q = strpos($this->uri, '?');
            $this->query = [];
            if ($q !== false) {
                parse_str(substr($this->uri, $q + 1), $this->query);
            }
        }
        $v = $this->query[$name] ?? null;
        return $v === null ? null : (string) $v;
    }

    public function header(string $name): ?string
    {
        return $this->headers[strtolower($name)] ?? null;
    }

    /**
     * CGI-style superglobals for this request: [$_SERVER, $_GET, $_POST, $_COOKIE].
     * @return array{0:array,1:array,2:array,3:array}
     */
    public function superglobals(): array
    {
        $q = strpos($this->uri, '?');
        $query = $q === false ? '' : substr($this->uri, $q + 1);
        $server = [
            'REQUEST_METHOD'  => $this->method,
            'REQUEST_URI'     => $this->uri,
            'QUERY_STRING'    => $query,
            'SCRIPT_NAME'     => '/index.php',
            'SERVER_PROTOCOL' => 'HTTP/1.1',
            'REQUEST_TIME'    => time(),
            'REQUEST_TIME_FLOAT' => microtime(true),
        ];
        foreach ($this->headers as $name => $value) {
            $server['HTTP_' . strtoupper(str_replace('-', '_', $name))] = $value;
        }
        if (isset($this->headers['content-type'])) {
            $server['CONTENT_TYPE'] = $this->headers['content-type'];
        }
        if (isset($this->headers['content-length'])) {
            $server['CONTENT_LENGTH'] = $this->headers['content-length'];
        }
        $get = [];
        parse_str($query, $get);
        $post = [];
        if ($this->method === 'POST' && str_starts_with($this->headers['content-type'] ?? '', 'application/x-www-form-urlencoded')) {
            parse_str($this->body, $post);
        }
        $cookie = [];
        foreach (explode(';', $this->headers['cookie'] ?? '') as $pair) {
            if (($eq = strpos($pair, '=')) !== false) {
                $cookie[trim(substr($pair, 0, $eq))] = urldecode(trim(substr($pair, $eq + 1)));
            }
        }
        return [$server, $get, $post, $cookie];
    }
}

final class Response
{
    /** @param array<string,string> $headers */
    public function __construct(
        public readonly string $body = '',
        public readonly int $status = 200,
        public readonly array $headers = [],
    ) {
    }

    public static function text(string $body, int $status = 200): self
    {
        return new self($body, $status, ['content-type' => 'text/plain; charset=utf-8']);
    }

    /** The handler already answered through another channel (gRPC stream, E10); the loop sends nothing. */
    public static function detached(): self
    {
        return new self('', 0, []);
    }

    public static function json(mixed $data, int $status = 200): self
    {
        return new self(json_encode($data, JSON_THROW_ON_ERROR), $status, ['content-type' => 'application/json']);
    }
}

} // namespace Ignis\Http
