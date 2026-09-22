# Packages

The runtime is one binary; everything a PHP application touches is a composer package under
`php/packages/`. They share one rule: an integration adds no mechanism of its own. Each is a thin
layer over the three the runtime has — park, offload and fiber-scoped context (ADR-0037) — which is
why a package can be read in an afternoon and why none of them can surprise you with a fourth way
to wait.

| Package | Install it when | Page |
|---|---|---|
| `ignis/runtime` | always — everything else depends on it | [Runtime](runtime.md) |
| `ignis/symfony-runtime` | the application is a Symfony kernel | [Symfony](symfony.md) |
| `ignis/doctrine` | that Symfony application uses Doctrine ORM | [Doctrine](doctrine.md) |
| `ignis/offload` | something blocks the thread and cannot be parked (SQLite, a CPU-bound extension) | [Offload](offload.md) |
| `ignis/grpc` | you want to serve or call gRPC on the same listener | [gRPC](grpc.md) |
| `ignis/revolt` | the code base already uses AMPHP or Revolt | [Revolt](revolt.md) |
| `ignis/temporal` | you run Temporal workflows and activities | [Temporal](temporal.md) |

## Installing them

Packagist publication is still open (M3-6), so today the packages are installed from a path
repository pointing at the tree that ships with the runtime image (`/opt/ignis/php/packages/*`):

```
composer config platform.php 8.5.10
composer config repositories.ignis '{"type":"path","url":"/opt/ignis/php/packages/*","options":{"symlink":false}}'
composer require ignis/runtime:@dev
```

Every package after that is one more `composer require ignis/<name>:@dev`.

## What is not here

`ignis/temporal-core-transport` is a host-agnostic package that runs `temporalio/sdk-php` on
Temporal's own sdk-core; it is written to be offered upstream rather than used directly, and the
[Temporal](temporal.md) page says where it fits. `ignis/temporal-prototype` is the workflow runtime
that predates ADR-0040, frozen and kept only because a replay negative-control test is built on it.
