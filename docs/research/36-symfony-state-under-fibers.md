# Research 36 — Symfony service state when two requests overlap inside one PHP thread

Date: 2026-09-17. Question from the owner: **which Symfony services are unsafe when two requests
overlap inside one PHP thread, and what is the right treatment for each.**

The subject is the owner's application at `/home/koe/projects/symfony-ignis` — Symfony **8.1.7**
(`vendor/symfony/http-kernel/Kernel.php:48`), FrameworkBundle only
(`config/bundles.php`), `session: true` (`config/packages/framework.yaml:6`), filesystem cache,
4 threads × 1024 fibers (`ignis.toml`). It is read-only: nothing was written there, nothing was run
there, and the container was **not** booted. Everything below comes from the already-compiled
container dump under `var/cache/prod/` and from the vendor sources, plus one probe run in the
session scratchpad against a transcription of `Kernel.php` (§3).

Two sentences of ground truth before the tables:

- The app already overrides `request_stack` with `Ignis\Symfony\FiberRequestStack`
  (`/home/koe/projects/symfony-ignis/config/services.yaml:26-27`, visible in the dump as
  `new \Ignis\Symfony\FiberRequestStack()` at
  `var/cache/prod/ContainerU4xubdd/App_KernelProdContainer.php:274`). That is ADR-0011 and it works.
- Overlap inside one thread is not hypothetical for this app: `App\Controller\WaitController`
  (`src/Controller/WaitController.php:22`) calls `usleep()`, which is interposed and parks the fiber
  (`crates/ignis/csrc/park.c:57`), and `/fetch` (`:38`) calls `file_get_contents()` over a socket,
  which parks too. Every request that hits either route hands the thread to another request.

## 1. Everything tagged `kernel.reset`, as compiled

`ResettableServicePass` (`vendor/symfony/dependency-injection/Compiler/ResettableServicePass.php:35-59`)
collects the tag into `services_resetter`'s two constructor arguments. The compiled result for this
app is `var/cache/prod/ContainerU4xubdd/getServicesResetterService.php` — **nine entries**, and it is
byte-identical (modulo namespace) to the dev and test dumps, so debug mode adds nothing here:
`debug.event_dispatcher` is never registered because `symfony/stopwatch` is absent and
`FrameworkExtension.php:1228` gates `debug.php` on `class_exists(Stopwatch::class)`.

The third `ServicesResetter` argument (`ServicesResetter.php:32`, the `\WeakMap` that tracks
non-shared instances via `Container::trackForReset()`, `Container.php:420-426`) is **not** passed,
so `$hasNonShared` was false (`ResettableServicePass.php:73-77`): this app has no non-shared
resettable services.

