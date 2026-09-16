# Research 25 — Laravel on Ignis: Octane server contract vs. symfony/runtime-style vs. classic mode

BACKLOG M3-4 (research only, no code). Answers what a new Octane server must implement, what Ignis
already gives it (ADR-0006/V-11, the Symfony adapter), and recommends one of three routes with a
kill criterion for each.

## Sources (read from source, commit-pinned)

- `laravel/octane`, cloned to `/tmp/cmp/octane`, checked out at tag **v2.19.1**, commit
  **`68a2516a0318baba0de0e4648f61e335c5e69dca`** (2026-08-23). `composer.json` at that commit:
  `"php": "^8.1.0"`, `"laravel/framework": "^10.10.1|^11.0|^12.0|^13.0"`. All line numbers below are
  against this commit unless stated otherwise.
- `illuminate/container` (the read-only split of `laravel/framework`'s container), fetched at tag
  **v12.0.0** via `raw.githubusercontent.com/illuminate/container/v12.0.0/Container.php` — one file,
  read in full for the `$instance`/`setInstance`/`getInstance` question below. This is the one
  non-Octane framework file read for this note; everything else about Laravel's runtime behaviour is
  taken from what Octane's own source assumes about it, not from `laravel/framework` itself — flagged
  "not verified" where it matters.
- In-repo: `docs/adr/0006-fiber-scoped-superglobals.md`, `VALIDATION.md` V-11 (and its addendum),
  V-16 (and its addendum), V-40, `php/symfony/src/IgnisWorkerRunner.php`,
  `php/symfony/src/IgnisRuntime.php`, `php/classic.php`, `docs/pain-map.md` item 1.

## What Octane requires of a server

### The two interfaces a driver implements

`src/Contracts/Worker.php:8-32` — `Laravel\Octane\Contracts\Worker`:
```
boot(): void
handle(Request $request, RequestContext $context): void
handleTask($data)
terminate(): void
```
Octane ships one concrete implementation, `Laravel\Octane\Worker` (`src/Worker.php`), used by all
three shipped backends — a new server does **not** normally implement `Worker` itself, it implements:

`src/Contracts/Client.php:11-27` — `Laravel\Octane\Contracts\Client`:
```
marshalRequest(RequestContext $context): array   // [Illuminate\Http\Request, RequestContext]
respond(RequestContext $context, OctaneResponse $response): void
error(Throwable $e, Application $app, Request $request, RequestContext $context): void
```
Optional contracts a `Client` may also implement: `ServesStaticFiles`
(`src/Contracts/ServesStaticFiles.php`, checked at `src/Worker.php:65-70`), `StoppableClient`
(`src/Contracts/StoppableClient.php`, implemented by `RoadRunnerClient`), `ServerProcessInspector`.
`RequestContext` (`src/RequestContext.php:7-46`) is a bare `ArrayAccess` bag — whatever the client's
`marshalRequest` needs (`$context->swooleRequest`, `$context->psr7Request`, nothing at all for
FrankenPHP) — the server defines its own shape.

### The request loop shape — read from all three shipped worker scripts, not from docs

`bin/frankenphp-worker.php:73-76`:
```php
[$request, $context] = $frankenPhpClient->marshalRequest(new RequestContext());
$worker->handle($request, $context);
```
run inside `while ($requestCount < $maxRequests && @frankenphp_handle_request($handleRequest)) { … }`
(`bin/frankenphp-worker.php:88`) — `frankenphp_handle_request()` is a blocking call that hands back
control once per HTTP request, invoking the closure synchronously.

`bin/roadrunner-worker:44-56`:
```php
while ($psr7Request = $psr7Client->waitRequest()) {
    …
    [$request, $context] = $roadRunnerClient->marshalRequest(new RequestContext(['psr7Request' => $psr7Request]));
    $worker->handle($request, $context);
}
```
`PSR7Worker::waitRequest()` blocks for the next request.

Swoole does not use a hand-rolled loop; the HTTP server's own `onRequest` callback plays the same
role (`bin/createSwooleServer.php`, not read line-by-line here, but `SwooleClient::marshalRequest`
(`src/Swoole/SwooleClient.php:37-46`) is the same `[Request, RequestContext]` shape).

**All three loops call `$worker->handle()` once, wait for it to return, then read the next request.**
This is not incidental — see "the decisive finding" below.

### What `Worker::handle()` does between requests (`src/Worker.php:63-120`)

1. `CurrentApplication::set($sandbox = clone $this->app)` (line 75) — clone the long-lived `$app`
   into a per-request `$sandbox`.
2. `$gateway = new ApplicationGateway($this->app, $sandbox)`; `$gateway->handle($request)`
   (lines 77, 84) dispatches `RequestReceived`, then `$sandbox->make(Kernel::class)->handle($request)`
   (`src/ApplicationGateway.php:26-39`).
3. `$this->client->respond(...)` sends the response (line 95-98).
4. `$gateway->terminate($request, $response)` → `Kernel::terminate()` + `RequestTerminated` event +
   `Route::flushController()` (`src/ApplicationGateway.php:44-55`).
5. `finally`: `$sandbox->flush()`, forget the Blade/PHP view-engine-resolver entries on the **root**
   `$this->app` (lines 108-111), `unset()` the locals, `CurrentApplication::set($this->app)` restores
   the root container as "current" for the next iteration (line 118).

`CurrentApplication::set()` (`src/CurrentApplication.php:14-23`) is the mechanism:
```php
$app->instance('app', $app);
$app->instance(Container::class, $app);
Container::setInstance($app);
Facade::clearResolvedInstances();
Facade::setFacadeApplication($app);
```

Lifecycle events and the listeners that reset state — `config/octane.php:66-119` — `RequestReceived`
runs `Octane::prepareApplicationForNextRequest()` (8 listeners: locale, queued cookies, session,
auth, request scheme, `GiveNewRequestInstanceToApplication`/`…ToPaginator`) plus
`Octane::prepareApplicationForNextOperation()` (`src/Concerns/ProvidesDefaultConfigurationOptions.php:27-64`)
— **28 `GiveNewApplicationInstanceTo*`/`Flush*` listener classes**, one per Laravel manager singleton
that captured a reference to the previous sandbox: `DatabaseManager`, `SessionManager`,
`CacheManager`, `LogManager`, `QueueManager`, `MailManager`, `Router`, `ViewFactory`, `URL` generator,
the authorization `Gate`, `BroadcastManager`, `FilesystemManager`, `ValidationFactory`,
`PipelineHub`, plus `FlushDatabaseQueryLog`, `FlushArrayCache`, `FlushStrCache`,
`FlushTranslatorCache`, `FlushMonologState`, `FlushVite`, and first-party package hooks for Inertia,
Livewire, Scout, Socialite. `OperationTerminated` additionally runs `FlushOnce`
(`src/Listeners/FlushOnce.php:14-16`, calls `Illuminate\Support\Once::flush()`) and
`FlushTemporaryContainerInstances` (`src/Listeners/FlushTemporaryContainerInstances.php`, calls
`$event->app->resetScope()`/`forgetScopedInstances()` and `forgetInstance()` on every
`config('octane.flush')` binding).

### `octane:start --server=...` is a closed dispatch, not a plugin point

`src/Commands/StartCommand.php:49-55`:
```php
$server = $this->option('server') ?: config('octane.server');
return match ($server) {
    'swoole' => $this->startSwooleServer(),
    'roadrunner' => $this->startRoadRunnerServer(),
    'frankenphp' => $this->startFrankenPhpServer(),
    default => $this->invalidServer($server),
};
```
There is no config-driven registry mapping a server name to a `Client`/command class — it is a
hard-coded `match`. **A fourth server cannot make `octane:start --server=ignis` work without patching
this file.** A driver can only add its own Artisan command (e.g. `octane:ignis`, the same shape as
`octane:swoole`/`octane:roadrunner`) and document that entry point; it cannot honestly claim
"`octane:start --server=ignis`" as BACKLOG's phrasing assumes. This is a verified constraint, not a
guess — I read the whole `match` and grepped for a server registry and found none.

## What Ignis already gives (read from `docs/adr/0006-*.md`, `VALIDATION.md`, `php/symfony/`)

- **ADR-0006** (accepted, V-11): the four superglobals (`$_SERVER`/`$_GET`/`$_POST`/`$_COOKIE`) are
  swapped per fiber by a `zend_observer` fiber-switch hook, at +100 ns/switch (V-11), ≤ 0.7 µs after
  the E13' lazy-swap fix (V-11 addendum). `Ignis\Scope` is a userland `WeakMap` keyed by
  `Fiber::getCurrent()` for app-level per-request state.
- **V-16**: `symfony/skeleton` in worker mode with the container's `request_stack` service overridden
  to `Ignis\Symfony\FiberRequestStack` — 100 concurrent `/whoami` requests, 0 mismatches, because
  Symfony's own DI container lets you *substitute* the one stateful service (`RequestStack`) with a
  fiber-aware one. Symfony's container itself has no equivalent of `Container::$instance`.
- **`php/symfony/src/IgnisWorkerRunner.php:24-27`**: boots the kernel once, and per request just
  reads `$_SERVER`/`$_GET`/`$_POST`/`$_COOKIE` (already fiber-scoped) into
  `Request::createFromGlobals()`, plus a hand-built `Request` for non-form bodies. This is
  structurally identical to `FrankenPhpClient::marshalRequest()` (`src/FrankenPhp/FrankenPhpClient.php:19-25`),
  which is just `Request::capture()` — FrankenPHP populates real superglobals per worker call the
  same way Ignis's observer does per fiber. **This part of the problem is already solved and proven**
  (V-16, V-40) for the request side.
- **`php/classic.php`**: `Ignis\Classic\serve()` boots nothing itself — `Runner::$run` does a plain
  `include $file` per request inside `Ignis\serve()`'s fiber, with a documented assumption
  (`php/classic.php:9-17`): "a classic script must not suspend (no `Ignis\sleep`/async I/O inside the
  include)" because the header table and output buffers are per-OS-thread, not per-fiber. Classic
  mode is therefore **strictly one script running to completion per fiber-turn on its thread** — no
  two classic requests are ever mid-execution at once on the same thread.

