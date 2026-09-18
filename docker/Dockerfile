# ghcr.io/koekaverna/ignis — the runtime image (M2 "Install"): `docker run -p 8080:8080 ghcr.io/koekaverna/ignis`
# serves examples/hello_server.php; mount your app and point /etc/ignis/ignis.toml at it.
#
# Two stages. The build stage starts from the PHP builder image (docker/php.Dockerfile: PHP 8.5.10
# ZTS + embed at /opt/php85-zts, clang for bindgen) and adds only a Rust toolchain. The runtime
# stage is a bare ubuntu:24.04 with the seven libraries libphp.so needs, libphp.so itself at the same
# path the binary's rpath names — so no LD_LIBRARY_PATH — the binary, and the PHP userland.
#
# `docker build -f docker/Dockerfile .` from the repo root.

ARG PHP_IMAGE=ghcr.io/koekaverna/ignis-php:8.5.10-zts

FROM ${PHP_IMAGE} AS build
ENV RUSTUP_HOME=/usr/local/rustup CARGO_HOME=/usr/local/cargo PATH=/usr/local/cargo/bin:$PATH
RUN curl -sSf https://sh.rustup.rs | sh -s -- -y --profile minimal --default-toolchain stable
WORKDIR /work
COPY Cargo.toml Cargo.lock ./
COPY crates ./crates
COPY php ./php
# PHP_CONFIG is already set by the builder image. Cache the registry and the target dir across
# builds; the binary is copied out of the cache mount inside the same RUN.
RUN --mount=type=cache,target=/usr/local/cargo/registry \
    --mount=type=cache,target=/work/target \
    cargo build --release -p ignis \
    && mkdir -p /out && cp target/release/ignis /out/ignis

FROM ubuntu:24.04 AS runtime
ENV DEBIAN_FRONTEND=noninteractive
# The direct `ldd libphp.so` set, minus what these packages already pull in transitively (libffi
# and libgmp arrive through libcurl -> gnutls, checked rather than assumed). libxml2 is here because
# the toolchain extensions (dom/xml/simplexml/xmlreader/xmlwriter, 2026-09-17) made libphp link it,
# and this list did not move with them -- the image then shipped and died on
# `libxml2.so.2: cannot open shared object file`. Re-check this line with `ldd` whenever
# scripts/build-php.sh gains a --with-* flag.
RUN apt-get update && apt-get install -y --no-install-recommends \
      libssl3t64 libsqlite3-0 libcurl4t64 libonig5 libpq5 libxml2 zlib1g ca-certificates \
    && rm -rf /var/lib/apt/lists/*
# Same path as the rpath compiled into the binary (crates/ignis-sys/build.rs).
COPY --from=build /opt/php85-zts/lib/libphp.so /opt/php85-zts/lib/libphp.so
COPY --from=build /out/ignis /usr/local/bin/ignis
# The userland finds ignis.php through __DIR__, so the tree shape under /opt/ignis is the repo's.
COPY php /opt/ignis/php
COPY examples /opt/ignis/examples
COPY docker/ignis.toml /etc/ignis/ignis.toml
RUN useradd --system --home /opt/ignis --shell /usr/sbin/nologin ignis
USER ignis
WORKDIR /opt/ignis
EXPOSE 8080
ENTRYPOINT ["ignis"]
CMD ["serve", "--config", "/etc/ignis/ignis.toml"]
