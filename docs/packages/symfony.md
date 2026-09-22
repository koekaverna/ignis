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

`IgnisBundle` does not replace either. It marks them, in the container, to be built through
`Ignis\Scope::create()` — the same way `lazy` is a mark on a definition — so that the object's
declared properties live per fiber and Symfony's own classes are the ones serving the request
(ADR-0042). The façades that used to stand in for them are deleted.

The list of framework ids is short and hand-picked, and `kernel.reset` is deliberately **not** the
criterion: of the fifteen services carrying that tag, nine are caches shared between requests on
purpose, and scoping those would give every request its own cache and quietly destroy what they are
for (research 36). Today the list is `request_stack`, `security.token_storage`,
`security.untracked_token_storage` and `security.logout_url_generator`.

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
| Extra services to scope | `ignis.scoped_ids` in `config/packages/ignis.yaml` | the framework ids above |

What the bundle does to Symfony's own services is not a choice — it is the correction that makes a
shared kernel safe under concurrency, and there is no switch to turn it off. The one setting is for
services it cannot know about: a vendor service of yours that holds one request's state.

```yaml
# config/packages/ignis.yaml
ignis:
    scoped_ids: ['acme.tenant_context']
```

What you list is **added** to the framework ids, never substituted for them. For a class your own
application owns there is nothing to configure — put `#[FiberScoped]` on the class and the bundle's
autoconfiguration does the rest:

```php
use Ignis\Symfony\Attribute\FiberScoped;

#[FiberScoped]
final class TenantContext
{
    private ?Tenant $tenant = null;          // this property is per request
}
```

Both routes end in the same place, and a service reached either way is built through
`Ignis\Scope::create()`. Adding a row wants a test that fails without it: the state they protect is
invisible until two requests overlap.

## Responses

An ordinary `Response` is copied whole. A `StreamedResponse` streams — its `sendContent()` becomes
the producer of an `Ignis\Http\StreamedResponse`, so the bytes leave as the callback writes them
instead of being collected first. Multi-valued headers survive: a response with three `Set-Cookie`
lines arrives with three.

## Limits

A kernel is booted per thread, so `threads × kernel` is the memory floor, and anything an
application stores in its own statics is shared across the requests on that thread — the bundle
fixes Symfony's own state, not yours. An entity manager and its database connection are among the
services that stay shared by every fiber on the thread until you mark them `scoped`
([ADR-0042](../adr/0042-fiber-scoped-objects.md)) — sharing them is a data leak rather than a
slowdown: two overlapping requests end up inside one PostgreSQL socket, one reading the other's row
(V-69, V-85).
