# ghcr.io/koekaverna/ignis-php:8.5.10-zts — PHP 8.5.10 ZTS + embed built by scripts/build-php.sh,
# plus the system tools the CI jobs need (bindgen's libclang, protoc, PostgreSQL client, wrk, composer).
# The php-src tree stays in the image (tests for the E15 phpt suites; objects removed).
FROM ubuntu:24.04
ARG PHP_TAG=php-8.5.10
ENV DEBIAN_FRONTEND=noninteractive
RUN apt-get update && apt-get install -y --no-install-recommends \
      build-essential autoconf bison re2c pkg-config git curl ca-certificates \
      libxml2-dev libsqlite3-dev libonig-dev zlib1g-dev libssl-dev libpq-dev libcurl4-openssl-dev \
      clang libclang-dev protobuf-compiler postgresql-client wrk jq python3 \
      php-cli php-xml php-mbstring php-curl composer \
    && rm -rf /var/lib/apt/lists/*
COPY scripts/build-php.sh /tmp/build-php.sh
RUN mkdir -p /home/user && ln -s /opt/php-src /home/user/php-src \
    && PHP_TAG="$PHP_TAG" PHP_SRC=/opt/php-src PREFIX=/opt/php85-zts JOBS="$(nproc)" bash /tmp/build-php.sh \
    && (cd /opt/php-src && make clean >/dev/null) \
    && /opt/php85-zts/bin/php -v
ENV PHP_CONFIG=/opt/php85-zts/bin/php-config
ENV LD_LIBRARY_PATH=/opt/php85-zts/lib