## The decisive finding: `Container::$instance` is process-global, and Octane's own concurrency model never has two requests in it at once

`illuminate/container` v12.0.0, `Container.php:30`: `protected static $instance;` — a class-static
property, one per PHP engine (per Ignis OS thread, under ZTS, not per fiber: ZTS gives each thread
its own copy of class statics, but a thread's fibers share it, which is exactly why ADR-0006 needed
an observer hook for superglobals — those are also thread-shared until swapped). `setInstance()`
(`Container.php:1624-1627`) writes `static::$instance = $container` unconditionally, and `Worker::handle()`
calls it once per request via `CurrentApplication::set()` (`src/CurrentApplication.php:19`).
`Facade::clearResolvedInstances()`/`setFacadeApplication()` in the same method touch Facade's own
static cache. The ~28 `GiveNewApplicationInstanceTo*` listeners exist *because* Laravel's manager
singletons (`DatabaseManager`, `SessionManager`, `Router`, `ViewFactory`, the auth `Gate`, …) hold a
captured reference to the sandbox from the *previous* request and have to be manually re-pointed —
this is Laravel's container model working exactly as designed: one mutable, process-wide object
graph, reset by discipline between requests, not isolated by scope.

Octane never has to make this concurrency-safe, because **it never runs two requests through
`Worker::handle()` at once on the same engine**, verified directly in the loops that drive it:
- FrankenPHP: `frankenphp_handle_request($handleRequest)` is called, blocks, returns, and only then
  is it called again (`bin/frankenphp-worker.php:88`).
