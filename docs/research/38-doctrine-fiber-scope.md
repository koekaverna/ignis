# Research 38 — a per-fiber Doctrine EntityManager under Ignis

Date: 2026-09-17. Question from the owner: make Doctrine's `EntityManager` per-fiber, the way
`Ignis\Symfony\FiberRequestStack` (`php/packages/symfony-runtime/src/FiberRequestStack.php:11`) is
per-fiber, and say precisely what the compiler pass, the request-end reset and the connection bill
look like. Everything below is read out of an installed vendor tree and a compiled container; every
claim carries a `file:line`.

## 0. Where the evidence comes from — and a correction to the premise

The brief said "a Symfony 8.1.7 app with doctrine installed is at `/home/koe/projects/symfony-ignis`".
**It is not installed there.** `/home/koe/projects/symfony-ignis/composer.json` requires
`symfony/framework-bundle 8.1.*`, `symfony/runtime`, `ignis/runtime` and nothing else;
`vendor/doctrine/` does not exist and `composer.lock` contains no `doctrine/*` package. No composer
was run there and nothing in that tree was modified.

The nearest real subject on this box, and the one used for everything below, is
**`/home/koe/projects/1xbet/payment-pipelines`** — `symfony/framework-bundle v8.1.7` (the same
Symfony as the target app) with `doctrine/doctrine-bundle 3.3.2`, `doctrine/orm 3.7.1`,
`doctrine/dbal 4.4.4`, `doctrine/persistence 4.2.0`. It has a **compiled dev container**
(`var/cache/dev/App_KernelDevDebugContainer.xml`, 1667 services) so the service graph below is
as-built, not as-remembered. Read only; nothing was written or run there either.

Throughout, `vendor/…` and `var/…` paths are relative to `/home/koe/projects/1xbet/payment-pipelines/`.

Two consequences to keep in mind: the *counts* in §4 and §7 are that app's counts, not the owner's
app's, and they are the only real numbers here. Everything about **cost** — EM construction time per
fiber, RSS per per-fiber EM, connection setup latency — is **unmeasured**. No bench exists yet; §8
says which one has to.

## 1. The service ids, as they actually appear

Ids are built by `DoctrineExtension` from the connection/manager names. `%s` is the configured name;
with the default configuration that name is literally `default`
(`vendor/doctrine/doctrine-bundle/src/DependencyInjection/DoctrineExtension.php:476-477` and `:482-483`
prepend a `default` connection and a `default` entity manager when none is configured).

| id | kind | class | declared where | flags as compiled |
|---|---|---|---|---|
| `doctrine.dbal.default_connection` | **definition** (`ChildDefinition` of `doctrine.dbal.connection`) | `Doctrine\DBAL\Connection` | `DoctrineExtension.php:630-639`; abstract parent `config/dbal.php:54-56` | `public="true"`, **shared**, **not lazy** — `var/cache/dev/App_KernelDevDebugContainer.xml:4739` |
| `doctrine.dbal.default_connection.configuration` | definition | `Doctrine\DBAL\Configuration` | `DoctrineExtension.php:598` | shared |
| `doctrine.dbal.default_connection.event_manager` | definition | `Symfony\Bridge\Doctrine\ContainerAwareEventManager` | `DoctrineExtension.php:625`; parent `config/dbal.php:58-62` | shared — `…Container.xml:4680` |
| `doctrine.orm.default_entity_manager` | **definition** (`ChildDefinition` of `doctrine.orm.entity_manager.abstract`) | `Doctrine\ORM\EntityManager` | `DoctrineExtension.php:1027-1037` | `public="true"`, **shared**, **`lazy="true"`** — `…Container.xml:5122` |
| `doctrine.orm.default_configuration` | definition | `Doctrine\ORM\Configuration` | abstract parent `config/orm.php:69` | shared |
| `doctrine.orm.default_manager_configurator` | definition | `Doctrine\Bundle\DoctrineBundle\ManagerConfigurator` | parent `config/orm.php:82-87`, wired as the EM's configurator `DoctrineExtension.php:1037` | shared |
| `doctrine` | definition | `Doctrine\Bundle\DoctrineBundle\Registry` | `config/dbal.php:67-76` | `public`, shared, **`kernel.reset` → `reset`** |
| `doctrine.orm.container_repository_factory` | definition | `…\Repository\ContainerRepositoryFactory` | `config/orm.php:75-80`, attached to the EM's `Configuration` via `setRepositoryFactory` (`DoctrineExtension.php:977`) | shared |

