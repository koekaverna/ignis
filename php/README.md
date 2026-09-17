# The Ignis PHP userland

One package per thing you might actually want, so an application installs the adapter it uses and
nothing else. Every package here needs the `ignis` binary — under php-fpm or php-cli they do nothing.

| package | what it is |
|---|---|
| [`ignis/runtime`](packages/runtime) | the scheduler: `Ignis\Loop`, `Future`, `async()`, `all()`, `sleep()`, `deadline()`, `Scope`, `serve()`, and the classic worker loop. Everything else depends on it. |
| [`ignis/symfony-runtime`](packages/symfony-runtime) | `symfony/runtime` adapter (ADR-0011): boot the kernel once, serve each request in its own fiber |
| [`ignis/doctrine`](packages/doctrine) | Doctrine's `EntityManager` per request instead of per process (V-69) — without it two overlapping requests share one identity map |
| [`ignis/pg`](packages/pg) | the runtime-owned PostgreSQL pool (ADR-0015) |
| [`ignis/offload`](packages/offload) | synchronous worker threads for what park cannot reach (ADR-0016) |
| [`ignis/grpc`](packages/grpc) | gRPC handlers and clients on the runtime's own listener (E10) |
| [`ignis/revolt`](packages/revolt) | Revolt driver, so AMPHP libraries run unchanged (E7) |
| [`ignis/swoole`](packages/swoole) | a shim for part of the Swoole coroutine API (E15d) |
| [`ignis/temporal`](packages/temporal) | Ignis host for the **official** `temporalio/sdk-php` (ADR-0040) |
| [`ignis/temporal-core-transport`](packages/temporal-core-transport) | the portable half of that: sdk-core activations ↔ sdk-php's command model. Depends on nothing from Ignis and is written to be offered upstream. |
| [`ignis/temporal-prototype`](packages/temporal-prototype) | our own pre-ADR-0040 workflow runtime (ADR-0013, V-19). Frozen; kept because the replay test was built on it. |

## Installing into an application

```bash
composer config repositories.ignis '{"type":"path","url":"/opt/ignis/php/packages/*","options":{"symlink":false}}'
composer require ignis/runtime:@dev ignis/symfony-runtime:@dev
```

## Working on them

The root `composer.json` is the monorepo aggregate — it is not published and nothing installs it.
`composer install` here pulls every package through the `packages/*` path repository, which is what
the test suites use.