| service | class | method | classification | reason |
|---|---|---|---|---|
| `session_listener` | `HttpKernel\EventListener\SessionListener` | `reset` | **must be fiber-scoped** | `AbstractSessionListener::reset()` (`AbstractSessionListener.php:282-294`) calls `session_abort()`, `session_unset()`, `$_SESSION = []`, `session_id('')` — all thread-global, and the storage it hands out is `NativeSessionStorageFactory` (`getSession_FactoryService.php:27`). Two overlapping requests share one `$_SESSION`. **Security bug** (§4.1). |
| `container.env_var_processor` | `DependencyInjection\EnvVarProcessor` | `reset` | reset-only | `$loadedVars` (`EnvVarProcessor.php:28`) is a memo of resolved env values plus `Container::resetEnvCache()` (`:382-389`). Env values are process configuration, identical for every request; the memo only grows. |
| `cache.app` | `Cache\Adapter\FilesystemAdapter` | `reset` | reset-only | `$deferred` (`Traits/AbstractAdapterTrait.php:43`) is a shared write-behind buffer: `reset()` commits it (`:286-293`). Request A's `saveDeferred()` gets flushed by request B's `commit()`, which reorders *when* a write lands, not *what* is written — `commit()` swaps `$this->deferred = []` before any I/O (`Adapter/AbstractAdapter.php:143-148`), so a re-entrant `saveDeferred()` lands in a fresh batch. |
| `cache.system` | `Cache\Adapter\PhpFilesAdapter` | `reset` | safe to share | `createSystemCache()` (`Adapter/AbstractAdapter.php`) returns the bare `PhpFilesAdapter` when APCu is absent, and it is (`/opt/php85-zts/bin/php -m`: no `apcu`; the chain-with-`ArrayAdapter` branch is dead here). Its `$values`/`$files`/`self::$valuesCache` (`Adapter/PhpFilesAdapter.php:33-38`) hold compiled, request-independent artefacts (routes, container metadata) keyed by content. Read-mostly and genuinely global. |
| `controller.cache_attribute_listener` | `HttpKernel\EventListener\CacheAttributeListener` | `?reset` | safe to share | `reset()` is empty (`CacheAttributeListener.php:131-133`); the only field is an injected `ExpressionLanguage` (`:33-36`). The `#[Cache]` attribute instances it mutates (`:46`) are memoised **on the Request** (`Event/ControllerEvent.php:94-112`, `_controller_attributes`), not on the listener. |
| `cache.controller_expression_language` | — | `reset` | n/a | compiled to `if (false)` — tagged before the removal passes, absent from the final container. Can never fire. |
| `cache.validator` | — | `reset` | n/a | same; `framework.validation` not enabled. |
| `cache.serializer` | — | `reset` | n/a | same. |
| `cache.property_info` | — | `reset` | n/a | same. |

**Counts: 1 must-be-fiber-scoped, 2 safe to share, 2 reset-only, 4 that can never be reset because
they are not in the container.**

### 1b. The tenth service — `request_stack` lost its tag

FrameworkBundle tags `request_stack` with
`kernel.reset{method: resetRequestFormats, on_invalid: ignore}`
(`vendor/symfony/framework-bundle/Resources/config/services.php:59-60`). It is **not** in this app's
resetter. The cause is the override in `config/services.yaml:26-27`: a service definition in
`services.yaml` *replaces* the bundle's definition rather than merging with it, so the tag went with
it, and `removed-ids.php:155` confirms the public id was dropped.

`RequestStack::resetRequestFormats()` (`vendor/symfony/http-foundation/RequestStack.php:118-123`)
rebinds a closure into `Request` to null `Request::$formats` — a **static** that
`Request::setFormat()` mutates. So the one piece of genuinely cross-request global state that this
tag existed to undo is now never undone. Classification: **reset-only**, and currently unreset.
`FiberRequestStack` (`php/packages/symfony-runtime/src/FiberRequestStack.php`) does not override
`resetRequestFormats`, so re-adding the tag is a one-line change to `services.yaml`, not code.

### 1c. Tag sites this app does not reach

For a fuller app, these are the other `kernel.reset` sites in the installed vendor tree. Their
classes are mostly **not present** in this vendor tree, so they are listed as tag sites, not as
classifications — grep evidence only, no reading of code that does not exist here.

| service | tag site | class present? |
|---|---|---|
| `http_client`, `http_client.transport`, `<scope>.scoping`, mock transports | `Resources/config/http_client.php:46,49,55`; `FrameworkExtension.php:2873,2913,2934` | no (`symfony/http-client` absent) |
| `translation.locale_switcher` | `Resources/config/translation.php:187` | no |
| `translator.data_collector` | `Resources/config/translation_debug.php:21` | no |
| `mailer.message_logger_listener` | `Resources/config/mailer.php:77` | no |
| `form.choice_list_factory.cached` | `Resources/config/form.php:106` | no |
| `messenger.transport.in_memory.factory` | `Resources/config/messenger.php:186` | no |
| `notifier.notification_logger_listener` | `Resources/config/notifier.php:143` | no |
| `asset_mapper.cached_mapped_asset_factory` | `Resources/config/asset_mapper.php:79` | no |
| `debug.validator` | `Resources/config/validator_debug.php:25` | no |
| `profiler`, `profiler_listener` | `FrameworkExtension.php:996`; `Resources/config/profiling.php:42` | no |
| `debug.stopwatch`, monolog `DebugProcessor` | `FrameworkExtension.php:1220,1251` | no |
| `debug.event_dispatcher` | `Resources/config/debug.php:32` | **yes** — `event-dispatcher/Debug/TraceableEventDispatcher.php` |
| any `cache.pool`-tagged pool with `reset` | `vendor/symfony/cache/DependencyInjection/CachePoolPass.php:164` | yes (the four `if (false)` rows above are this) |