Aliases (all verified in the compiled container, `…Container.xml:8811-8826`):

| alias | → target | declared where |
|---|---|---|
| `database_connection` (public) | `doctrine.dbal.default_connection` | `DoctrineExtension.php:516-517` |
| `Doctrine\DBAL\Connection` | `doctrine.dbal.default_connection` | `config/dbal.php:32` |
| `.Doctrine\DBAL\Connection $default` / `Doctrine\DBAL\Connection $defaultConnection` | `doctrine.dbal.default_connection` | `registerAliasForArgument`, `DoctrineExtension.php:641-642` |
| `doctrine.dbal.event_manager` | `doctrine.dbal.default_connection.event_manager` | `DoctrineExtension.php:518` |
| **`doctrine.orm.entity_manager` (public)** | **`doctrine.orm.default_entity_manager`** | `DoctrineExtension.php:844-845` |
| `Doctrine\ORM\EntityManagerInterface` | `doctrine.orm.default_entity_manager` | `config/orm.php:55` |
| `.Doctrine\ORM\EntityManagerInterface $default` / `…$defaultEntityManager` | `doctrine.orm.default_entity_manager` | `registerAliasForArgument`, `DoctrineExtension.php:1039-1040` |
| `doctrine.orm.default_entity_manager.event_manager` | `doctrine.dbal.default_connection.event_manager` | `DoctrineExtension.php:1042-1045` |
| `Doctrine\Persistence\ManagerRegistry` | `doctrine` | `config/dbal.php:33` |

**The one concrete id to target is `doctrine.orm.default_entity_manager`** — `doctrine.orm.entity_manager`
and `Doctrine\ORM\EntityManagerInterface` are aliases onto it, so decorating the concrete id covers
every injection path. Same for `doctrine.dbal.default_connection` and its four aliases.

The registry resolves managers *by id through the container on every call*, it caches nothing:
`AbstractManagerRegistry::getManager()` is `return $this->getService($this->managers[$name])`
(`vendor/doctrine/persistence/src/AbstractManagerRegistry.php:122`), and `getService()` is
`$this->container->get($name)` (`vendor/symfony/doctrine-bridge/ManagerRegistry.php:27-30`). Good
news for us: the registry does not have to be taught anything about fibers to see a per-fiber
manager — it just has to keep asking the container.

## 2. `lazy`, and what the bundle already does with `kernel.reset`

- **Lazy**: only the entity manager. `config/orm.php:71-73` marks the abstract
  `doctrine.orm.entity_manager.abstract` `->lazy()`, inherited by the child
  (`lazy="true"` at `…Container.xml:5122`). **The connection is not lazy** — it is a plain shared
  service built by the `doctrine.dbal.connection_factory` factory (`…Container.xml:4739-4755`).
  The DBAL connection is nonetheless not *connected* until first use, which is why a non-lazy
  definition is harmless today.
