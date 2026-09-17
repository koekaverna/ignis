#!/usr/bin/env bash
# PHPUnit for the userland packages — the parts that need no ignis binary and no server.
#
# These cover the pure logic: fiber-scoped storage (`Ignis\Scope`), request parsing (including the
# multipart body a browser sends for any FormData), response construction, and future settling.
# Everything that needs the reactor is measured by the E-suites against the real binary instead.
#
# Composer cannot run under our PHP build (no ext-phar), so the dev dependencies are installed in
# docker; phpunit itself is a plain PHP script and runs under /opt/php85-zts/bin/php. There is no
# phpunit.xml: reading one needs ext-dom, which this build does not have.
set -uo pipefail
cd "$(dirname "$0")/.."
PKG=php/packages/runtime
PHP=${IGNIS_STOCK_PHP:-/opt/php85-zts/bin/php}
export LD_LIBRARY_PATH=${LD_LIBRARY_PATH:-/opt/php85-zts/lib}

if [ ! -x "$PKG/vendor/bin/phpunit" ]; then
  echo "== installing phpunit into $PKG (composer in docker)"
  command -v docker >/dev/null || { echo "docker needed to install phpunit"; exit 2; }
  timeout 600 docker run --rm -u "$(id -u):$(id -g)" -v "$PWD/$PKG":/app -w /app composer:latest \
    update --no-scripts --ignore-platform-reqs --no-interaction 2>&1 | tail -3
fi
[ -x "$PKG/vendor/bin/phpunit" ] || { echo "phpunit not installed"; exit 1; }

BOOT="$PWD/$PKG/tests/bootstrap.php"
cat > "$BOOT" <<'BOOT'
<?php
declare(strict_types=1);
require dirname(__DIR__) . '/vendor/autoload.php';   // psr-4 Ignis\ -> src/, plus autoload.files
BOOT

# `vendor/bin/phpunit` refuses to start without ext-dom/libxml/xmlwriter, which this build does not
# have; the library itself does not need them for a plain text run. Entering through the Application
# class skips that gate — the same workaround bench/e15-revolt.sh uses, and its own header explains
# why there is no phpunit.xml either (reading one WOULD need ext-dom).
RUN=/tmp/ignis-phpunit-run.php
cat > "$RUN" <<'RUNNER'
<?php declare(strict_types=1);
$_SERVER['PHP_SELF'] ??= $_SERVER['argv'][0] ?? __FILE__;
$_SERVER['SCRIPT_NAME'] ??= $_SERVER['PHP_SELF'];
$_SERVER['SCRIPT_FILENAME'] ??= $_SERVER['PHP_SELF'];
require getenv('PHPUNIT_AUTOLOAD');
exit((new PHPUnit\TextUI\Application())->run($_SERVER['argv']));
RUNNER

PHPUNIT_AUTOLOAD="$PWD/$PKG/vendor/autoload.php" "$PHP" "$RUN" \
  --no-configuration --bootstrap "$BOOT" --colors=never "$PKG/tests"