The one whose source *is* here is worth a line, because it is the shape a fiber-scoped fix has to
handle in a fuller app: `TraceableEventDispatcher` keys its accumulated listener call data by
`$this->currentRequestHash` (`TraceableEventDispatcher.php:42`), a single scalar overwritten per
request, alongside `$callStack`/`$wrappedListeners`/`$dispatchDepth` (`:36-41`) — all per-request,
all shared. Under interleaving its profiler output would attribute one request's listeners to
another. That is **reasoning from the fields, not a measurement**; the service is not registered in
this app.

Also not registered here, but the same shape: `.virtual_request_stack`
(`http-kernel/Debug/VirtualRequestStack.php:24-49`) extends `RequestStack` and pushes into its
**own** `private array $requests` for virtual requests (`:38`), delegating only non-virtual pushes to
the decorated stack (`:43`). Overriding the `request_stack` class does not reach it. If the profiler
is ever enabled on Ignis, this wrapper needs the same treatment as `request_stack`.

## 2. What `reset()` actually does

`ServicesResetter::reset()` (`ServicesResetter.php:36-53`) walks the `RewindableGenerator`. The
generator's body is the interesting part: every row is guarded by
`isset($container->services[...])` / `isset($container->privates[...])`
(`getServicesResetterService.php`, the `IGNORE_ON_UNINITIALIZED_REFERENCE` from
`ResettableServicePass.php:40`). So the resetter only touches services that have **already been
instantiated** in this process, and an uninitialized lazy object is skipped again at
`ServicesResetter.php:57-59`. A throwing `reset()` does not stop the others
(`:66-71`) — the first throwable is rethrown after the loop.

## 3. `services_resetter` under interleaving

The call site is `Kernel::boot()`:

```
 66  public function boot(): void
 68      if ($this->booted) {
 69          if (!$this->requestStackSize && $this->resetServices) {
 70              if ($this->container->has('services_resetter')) {
 71                  $this->container->get('services_resetter')->reset();
 73              $this->resetServices = false;
 79          return;
```

and the counter's only two mutation sites are inside `handle()`:

```
119  public function handle(Request $request, int $type = MAIN_REQUEST, bool $catch = true): Response
136      $this->boot();                      // <- reset decision happens HERE
137      ++$this->requestStackSize;
139      $this->resetServices = true;        // (guarded by !$this->handlingHttpCache at :138)
143          return $this->getHttpKernel()->handle($request, $type, $catch);
145      } finally { --$this->requestStackSize; }
```

plus the two whole-kernel zeroings, `__clone()` at `Kernel.php:61-62` and `shutdown()` at
`:115-116`, neither of which Ignis calls per request. `$resetServices` is also set at `:132` on the
`http_cache` path, which this app does not have.

The decisive detail is the **ordering of `:136` and `:137`**: `boot()` reads the counter *before*
the current request has been counted. So the predicate at `:69` means "no other request is between
`:137` and `:145` right now", and:

- **When it fires.** Only at the top of a `handle()` that finds the counter at zero — i.e. only when
  the thread has no Symfony request between line 137 and line 145. Under Ignis's runner the
  entrypoint is `$kernel->handle($request)` at
  `php/packages/symfony-runtime/src/IgnisWorkerRunner.php:35`, and `$application` there is the
  `Kernel` itself (`IgnisRuntime.php:15-16`), so this is exactly `Kernel::handle`.
- **When it never fires.** Whenever at least one request is always in flight. Requests arriving
  during another request's park see `requestStackSize >= 1` at `:69` and skip the reset; the flag
  stays true and simply gets re-set at `:139`. Under sustained load on a parking route the resetter
  never runs at all, so every "reset-only" service above accumulates without bound for the life of
  the thread.
