# PHP API reference

Every public function, class and method a script or framework integration is meant to call.
Sources: `php/packages/runtime/src/ignis.php` (the userland scheduler — `Ignis\Loop`, `Future`, `async()`, `all()`,
`sleep()`, `deadline()`, `Scope`, `serve()`, `Ignis\Http\Request`/`Response`), `php/packages/runtime/src/classic.php`
(`Ignis\Classic`), `php/packages/runtime/src/Output.php` (`Ignis\Output`),
`php/packages/offload/src/ignis-offload.php` (`Ignis\Offload`, `Ignis\offload()`),
`php/packages/revolt/src/IgnisDriver.php` (`Ignis\Revolt\IgnisDriver`), `php/packages/symfony-runtime/src/*`
(`Ignis\Symfony`), `php/packages/doctrine/src/*` (`Ignis\Doctrine`), `php/packages/temporal/src/*`
(`Ignis\Temporal`), and the internal `ignis_*` function table registered from `crates/ignis/src/php/module.rs`.
Also cross-checked against `php/packages/runtime/stubs/ignis.php`, the static-analysis-only declaration file
(`scanFiles`, never executed — see below), and `examples/app.php`, the API spec.

The userland ships as one composer package per integration under `php/packages/` — `ignis/runtime`
is the scheduler and everything else `require`s it. Ten packages exist today (`composer.json`
names in parentheses): `runtime` (`ignis/runtime`), `symfony-runtime` (`ignis/symfony-runtime`),
`offload` (`ignis/offload`), `grpc` (`ignis/grpc`), `revolt` (`ignis/revolt`),
`swoole` (`ignis/swoole`), `doctrine` (`ignis/doctrine`), and three for Temporal:
`temporal-core-transport` (`ignis/temporal-core-transport`, host-agnostic — no dependency on
Ignis), `temporal` (`ignis/temporal`, ADR-0040: the current, supported host for the official
`temporalio/sdk-php`), and `temporal-prototype` (`ignis/temporal-prototype`, ADR-0013: the
pre-ADR-0040 workflow runtime, frozen — kept only because the replay test is built on it; new work
uses `ignis/temporal`). The list, and how to install them into an application, is in
[`php/README.md`](https://github.com/koekaverna/ignis/blob/main/php/README.md).

## `namespace Ignis` — the scheduler (`php/packages/runtime/src/ignis.php`)

### Top-level functions

| Signature | What it does |
|---|---|
| `sleep(int $ms): void` | Non-blocking sleep — suspends the current fiber; tokio owns the timer. Equivalent to `Loop::awaitOp(ignis_submit_sleep($ms))`. |
| `async(callable $fn, mixed ...$args): Future` | Runs `$fn(...$args)` concurrently in a (pooled) fiber; returns a `Future` you `await()`. Alias for `Loop::spawn()`. |
| `all(iterable<Future> $futures): array` | Awaits every future, returning their values in the same order/keys as given. Each `await()` can throw — a rejected future's exception propagates from here. |
| `deadline(int $ms): void` | Sets a wall-clock deadline for the **current request**: after `$ms`, the request's fiber and every fiber it spawned via `async()` receive a `DeadlineExceededException` at their suspension point. Must be called from inside a request (throws `LogicException` otherwise — there is no "current request" outside one). |
| `serve(callable(Http\Request):Http\Response $handler, string $addr = '127.0.0.1:8080'): void` | Worker mode: listens on `$addr`, runs `$handler` in a pooled fiber per request, forever. Wraps `Loop::serve()`. |
| `offload(string $fn, mixed ...$args): mixed` | See `Ignis\Offload` below — runs `$fn(...$args)` on a synchronous offload worker thread; the current fiber parks. Declared in `php/packages/offload/src/ignis-offload.php`, not `php/packages/runtime/src/ignis.php`. |

```php
Ignis\serve(function (Ignis\Http\Request $req): Ignis\Http\Response {
    Ignis\deadline(2000); // 504 if the handler doesn't finish in 2s
    [$a, $b] = Ignis\all([
        Ignis\async(fn () => file_get_contents('https://example.com/a')),
        Ignis\async(fn () => file_get_contents('https://example.com/b')),
    ]);
    return Ignis\Http\Response::json(['a' => $a, 'b' => $b]);
}, getenv('IGNIS_LISTEN') ?: '127.0.0.1:8080');
```

### `Ignis\Loop` (public methods/properties only)

The scheduler class behind every function above; shaped so it can become a Revolt driver
(`activate`/`dispatch`/`deactivate`/`now` — see `IgnisDriver` below). Most applications never call
`Loop` directly and use the top-level functions instead; integrations (`Ignis\Offload`, `ignis/doctrine`)
call `Loop::awaitOp()` and `Loop::spawn()` directly.

| Member | What it does |
|---|---|
| `static awaitOp(int $id): mixed` | Suspends the current fiber (or, outside a fiber, drives the loop with a throwaway one) until reactor op `$id` completes; returns its payload. The primitive every `Op::submit()`-returning `ignis_*` call is awaited through. |
| `static spawn(callable $fn, mixed ...$args): Future` | Same as `async()`; reuses a parked pool fiber when one is available (`ADR-0019` — this reuse is "the single biggest win of the project", per `ignis.php`'s file comment) or creates a new one. Automatically attributes the child fiber to the current request for cancellation (ADR-0009/E11). |
| `static idleFibers(): int` | Count of pool fibers currently parked and ready for reuse. |
| `static run(): void` | Runs the loop until nothing is waiting on anything and no server is listening. |
| `static runUntil(callable():bool $stop): void` | Runs the loop until `$stop()` returns `true`. Throws `LogicException` if the loop is already running (no re-entrant `run`/`runUntil`). |
| `static serve(callable(Http\Request):Http\Response $handler, string $addr): void` | Calls `ignis_serve($addr)`, installs `$handler`, then `run()`s forever. What `Ignis\serve()` wraps. |
| `static deadline(int $ms): void` | What `Ignis\deadline()` wraps. |
| `static budgetStats(): array` | `['budget', 'queue_depth', 'inflight', 'queued', 'queued_peak', 'queued_admitted', 'rejected']` — the ADR-0019 admission-control counters, e.g. for a `/stats` endpoint. |
| `static int $fiberBudget`, `static int $queueDepth` | Read-only in practice (set from `IGNIS_FIBER_BUDGET`/`IGNIS_QUEUE_DEPTH` on first use); `0` means unlimited/unbounded. |
| `static $rawRequestHandler` | `?callable(int $id, array $raw): void`. Set by `Ignis\Classic\listen()` to receive raw dispatched requests on the loop's own stack instead of a spawned fiber — see the "classic worker loop" note under `Ignis\Classic` below. Not something an application sets directly unless it is itself implementing an alternative request-dispatch mode. |

`Loop::markReady()`, `Loop::$unobserved`, `Loop::$offloadCallbackHandler`, `Loop::$children`,
`Loop::$deadlines`, `Loop::$parkedOn`, `Loop::$cancelled` etc. are marked `@internal` or are wiring
for other parts of the runtime (`Ignis\Offload\Client` sets `$offloadCallbackHandler`) — not part of
the application-facing surface, so not detailed here.

Since commit `9077206`, a failure while *sending* the answer (as opposed to a failure inside the
handler, which `runHandler()` already turns into a `500`/`499`/`504`) no longer silently drops the
request: it is logged and a `500` is attempted for it too, and if that second attempt also throws,
that is logged as well rather than left to surface later as an unrelated "unobserved rejection" that
could stop the whole loop.

### `Ignis\Future`

| Member | What it does |
|---|---|
| `isDone(): bool` | Whether the future has settled (resolved or rejected). |
| `resolve(mixed $value): void` | Settles with a value. Throws `LogicException` if already settled. |
| `reject(\Throwable $e): void` | Settles with an exception. |
| `await(): mixed` | Suspends the current fiber until settled; returns the value or rethrows the exception. Outside a fiber, drives the loop itself. A rejected future nobody ever `await()`s is reported when the loop stops (an "unobserved rejection"), rather than silently discarded. |

### `Ignis\Scope`

Fiber-scoped storage (ADR-0006): a value set inside one fiber is invisible to every other fiber and
is dropped when that fiber is garbage-collected (backed by a `WeakMap`). Outside any fiber (in
`{main}`), it's a plain static array.

| Member | What it does |
|---|---|
| `static set(string $key, mixed $value): void` | Stores `$value` under `$key`, scoped to the current fiber (or `{main}`). |
| `static get(string $key, mixed $default = null): mixed` | Reads it back; `$default` if unset. |

Used internally for `ignis.request` (the current request id, for cancellation/deadline routing) and
by `ignis/doctrine` (one entity manager and one connection per fiber) and Symfony's `FiberRequestStack`.

### `Ignis\Output` (`php/packages/runtime/src/Output.php`)

Everything a fiber writes, and nothing another fiber wrote. Under the `ignis` binary this is native
(the SAPI output hook attributes every `echo` to `EG(active_fiber)`, backed by `ignis_capture_start`/
`ignis_capture_take`/`ignis_capture_reset` below); under plain PHP (a unit test, an older binary) it
falls back to a lock-guarded `ob_start()`, because `ob_start()`'s stack is a thread resource that
does not tolerate interleaved fibers (V-72). An application does not need this for an ordinary
request or a `StreamedResponse` producer — the runtime already captures/streams those on its own —
it exists for code that wants a fiber-safe buffer of its own.

| Member | What it does |
|---|---|
| `static capture(callable $emit): string` | Runs `$emit()` and returns everything it wrote. Nesting inside the same fiber's own buffer never waits; a different fiber waits its turn only under the `ob_start()` fallback. |
| `static isHeld(): bool` | True while some fiber on this thread holds the **fallback** buffer. For tests. |
| `static reset(): void` | Drops whatever the current fiber left behind before it goes back to the pool (V-67); called by `Ignis\Loop::releaseRequest()` after every request. |

### Exceptions

| Class | Thrown when |
|---|---|
| `Ignis\CancelledException` | The client disconnected while the request (or a fiber it spawned) was in flight; the handler sees this at its suspension point. `Ignis\Loop`'s request dispatcher turns an unhandled one into a `499` response. Since commit `1b2244e` this also reaches a `StreamedResponse` producer parked *between* chunks (a `sleep()` or a query, not just an in-flight `Ignis\write()`) — before that fix, a client that left mid-body during such a wait went unnoticed and the producer kept producing (S2-STREAM-CANCEL). |
| `Ignis\DeadlineExceededException extends CancelledException` | `Ignis\deadline()`'s timer fired first. Turned into a `504` response if unhandled. |

### `namespace Ignis\Http`

| Class / member | What it does |
|---|---|
| `Request::__construct(string $method, string $uri, array $headers, string $body, int $id = 0)` | `$headers` keys are already lower-cased by the runtime. `$id` is the reactor request id (`0` outside a served request); gRPC handlers and `finish_request()`-style flows answer through it directly. |
| `Request::path(): string` | `$uri` with the query string (if any) stripped. |
| `Request::query(string $name): ?string` | One query-string parameter, parsed lazily and cached on first call. |
| `Request::header(string $name): ?string` | Case-insensitive header lookup. |
| `Request::superglobals(): array{0:array,1:array,2:array,3:array}` | `[$_SERVER, $_GET, $_POST, $_COOKIE]` built CGI-style from this request; what the runtime feeds to `ignis_set_superglobals()` per fiber (ADR-0006). `$_POST` is only populated for `application/x-www-form-urlencoded` bodies. |
| `Response::__construct(string $body = '', int $status = 200, array $headers = [])` | Plain value object; all three properties are `public readonly`. `$headers` is `array<string, string\|list<string>>`: a value can be a list so one name carries several header lines — `Set-Cookie` is the RFC 7230 exception that cannot be comma-joined into one line, and PHP's own array cannot hold the same string key twice (commit `4a4bf67`, S1-COOKIES). Any other array shape logs a warning and is skipped. |
| `Response::text(string $body, int $status = 200): self` | `text/plain; charset=utf-8`. |
| `Response::json(mixed $data, int $status = 200): self` | `application/json`, `JSON_THROW_ON_ERROR`. |
| *(removed)* `Response::detached()` | Was status `0` as a sentinel. A handler now says how it answered by what it **returns**: `Response` (the loop sends it), `StreamedResponse` (a producer the loop drives and ends), or `null` (answered through another channel, e.g. gRPC). |
| `Ignis\Http\StreamedResponse::__construct(\Closure $producer, int $status = 200, array $headers = [])` | A body written while the client reads. The producer takes no arguments and its return value is ignored, as Symfony's does — so `$response->sendContent(...)` is a producer as it stands — and it writes with `Ignis\write()` or `echo`. `\Closure` rather than `callable` so it can be promoted; first-class callable syntax already gives one. **Nothing is sent until the first byte**, which is why the loop owns the lifetime: a producer that throws before writing anything still becomes a real `500`, and once the status line is out an error can only truncate the body, which is all HTTP allows. Measured before the loop owned it: a handler that threw straight after opening a stream answered `HTTP/1.1 200 OK` with an empty chunked body (V-77). |
| `Ignis\write(string $chunk): void` | Sends one frame of the response this fiber is producing, and waits if the client is behind — which `echo` cannot do. |

## `namespace Ignis\Classic` (`php/packages/runtime/src/classic.php`)

Serves a document root of ordinary PHP scripts, one `include` per request — the FrankenPHP/RoadRunner-style worker mode, for legacy procedural apps that expect real top-level globals.

| Signature | What it does |
|---|---|
| `serve(string $docroot, string $addr, ?string $index = 'index.php', array $server = [], ?callable $run = null): void` | Front-controller-style classic mode: every request is dispatched to a spawned fiber like `Ignis\serve()`, running `$run` (default a plain `include $file`) per request. Use this for a framework front controller (Symfony, Laravel) that doesn't rely on real top-level globals. |
| `listen(string $docroot, string $addr, ?string $index = 'index.php', array $server = []): void` + `accept(): ?string` + `respond(): void` | The **top-level worker loop** shape — the only one where an entry script's own top-level variables become real globals (`$GLOBALS` and `global $x` both work; every alternative tried, including a function/closure/fiber, left `$GLOBALS` empty — V-53). One request at a time per thread, by construction: nothing else runs while the included script does. Required for legacy procedural apps that keep state in globals (WordPress's `$wpdb`, Drupal, etc.). |
| `finish(): never` | The classic-mode replacement for `exit()`: ends the current script's `include` via a caught internal exception; the runner sends the response and moves on. Calling real `exit()` instead escapes the fiber and ends the resident worker script entirely — always use `finish()`. |
| `finish_request(): bool` | Sends the response immediately and lets the script keep running after that (`fastcgi_finish_request()` analogue); any output after this point is dropped. Returns `false` if called outside a request or after the response was already sent. |

```php
require '.../php/packages/runtime/src/ignis.php';
require '.../php/packages/runtime/src/classic.php';
Ignis\Classic\listen('/var/www/html/public', '0.0.0.0:8080');
while ($script = Ignis\Classic\accept()) {
    try {
        include $script;          // top level of the main script: real globals
    } catch (Ignis\Classic\Finished) {
        // finish() ends the script, not the worker; without this it unwinds the loop
    }
    Ignis\Classic\respond();
}
```

**A top-level function or class an entry script declares unguarded fatals on the second request**
(V-54/V-53) — the worker process is resident, so a bare `function foo() {}` at the top level of a
script re-`include`d on request 2 is a redeclaration error. Guard with `function_exists()` or use
`require_once`, exactly the rule every other PHP worker runtime (RoadRunner, FrankenPHP worker
mode) already imposes.

Also declared (global namespace, only if not already defined — e.g. under `Ignis\Classic`):
`getallheaders()`, `apache_request_headers()`, `apache_response_headers()` polyfills built from
`$_SERVER`'s `HTTP_*` entries and the sent header list.

## `namespace Ignis\Offload` (`php/packages/offload/src/ignis-offload.php`, E16/ADR-0016)

| Signature | What it does |
|---|---|
| `Ignis\offload(string $fn, mixed ...$args): mixed` | The entry point: runs the named function (or `"Class::method"`) on a synchronous offload worker thread. Arguments are `serialize()`d in, the result is `serialize()`d back; the calling fiber parks meanwhile. A `\Closure` anywhere in `$args` becomes a callback the worker can invoke back on the calling thread (itself allowed to `await`, since it runs in its own spawned fiber). Throws `RuntimeException` if no offload pool exists (`ignis` wasn't started with `--offload N`). |
| `Offload\Client::stats(): array` | `['workers', 'busy', 'done', 'queued', 'this']` (`ignis_offload_stats()` passthrough; `this` is the calling thread's own worker index, or `-1`). |
| `Offload\Router::enable(): void` | Turns on E16 **auto-routing**: configured internal functions/classes (`IGNIS_OFFLOAD_FUNCTIONS`/`IGNIS_OFFLOAD_CLASSES`, see `configuration.md`) are transparently redirected to the offload pool when called inside a fiber. Called automatically at file-load time whenever `ignis_route_enable` exists and at least one offload worker is running — an application does not normally call this itself. |
| `Offload\Handle` | What a routed call returns for a class with no generated proxy (e.g. a `final` class like `CurlHandle`): `__call()` forwards every method to the worker; released automatically on destruction. |
| `Offload\RemoteException extends \RuntimeException` | Thrown when the offloaded call failed on the worker side; carries `$remoteClass`, the message/code, and `$remoteTrace`. |

```php
// ignis --offload 4 app.php
$result = Ignis\offload('some_slow_c_extension_call', $arg1, $arg2);
```

With auto-routing on (the default whenever `--offload N > 0`), ordinary code is unaffected —
`new SQLite3(...)`/`new PDO(...)` calls made *inside a fiber* run on an offload worker without any
source change; the same calls made outside a fiber (worker thread 0, or an offload worker itself)
run exactly as stock PHP.

## `Ignis\Revolt\IgnisDriver` (`php/packages/revolt/src/IgnisDriver.php`, ADR-0008/E7)

A `Revolt\EventLoop\Internal\AbstractDriver` implementation over the Ignis reactor — lets
`amphp`/Revolt-based code (and anything built on it) run unmodified on Ignis. Select it with the
`REVOLT_DRIVER` environment variable:

```
REVOLT_DRIVER=Ignis\Revolt\IgnisDriver
```

No application code calls this class directly; Revolt's `EventLoop` picks it up by class name.
Constructing it when `ignis_poll` doesn't exist (i.e. not actually running under the `ignis`
binary) throws `Revolt\EventLoop\UnsupportedFeatureException`. Signal callbacks
(`SignalCallback`) are not supported yet and also throw `UnsupportedFeatureException` if a caller
registers one.

## `namespace Ignis\Symfony` (`php/packages/symfony-runtime/src/*`, ADR-0011)

The `symfony/runtime` adapter for worker mode. Select it via the standard Symfony Runtime
mechanism:

```
APP_RUNTIME=Ignis\Symfony\IgnisRuntime
```

| Class | What it does |
|---|---|
| `IgnisRuntime extends SymfonyRuntime` | `getRunner($application)`: if `$application` is an `HttpKernelInterface` and `ignis_serve` exists, returns an `IgnisWorkerRunner` listening on `$this->options['ignis_listen']` (a runtime option, e.g. from `composer.json`'s `extra.runtime`) or `getenv('IGNIS_LISTEN')`, falling back to `127.0.0.1:8080`. Otherwise defers to the parent (stock `SymfonyRuntime`) runner — so selecting this runtime is harmless for non-HTTP-kernel apps (console commands, etc.). |
| `IgnisWorkerRunner implements RunnerInterface` | Boots the kernel once, then `Ignis\serve()`s: every HTTP request gets its own fiber, is adapted into a Symfony `Request` via `Request::createFromGlobals()` (superglobals are already this fiber's own, per ADR-0006) and the response's headers/content are copied back into an `Ignis\Http\Response`. Calls `$kernel->terminate()` when the kernel is `TerminableInterface`. Not constructed directly by application code — `IgnisRuntime::getRunner()` builds it. |
| `FiberRequestStack extends RequestStack` | One Symfony request stack **per fiber**, backed by `Ignis\Scope`, so concurrently interleaved requests never see each other's `Request` via `RequestStack::getCurrentRequest()`. Wired in as a service by the Symfony integration; not something application code instantiates by hand. |

## `namespace Ignis\Doctrine` (`php/packages/doctrine/src/*`)

Doctrine ORM under fibers: one `EntityManager` **and one database connection** per fiber instead of
one per process, so two overlapping requests never share an identity map, a transaction the other
one left open, or a PostgreSQL socket. **Two connection modes**, chosen in `config/packages/ignis_doctrine.yaml` — `pool.size: 0` (or no `pool` key) for per-fiber, `pool.size: N` for the pool. Not an environment variable: the numbers are service arguments like any other, and a deployment that wants them outside the code writes `%env(int:DB_POOL)%` there. (`IGNIS_DOCTRINE_POOL` was documented here until 2026-09-18 and never existed in the code.)

- **per fiber** (default): every request that touches the database opens its own connection and
  closes it at request end — about 1 ms of TCP plus SCRAM per request, which is what php-fpm does
  without `pconnect`, and no ceiling on how many connections a burst opens.
- **pool**: a fixed set per thread, opened while the thread boots and leased one fiber at a time.
  The process opens `threads × size` connections and no more; a request that arrives when every
  connection is leased parks until one comes back, and is refused after `wait_ms` rather than
  waiting behind a stuck request.

The mode is set per Doctrine connection, in the shape `doctrine.dbal` uses: one block for all of
them, and names for the ones that differ.

```yaml
# config/packages/ignis_doctrine.yaml — the default is a connection per fiber
ignis_doctrine:
    pool:
        size: 10          # connections per thread; 0 keeps the per-fiber mode
        warm: 10          # opened at boot; defaults to size, 0 fills lazily
        wait_ms: 5000     # before Ignis\Doctrine\Pool\PoolTimeoutException

    connections:          # every key falls back to the block above
        reporting:
            pool: { size: 2, wait_ms: 500 }
        archive:
            pool: { size: 0 }        # this one stays a connection per fiber
```

The names are Doctrine's own (`doctrine.dbal.connections`); one that matches none of them fails the
container build rather than silently doing nothing. Measured in `bench/e24`: in one application with
`default` at 4 and `reporting` at 1, four concurrent queries spread over **4** backends on the first
connection and **1** on the second.

Deployment-time numbers stay deployment-time without the package reading the environment itself:
`size: '%env(int:DB_POOL)%'` is Symfony's own mechanism and resolves at runtime, so a warmed
container cache does not pin it.

Requires
`ignis/symfony-runtime`. Register `IgnisDoctrineBundle` **after** `DoctrineBundle` in
`config/bundles.php`:

```php
Ignis\Doctrine\IgnisDoctrineBundle::class => ['all' => true],
```

| Class | What it does |
|---|---|
| `IgnisDoctrineBundle extends Bundle` | Adds `DoctrineFiberScopePass`, a compiler pass that rewrites the `EntityManager` service definition so every fiber resolves its own instance from a non-shared inner definition, and marks every connection in `doctrine.connections` non-shared so the fiber's manager opens its own. Without this bundle Doctrine's `EntityManager` stays one shared object across every fiber on the thread, **and so does its database connection** — which under PostgreSQL means two overlapping requests inside one socket: measured as one request receiving another's row with a 200, plus `SQLSTATE[HY000] 7 timeout expired` for the rest (V-85). |
| `Pool\PoolingMiddleware implements Doctrine\DBAL\Driver\Middleware` | The switch between the two connection modes, registered per Doctrine connection by `DoctrinePoolPass` (tagged `doctrine.middleware` with that connection's name). With its size at 0 it hands the driver back untouched — a connection per fiber, opened and closed per request. With a size it wraps the driver so `connect()` leases from a per-thread `ConnectionPool` instead. Its three numbers are ordinary constructor arguments, which is what makes them configurable in code. |
| `Pool\ConnectionPool` | At most `pool.size` connections per thread, each leased to exactly one fiber at a time. Fills itself to `pool.warm` when created (at boot, via the bundle), parks a fiber that finds every connection leased — `pool.wait_ms`, then `PoolTimeoutException` — and clears the session (`ROLLBACK; CLOSE ALL; RESET ALL; …`, the V-21 expansion of `DISCARD ALL`) before a connection is handed on. PostgreSQL only: other drivers get no reset, so a reused connection carries its session settings into the next request. |
| `FiberManager` | What the fiber's scope actually holds: the manager plus a destructor that rolls back an open transaction, closes the connection and clears the manager. It exists because `EntityManager` and `UnitOfWork` reference each other, so dropping the manager frees nothing until a cycle collection — measured as 8 to 31 PostgreSQL backends still open after 30 sequential requests (V-85). The handle is in no cycle, so `Scope::clear()` at request end releases the connection there and then. |
| `FiberEntityManager implements EntityManagerInterface, ResetInterface` | The shared object every application service keeps injected; each of its ~35 interface methods forwards to `Ignis\Scope`'s per-fiber real `EntityManager`, resolved on every call rather than fixed at construction — the decorator itself is not extended from Doctrine's own `EntityManagerDecorator`, which reads `$this->wrapped` directly, exactly the thing that must stay dynamic. Not constructed directly by application code. |

Not covered in full here: `DependencyInjection\DoctrineFiberScopePass`, the compiler pass itself.

## `namespace Ignis\Temporal` (`php/packages/temporal/src/*`, ADR-0040)

The current, supported host for the official `temporalio/sdk-php` — runs its worker as one
workflow fiber (workflow code never really waits, so one is enough and one is required) plus a
pool of activity fibers, RoadRunner's activity-worker-pool shape with fibers instead of processes.
Depends on `ignis/temporal-core-transport` (a standalone, host-agnostic package implementing
`ActivationSource`/`CodecInterface` over sdk-core's wire format — written to be offered upstream,
not itself covered here) and `ignis/grpc`.

| Class | What it does |
|---|---|
| `CoreSource` | Implements `Temporal\Worker\Transport\Core\ActivationSource` over the eight `ignis_temporal_*` primitives above (`ignis_temporal_connect`/`poll`/`poll_activity`/`complete`/`complete_activity`/`heartbeat`/`shutdown`) and starts the workflow/activity worker fibers. Not constructed directly by application code — it is the transport `CoreWorkerFactory` is given. |
| `GrpcServiceCall` | Answers sdk-php's own gRPC client calls (used by `WorkflowClient` to start/signal/query workflows) through `ignis_grpc_call()` — "there is no new Rust for this", per the file's own docblock, since `ignis_grpc_call()` was already a generic unary call by path. |

`ignis/temporal-prototype` (`php/packages/temporal-prototype/src/ignis-temporal.php`, ADR-0013) is
the pre-ADR-0040 workflow runtime of our own that `Ignis\Temporal` replaces for new work — frozen,
kept only because the replay negative-control test is built on it, and not covered in full here.

## Internal `ignis_*` functions (`crates/ignis/src/php/module.rs`)

These are the raw primitives the runtime registers from Rust. **They are internal — use the
`Ignis\*` wrappers above.** Calling them directly bypasses the scheduler's bookkeeping (fiber
attribution, admission control, cancellation routing) and is not a supported integration point,
except for the two noted below.

| Function | Wrapped by |
|---|---|
| `ignis_submit_sleep(int $ms): int` | `Ignis\sleep()` |
| `ignis_poll(int $timeout_ms): array` | `Ignis\Loop::run()`/`runUntil()` (the runtime's single wait point) |
| `ignis_inflight(): int` | — (used for least-inflight request dispatch across threads, ADR-0010) |
| `ignis_stats(): array` | — (`['threads', 'stalled', 'restarts']`, ADR-0012) |
| `ignis_serve(string $addr): bool` | `Ignis\serve()` / `Ignis\Loop::serve()` |
| `ignis_respond(int $id, int $status, array $headers, string $body): bool` | `Ignis\Loop`'s request dispatch |
| `ignis_respond_chunk(int $id, string $bytes): int` | `Ignis\Loop`'s streaming dispatch (`endStream()`) — sends whatever `ignis_stream_unbind()` reports as the unflushed tail; returns `0` if taken immediately or an op id to `await()` if the channel is full |
| `ignis_respond_end(int $id): bool` | Same — closes a streamed body once the producer is done |
| `ignis_capture_start(): bool` / `ignis_capture_take(): string` / `ignis_capture_reset(): bool` | `Ignis\Output::capture()`/`reset()` — the native per-fiber output buffer (ADR-0006 applied to `sapi_module.ub_write`, V-72/V-67) |
| `ignis_stream_bind(int $id, int $status, array $headers): bool` / `ignis_stream_unbind(): array` | `Ignis\Loop`'s streaming dispatch — binds this fiber's `echo`/`Ignis\write()` output directly to response `$id`'s body; `_unbind` stops forwarding and reports `[string $tail, bool $started]` |
| `ignis_stream_write(string $bytes): int` | `Ignis\write()` — one frame of the currently bound stream; `0` taken, an op id to `await()` if the channel is full, `-1` if this fiber is not streaming |
| `ignis_publish_stats(array $stats): void` | `Ignis\Loop`'s own bookkeeping (M4-4) — hands the loop's counters to the runtime so `/_ignis/metrics` can answer while PHP is busy; not called by application code |
| `ignis_set_superglobals(array, array, array, array): void` | `Ignis\Loop`'s request dispatch (ADR-0006) |
| `ignis_cancel_parked_any(\Fiber, \Throwable): bool` | `Ignis\Loop::throwInto()` (ADR-0009 cancellation) |
| `ignis_grpc_send`/`_end`/`_call`/`_recv` | `php/packages/grpc/src/ignis-grpc.php` (E10; not covered in full by this reference) |
| `ignis_offload_submit`/`_next`/`_done`/`_callback`/`_cb_result`/`_stats` | `Ignis\Offload\Client`/`offload()` above |
| `ignis_route_enable`/`_route_pass` | `Ignis\Offload\Router` above |
| `ignis_temporal_*` (8 functions — `connect`, `replay`, `poll`, `complete`, `poll_activity`, `complete_activity`, `heartbeat`, `shutdown`; `feature = "temporal"` builds only) | Two consumers, neither covered in full by this reference: `php/packages/temporal-prototype/src/ignis-temporal.php` (the frozen ADR-0013 workflow runtime of our own) and `Ignis\Temporal\CoreSource` (`php/packages/temporal/src/CoreSource.php`, ADR-0040 — the current, supported host, which drives the official `temporalio/sdk-php` over the same primitives via `ignis/temporal-core-transport`'s `ActivationSource`). |
| `ignis_park_on`/`ignis_op_result` (`cfg(php_async_abi)` builds only — the true-async backend, ADR-0003) | `backend/async_core.rs`'s PHP-side counterpart |

**Two exceptions a custom event-loop integration legitimately calls directly** (as `IgnisDriver`
does): `ignis_watch(resource $stream, int $mode): int` (one-shot readiness watch, mode `1` =
readable / `2` = writable, ADR-0008) and `ignis_cancel(int $op): int` (cancels a pending
`ignis_watch`). Every other application should go through `Ignis\sleep()`/`async()`/the integrations above.

### Consistency with `php/packages/runtime/stubs/ignis.php`

`php/packages/runtime/stubs/ignis.php` (a **declaration-only** file for static analysers: every
function throws `LogicException` if the real binary somehow reaches one, and since commit
`5b88148` it is no longer `require`d by anything — it was removed from `php/composer.json`'s
`autoload-dev.files` and is instead read by phpstan through `scanFiles`, which parses a file
without executing it) declares every function above, **plus** the 8 `ignis_temporal_*` functions
and `ignis_park_on`/`ignis_op_result` unconditionally — i.e. it is a superset of any single build's
function table, since those two groups only exist in the `feature = "temporal"` and
`cfg(php_async_abi)` builds of `module.rs::FUNCTIONS` respectively. That is intentional (a stub
file has to cover every build an IDE might target) and not a discrepancy. `StubsMatchTheBinaryTest`
(`php/packages/runtime/tests/`) is the guard, and since 2026-09-18 it checks both directions: every
`ignis_*` call site under `php/packages` and `examples/` has a declaration here, **and** every
`fe(c"ignis_…")` in the three `FUNCTIONS` tables of `module.rs` has one too. It used to check only
the first, which is how `ignis_respond_start` sat in the default build's table with no stub and no
caller until the audit found it — the registration is deleted now, and a test that carries "matches
the binary" in its name reads the binary.
