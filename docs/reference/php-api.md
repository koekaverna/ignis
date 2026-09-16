# PHP API reference

Every public function, class and method a script or framework integration is meant to call.
Sources: `php/ignis.php` (the userland scheduler — `Ignis\Loop`, `Future`, `async()`, `all()`,
`sleep()`, `deadline()`, `Scope`, `serve()`, `Ignis\Http\Request`/`Response`), `php/classic.php`
(`Ignis\Classic`), `php/pg/ignis-pg.php` (`Ignis\Pg`), `php/offload/ignis-offload.php`
(`Ignis\Offload`, `Ignis\offload()`), `php/amphp/src/IgnisDriver.php` (`Ignis\Revolt\IgnisDriver`),
`php/symfony/src/*` (`Ignis\Symfony`), and the internal `ignis_*` function table registered from
`crates/ignis/src/php/module.rs`. Also cross-checked against `php/stubs/ignis.php`, the
IDE/static-analysis stub file.

## `namespace Ignis` — the scheduler (`php/ignis.php`)

### Top-level functions

| Signature | What it does |
|---|---|
| `sleep(int $ms): void` | Non-blocking sleep — suspends the current fiber; tokio owns the timer. Equivalent to `Loop::awaitOp(ignis_submit_sleep($ms))`. |
| `async(callable $fn, mixed ...$args): Future` | Runs `$fn(...$args)` concurrently in a (pooled) fiber; returns a `Future` you `await()`. Alias for `Loop::spawn()`. |
| `all(iterable<Future> $futures): array` | Awaits every future, returning their values in the same order/keys as given. Each `await()` can throw — a rejected future's exception propagates from here. |
| `deadline(int $ms): void` | Sets a wall-clock deadline for the **current request**: after `$ms`, the request's fiber and every fiber it spawned via `async()` receive a `DeadlineExceededException` at their suspension point. Must be called from inside a request (throws `LogicException` otherwise — there is no "current request" outside one). |
| `serve(callable(Http\Request):Http\Response $handler, string $addr = '127.0.0.1:8080'): void` | Worker mode: listens on `$addr`, runs `$handler` in a pooled fiber per request, forever. Wraps `Loop::serve()`. |
| `offload(string $fn, mixed ...$args): mixed` | See `Ignis\Offload` below — runs `$fn(...$args)` on a synchronous offload worker thread; the current fiber parks. Declared in `php/offload/ignis-offload.php`, not `php/ignis.php`. |

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
`Loop` directly and use the top-level functions instead; integrations (`Ignis\Pg`, `Ignis\Offload`)
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
by `Ignis\Pg` (one lease per fiber per pool) and Symfony's `FiberRequestStack`.

### Exceptions

| Class | Thrown when |
|---|---|
| `Ignis\CancelledException` | The client disconnected while the request (or a fiber it spawned) was in flight; the handler sees this at its suspension point. `Ignis\Loop`'s request dispatcher turns an unhandled one into a `499` response. |
| `Ignis\DeadlineExceededException extends CancelledException` | `Ignis\deadline()`'s timer fired first. Turned into a `504` response if unhandled. |

### `namespace Ignis\Http`

| Class / member | What it does |
|---|---|
| `Request::__construct(string $method, string $uri, array $headers, string $body, int $id = 0)` | `$headers` keys are already lower-cased by the runtime. `$id` is the reactor request id (`0` outside a served request); gRPC handlers and `finish_request()`-style flows answer through it directly. |
| `Request::path(): string` | `$uri` with the query string (if any) stripped. |
| `Request::query(string $name): ?string` | One query-string parameter, parsed lazily and cached on first call. |
| `Request::header(string $name): ?string` | Case-insensitive header lookup. |
| `Request::superglobals(): array{0:array,1:array,2:array,3:array}` | `[$_SERVER, $_GET, $_POST, $_COOKIE]` built CGI-style from this request; what the runtime feeds to `ignis_set_superglobals()` per fiber (ADR-0006). `$_POST` is only populated for `application/x-www-form-urlencoded` bodies. |
| `Response::__construct(string $body = '', int $status = 200, array $headers = [])` | Plain value object; all three properties are `public readonly`. |
| `Response::text(string $body, int $status = 200): self` | `text/plain; charset=utf-8`. |
| `Response::json(mixed $data, int $status = 200): self` | `application/json`, `JSON_THROW_ON_ERROR`. |
| `Response::detached(): self` | Status `0` — tells the loop the handler already answered through another channel (a gRPC stream, E10) and to send nothing itself. |