- **Can it fire mid-flight? Yes — after `handle()` returns but before the request is finished.**
  `--$this->requestStackSize` happens in `handle()`'s `finally` at `:145`, and the Ignis runner then
  does two more things *outside* the counter: `$response->sendContent()` for a `StreamedResponse`
  (`IgnisWorkerRunner.php:36-40`) and `$kernel->terminate($request, $response)`
  (`:50-52`). Both can park. A request sitting in `sendContent()` or in its `kernel.terminate`
  listeners has counter 0, so a concurrently admitted request's `boot()` will call
  `services_resetter->reset()` on top of it. For `session_listener` that means `session_abort()` and
  `$_SESSION = []` land in the middle of another request's terminate phase.

### The probe

`Kernel.php:43-45`, `:66-92` and `:119-147` were transcribed line-for-line into a stub kernel and
driven by a round-robin fiber scheduler, PHP 8.5.10 ZTS
(`LD_LIBRARY_PATH=/opt/php85-zts/lib /opt/php85-zts/bin/php resetter-probe.php`, file left in the
session scratchpad, not in the repo). This is a **model of the control flow, not the real kernel** —
it proves the counter logic, nothing about the services.

```
== sequential: three requests, no overlap ==
  R1 handle()
  R2 handle()
      >>> services_resetter->reset()  (while in flight: 0)
  R3 handle()
      >>> services_resetter->reset()  (while in flight: 0)
  resets fired = 2

== overlap then a gap: R1 parks, R2 runs inside it, then R3 ==
  R1 handle()
  R2 handle() (stack size = 1)      <- no reset: R1 is between :137 and :145
  R2 done
  R1 done
  R3 handle()
      >>> services_resetter->reset()
  resets fired = 1

== sustained overlap: always >=1 request in flight, 50 requests ==
      >>> services_resetter->reset()          <- fired once, on the first of the 50
  resets fired during 50 overlapping requests = 1

== post-handle work: R1 streams its body after handle() returned ==
  R1 handle() returned, now sendContent()/terminate() with stack size 0
  R2 handle() while R1 is STILL STREAMING
      >>> services_resetter->reset()          <- mid-flight reset
  R1 finished streaming
```

Read plainly: **the reset is skipped exactly when it is needed and fires exactly when it is
harmful.** Two overlapping requests get no reset between them; a request that is still streaming or
terminating gets one dropped on it.

## 4. Two hazards that are not on the `kernel.reset` list

### 4.1 `$_SESSION` is not fiber-scoped

`crates/ignis/src/php/superglobals.rs:22` scopes exactly four superglobals per fiber:
`KEYS: [&[u8]; 4] = [b"_SERVER", b"_GET", b"_POST", b"_COOKIE"]`. `$_SESSION` is not among them, and
neither is `session_id()`/`session_status()`, which are per-thread in ZTS and therefore shared by
every fiber on that thread. `ext/session` is compiled into our build (`/opt/php85-zts/bin/php -m`
lists `session`), so this is the real native session, not a no-op.

This app runs `session: true` with the default `NativeSessionStorageFactory`
(`getSession_FactoryService.php:27`). `AbstractSessionListener::onKernelRequest` installs a session
factory per request (`AbstractSessionListener.php:66-94`) that calls `$sess->setId(...)` from the
request's cookie (`:89`) — into the one thread-global native session. Two overlapping requests on
one thread therefore write each other's session id and read each other's `$_SESSION`. That is a
**cross-user data disclosure**, not a correctness nuisance, and it is the most serious thing in this
document. Nothing here measures it (§6).

### 4.2 `Ignis\Scope` is per-fiber, and fibers are pooled

