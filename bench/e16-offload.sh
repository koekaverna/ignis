#!/usr/bin/env bash
# E16 / H24: offload pool of synchronous PHP threads. Usage: bench/e16-offload.sh [workers]
set -uo pipefail
cd "$(dirname "$0")/.."
# Listen address for the local HTTP target the auto-routing curl check hits below. Override with
# IGNIS_LISTEN when :8080 is taken; readiness is the expected body, never "something answered".
ADDR="${IGNIS_LISTEN:-127.0.0.1:8080}"; export IGNIS_LISTEN="$ADDR"
W="${1:-8}"; export IGNIS_OFFLOAD_PRELUDE="$PWD/bench/php/e16_prelude.php"
echo "load: $(uptime | sed 's/.*load average/load average/')"
echo "== --offload $W"; timeout 120 ./target/release/ignis --offload "$W" bench/php/e16_offload.php
echo "== auto-routing (SQLite3 / PDO pgsql / curl with WRITEFUNCTION), --offload $W, needs a local HTTP target on $ADDR"
./target/release/ignis examples/hello_server.php > /tmp/e16-hello.log 2>&1 & HS=$!
up=0; for _ in $(seq 1 50); do curl -sf "http://$ADDR/" 2>/dev/null | grep -q "Hello, World!" && { up=1; break; }; sleep 0.1; done
if [ "${up:-0}" != 1 ] || ! kill -0 $HS 2>/dev/null; then
  echo "our server never answered on $ADDR (port taken? set IGNIS_LISTEN); see the server log"
  kill $HS 2>/dev/null; exit 1
fi
CURL_URL="http://$ADDR/" FIBERS="${FIBERS:-100}" timeout 180 ./target/release/ignis --offload "$W" bench/php/e16_route.php; kill $HS 2>/dev/null; wait $HS 2>/dev/null || true
echo "== --offload 100 (blocking-calls line only)"; N=100 timeout 120 ./target/release/ignis --offload 100 bench/php/e16_offload.php 2>&1 | grep -E "^blocking|^pdo_pgsql"