- **`kernel.reset`** — `grep -rn "kernel.reset" vendor/doctrine/` returns exactly three hits:
  - `vendor/doctrine/doctrine-bundle/config/dbal.php:76` — `doctrine` (the `Registry`), method `reset`;
  - `vendor/doctrine/doctrine-bundle/config/middlewares.php:29` — the debug data holder;
  - `vendor/doctrine/doctrine-bundle/src/DependencyInjection/DoctrineExtension.php:770` — `form.type.entity`.

  **The entity manager itself is not tagged `kernel.reset`.** All Doctrine request-scoped cleanup
  goes through `Registry::reset()` (`vendor/doctrine/doctrine-bundle/src/Registry.php:34-75`), which
  for each manager id:
  1. returns immediately if `$this->container->initialized($serviceId)` is false (`Registry.php:43-45`);
  2. returns immediately if the service is an uninitialized lazy object (`Registry.php:62-65`);
  3. **calls `$manager->clear()` if `$manager->isOpen()`** (`Registry.php:67-71`);
  4. otherwise calls `resetManager($managerName)` (`Registry.php:74`), which is
     `resetService()` → `ReflectionClass::resetAsLazyGhost/resetAsLazyProxy`
     (`vendor/symfony/doctrine-bridge/ManagerRegistry.php:47-88`) — and **throws
     `LogicException('Resetting a non-lazy manager service is not supported…')` if the service is not
     lazy** (`ManagerRegistry.php:87`). That is why the bundle marks the EM `lazy()`.

  `reset()` is reached at runtime through `services_resetter`
  (`Symfony\Component\HttpKernel\DependencyInjection\ServicesResetter`), and `doctrine` is in its
  iterator — `…Container.xml:1718` (`<argument key="doctrine" … on-invalid="ignore_uninitialized"/>`).

  **Note what `clear()` is and is not.** `EntityManager::clear()` is
  `$this->unitOfWork->clear()` (`vendor/doctrine/orm/src/EntityManager.php:425-428`), and
  `UnitOfWork::clear()` nulls 20 identity/changeset arrays and fires `Events::onClear`
  (`vendor/doctrine/orm/src/UnitOfWork.php:2395-2419`). It does **not** touch the connection and it
  does **not** roll back an open transaction. Nothing in DoctrineBundle rolls back at request end:
  the only `Connection::close()` in the bundle is `DoctrineBundle::shutdown()`
  (`vendor/doctrine/doctrine-bundle/src/DoctrineBundle.php:104-112`), which runs at process end.

## 3. What breaks if the entity-manager definition is marked `shared: false`

Nothing in DoctrineBundle *holds* the manager in a property — `Registry` re-resolves it through the
container every time (§1), `ContainerAwareEventManager` holds only the container and listener
bookkeeping (`vendor/symfony/doctrine-bridge/ContainerAwareEventManager.php:26-38` — `$initialized`,
`$initializedSubscribers`, `$initializedHashMapping`, `$methods`, `$container`, `$listeners`; no
manager). So the *bundle* survives.

**The application does not.** In the compiled container, **38 shared application services take
`doctrine.orm.default_entity_manager` as a constructor argument** (`grep -c 'id="doctrine.orm.default_entity_manager"/>'`
→ 39, of which 38 are `App\…` services and one is
`doctrine.orm.default_entity_manager.property_info_extractor`) — e.g.
`App\Pipeline\Infrastructure\Repository\PipelineRepository`,
`App\Run\Projection\RunMaterializer`, `App\Run\Ingress\WebhookReceiver`. Each is itself shared, so
each captures **one** EM instance at its own first instantiation and hands that same instance to
every fiber forever. Marking the EM `shared: false` gives those 38 services a *fresh* manager once,
at boot, and changes nothing afterwards — it is strictly worse than today, because the identity of
that captured manager is now arbitrary rather than "the one everything else uses".

Two more instance-pinning caches, both real:

- **`ContainerRepositoryFactory::$managedRepositories`** keys its cache by
  `$metadata->getName() . spl_object_hash($entityManager)`
  (`vendor/doctrine/doctrine-bundle/src/Repository/ContainerRepositoryFactory.php:96-105`). The
  factory is a **shared** service reached through the shared `Configuration`
  (`DoctrineExtension.php:977`), so one map serves every per-fiber EM. `spl_object_hash` is derived
  from the object handle and handles are **reused after free**: if per-fiber EMs are ever destroyed
  and rebuilt, a new EM can collide with a freed one's hash and be handed a repository still holding
  the dead manager. This is a correctness bug, not just a leak. It argues for *keeping* each fiber's
  EM alive rather than rebuilding it per request (§5).
- **`ServiceEntityRepository`** in 3.3.2 no longer takes an EM in its constructor — it takes the
  registry and memoises `$this->repository ??= $this->resolveRepository()` on first use
  (`vendor/doctrine/doctrine-bundle/src/Repository/ServiceEntityRepository.php:41,52,127-142`), where
  `resolveRepository()` builds `new EntityRepository($manager, $classMetadata)` from
  `$this->registry->getManagerForClass(...)`. The repository service is shared, so **the first fiber
  to touch it pins its EM into every later fiber's queries.**