## `namespace Ignis\Classic` (`php/classic.php`)

Serves a document root of ordinary PHP scripts, one `include` per request — the FrankenPHP/RoadRunner-style worker mode, for legacy procedural apps that expect real top-level globals.

| Signature | What it does |
|---|---|
| `serve(string $docroot, string $addr, ?string $index = 'index.php', array $server = [], ?callable $run = null): void` | Front-controller-style classic mode: every request is dispatched to a spawned fiber like `Ignis\serve()`, running `$run` (default a plain `include $file`) per request. Use this for a framework front controller (Symfony, Laravel) that doesn't rely on real top-level globals. |
| `listen(string $docroot, string $addr, ?string $index = 'index.php', array $server = []): void` + `accept(): ?string` + `respond(): void` | The **top-level worker loop** shape — the only one where an entry script's own top-level variables become real globals (`$GLOBALS` and `global $x` both work; every alternative tried, including a function/closure/fiber, left `$GLOBALS` empty — V-53). One request at a time per thread, by construction: nothing else runs while the included script does. Required for legacy procedural apps that keep state in globals (WordPress's `$wpdb`, Drupal, etc.). |
| `finish(): never` | The classic-mode replacement for `exit()`: ends the current script's `include` via a caught internal exception; the runner sends the response and moves on. Calling real `exit()` instead escapes the fiber and ends the resident worker script entirely — always use `finish()`. |
| `finish_request(): bool` | Sends the response immediately and lets the script keep running after that (`fastcgi_finish_request()` analogue); any output after this point is dropped. Returns `false` if called outside a request or after the response was already sent. |