- RoadRunner: `while ($psr7Request = $psr7Client->waitRequest())` — `waitRequest()` blocks for the
  next request; `$worker->handle()` runs to completion first (`bin/roadrunner-worker:44-56`).
- Swoole: `defaultServerOptions()` sets **`'enable_coroutine' => false`** explicitly
  (`src/Commands/StartSwooleCommand.php:126`) — Octane deliberately disables Swoole's own per-request
  coroutine concurrency. Concurrency across all three backends comes from **multiple OS
  worker processes** (`worker_num` / RoadRunner workers / FrankenPHP threads), never from
  interleaving two requests on one engine.

Ignis's entire value proposition (`CLAUDE.md`, ADR-0006, V-11) is the opposite: many fibers,
concurrent I/O, one engine per OS thread. Running Octane's `Worker::handle()` — unmodified — as the
body of two interleaved `Ignis\serve()` fibers on one thread would let the second fiber's
`CurrentApplication::set()` clobber the container, facades, and every one of those ~28 manager
singletons the first fiber is still relying on mid-request, the moment the first fiber suspends
(`Ignis\sleep`, an awaited pg query, an outbound HTTP call). This is exactly the class of bug
ADR-0006 fixed for `$_SERVER`/`$_GET`/`$_POST`/`$_COOKIE`, and exactly the class `docs/pain-map.md`
item 1 already flags as open: "userland statics remain the app's problem (leak detector NOT
STARTED)". Laravel's container is such a static, and it is not optional plumbing — `app()`,
`resolve()`, every Facade, and Octane's own reset listeners all go through it.