`FiberRequestStack` stores the stack in `Ignis\Scope` (`FiberRequestStack.php:13-19`), which is a
`\WeakMap` keyed on `\Fiber::getCurrent()` (`php/packages/runtime/src/ignis.php:686-712`). Pooled
fibers are **reused**: `Loop::poolBody()` loops forever and suspends between jobs
(`ignis.php:202-216`), so the Fiber object — and its whole `Scope` bag — outlives the request. At
request end the loop nulls only one key, `Scope::set('ignis.request', null)` (`ignis.php:579`);
`symfony.request_stack` is left as it was.

In practice the stack is balanced: `HttpKernel` pushes at `HttpKernel.php:76` and pops in a `finally`
at `:96` (and `:133`/`:140` for sub-requests), so it returns to `[]`. The residual consequences are:

- **A `Ignis\async()` child does not inherit the request stack.** The child runs in a different
  fiber, so `RequestStack::getCurrentRequest()` returns `null` inside it — `LocaleListener`, the
  `Logger` and the session factory all take `request_stack` as a constructor argument
  (`App_KernelProdContainer.php:244,254`) and would see no request. A functional gap, not a leak.
- The scope bag is only ever empty because of a `finally` in someone else's code. Any path that
  destroys a suspended request fiber without unwinding would leave another request's `Request`
  object reachable from the next user of that pooled fiber.

## 5. What a fix has to do

In priority order, highest risk first.

1. **`session_listener` / native sessions.** Either give each fiber its own session
   (`$_SESSION` as a fifth key in `superglobals.rs`, plus a session id and `session_status` that are
   fiber-scoped rather than thread-scoped), or refuse the combination: a `storage_factory_id` that is
   not thread-global, or `budget.fibers = 1` for apps with `session: true` (the M3-5a shape already
   used for Laravel, research 25). Anything that leaves `NativeSessionStorage` addressable from two
   fibers on one thread is wrong. This is the only item on the list that is a security bug.
2. **Make the reset boundary the request, not the kernel counter.** `Kernel::boot()`'s predicate
   cannot express "this request is done" when requests interleave — §3 shows it firing during another
   request's `sendContent()`/`terminate()` and not firing at all under load. The runner, which knows
   the fiber boundary, is the place that should decide. Whatever replaces it has to be re-entrant:
   it will be called while other requests are mid-flight, which is exactly what upstream's counter
   was written to prevent.
3. **`cache.app`'s `$deferred`.** Not corrupting, but it is the accumulation that §3 shows never
   being flushed under sustained load. Either commit at the runner's request boundary or leave it —
   but say which, because today it is neither.
4. **`request_stack`'s lost tag.** Put `kernel.reset{method: resetRequestFormats}` back on the
   override in `config/services.yaml:26-27`, or document that `Request::$formats` is deliberately
   never reset. One line either way.
5. **`container.env_var_processor`.** Reset-only and cheap; it only needs to ride whatever boundary
   comes out of (2). Worth one look at `EnvVarProcessor.php:189-218`, where `$this->loaders` is
   swapped to an empty iterator for the duration of a load and restored afterwards — if a loader ever
   parks, a concurrent `getEnv()` sees no loaders. It cannot park today (§6), so this is a note, not
   a defect.
6. **`cache.system`, `controller.cache_attribute_listener`.** Nothing to do. Leave them shared.
7. **`.virtual_request_stack` and `debug.event_dispatcher`** — before the profiler is ever turned on
   under Ignis, not now.

One constraint that narrows (3) and (5) usefully: **local file I/O does not park.**
`crates/ignis/src/php/park.rs:214` refuses to park anything whose `st_mode` is not `S_IFSOCK`, so
`FilesystemAdapter`'s and `PhpFilesAdapter`'s writes, and Dotenv's reads, are atomic with respect to
fibers today. Switch `cache.app` to Redis and that stops being true: `doSave()` becomes a socket
write, it parks, and every cache adapter re-entrancy argument above has to be re-made.

## 6. Not measured

Nothing in this document is a number. Specifically:

- **The session leak has not been reproduced.** The check is a controller that writes a
  per-user marker into `$_SESSION`, parks (`usleep`), then reads it back and compares —
  the shape of `WaitController::__invoke` (`src/Controller/WaitController.php:19-28`), which is how
  V-16 proved `RequestStack` was fiber-safe with 0/100 mismatches. Two concurrent clients with
  different session cookies against `threads = 1`; the number to report is mismatches out of N.
  Until that is run, §4.1 is reading, not evidence.