**Conclusion: `shared: false` on the id everything injects is the wrong lever.** The id everything
injects must stay **shared and stable**, and be a fiber-aware façade — exactly the
`FiberRequestStack` shape, where the shared `request_stack` service is the façade and
`Ignis\Scope` holds the per-fiber value (`FiberRequestStack.php:13-26`). `shared: false` belongs on
the *inner* definition only.

For the record, `Doctrine\ORM\Decorator\EntityManagerDecorator` **does** exist in 3.7.1
(`vendor/doctrine/orm/src/Decorator/EntityManagerDecorator.php:33`), is `abstract`, extends
`Doctrine\Persistence\ObjectManagerDecorator`, implements `EntityManagerInterface`, and delegates
**every** call through the single protected property
`ObjectManagerDecorator::$wrapped` (`vendor/doctrine/persistence/src/ObjectManagerDecorator.php:18`).
The three the brief asked about all route through it:
`getConnection()` → `$this->wrapped->getConnection()` (`EntityManagerDecorator.php:55-58`),
`getRepository()` → `$this->wrapped->getRepository()` (`:40-43`),
`getUnitOfWork()` → `$this->wrapped->getUnitOfWork()` (`:140`). The combined public surface is
**35 methods**: 27 declared on `EntityManagerDecorator` (`:40`–`:170`) plus 8 inherited unchanged
from `ObjectManagerDecorator` (`persist`, `remove`, `clear`, `detach`, `flush`, `initializeObject`,
`isUninitializedObject`, `contains`).

`$wrapped` is a *property*, not an accessor, so extending `EntityManagerDecorator` and leaving it
alone would pin one manager. The fiber-aware subclass must re-point `$wrapped` on entry to each
call. That is the whole mechanism.

## 4. The compiler-pass spec

One pass, `Ignis\Symfony\Doctrine\FiberScopePass`, registered from the app kernel (or from an
`IgnisDoctrineBundle` if one is ever wanted), `PassConfig::TYPE_BEFORE_OPTIMIZATION`, **priority 0 —
i.e. after every DoctrineBundle pass**. Justification for the position, not preference:

- Extensions all run before any compiler pass, so `doctrine.orm.default_entity_manager` and
  `doctrine.dbal.default_connection` already exist with their final arguments by the time any
  `BEFORE_OPTIMIZATION` pass runs.
- DoctrineBundle registers `ServiceRepositoryCompilerPass`, `EntityListenerPass`, `IdGeneratorPass`,
  `MiddlewaresPass`, `RegisterEventListenersAndSubscribersPass` at `BEFORE_OPTIMIZATION` /
  default priority (`vendor/doctrine/doctrine-bundle/src/DoctrineBundle.php:51,61-71`). Passes at
  equal priority run in registration order and bundles are built before the kernel's own passes, so
  registering from the kernel at priority 0 lands after all of them. Running *earlier* would mean
  `MiddlewaresPass` and friends mutating the definition after we had renamed it.
- It must run **before** `DecoratorServicePass` (optimization group), which is what performs the
  `.inner` rename (`vendor/symfony/dependency-injection/Compiler/DecoratorServicePass.php:62`) — so
  we declare decoration and let Symfony do the renaming, rather than renaming ids by hand.

What the pass does:

1. **Guard.** `if (!$container->hasDefinition('doctrine.orm.default_entity_manager')) return;` —
   generalised as: for every id in the `doctrine.entity_managers` parameter
   (`DoctrineExtension.php:831-835`) and every id in `doctrine.connections` (`:526-530`). Never
   hard-code `default`; multi-manager apps exist and the parameter is the authority.

