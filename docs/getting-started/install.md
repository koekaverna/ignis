# Install

## Docker image (recommended)

The published image is 64 MB, built and smoke-tested by CI on every push to `main`, and serves the
bundled hello-world entry on its own:

```
docker run -p 8080:8080 ghcr.io/koekaverna/ignis
curl http://127.0.0.1:8080/_ignis/health
```

To serve your own app, mount it and point the config at its entry script:

```
docker run -p 8080:8080 \
  -v ./ignis.toml:/etc/ignis/ignis.toml \
  -v ./app:/app \
  ghcr.io/koekaverna/ignis
```

with `entry = "/app/public/index.php"` and `listen = "0.0.0.0:8080"` in `ignis.toml` — `0.0.0.0`
matters here because `127.0.0.1` (the default) is unreachable from outside the container. The
userland scheduler lives at `/opt/ignis/php` inside the image.

## Binary

A static binary is not shipped yet — `libphp` pulls in roughly 35 shared libraries through
`libcurl` alone, so the Docker image is the distributable artifact for now
([ADR-0027](../adr/0027-build-and-distribution.md)). Building from source (below) is how you get
the `ignis` binary directly, for example to run it outside a container or to use the [CLI](../reference/cli.md).

## Build from source

Ignis needs PHP 8.5.10 built **ZTS** (Zend Thread Safety) with the embed SAPI — distribution
packages are NTS and will not link (see [What it is not](../concept/non-goals.md)).

```
scripts/build-php.sh                  # idempotent, ~7 min, builds PHP 8.5.10 ZTS+embed → /opt/php85-zts
cargo build --release -p ignis        # PHP_CONFIG=/opt/php85-zts/bin/php-config is the default
LD_LIBRARY_PATH=/opt/php85-zts/lib ./target/release/ignis serve examples/hello_server.php
```

`scripts/smoke.sh` is the end-to-end gate that CI runs on every push; `IGNIS_LISTEN` overrides the
port if `:8080` is already taken.

Next: [Quickstart](quickstart.md).
