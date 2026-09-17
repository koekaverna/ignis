#!/usr/bin/env bash
# E20 (ADR-0040): the official temporalio/sdk-php runs on Ignis through the core transport.
#
# Installs the package's dependencies (composer in docker — our PHP build has no ext-phar, so composer cannot run
# under it; only vendor/ is needed and its autoloader is plain PHP), then runs the conformance test
# twice: under the ignis binary, and under the stock PHP CLI. The second run is the point of the
# package — the translation is host-agnostic, so it must pass with no Ignis at all.
set -uo pipefail
cd "$(dirname "$0")/.."

VENDOR=${SDKPHP_VENDOR:-php/packages/temporal-core-transport/vendor/autoload.php}
PHP=${IGNIS_STOCK_PHP:-/opt/php85-zts/bin/php}
BIN=${IGNIS_BIN:-./target/release/ignis}
export LD_LIBRARY_PATH=${LD_LIBRARY_PATH:-/opt/php85-zts/lib}

if [ ! -f "$VENDOR" ]; then
  echo "== installing temporal/sdk into php/packages/temporal-core-transport/vendor (composer in docker)"
  command -v docker >/dev/null || { echo "docker needed to install sdk-php, or set SDKPHP_VENDOR"; exit 2; }
  timeout 600 docker run --rm -v "$PWD/php/packages/temporal-core-transport":/app -w /app composer:latest \
    install --ignore-platform-reqs --no-interaction 2>&1 | tail -3
  VENDOR=php/packages/temporal-core-transport/vendor/autoload.php
fi
[ -f "$VENDOR" ] || { echo "no sdk-php at $VENDOR"; exit 1; }
printf '== temporal/sdk %s\n' "$(python3 -c "import json,sys;d=json.load(open(sys.argv[1]));print(next((p[\"version\"] for p in d[\"packages\"] if p[\"name\"]==\"temporal/sdk\"), \"?\"))" "$(dirname "$VENDOR")/composer/installed.json" 2>/dev/null)"

fail=0
echo "== conformance under the ignis binary"
SDKPHP_VENDOR="$VENDOR" timeout 120 "$BIN" php/packages/temporal-core-transport/tests/conformance.php || fail=1

echo "== conformance under stock PHP (the transport must not need Ignis)"
SDKPHP_VENDOR="$VENDOR" timeout 120 "$PHP" php/packages/temporal-core-transport/tests/conformance.php || fail=1

[ "$fail" = 0 ] && echo "E20: GREEN" || echo "E20: FAILED"
exit $fail