2. **Per entity-manager id `$em`:**
   - `$inner = $container->getDefinition($em);`
   - `$inner->setShared(false);` — each `$container->get()` now builds a fresh manager.
   - `$inner->setLazy(false);` — the `lazy()` from `config/orm.php:73` exists so that
     `Registry::resetService()` can reset the ghost in place (`ManagerRegistry.php:39-88`). Under
     this design the fiber slot is the reset unit (§5) and the decorator only calls `get()` when a
     fiber actually touches Doctrine, so the ghost is a second proxy layer buying nothing.
     *Cost of removing it is unmeasured; if the ORM `Configuration` ever grows a constructor-time
     cost this is the knob to revisit.*
   - `$inner->setPublic(false);` — the public id is now the decorator.
   - Register `$em.'.fiber'` = `new Definition(FiberEntityManager::class)`,
     `->setDecoratedService($em)` (Symfony renames the old definition to `$em.'.inner'` and points
     the alias `$em` at ours), `->setArguments([new Reference($em.'.fiber.inner'), $em.'.inner'])`
     — i.e. a `ServiceLocator` (or the `service_container` reference, matching how `Registry` itself
     is wired at `config/dbal.php:67-75`) plus the inner id as a string, **not** a plain
     `Reference` to the inner: a `Reference` would be resolved once at decorator construction and
     defeat the whole thing. `->setPublic(true)` so `doctrine.orm.entity_manager` stays public.
   - Do **not** touch the aliases. `doctrine.orm.entity_manager`,
     `Doctrine\ORM\EntityManagerInterface` and the two `registerAliasForArgument` aliases all point
     at `$em`, which is now the decorator. All 38 injection sites of §3 keep working unchanged.

3. **Per connection id `$conn`:** `$container->getDefinition($conn)->setShared(false);` and nothing
   else. There is no `ConnectionInterface` in DBAL 4 and `Doctrine\DBAL\Connection` is a concrete
   class with ~40 public methods and private driver state
   (`vendor/doctrine/dbal/src/Connection.php:63`), so there is no cheap decorator for it. Non-shared
   is enough **for the path that matters**: the per-fiber inner EM references
   `doctrine.dbal.default_connection` (`DoctrineExtension.php:1033`), so each fiber's manager gets
   its own connection as a side effect of its own construction. The path that does not get fixed is
   §7 hole 1.

4. **Replace the registry class.** `$container->getDefinition('doctrine')->setClass(FiberRegistry::class)`
   where `FiberRegistry extends Doctrine\Bundle\DoctrineBundle\Registry` and overrides
   `resetService(string $name): void` to drop this fiber's slot instead of calling
   `resetAsLazyGhost`. Without this, any application call to `$registry->resetManager()` — the
   normal thing to do after catching a `Doctrine\ORM\Exception\ORMException` — reaches
   `ManagerRegistry.php:87` and throws `LogicException('Resetting a non-lazy manager service is not
   supported…')`, because our decorator is not a lazy object. ~15 lines, one class.

5. **Gate (build-time).** In the same pass, collect every definition whose arguments contain a
   `Reference` to a connection id or to one of its four aliases, excluding the entity-manager inner
   definitions, and record the list as a container parameter (`ignis.doctrine.direct_connections`)
   so `bin/console` and the smoke gate can print it. In the subject app that list has exactly one
   entry (§7). See §8 for what it must be used for.

The class itself, in `php/packages/symfony-runtime/src/Doctrine/FiberEntityManager.php`:

```php
final class FiberEntityManager extends EntityManagerDecorator
{
    private const KEY = 'doctrine.em.';

    public function __construct(private ContainerInterface $locator, private string $innerId) {}

    /** The fiber's own manager; built on first touch, reused for the rest of the fiber's life. */
    public function inner(): EntityManagerInterface
    {
        $key = self::KEY . $this->innerId;
        $em  = Scope::get($key);
        if ($em === null) {
            $em = $this->locator->get($this->innerId);   // shared:false → a fresh EM + Connection
            Scope::set($key, $em);
        }
        return $this->wrapped = $em;
    }
    // …35 overrides, each `return $this->inner()->foo(...)`.
}
```

`Scope` is `php/packages/runtime/src/ignis.php:686-712` — a `WeakMap` keyed by `\Fiber`, with a
`$main` array for the no-fiber case. It has no `delete`; the idiom for dropping a slot is
`Scope::set($key, null)`, as `Ignis\Pg\Lease::release()` does
(`php/packages/pg/src/ignis-pg.php:136,145`).

