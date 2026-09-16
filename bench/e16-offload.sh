#!/usr/bin/env bash
# E16 / H24: offload pool of synchronous PHP threads. Usage: bench/e16-offload.sh [workers]
set -uo pipefail
cd "$(dirname "$0")/.."
W="${1:-8}"; export IGNIS_OFFLOAD_PRELUDE="$PWD/bench/php/e16_prelude.php"
echo "load: $(uptime | sed 's/.*load average/load average/')"
echo "== --offload $W"; timeout 120 ./target/release/ignis --offload "$W" bench/php/e16_offload.php
echo "== auto-routing (SQLite3 / PDO pgsql / curl with WRITEFUNCTION), --offload $W, needs a local HTTP target on :8080"
./target/release/ignis examples/hello_server.php > /tmp/e16-hello.log 2>&1 & HS=$!; sleep 0.7
FIBERS="${FIBERS:-100}" timeout 180 ./target/release/ignis --offload "$W" bench/php/e16_route.php; kill $HS 2>/dev/null; wait $HS 2>/dev/null || true
echo "== --offload 100 (blocking-calls line only)"; N=100 timeout 120 ./target/release/ignis --offload 100 bench/php/e16_offload.php 2>&1 | grep -E "^blocking|^pdo_pgsql"
