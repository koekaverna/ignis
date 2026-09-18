# `ignis/symfony-runtime`

Boots a Symfony kernel once and serves every request in its own fiber (ADR-0011).

## What it does

Symfony already has the seam: `symfony/runtime` lets an application name the class that runs it.
`Ignis\Symfony\IgnisRuntime` returns a runner that boots the kernel one time per PHP thread and then
`Ignis\serve()`s — each request becomes a fiber, is adapted into a Symfony `Request`, and the
response's status, headers and body are copied back. `kernel.terminate` still runs. No wrapper
script, no change to the skeleton (V-40).

Selecting the runtime is harmless for anything that is not an HTTP kernel — console commands defer
to the stock `SymfonyRuntime`.

## What it fixes, and why the bundle is not optional

Several requests share one thread, so anything Symfony keeps in a container singleton is shared
between requests that overlap. Two of those are security holes rather than curiosities:

- `RequestStack` — a service asking for "the current request" could get another request's.
- `TokenStorage` — measured: request B read request A's token, and with different roles that is a
  privilege escalation, not a mix-up (V-68).

`IgnisBundle` replaces both with fiber-scoped versions and adds a compiler pass that walks the
`kernel.reset` tag — Symfony's own inventory of per-request state — rather than a list we made up
(research 36).

## Install

```
composer require ignis/runtime:@dev ignis/symfony-runtime:@dev --no-scripts
composer config extra.runtime.class 'Ignis\Symfony\IgnisRuntime'
```

Then register the bundle in `config/bundles.php`:

```php
Ignis\Symfony\IgnisBundle::class => ['all' => true],
```

The step-by-step version, including why `--no-scripts` matters when composer's own PHP is older
than 8.4, is on the [Symfony getting-started page](../getting-started/symfony.md).

## Configure

| What | Where | Default |
|---|---|---|
| Listen address | `extra.runtime.ignis_listen` in `composer.json`, or `IGNIS_LISTEN` | `127.0.0.1:8080` |
| Threads, budget, limits | `ignis.toml` / `IGNIS_*` — the runtime's own settings | see [configuration](../reference/configuration.md) |

The bundle itself has no configuration: what it does is not a choice, it is the correction that
makes a shared kernel safe under concurrency.

## Responses

An ordinary `Response` is copied whole. A `StreamedResponse` streams — its `sendContent()` becomes
the producer of an `Ignis\Http\StreamedResponse`, so the bytes leave as the callback writes them
instead of being collected first. Multi-valued headers survive: a response with three `Set-Cookie`
lines arrives with three.

## Limits

A kernel is booted per thread, so `threads × kernel` is the memory floor, and anything an
application stores in its own statics is shared across the requests on that thread — the bundle
fixes Symfony's own state, not yours. Doctrine needs [its own package](doctrine.md); without it the
EntityManager and its database connection are shared by every fiber on the thread, which is a data
leak rather than a slowdown (V-69, V-85).
