# `ignis/doctrine`

One EntityManager and one database connection per request, and — when you ask for it — a pool of
connections per thread.

## What it does

Doctrine's EntityManager is a container singleton holding a UnitOfWork, which is a per-request
object by nature. Under Ignis several requests share a thread, so sharing it means one request's
entities are visible to another and one request's `flush()` writes another's changes. Measured with
two overlapping requests: the identity map leaked, every time (V-69).

Its `Doctrine\DBAL\Connection` was worse. libpq is not reentrant per connection, so two fibers
inside one connection take each other's result sets — measured as **one request answering with
another request's row, HTTP 200, no error** (V-85). The package gives every fiber its own manager
and its own connection, and releases both at request end.

## Install

```
composer require ignis/doctrine:@dev
```

Register it **after** DoctrineBundle in `config/bundles.php`:

```php
Ignis\Doctrine\IgnisDoctrineBundle::class => ['all' => true],
```

## Configure: two connection modes

`config/packages/ignis_doctrine.yaml`, shaped the way `doctrine.dbal` is shaped — one block for
every connection, names for the ones that differ:

```yaml
ignis_doctrine:
    pool:
        size: 10          # connections per thread; 0 (the default) is a connection per fiber
        warm: 10          # opened while the thread boots; defaults to size, 0 fills lazily
        wait_ms: 5000     # a fiber waits this long for a free connection, then is refused

    connections:          # every key falls back to the block above
        reporting:
            pool: { size: 2, wait_ms: 500 }
        archive:
            pool: { size: 0 }        # this one stays a connection per fiber
```

**Per fiber** (the default) opens a connection per request that touches the database — about 1 ms
of TCP and SCRAM — and closes it at request end. It is what php-fpm does without `pconnect`, and
nothing caps how many a burst opens.

**Pool** opens at most `size` connections per **thread**, while the thread boots, and leases one
to a fiber at a time. The process therefore opens `threads × size` connections and no more. A
request that arrives when every connection is leased parks until one comes back, and after
`wait_ms` gets `Ignis\Doctrine\Pool\PoolTimeoutException` rather than waiting behind a stuck
request.

Measured, one application, `default` at 4 and `reporting` at 1, four concurrent queries at each:
four backends on the first connection and one on the second, no rows crossed. A pool of two serving
six overlapping requests answered all six correctly, in turn (V-85 addenda 2–4).

Deployment-time numbers stay deployment-time without the package reading the environment:
`size: '%env(int:DB_POOL)%'` is Symfony's own mechanism and resolves at runtime.

## What happens at request end

`Loop` clears the fiber's scope in a `finally`, which drops the handle holding the manager: an open
transaction is rolled back, the connection is closed or returned to its pool, and the manager is
cleared. An exception, a cancelled request and a deadline all take that path — six handlers that
throw while holding a lease leave a pool of two serving (V-85 addendum 5).

## Limits

- **A hung fiber never releases.** A fiber that never resumes never reaches that `finally`, so its
  connection is gone from the pool for good. There is no lease age, no reaper and no watchdog yet
  (`S-POOL-LEASE-AGE`).
- **A connection injected directly is not fixed.** A service that takes `Doctrine\DBAL\Connection`
  in its constructor keeps one instance for the life of the thread. Resolve it per call — through
  the `ManagerRegistry`, say — or keep to the manager (`S-DBAL-DIRECT`).
- **A returned connection is cleaned for PostgreSQL only.** The pool runs the documented expansion
  of `DISCARD ALL` before handing a connection on; other drivers get no reset, so a reused MySQL or
  SQLite connection carries its session settings into the next request.
- **The pool is per thread**, not per process. `threads × size` is the number to give your database
  administrator.