**Why 35 hand-written overrides and not something clever.** `$wrapped` is a declared protected
property read directly by every inherited method (`ObjectManagerDecorator.php:25,30,35,…`), so the
only alternatives are `__call` (impossible: the parent declares the methods, so `__call` never
fires) or re-pointing `$wrapped` from outside on every fiber switch (a new mechanism, which the
mechanism budget forbids). The overrides are mechanical and each is one line. `FiberRequestStack`
made the same trade at a smaller scale — it overrides all five `RequestStack` methods
(`FiberRequestStack.php:21-55`).

## 5. The request-end reset

**The trap, stated plainly.** `Ignis\Loop` reuses parked fibers — `poolBody()` loops forever and
pushes itself back onto `self::$idle` between jobs (`php/packages/runtime/src/ignis.php`, `poolBody`
/ `spawn`), and that reuse is V-4, the project's single biggest win. `Scope`'s `WeakMap` is keyed by
the `\Fiber` object, so **a pooled fiber's `Scope` entries never expire**: the manager, its identity
map, its connection and its open transaction all survive into whatever request lands on that fiber
next. The existing per-fiber state avoids this by being written fresh every request
(`Scope::set('ignis.request', $id)` at admit and `…, null)` in the `finally`, `ignis.php:561,579`;
`FiberRequestStack` is pushed and popped by the kernel). A Doctrine manager is not written fresh —
that is the point of keeping it — so it must be reset explicitly.

**What must be called, in order, when a request finishes:**

1. `$em = Scope::get('doctrine.em.'.$innerId);` — `null` means this fiber never touched Doctrine.
   Do nothing. (Necessary: the decorator must not *create* a manager during cleanup, which is
   exactly the mistake `Registry::resetOrClearManager` avoids with its `initialized()` check,
   `Registry.php:43-45`.)
2. **Roll back an open transaction.**
   `if ($em->getConnection()->isTransactionActive()) { $em->getConnection()->rollBack(); }`
   (`vendor/doctrine/dbal/src/Connection.php:348` and `:1126`). **DoctrineBundle does not do this
   today and nothing else will.** Under php-fpm a leaked `BEGIN` dies with the process; on a pooled
   fiber it becomes the next request's transaction, on the same connection, holding the same locks.
   This is the single most dangerous carry-over and it is not covered by `clear()` (§2).
3. **Clear or drop.** Mirror `Registry::resetOrClearManager` (`Registry.php:55-74`):
   `$em->isOpen() ? $em->clear() : Scope::set($key, null);` — a healthy manager is cleared and
   **kept**, a closed one is dropped so the next request on this fiber builds a new one.
   Keeping the healthy manager is deliberate: dropping it every request would rebuild the
   `ClassMetadataFactory`, `UnitOfWork` and `ProxyFactory` per request
   (`vendor/doctrine/orm/src/EntityManager.php:130-148`), re-open a connection per request, and feed
   the `spl_object_hash` collision in `ContainerRepositoryFactory` (§3). The cost of *not* keeping
   it is unmeasured; the cost of keeping it is §6.

**Where it goes.** `php/packages/symfony-runtime/src/IgnisWorkerRunner.php:24-54`. The handler
closure currently runs `Request::createFromGlobals()` → `$kernel->handle()` → response marshalling →
`$kernel->terminate()` → `return new IgnisResponse(...)`. The reset belongs in a `try { … } finally { … }`
wrapping the **entire** closure body, so it runs after `terminate()` (line 51) and also on the throw
path — a request that dies mid-transaction is precisely the one that must not leak its `BEGIN`.
It must be inside the per-request closure, never in `run()`: `run()` executes once.

**What must not be called.** Not `services_resetter->reset()`. That id exists
(`…Container.xml:1698`, public) and does include `doctrine`, but it also includes `request_stack`
(`…Container.xml:1703`) and every other resettable service on the thread; calling it from one fiber
would reset state belonging to the other fibers interleaved with it. And not `Registry::reset()`
unaltered — with `shared:false` on the inner, `$this->container->initialized($serviceId)`
(`Registry.php:43`) is permanently false for a non-shared id, so it would silently do nothing; with
the decorator in place at the public id it would clear *the current fiber's* manager, which is
correct but does not roll back (step 2) and is not reachable from a per-request hook that does not
exist yet.

## 6. The connection bill

