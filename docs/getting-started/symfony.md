# Symfony

An untouched `symfony/skeleton` app served through `symfony/runtime`'s `extra.runtime.class` hook —
no wrapper script, no change to the skeleton (V-40). `ignis/runtime` is installed as a path
repository today; Packagist publication is tracked separately.

Inside your app (composer needs PHP ≥ 8.4 — use the builder image,
`ghcr.io/koekaverna/ignis-php:8.5.10-zts`, with this repo bind-mounted at `/opt/ignis`, if the
app's own PHP is older):

```
composer config platform.php 8.5.10
composer config repositories.ignis '{"type":"path","url":"/opt/ignis/php/packages/*","options":{"symlink":false}}'
composer require ignis/runtime:@dev ignis/symfony-runtime:@dev --no-scripts
composer config extra.runtime.class 'Ignis\Symfony\IgnisRuntime'
# then add Ignis\Symfony\IgnisBundle::class => ['all' => true] to config/bundles.php
composer dump-autoload
mkdir -p var && chmod -R a+rwX var
```

`--no-scripts` matters when composer's own PHP is older than 8.4: `platform.php` satisfies the
solver, but the generated `vendor/composer/platform_check.php` checks the interpreter actually
running Flex's `cache:clear` hook and fatals. Nothing is lost — Symfony warms its cache on the
first request. With `--no-scripts` nothing creates `var/`, hence the `mkdir`.

`ignis.toml`:

```toml
entry = "/app/public/index.php"
listen = "0.0.0.0:8080"
```

Run it the same way as [Install](install.md):

```
docker run -p 8080:8080 \
  -v ./ignis.toml:/etc/ignis/ignis.toml \
  -v ./app:/app \
  ghcr.io/koekaverna/ignis
```

## Two traps (V-40)

- `APP_RUNTIME=…` in `.env` does **nothing**: `symfony/runtime` reads it from `$_SERVER` before
  `.env` is loaded, so `public/index.php` runs as a single CGI request, prints the page and exits
  0. The runtime class belongs in `composer.json`'s `extra.runtime.class`, set above.
- `var/` must be writable by the image's `ignis` user (the `chmod` above); files composer wrote as
  root inside a container cannot be removed from the host without root — clean up through the same
  image.
- This recipe serves the skeleton in **dev mode** (`APP_ENV=dev` is the skeleton's default): the
  welcome page is a 404 and the profiler is on. Set `APP_ENV=prod` in `.env.local` for anything you
  measure or expose.

## Fiber-scoped state

Register the bundle — without it the container's request-scoped singletons are shared by every
fiber, and for the security token that is a privilege-escalation bug, not a slowdown (V-68):

```php
// config/bundles.php
Ignis\Symfony\IgnisBundle::class => ['all' => true],
```

It makes `request_stack` (ADR-0011, V-16) and `security.token_storage` (V-68) per fiber, so two
interleaved requests never observe each other's `Request` or authenticated user even though both run
on the same OS thread at the same time. This is the [context](../concept/mechanisms.md) mechanism
applied to the framework's own state, on top of PHP's superglobals.

!!! warning "Only the listed services are covered"

    An earlier version of this page said "`RequestStack` **and the request-scoped services
    Symfony's DI container hands out** are fiber-scoped". The second half was false and V-68
    measured it: every container singleton that keeps per-request state is shared by all fibers
    until something scopes it. Symfony's own `kernel.reset` tag is the inventory of such services,
    and resetting cannot help between *overlapping* requests — `Kernel::boot()` only resets when
    nothing else is in flight, which under load is never (research 36).

    The services scoped today are `request_stack`, `security.token_storage` and
    `security.untracked_token_storage`. `$_SESSION` is **not** one of the four superglobals the
    runtime swaps per fiber, so two overlapping requests share it (V-67) — and `session_start()`
    does not work under the embed SAPI at all, so server-side sessions are a non-goal here
    (ADR-0038). Doctrine's `EntityManager` is next and is not scoped yet.

## Deployment

For a production deployment recipe — process supervision, TLS termination in front, rolling
restarts — see [Deploy](../deploy.md).

Next: [Legacy apps](legacy.md) if part of your stack is a procedural docroot rather than a
framework front controller.
