#!/usr/bin/env bash
# E16 / H24: offload pool of synchronous PHP threads. Usage: bench/e16-offload.sh [workers]
set -uo pipefail
cd "$(dirname "$0")/.."
W="${1:-8}"; export IGNIS_OFFLOAD_PRELUDE="$PWD/bench/php/e16_prelude.php"
echo "load: $(uptime | sed 's/.*load average/load average/')"
echo "== --offload $W"; timeout 120 ./target/release/ignis --offload "$W" bench/php/e16_offload.php
echo "== --offload 100 (blocking-calls line only)"; N=100 timeout 120 ./target/release/ignis --offload 100 bench/php/e16_offload.php 2>&1 | grep -E "^blocking|^pdo_pgsql"