- **The resetter's real behaviour on the real kernel is not observed.** §3's probe is a
  transcription. The direct measurement is a `services_resetter` decorator that counts invocations
  and logs `requestStackSize` (reflection on the private property), under a load of parking
  requests: the prediction is 1 reset for the first request and 0 thereafter. Cannot be done without
  writing to the owner's app, so it needs a copy of it elsewhere.
- **The unbounded-accumulation claim has no curve.** `cache.app`'s `$deferred` count and process RSS
  over a long parking soak would turn "accumulates without bound" into a slope. E4's soak harness is
  the right instrument.
- **`TraceableEventDispatcher`, `.virtual_request_stack`, `http_client`, `messenger`, `form` and the
  profiler are classified from tag sites only**, and in most cases the class is not even in this
  vendor tree. A second pass on an app that installs them is required before any of those rows is
  worth acting on.
- **No claim is made about the true-async backend** (`cfg(php_async_abi)`), which was not built or
  consulted for this note.

---

## Correction by the main agent, 2026-09-17 (V-67)

The headline above — `$_SESSION` as a cross-user disclosure through Symfony's
`NativeSessionStorageFactory` — was re-run before being accepted (rule C15) and the mechanism is not
that one. Measured from a real request under `target/release/ignis`:

```
{"started":false,"status":1,"id":"",
 "error":"session_start(): Session cannot be started after headers have already been sent"}
```

`session_start()` **fails on every request** under the embed SAPI, so nothing that goes through
ext/session — Symfony's native storage included — ever holds data to leak. What is real, and was
measured with two overlapping requests, is narrower and still worth fixing: `$_SESSION` used as a
plain array is a thread-global (`superglobals.rs:22` swaps exactly `_SERVER`, `_GET`, `_POST`,
`_COOKIE`), and one request read the other's value across a park. Both halves are in V-67, and the
probe is `bench/php/session_shared.php`.

The `Kernel::boot()` analysis in §2 stands and was the more useful half: it is why resetting cannot
be the answer under concurrency, and it is quoted in `FiberScopePass`.

## Addendum, 2026-09-20 — one of the two candidates was half wrong, and only an arm could tell

§1 sorted the fifteen `kernel.reset` services and named two candidates. The first,
`security.logout_url_generator`, was identified by reading the class: `reset()` clears
`currentFirewallName`, and the firewall listener sets it per request. That reading is correct and the
verdict drawn from it was still half wrong.

Built as an arm — a second `admin` firewall beside `main`, so the two overlapping requests want
different logout paths — the **authenticated** case does not leak at all: 0 of 3 in both directions,
with timestamps proving B ran entirely inside A's 300 ms sleep. `LogoutUrlGenerator::getListener()`
asks `$this->tokenStorage->getToken()?->getFirewallName()` **first** and only then falls back to its
own property, and `security.token_storage` was already scoped. The service was correct by way of a
service that was correct.

The **anonymous** case is where the property is reached, and there it leaks: with no token the
fallback runs, and a neighbour's `onKernelFinishRequest` sets the property back to `null` under a
request that is still awaiting — `InvalidArgumentException: This request is not behind a firewall`,
2 of 2 (V-105). Two firewalls *and* no credentials is the only shape in which the defect exists;
neither half is visible from the class alone.

What this says about the method of §1: reading each `reset()` is the right way to sort the list, and
it is not sufficient to decide a row. Whether a per-request property is ever *read* can depend on the
request, and an arm is the only thing that answers that. The second candidate,
`doctrine.debug_data_holder`, is still unmeasured for the same reason it was then — it is registered
in debug only, and E21 runs `APP_ENV=prod`.

`FiberRequestStack`, named in the ground-truth note above, no longer exists: ADR-0042 replaced the
façades with Symfony's own classes marked in the container (V-100).