```php
require '.../php/ignis.php';
require '.../php/classic.php';
Ignis\Classic\listen('/var/www/html/public', '0.0.0.0:8080');
while ($script = Ignis\Classic\accept()) {
    include $script;              // top level of the main script: real globals
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

## `namespace Ignis\Pg` (`php/pg/ignis-pg.php`, E14/ADR-0015)

Connections belong to the runtime (a process-wide pool); PHP code holds *leases*.

| Class / member | What it does |
|---|---|
| `Pool::__construct(string $dsn, int $max = 10)` | Opens a pool (`ignis_pg_open`, no I/O yet) shared by every thread/fiber in the process. |
| `Pool::acquire(): Lease` | Leases a connection for the current fiber. A **second** `acquire()` from a pool already leased in the same fiber throws `LeaseError` — never waits (one lease per fiber per pool, tracked via `Ignis\Scope`). |
| `Pool::query(string $sql, array $params = []): array` | Acquire → query → release in one call; returns the row list. |
| `Pool::exec(string $sql, array $params = []): int` | Acquire → exec → release; returns the affected-row count. |
| `Pool::transaction(callable(Lease):mixed $fn): mixed` | Runs `$fn($lease)` inside `BEGIN`/`COMMIT` on one leased connection; any `\Throwable` triggers `ROLLBACK` and rethrows. |
| `Pool::stats(): ?array` | `['idle', 'created', 'available', 'oldest_lease_ms', 'leases_over_warn']` (the last two per `IGNIS_PG_LEASE_WARN_MS`, see `configuration.md`); `null` if the pool id is unknown to the runtime. |
| `Lease::query(string $sql, array $params = []): array` | Rows only. |
| `Lease::exec(string $sql, array $params = []): int` | Affected-row count only. |
| `Lease::backendPid(): int` | `SELECT pg_backend_pid()` convenience. |
| `Lease::release(bool $reset = true): void` | Returns the connection to the pool; `$reset` runs `ROLLBACK; DISCARD ALL` server-side first. Safe to call more than once (a no-op after the first). |
| `Lease::__destruct()` | Fire-and-forget release if the script never called `release()` explicitly — nobody awaits this completion. |

`LeaseError extends \LogicException` (double-acquire, or using a released lease);
`QueryError extends \RuntimeException` (the query itself failed server-side).

```php
$pool = new Ignis\Pg\Pool('postgres://user:pass@localhost/db', max: 10);
$rows = $pool->query('SELECT id, name FROM users WHERE id = $1', [42]);
$pool->transaction(function (Ignis\Pg\Lease $l) {
    $l->exec('UPDATE accounts SET balance = balance - 100 WHERE id = $1', [1]);
    $l->exec('UPDATE accounts SET balance = balance + 100 WHERE id = $1', [2]);
});
```

## `namespace Ignis\Offload` (`php/offload/ignis-offload.php`, E16/ADR-0016)

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
`curl_exec()`/`new PDO(...)` calls made *inside a fiber* run on an offload worker without any
source change; the same calls made outside a fiber (worker thread 0, or an offload worker itself)
run exactly as stock PHP.

## `Ignis\Revolt\IgnisDriver` (`php/amphp/src/IgnisDriver.php`, ADR-0008/E7)

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

## `namespace Ignis\Symfony` (`php/symfony/src/*`, ADR-0011)

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
| `ignis_set_superglobals(array, array, array, array): void` | `Ignis\Loop`'s request dispatch (ADR-0006) |
| `ignis_cancel_parked_any(\Fiber, \Throwable): bool` | `Ignis\Loop::throwInto()` (ADR-0009 cancellation) |
| `ignis_grpc_send`/`_end`/`_call`/`_recv` | `php/grpc/ignis-grpc.php` (E10; not one of the four files this reference documents in full) |
| `ignis_pg_open`/`_acquire`/`_query`/`_release`/`_stats` | `Ignis\Pg\Pool`/`Lease` above |
| `ignis_offload_submit`/`_next`/`_done`/`_callback`/`_cb_result`/`_stats` | `Ignis\Offload\Client`/`offload()` above |
| `ignis_route_enable`/`_route_pass` | `Ignis\Offload\Router` above |
| `ignis_temporal_*` (7 functions, `feature = "temporal"` builds only) | `php/temporal/ignis-temporal.php` (not covered by this reference) |
| `ignis_park_on`/`ignis_op_result` (`cfg(php_async_abi)` builds only — the true-async backend, ADR-0003) | `backend/async_core.rs`'s PHP-side counterpart |

**Two exceptions a custom event-loop integration legitimately calls directly** (as `IgnisDriver`
does): `ignis_watch(resource $stream, int $mode): int` (one-shot readiness watch, mode `1` =
readable / `2` = writable, ADR-0008) and `ignis_cancel(int $op): int` (cancels a pending
`ignis_watch`). Every other application should go through `Ignis\sleep()`/`async()`/`Ignis\Pg`/etc.

### Consistency with `php/stubs/ignis.php`

`php/stubs/ignis.php` (IDE/static-analysis stubs, guarded with `function_exists()` and throwing if
somehow reached under the real binary) declares every function above, **plus** the 7
`ignis_temporal_*` functions and `ignis_park_on`/`ignis_op_result` unconditionally — i.e. it is a
superset of any single build's function table, since those two groups only exist in the
`feature = "temporal"` and `cfg(php_async_abi)` builds of `module.rs::FUNCTIONS` respectively. That
is intentional (a stub file has to cover every build an IDE might target) and not a discrepancy.
No function present in the default build's `FUNCTIONS` table is missing from the stub file, and
vice versa.