## Three options, each with a kill criterion

**(a) An Octane server/client for Ignis** (`Ignis\Octane\IgnisClient implements Client`, a
`bin/ignis-worker.php` shaped like `bin/frankenphp-worker.php`, an `octane:ignis` Artisan command).
Structurally the closest fit — `IgnisClient::marshalRequest()` would be `FrankenPhpClient`'s
`Request::capture()` line verbatim, since ADR-0006 already gives per-fiber superglobals — and it
gets all ~28 state-reset listeners for free. But it is only *safe* the moment two fibers can be
mid-`handle()` at once, which is the reason to want Ignis over Octane's existing backends at all. Not
buildable today without first fiber-scoping `Container::$instance` (and `Facade`'s static caches) the
way ADR-0006 scoped the four superglobals — an engineering item at least as large as ADR-0006 itself,
unverified for feasibility, and untested for every manager singleton Octane's listener list names.
**Kill criterion:** a V-11/V-16-style concurrency test — N interleaved fiber requests each reading
`app()`, `Auth::user()`/a per-request-bound value, `DB::connection()`, and a bound service resolved
mid-request while another fiber suspends and resumes — must show **0 mismatches**, the same bar V-11
(superglobals) and V-16 (`RequestStack`) already cleared. If that test cannot be made to pass without
serializing `handle()` calls per thread (i.e., without giving up concurrent fibers, which defeats the
point), kill this option; ship (c) instead.

**(b) A symfony/runtime-style runner** (boot `bootstrap/app.php` once, call
`Illuminate\Contracts\Http\Kernel::handle()` per fiber, hand-reset what Octane resets). This
inherits the identical `Container::$instance` problem as (a) — nothing about it is Octane-specific,
it is intrinsic to Laravel's container — but discards Octane's ~28 already-written, already-tested
reset listeners and the sandbox-clone/`ApplicationGateway` machinery, requiring them to be
reimplemented by hand with no source to copy from (Octane's classes are the only public reference
implementation of "reset Laravel's manager singletons between requests" this research found).
**Kill criterion:** compare listener/class count needed against (a) — it is strictly more code for
the same unresolved concurrency problem, so it is dominated by (a) before any test is run; kill it
immediately, do not build a prototype.

**(c) `php/classic.php` per request, no worker mode.** `bootstrap/app.php` and `vendor/autoload.php`
are `include`d fresh inside the classic fiber every request, so `Container::$instance` is set by
Laravel's own bootstrap to a brand-new object each time — there is no cross-request sharing to
protect, because classic mode's own contract ("a classic script must not suspend") already forbids
the interleaving that would make it dangerous. This needs **zero new Ignis or adapter code** — the
same `php/classic.php` that already serves plain document roots. Cost: full framework boot every
request (autoload + service providers), the same price `php-fpm` already pays today, no Octane-style
warm-container win, and any blocking call (PDO, sync HTTP) blocks the whole OS thread for that
request's duration (V-12 already found PDO cannot be hooked). **Kill criterion:** benchmark a
`laravel/laravel` skeleton's `/` welcome route through `php/classic.php` (same methodology as V-40)
against `php-fpm` (V-6's floor, 9.9k req/s for a routeless hello) at 1 and N threads; if it is not at
least competitive with that floor, classic mode is not even a safe "no worse than today" fallback and
Laravel should wait rather than ship a slow default.

## Recommendation

Ship **(c) now** for M3-5: it requires no new code, is safe by construction (not by discipline), and
matches "M3 — Real apps unchanged." Treat **(a)** as the correct end state but **not buildable yet**:
it needs a precursor ADR that fiber-scopes `Illuminate\Container\Container::$instance` and
`Facade`'s static caches, on the same model as ADR-0006, validated by the concurrency test in (a)'s
kill criterion before any Octane `Worker::handle()` call is allowed inside an Ignis fiber that can be
interleaved. **Drop (b) outright** — it is strictly dominated by (a), same unsolved problem, more
code, no reference implementation to build from. This means BACKLOG M3-5 as currently scoped ("the
V-16 RequestStack test, for Laravel's container", implying concurrent interleaving) cannot be met
safely by any option available today; M3-5 should either be re-scoped to (c)'s serialized-by-
construction semantics (in which case the interleaving test is trivially true and proves nothing new)
or explicitly re-blocked on the precursor container-scoping ADR.

## Laravel/Octane versions verified

`laravel/octane` v2.19.1 (commit `68a2516a0318baba0de0e4648f61e335c5e69dca`), requiring
`laravel/framework` `^10.10.1|^11.0|^12.0|^13.0`. `illuminate/container` v12.0.0 read directly for
the `Container::$instance` claim. No other `laravel/framework` component was read from source in
this research.

## Claims not independently verified from source (flagged, not asserted)

- The exact behaviour of `frankenphp_handle_request()` (its C implementation) beyond what its usage
  in `bin/frankenphp-worker.php` shows (a blocking call, one request per invocation) — not read; only
  the PHP-side call site was read.
- `Spiral\RoadRunner\Http\PSR7Worker::waitRequest()`'s internal blocking behaviour — not read;
  inferred from the `while ($psr7Request = $psr7Client->waitRequest())` loop shape only.
- Swoole's coroutine scheduler semantics beyond the one `enable_coroutine => false` config line read
  in `StartSwooleCommand.php:126` — not read from the Swoole extension source.
- Any behaviour of `laravel/framework` outside `illuminate/container`'s `Container.php` (e.g. whether
  any Laravel manager other than the ~28 named by Octane's listener list also holds a stale sandbox
  reference) — not read; taken only from what Octane's own listener list documents it resets.
- (Resolved while drafting this note, no longer open) Whether Octane has any undocumented
  server-registration extension point: `src/OctaneServiceProvider.php` was read in full —
  `registerCommands()` (lines ~186-200) registers exactly `StartCommand`,
  `StartRoadRunnerCommand`, `StartSwooleCommand`, `StartFrankenPhpCommand`, `ReloadCommand`,
  `StatusCommand`, `StopCommand`. No config-driven or container-bound registry of servers exists
  anywhere in the provider. This confirms the `StartCommand::handle()` `match` finding above with no
  remaining doubt.
