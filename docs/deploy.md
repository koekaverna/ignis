# Deploying a Symfony app on Ignis

Start-to-finish recipe for running a Symfony app on the Ignis runtime image in production. For
sizing, `/_ignis/health`, logging and the full `ignis.toml`/`IGNIS_*` reference, see
[operate.md](operate.md); for the bare-image quickstart see [README.md#install](https://github.com/koekaverna/ignis/blob/main/README.md#install).
Every claim below links to a `V-n` in [VALIDATION.md](https://github.com/koekaverna/ignis/blob/main/VALIDATION.md); nothing here is guessed.

PHP version: **8.5.10 ZTS**, embedded in the `ignis` binary — the app needs no PHP of its own at
runtime (`docker/Dockerfile`). The app itself needs **no code changes**: an untouched
`symfony/skeleton` served through `symfony/runtime`'s `extra.runtime.class` hook (V-40), and a real
Symfony 8.1 app run unmodified across three cycles of the project's own deletion passes, answering
its own JSON route at 21k req/s on 4 threads with 0 fatals (V-53). (An earlier probe, V-16, did add
one service override to get a fiber-scoped `RequestStack` and predates the `extra.runtime.class`
recipe below — it is not what you follow today; V-40/V-53 are.)

## 1. Get the image

Published by the release workflow on every `v*` tag (`.github/workflows/release.yml`): `:latest`
is the last release, and the image is smoke-tested there before this doc trusts it (V-39 addendum):

```
docker pull ghcr.io/koekaverna/ignis:latest
```

Prefer a `:<sha>` tag (also pushed by the same workflow) over `:latest` for anything you intend to
roll back to later — "latest" is a moving target, a sha is not.

## 2. Prepare the app

These steps run against the **app's own checkout**, not inside the runtime image. If the app's own
`composer`/`php` is older than 8.4 (`ignis/runtime` requires `php >= 8.4`, `php/composer.json`),
run them inside the builder image instead, with this repo mounted at `/opt/ignis`:

```
docker run --rm -it -v "$PWD":/app -v /path/to/ignis:/opt/ignis:ro -w /app \
  ghcr.io/koekaverna/ignis-php:8.5.10-zts bash
```

Then, from the app's root:

```
composer config platform.php 8.5.10
composer config repositories.ignis '{"type":"path","url":"/opt/ignis/php/packages/*","options":{"symlink":false}}'
composer require ignis/runtime:@dev ignis/symfony-runtime:@dev --no-scripts
composer config extra.runtime.class 'Ignis\Symfony\IgnisRuntime'
composer dump-autoload
mkdir -p var && chmod -R a+rwX var
```

Two of these lines are easy to skip and both matter (V-40 addendum):

- **`--no-scripts`** — only needed when the interpreter running composer is older than 8.4 (the
  builder image's own `php-cli` is 8.3.6). `platform.php` satisfies the dependency solver, but the
  generated `vendor/composer/platform_check.php` checks the *actual* interpreter running Flex's
  `cache:clear`/`assets:install` hooks and fatals with "require a PHP version >= 8.4.0" if you omit
  it. Nothing is lost by skipping those hooks — Symfony warms its own cache on the first request
  (see step 4).
- **`mkdir -p var`** — with `--no-scripts`, nothing creates `var/`, so the `chmod` right after it
  needs the directory to already exist.

`extra.runtime.class` is what actually wires the app to Ignis. **`APP_RUNTIME=…` in `.env` does
nothing here**: `symfony/runtime` reads `APP_RUNTIME` from `$_SERVER` *before* `.env` is loaded, so
with only `.env` set, `public/index.php` runs as a single CGI-style request under the default
runtime, prints the page once and exits — which the supervisor sees as a worker that immediately
ends, respawns, ends again, and within a few seconds gives up ("restart budget exhausted (10/min),
not respawning" — same signature as the missing-`vendor/` failure in §6). Put the class in
`composer.json`'s `extra.runtime.class` as shown above; that is read at `require`/autoload time and
does not depend on `$_SERVER` timing.

## 3. Production mode

The skeleton's own default is `APP_ENV=dev` (profiler on, the welcome page 404s in dev mode —
V-40). For anything you expose or measure, set production mode explicitly in `.env.local`
(uncommitted, per Symfony's own convention):

```
echo "APP_ENV=prod" >> .env.local
echo "APP_SECRET=$(openssl rand -hex 16)" >> .env.local   # skeleton ships this blank
```

## 4. Warm the cache (optional but recommended)

`--no-scripts` in step 2 skipped `cache:clear`, so the first real request pays for warming
`var/cache/prod` (README.md#symfony: "Symfony warms its cache on the first request" — no code
change needed for that to work). To avoid that latency spike landing on your first real user, warm
it explicitly instead, using the same 8.5.10 interpreter the container will run the app under:

```
docker run --rm -v "$PWD":/app -w /app --entrypoint /opt/php85-zts/bin/php \
  ghcr.io/koekaverna/ignis-php:8.5.10-zts bin/console cache:warmup --env=prod
chmod -R a+rwX var   # cache:warmup just wrote var/cache/prod as your host user (or root)
```

## 5. `ignis.toml`

Next to your `docker compose.prod.yaml` (or wherever you mount it from):

```toml
entry = "/app/public/index.php"
listen = "0.0.0.0:8080"
threads = 4
```

`entry` and `listen` are the two keys the image's shipped `docker/ignis.toml` already sets for a
generic app (`listen = "0.0.0.0:8080"` — inside a container `127.0.0.1` is unreachable from the
host); point `entry` at the Symfony app's own `public/index.php`, unmodified. `threads` defaults to
the container's available parallelism if omitted (`crates/ignis/src/config.rs`) — set it to match
whatever CPU limit you give the container (see `docker/compose.prod.yaml`'s `deploy.resources`).

## 6. Run it

```
docker compose -f docker/compose.prod.yaml up -d
```

`docker/compose.prod.yaml` in this repo is the production-shaped file: the published image (not a
local build), `restart: unless-stopped`, a `/_ignis/health` healthcheck, CPU/memory limits, a
read-only root filesystem, the non-root user the image already runs as, bounded JSON logs, and the
`IGNIS_THREADS`/`IGNIS_LISTEN`/`ignis.toml` mount wired to what the binary actually reads — every
option in it is commented with the source line that backs it. Equivalent one-shot `docker run`,
without compose:

```
docker run -d --name app --restart unless-stopped -p 8080:8080 \
  -v "$PWD/ignis.toml":/etc/ignis/ignis.toml:ro \
  -v "$PWD":/app \
  -e IGNIS_THREADS=4 \
  ghcr.io/koekaverna/ignis:latest
```

## 7. Check it is up

```
curl -w ' [%{http_code}]\n' http://127.0.0.1:8080/_ignis/health
```

`200` with `{"status":"ok","threads":N,"stalled":0,"restarts":0}` means at least one PHP worker
thread is registered and not stalled; `503`/`"unavailable"` means every thread is (operate.md,
V-38). This endpoint is answered by the Rust runtime, never by PHP, and is exempt from the request
budget by construction, so it stays reachable even under a saturated server.

## 8. Deploy a new version

```
docker compose -f docker/compose.prod.yaml pull
docker compose -f docker/compose.prod.yaml up -d
```

**`SIGTERM` drains** (V-56), which is what `docker compose up -d` sends to the old container, so
in-flight requests are finished rather than cut. Two knobs:

- `IGNIS_DRAIN_DELAY_MS` (default `0`) — how long `/_ignis/health` answers `503 {"status":"draining"}`
  **while the listener is still accepting**. Set it a little above your load balancer's health-check
  interval and a rolling deploy loses nothing: the balancer takes the instance out of rotation,
  requests it already sent are still served, and only then does the socket close.
- `IGNIS_DRAIN_TIMEOUT_MS` (default `10000`) — how long in-flight requests get to finish after the
  listener closes. Past it the process exits anyway and logs how many were still pending.

Measured (V-56): five 1.5 s requests in flight when `SIGTERM` arrived all returned 200; a new
request during the grace window returned 200; after it, connections were refused; the process
exited by itself. With a single instance there is still a gap between the socket closing and the
new container binding — run two behind a balancer if that matters.

## 9. Roll back

Before deploying, note what is currently running:

```
docker inspect --format '{{.Config.Image}}' app
```

That gives you the exact `ghcr.io/koekaverna/ignis:<sha>` to go back to. To roll back, set that sha
as the `image:` in `docker/compose.prod.yaml` (or pass it to `docker run`) and repeat step 6; the
old container drains on `SIGTERM` as in step 8.

## What to do when it does not start

Three failure modes exist today; nothing else is a documented mechanism.

**The boot self-check refuses to start (exit code 2).** Before any worker thread exists, the binary
proves that libcurl/libpq calls really reach Ignis's park interposers rather than silently blocking
a thread (`crates/ignis/src/php/park.rs::selfcheck`, `main.rs`). If it can't confirm that, it prints
which library missed and **exits 2 without serving anything** — a hang later is worse than refusing
to start now. The message names the fix directly: check the binary exports the symbols (`nm -D`)
and that nothing (`LD_PRELOAD`) shadows them. `IGNIS_SKIP_PARK_SELFCHECK=1` starts anyway (logged as
a `warn`, not silent) if you need to get unblocked immediately; treat that as a stopgap, not a fix —
it means blocking calls in that library are no longer provably non-blocking on this box.

**A port already in use.** The listener bind happens inside the PHP entry script's first call to
`ignis_serve()` (`php/packages/runtime/src/ignis.php`'s `serve()`), not before the process starts — so if another process
already holds the port, `/_ignis/health` will refuse the connection outright (nothing is listening
at all, not even a 503), and the container log shows an uncaught `ignis_serve: bind …: Address
already in use` exception from every worker, followed by the same respawn-then-give-up sequence
described in operate.md ("worker script ended; respawning" → "restart budget exhausted (10/min)").
Fix: find and stop whatever else is bound to that host port, or change the port you publish (the
container's own `IGNIS_LISTEN` stays `0.0.0.0:8080`; only the host-side mapping needs to change).

**A missing `vendor/`.** If the app directory was mounted before `composer install`/`require` ran
(or `vendor/` was excluded from what you shipped), `public/index.php` fails on its own first
`require` of `vendor/autoload_runtime.php` inside every worker thread — the exact respawn signature
V-40 recorded for the `APP_RUNTIME`-in-`.env` mistake ("the supervisor respawned it ten times and
gave up") shows up here too, because the mechanism is the same: a script that fails immediately on
every run burns the supervisor's 10-restarts-per-minute budget and stops. `/_ignis/health` degrades
from `200` to `503` as threads exhaust that budget one by one. Fix: run `composer install
--no-dev --optimize-autoloader` (or the step-2 recipe, for a fresh app) against the mounted
directory before starting the container, and confirm `vendor/autoload_runtime.php` exists on the
host path you're mounting in.
