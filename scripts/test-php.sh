#!/usr/bin/env bash
# PHPUnit for the userland packages — the parts that need no ignis binary and no server.
#
# These cover the pure logic: fiber-scoped storage (`Ignis\Scope`), request parsing (including the
# multipart body a browser sends for any FormData), response construction, and future settling.
# Everything that needs the reactor is measured by the E-suites against the real binary instead.
#
# The suite, the bootstrap and the coverage source list all live in php/phpunit.xml; this script
# only picks the interpreter. The dev dependencies are installed with composer in docker, because
# that image carries a composer and the unzip binary; PHPUnit itself then runs under the
# production engine at /opt/php85-zts.
#
#   scripts/test-php.sh              # the suite under PHP 8.5.10 ZTS
#   scripts/test-php.sh --coverage   # the same, with pcov line coverage (text only)
#   scripts/test-php.sh --filter Scope
set -uo pipefail
cd "$(dirname "$0")/.."
PHP=${IGNIS_PHP:-/opt/php85-zts/bin/php}
IMAGE=${IGNIS_PHP_IMAGE:-ghcr.io/koekaverna/ignis-php:8.5.10-zts}
PCOV=${IGNIS_PCOV:-/opt/pcov/pcov.so}
export LD_LIBRARY_PATH=${LD_LIBRARY_PATH:-/opt/php85-zts/lib}

if [ ! -x php/vendor/bin/phpunit ]; then
  echo "== installing the php dev tooling (composer in docker)"
  command -v docker >/dev/null || { echo "docker needed to install the php dev tooling"; exit 2; }
  timeout 900 docker run --rm -u "$(id -u):$(id -g)" -e COMPOSER_HOME=/tmp/composer \
    -v "$PWD":/w -w /w/php "$IMAGE" composer install --no-interaction 2>&1 | tail -3
fi
[ -x php/vendor/bin/phpunit ] || { echo "phpunit not installed"; exit 1; }

if [ "${1:-}" = "--coverage" ]; then
  shift
  if [ ! -f "$PCOV" ]; then
    echo "== extracting pcov from $IMAGE to $PCOV"
    command -v docker >/dev/null || { echo "docker needed to extract pcov"; exit 2; }
    mkdir -p "$(dirname "$PCOV")" && docker run --rm "$IMAGE" cat /opt/pcov/pcov.so > "$PCOV"
  fi
  exec "$PHP" -d extension="$PCOV" -d pcov.enabled=1 -d pcov.directory=php/packages \
    php/vendor/bin/phpunit -c php/phpunit.xml --colors=never --coverage-text "$@"
fi

exec "$PHP" php/vendor/bin/phpunit -c php/phpunit.xml --colors=never "$@"
