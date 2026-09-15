#!/usr/bin/env bash
# End-to-end smoke test: builds, runs unit tests, runs the API spec example
# and the two thesis benches with pass/fail thresholds. Exit 0 = green.
set -euo pipefail
cd "$(dirname "$0")/.."
export PHP_CONFIG="${PHP_CONFIG:-/opt/php85-zts/bin/php-config}"
[ -x "$PHP_CONFIG" ] || { echo "PHP not built; run scripts/build-php.sh"; exit 1; }

echo "== build (release)"; cargo build --release -q -p ignis
echo "== unit tests";      cargo nextest run --workspace 2>&1 | tail -1
echo "== hello";           ./target/release/ignis examples/hello.php
echo "== app.php";         ./target/release/ignis examples/app.php
echo "== E2 (all() < 230 ms, per-fiber < 100 us)"; N=2000 ./target/release/ignis bench/php/e2_all.php
echo "== E1 (10k fibers x 1000 ms < 1200 ms)"
out=$(N=10000 MS=1000 ./target/release/ignis bench/php/e1_sleep_10k.php | tail -1); echo "$out"
wall=$(sed -E 's/.*wall_ms=([0-9.]+).*/\1/' <<<"$out")
awk -v w="$wall" 'BEGIN { exit (w < 1200) ? 0 : 1 }' || { echo "E1 FAILED: wall_ms=$wall"; exit 1; }
echo "== E5 (4 threads, each prints its own time)"; IGNIS_THREADS=4 ./target/release/ignis --threads 4 bench/php/e5_cpu.php | wc -l | grep -q "^4$" || { echo "E5 FAILED: expected 4 thread lines"; exit 1; }
echo "smoke: GREEN"