With a per-fiber connection, **open connections = peak concurrent fibers per PHP thread × threads**,
and because pooled fibers are never freed, the peak is **sticky**: once N fibers have existed on a
thread, N connections stay open on it for the life of the process, even at idle. `clear()` does not
close a connection and nothing else in the request path does either (§2).

For the owner's own configuration, `/home/koe/projects/symfony-ignis/ignis.toml`: `threads = 4`,
`[budget] fibers = 1024`. Worst case **4 × 1024 = 4096** connections from one Ignis process. MySQL
ships `max_connections = 151`; PostgreSQL ships `max_connections = 100`. The default configuration
therefore oversubscribes a stock database by more than an order of magnitude.

What bounds it — all three are existing knobs, no new mechanism:

- **`IGNIS_FIBER_BUDGET`** / `[budget] fibers` (ADR-0019, `ignis.php:411-425`): the hard cap on
  concurrently admitted *request* fibers per thread. This is the multiplicand. Set it to the
  per-thread share of the database's connection budget, not to a throughput target.
- **`IGNIS_THREADS`** / `threads` (ADR-0010): the multiplier.
- The bill is per **process**, so `--supervise` with multiple processes multiplies again.

The rule to publish with the feature: *`threads × fibers ≤ (max_connections − headroom)`*, and a
boot-time warning when the product exceeds a configured `doctrine.max_connections`. Whether the
per-fiber connection actually costs what a per-fiber connection costs — setup latency, RSS,
server-side memory — is **unmeasured**; §8 gates it.

Relevant to the shape of the bill: since 2026-09-17 (V-59 addendum) `PDO` is **not** offload-routed;
only `SQLite3` is (`crates/ignis/src/php/route.rs:41-52`), because `pdo_pgsql`/`pdo_mysql` talk over
a socket and park (303 ms parked vs 2,753 ms through an 8-worker pool). So a per-fiber PDO handle is
a real socket held by a real parked fiber — the count above is a count of sockets, and none of it is
mitigated by the offload pool. `pdo_sqlite` is the exception and blocks the thread
(`route.rs:48-51`); a Doctrine app on SQLite gets no concurrency from this design at all.

## 7. Holes

1. **Direct `Connection` injection — the design does not cover it.** Services that constructor-inject
   `Doctrine\DBAL\Connection` (or `database_connection`, or a `$defaultConnection`-named argument)
   are themselves shared, so each captures **one** connection at first instantiation and every fiber
   then issues statements through that one handle. There is no interleaving protection anywhere in
   DBAL; two fibers sharing a PDO handle interleave at the wire. **Size of the hole, measured in the
   subject app:** of 1667 compiled services, exactly **one** application service takes a raw
   connection — `App\Integration\Legacy\LegacyOrderRepository`, via the
   `Doctrine\DBAL\Connection $legacyConnection` argument alias — and exactly **one** framework
   service takes `doctrine.dbal.default_connection` directly, and that one is
   `doctrine.orm.default_entity_manager` itself. `database_connection`,
   `Doctrine\DBAL\Connection` and `cache.default_doctrine_dbal_provider` have **zero** argument
   references. So the practical hole is *one app service in one app* — small, but not zero, and
   silent when hit. The only honest treatment is the build-time list from §4 step 5 plus a documented
   failure mode; a per-fiber `Connection` would need either a 40-method subclass through DBAL's
   `wrapper_class` option (`DoctrineExtension.php:644-647`) or an upstream `ConnectionInterface`.
   **Unmeasured:** whether two fibers on one PDO handle corrupt results or merely serialise.
2. **`ContainerRepositoryFactory` cache keyed by `spl_object_hash`**
   (`ContainerRepositoryFactory.php:96`) — shared across all per-fiber EMs, unbounded in entries, and
   handle-reuse can return a repository bound to a freed manager. Mitigated, not fixed, by keeping
   each fiber's EM alive (§5). A real fix is a per-fiber repository factory, i.e. a second decorator;
   out of scope here and worth its own note before anyone rebuilds EMs per request.
3. **`ServiceEntityRepository` memoisation** (`ServiceEntityRepository.php:52,127-142`) — the shared
   repository service caches `new EntityRepository($manager, …)` from whichever fiber touched it
   first. Apps that use `ServiceEntityRepository` (the `make:entity` default) get the first fiber's
   EM in every later fiber unless those repository services are also made non-shared. Untested
   against this design.
