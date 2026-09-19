# Ignis

An application server for PHP. One Rust process embeds PHP 8.5 (ZTS) and runs many requests per
OS thread on native Fibers; every wait — a timer, a socket, TLS, a PostgreSQL query — is owned by
tokio, so the thread serves other requests meanwhile. **Unmodified synchronous PHP becomes
non-blocking**: `file_get_contents('http://…')`, `fsockopen`, `ssl://`, `ext/sockets`, `sleep()`
park the fiber instead of the thread — `curl_*` included, libcurl's own blocking calls are
interposed, so there is no worker thread and no copy (V-59). What genuinely cannot be parked
(`SQLite3`, a file-backed `PDO`: `epoll` refuses regular files) is routed
to a pool of synchronous worker threads with no code change.

It replaces php-fpm, FrankenPHP or RoadRunner in front of a Symfony or Laravel app. Numbers, all
reproducible from `bench/`, are in [STATUS.md](STATUS.md) and [VALIDATION.md](VALIDATION.md).

## Run

```
ignis serve                 # reads ./ignis.toml
ignis serve app.php         # or name the entry script
ignis serve --config /etc/ignis/ignis.toml
```

`ignis serve` starts one PHP thread per core, supervises them (a worker that dies is respawned
without an opcache reset), listens on `127.0.0.1:8080`, and answers `/_ignis/health` itself — 200
while at least one worker is alive and not stalled, 503 otherwise, so a load balancer can stop
sending to a wedged process even when PHP could not say so.

