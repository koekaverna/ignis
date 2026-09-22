#!/usr/bin/env bash
# E8: symfony/skeleton installed the composer-package way (V-40) — ignis/runtime as a path
# repository, extra.runtime.class, dump-autoload — then served by the local binary through the
# skeleton's own untouched public/index.php. No worker-shim entry script (superseded, M3-2).
# This box has no composer/php-cli (docs/orchestration.md): the skeleton is built once inside the
# ghcr.io/koekaverna/ignis-php:8.5.10-zts builder image with this repo bind-mounted read-only at
# /opt/ignis, exactly as V-40 did; the result lands in a host-side temp dir so the host's
# ./target/release/ignis (built against the real /opt/php85-zts embed) can serve it directly.
set -uo pipefail
cd "$(dirname "$0")/.."
REPO="$(pwd)"
case "${IGNIS_BIN:-}" in ""|./target/release/ignis) export LD_LIBRARY_PATH="${LD_LIBRARY_PATH:-/opt/php85-zts/lib}";; esac
IGNIS_BIN="${IGNIS_BIN:-./target/release/ignis}"
LISTEN="${IGNIS_LISTEN:-127.0.0.1:8120}"; export IGNIS_LISTEN="$LISTEN"

SKEL="$(mktemp -d)"
# composer runs as root in the container, so its writes land root-owned on the host; clean up
# through the same image instead of a plain rm -rf (which a non-root host user cannot do).
cleanup() { docker run --rm -v "$SKEL:/skel" ghcr.io/koekaverna/ignis-php:8.5.10-zts rm -rf /skel/app >/dev/null 2>&1; rmdir "$SKEL" 2>/dev/null || rm -rf "$SKEL" 2>/dev/null; }
trap cleanup EXIT

cat > "$SKEL/install.sh" <<'EOF'
set -euo pipefail
# --no-scripts: the builder's composer/console run under PHP 8.3.6 (php-cli, NTS) but the
# generated vendor/composer/platform_check.php checks the *actual* interpreter against
# ignis/runtime's require (php >=8.4), so Symfony Flex's post-install cache:clear/assets:install
# hooks fatal here even with platform.php pinned. The app's real PHP is /opt/php85-zts (8.5.10,
# embed SAPI) on the host — Symfony warms its own cache lazily on first request, so skipping the
# hooks costs nothing.
composer create-project symfony/skeleton app --no-interaction --no-scripts
cd app
composer require symfony/runtime --no-interaction --no-scripts
composer config platform.php 8.5.10
composer config repositories.ignis '{"type":"path","url":"/opt/ignis/php","options":{"symlink":false}}'
composer require ignis/runtime:@dev --no-interaction --no-scripts
composer config extra.runtime.class 'Ignis\Symfony\IgnisRuntime'
composer dump-autoload
mkdir -p var
chmod -R a+rwX var
EOF

echo "== composer install (ignis/runtime path repo, builder image)"
docker run --rm \
  -v "$REPO:/opt/ignis:ro" \
  -v "$SKEL:/skel" \
  -w /skel \
  ghcr.io/koekaverna/ignis-php:8.5.10-zts \
  bash /skel/install.sh
status=$?
if [ "$status" != 0 ]; then
  echo "composer install FAILED (exit $status)"
  exit 1
fi

APP="$SKEL/app"
if [ ! -f "$APP/public/index.php" ]; then
  echo "no public/index.php after install"
  exit 1
fi
echo "skeleton installed at $APP"

fail=0
for T in 1 4; do
  echo "### threads=$T"
  "$IGNIS_BIN" --threads "$T" "$APP/public/index.php" > /tmp/ignis-sf.log 2>&1 &
  PID=$!
  up=0
  for _ in $(seq 1 60); do
    curl -sf "http://$LISTEN/_ignis/health" 2>/dev/null | grep -q '"status":"ok"' && { up=1; break; }
    sleep 0.1
  done
  if [ "$up" != 1 ] || ! kill -0 "$PID" 2>/dev/null; then
    echo "server never answered /_ignis/health on $LISTEN (threads=$T); log:"
    tail -n 40 /tmp/ignis-sf.log
    kill "$PID" 2>/dev/null
    wait "$PID" 2>/dev/null || true
    fail=1
    continue
  fi
  echo "== /"; curl -s -i "http://$LISTEN/" | sed -n '1p;$p'
  echo "== hello throughput (wrk -t2 -c64 -d10s, $T thread(s))"
  wrk -t2 -c64 -d10s --latency "http://$LISTEN/" | grep -E "Requests/sec|99%|Non-2xx|Socket"
  kill -0 "$PID" 2>/dev/null && echo "server alive" || echo "server DIED"
  kill "$PID" 2>/dev/null
  wait "$PID" 2>/dev/null || true
  grep -ciE "critical|fatal" /tmp/ignis-sf.log | sed 's/^/log critical\/fatal lines: /'
done
exit $fail