4. **`ContainerAwareEventManager` stays shared** — its listener *instances* are container services,
   so a stateful Doctrine event listener is still shared across fibers. That is ADR-0029's "vendor
   state" problem, not this design's, but Doctrine listeners are a common place for it.
5. **The idle-connection listener** (`Symfony\Bridge\Doctrine\Middleware\IdleConnection\Listener:39-50`)
   looks connections up by *name* — `$this->container->get("doctrine.dbal.{$name}_connection")` — so
   with `shared:false` it builds a brand-new connection just to `close()` it, and the expiry
   `ArrayObject` is keyed by connection name across all fibers. The subject app has
   `idle_connection_ttl: 600` set (`…Container.xml:4743`). Either drop the middleware under Ignis or
   accept a per-request wasted connect. Unmeasured.
6. **`DoctrineBundle::shutdown()`** (`DoctrineBundle.php:80-113`) closes connections via
   `$container->initialized($id)`, which is permanently false for a non-shared id — process shutdown
   will close nothing. Harmless in a long-lived worker, noted so nobody is surprised in tests.
7. **Multiple entity managers / multiple connections** are handled by iterating the
   `doctrine.entity_managers` and `doctrine.connections` parameters (§4 step 1), but a second manager
   sharing a connection with the first (`DoctrineExtension.php:1023-1025`) means two per-fiber
   managers over two per-fiber connections where the stock app had two managers over one. Multiplies
   §6. Untested.
8. **Cost is entirely unmeasured.** Per-fiber EM construction, per-fiber connection setup, RSS per
   fiber with a live `UnitOfWork`, and the 35-method delegation overhead all have no number.

## 8. Gate — what must pass before this is called working

Every item is a pass/fail check, not a judgement call.

1. **Isolation.** Two concurrent requests, each `persist()`ing a distinct entity and
   `sleep()`ing between persist and flush so they are guaranteed to interleave; assert each response
   sees only its own entity, and that `$em->getUnitOfWork()->size()` is 1 in each. Fails today with
   the shared EM. Run under `IGNIS_CHAOS=1` with two seeds, per the research-20 protocol.
2. **No carry-over across fiber reuse.** A request that `persist()`s without flushing, followed by
   ≥ `2 × threads` further requests on the same listener so the fiber is provably reused; assert the
   later requests see an empty identity map. Directly tests the trap this document exists for.
3. **Transaction leak.** A request that opens a transaction and throws; assert the next request on
   that fiber sees `isTransactionActive() === false`, and that the row the first request wrote is
   absent. Fails without §5 step 2.
4. **Closed-manager recovery.** Force an `ORMException` so `isOpen()` goes false; assert the next
   request on that fiber gets a working manager (i.e. the slot was dropped), and that
   `$registry->resetManager()` called from application code does **not** throw the
   `LogicException` from `ManagerRegistry.php:87` (tests §4 step 4).
5. **Connection count.** With `threads=2`, `fibers=8`, drive 64 concurrent requests; assert
   `SHOW STATUS LIKE 'Threads_connected'` (or `pg_stat_activity`) never exceeds 16, and that it does
   not keep growing over a 10k-request soak. This is the number §6 asserts and has not measured.
6. **RSS soak.** 100k requests through the Doctrine path, RSS sampled per 10k, gated the way E4 is —
   a flat tail, not a slope. The `ContainerRepositoryFactory` map (hole 2) fails here if it is going
   to fail.
7. **Direct-`Connection` inventory.** `ignis.doctrine.direct_connections` (§4 step 5) is printed at
   boot and is **empty** for the app under test, or every entry is explicitly acknowledged in the
   app's config. A silent hole 1 is the failure this catches.
8. **E15 does not regress.** `dbal` and `orm` suite pass counts under `scripts/ci-gate.sh` stay at
   their V-27 baselines (dbal 3901/1 failure/633 skipped, orm 3641/16/62/79) — this design touches
   no ORM internals, so any movement there is ours.
9. **Numbers into VALIDATION.md.** Gates 5 and 6 produce a V-n with the exact command and machine
   state; until then §6 stays labelled unmeasured in STATUS.md.