The entry script is either a plain script that calls `Ignis\serve()` (see
[examples/hello_server.php](examples/hello_server.php)) or a framework runtime — an untouched
`symfony/skeleton`'s own `public/index.php`, through `symfony/runtime`'s `extra.runtime.class`
hook (see [Symfony](#symfony) below).

## Configure

Copy [ignis.toml.example](ignis.toml.example) to `ignis.toml`. Every key is optional; an unknown
key is an error. Precedence, highest first: CLI flag, `IGNIS_*` environment variable, the file,
the default.

| key | default | what it does |
|---|---|---|
| `entry` | — | PHP entry script every worker runs |
| `listen` | `127.0.0.1:8080` | listener address |
| `threads` | cores | PHP worker threads |
| `offload` | `0` | synchronous workers for what cannot park: `SQLite3`, file-backed `PDO`, CPU-bound calls |
| `supervise` | `true` | respawn a worker whose script ends |
| `php_ini` | — | extra php.ini (the embed SAPI has no `-c`/`-d`) |
| `log` | `warn` | `RUST_LOG` filter; a respawn or a stalled thread is never silent |
| `budget.fibers` | `1024` | request fibers admitted per thread; the rest wait as data, not fibers |
| `budget.queue` | `4096` | waiting requests before `503` + `retry-after` |
| `exempt` | `["/_ignis/"]` | path prefixes admitted regardless of the budget |

Every `ignis_*` function is defined in Rust, so an IDE, PHPStan or Psalm sees "unknown function" at
call sites unless it also loads `php/packages/runtime/stubs/ignis.php` (BACKLOG H-7) — pull it in via `ignis/runtime`'s
`autoload-dev.files` (composer, dev only), or `require 'php/packages/runtime/stubs/ignis.php';` directly in your
analyser's bootstrap. Each stub is `function_exists()`-guarded, so loading it under the real binary
is a no-op.

## Install

```
docker run -p 8080:8080 ghcr.io/koekaverna/ignis
curl http://127.0.0.1:8080/_ignis/health
```

The image (84 MB since the engine took the toolchain extensions and `libxml2` — V-83; V-39's 64 MB is the figure before that, and several pages still quoted it) serves the hello entry on
its own. To serve your app, mount it and point the config at its entry script:

```
docker run -p 8080:8080 \
  -v ./ignis.toml:/etc/ignis/ignis.toml \
  -v ./app:/app \
  ghcr.io/koekaverna/ignis
```

with `entry = "/app/public/index.php"` (Symfony: see [Symfony](#symfony) below for the entry
script your app needs, none) and `listen = "0.0.0.0:8080"` in that file. The userland lives at
`/opt/ignis/php` inside the image. A static binary is not shipped yet — `libphp` pulls in ~35
shared libraries through libcurl — so the image is the artifact for now.

## Symfony

An untouched `symfony/skeleton` app served through `symfony/runtime`'s `extra.runtime.class`
hook — no wrapper script, no change to the skeleton (V-40). `ignis/runtime` is installed as a
path repository today; Packagist publication is tracked separately (BACKLOG M3-6).

Inside your app (composer needs PHP ≥ 8.4; use the builder image,
`ghcr.io/koekaverna/ignis-php:8.5.10-zts`, with this repo bind-mounted at `/opt/ignis`, if the
app's own PHP is older):

```
composer config platform.php 8.5.10
composer config repositories.ignis '{"type":"path","url":"/opt/ignis/php/packages/*","options":{"symlink":false}}'
composer require ignis/runtime:@dev ignis/symfony-runtime:@dev --no-scripts
composer config extra.runtime.class 'Ignis\Symfony\IgnisRuntime'
composer dump-autoload
mkdir -p var && chmod -R a+rwX var
```

`--no-scripts` matters when composer's own PHP is older than 8.4: `platform.php` satisfies the
solver, but the generated `vendor/composer/platform_check.php` checks the interpreter actually
running Flex's `cache:clear` hook and fatals. Nothing is lost — Symfony warms its cache on the
first request. With `--no-scripts` nothing creates `var/`, hence the `mkdir`.

`ignis.toml`:

```
entry = "/app/public/index.php"
listen = "0.0.0.0:8080"
```

Run it the same way as [Install](#install):

```
docker run -p 8080:8080 \
  -v ./ignis.toml:/etc/ignis/ignis.toml \
  -v ./app:/app \
  ghcr.io/koekaverna/ignis
```

Two traps (V-40):
- `APP_RUNTIME=…` in `.env` does **nothing**: `symfony/runtime` reads it from `$_SERVER` before
  `.env` is loaded, so `public/index.php` runs as a single CGI request, prints the page and exits
  0 — the runtime class belongs in `composer.json`'s `extra.runtime.class`, set above.
- `var/` must be writable by the image's `ignis` user (the `chmod` above); files composer wrote
  as root inside a container cannot be removed from the host without root — clean up through the
  same image.
- This recipe serves the skeleton in **dev mode** (`APP_ENV=dev` is the skeleton's default): the
  welcome page is a 404 and the profiler is on. Set `APP_ENV=prod` in `.env.local` for anything
  you measure or expose.

## Legacy apps: the classic worker loop

A framework front controller (Symfony, Laravel) keeps its state in objects and runs fine in worker
mode. A procedural docroot usually does not: it assigns at the top level of `index.php` and reads
those variables back with `global` inside functions. That only works if the `include` happens at
the top level of the main script — not in a function, not in a closure, not in a fiber (V-54
measured all four). So classic mode offers a loop your own script owns:

```php
require '/path/to/ignis/php/packages/runtime/src/ignis.php';
require '/path/to/ignis/php/packages/runtime/src/classic.php';

Ignis\Classic\listen('/var/www/html/public', '0.0.0.0:8080');
while ($script = Ignis\Classic\accept()) {
    try {
        include $script;      // top level of this script: real globals
    } catch (Ignis\Classic\Finished) {
        // finish() ends the script, not the worker; without this it unwinds the loop
    }
    Ignis\Classic\respond();
}
```

`ignis --threads 4 examples/classic_worker.php /var/www/html/public` runs it. One request at a time
per thread by construction — the loop is your `while`, so nothing else on that thread runs while
the script does; concurrency comes from threads, as in php-fpm. Two rules, both the same as in
RoadRunner and FrankenPHP's worker mode: declare functions and classes with `require_once` or
behind `function_exists()`, and do not suspend inside the script.

## Build from source

Ignis needs PHP 8.5.10 ZTS with the embed SAPI at `/opt/php85-zts`; distribution packages are
NTS and will not do.

```
scripts/build-php.sh                  # idempotent, ~7 min, from $HOME/php-src (PHP_SRC= to override)
cargo build --release -p ignis        # PHP_CONFIG=/opt/php85-zts/bin/php-config is the default
LD_LIBRARY_PATH=/opt/php85-zts/lib ./target/release/ignis serve examples/hello_server.php
```

`scripts/smoke.sh` is the end-to-end gate; set `IGNIS_LISTEN` if something else holds `:8080`.

## What works unchanged, and what does not

| PHP does | Ignis does | evidence |
|---|---|---|
| `Ignis\sleep`, `Ignis\all`, `Ignis\async` | parks the fiber on a tokio timer | V-2, V-3 |
| `file_get_contents('http://…')`, `fsockopen`, `stream_socket_client` over `tcp://`, `ssl://`, `tls://`, `unix://` | parks the fiber; STARTTLS supported | V-12, V-25, V-29 |
| `socket_read/recv/accept/write/send…` (`ext/sockets`) | parks the fiber | V-29 |
| `sleep()`, `usleep()` | park the fiber | V-22 |
| `stream_select()` on hooked streams | answered without blocking | V-26 (TLS read-ahead: open, B7) |
| `curl_*` (including `CURLOPT_WRITEFUNCTION` and `curl_multi_*`) | **parks the fiber** — libcurl's own blocking calls are interposed, no worker thread and no copy, and the write callback runs in the calling fiber | V-45, V-59 |
| `PDO` on a socket-backed driver (`pgsql`; `mysql` is not compiled into this build) | **parks the fiber** — 303 ms for 100 × 200 ms queries on one thread, against 2,753 ms through an 8-worker offload pool | V-45, V-59 |
| `SQLite3` | routed to the offload pool, the fiber sleeps — a regular file cannot be parked (ADR-0024), so offload is the only mechanism it has | V-24 |
| `PDO` on `sqlite:` | **blocks the OS thread** for the length of the file access, like every other regular-file call (ADR-0024). Routing is by class name and the driver is in the DSN, which the runtime cannot see when it decides — set `IGNIS_OFFLOAD_CLASSES=PDO,SQLite3` to send every `PDO` to the pool instead | V-59 addendum |
| PostgreSQL | `pdo_pgsql` and `ext/pgsql` unchanged — the driver's socket parks the fiber; `ignis/doctrine` gives every fiber its own connection and pools them per thread | V-85, V-86 |
| `$_SERVER`, `$_GET`, `$_POST`, `$_COOKIE` | fiber-scoped; two interleaved requests never see each other's | V-11 |
| a client disconnect | cancels the request fiber and its children within 1 ms; `Ignis\deadline()` per request | V-14, V-30 |
| a fatal error in a handler | ends one worker thread, which is respawned; other threads keep serving | V-17 |
| Symfony via `symfony/runtime` | worker mode, fiber-scoped `RequestStack`, sessions | V-16 |
| Revolt / AMPHP | `Ignis\Revolt\IgnisDriver` runs the examples unchanged | V-13, V-23 |
| a legacy docroot that keeps state in globals (`$wpdb` and friends) | **only in the top-level worker loop** — `examples/classic_worker.php`. In that shape `$GLOBALS` and `global $x` behave as under `php -S`; in the fiber-based `Ignis\Classic\serve()` they do not, because an entry included from a fiber has its top-level variables as locals | V-53, V-54 |
| a script that declares a function at top level without a guard | **fatals on the second request** (`Cannot redeclare`, uncatchable; the thread is respawned). Use `require_once` or `function_exists()` — the rule in every worker runtime | V-53, V-54 |

Every hook has an off switch (`IGNIS_NO_SUPERGLOBALS`, `IGNIS_NO_UNIVERSAL_PARK` + the `IGNIS_PARK` table) — a
claim about a hook is only ever made against its control. `IGNIS_PARK` is the policy table
(ADR-0037): comma-separated `lib` or `lib:symbol` rows naming what may park; unset = the built-in
seed, empty = nothing. The seed is `SEED` in `crates/ignis/src/php/park.rs` and it grows with each cycle — sixteen `libphp:` symbols today (`sleep`, `usleep`, `nanosleep`, `select`, `accept`, `poll`, `recv`, `send`, `recvfrom`, `sendto`, `recvmsg`, `sendmsg`, `connect`, `read`, `write`, `flock`) plus `libcurl`, `libpq`, `libssl`, `libcrypto` whole. Three documents listed three different seeds until 2026-09-18; read the constant, not a copy of it.

## Where it is

The runtime is measured and hardened ([STATUS.md](STATUS.md)); the product around it is being
built, in the order in [ROADMAP.md](ROADMAP.md): run → install → real apps unchanged → operate →
ship. [examples/app.php](examples/app.php) is the API an application developer writes against.

MIT.
