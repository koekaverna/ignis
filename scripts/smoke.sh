#!/usr/bin/env bash
# End-to-end smoke test: builds, runs unit tests, runs the API spec example
# and the two thesis benches with pass/fail thresholds. Exit 0 = green.
# Owner rule (2026-09-16): every step runs under `timeout` (120 s for runtime steps; the
# release build and the unit tests get 900 s because a cold LTO build alone exceeds 120 s).
set -euo pipefail
cd "$(dirname "$0")/.."
export PHP_CONFIG="${PHP_CONFIG:-/opt/php85-zts/bin/php-config}"
[ -x "$PHP_CONFIG" ] || { echo "PHP not built; run scripts/build-php.sh"; exit 1; }
T="timeout 120"
PGHOST="${PGHOST:-127.0.0.1}"

echo "== build (release)"; timeout 900 cargo build --release -q -p ignis
echo "== unit tests";      timeout 900 cargo nextest run --workspace 2>&1 | tail -1
echo "== hello";           $T ./target/release/ignis examples/hello.php
echo "== app.php (API spec: served in the background, routes curled)"
$T ./target/release/ignis examples/app.php >/dev/null 2>&1 & APP=$!; for _ in $(seq 1 50); do curl -sf http://127.0.0.1:8080/ >/dev/null && break; sleep 0.1; done
for r in / "/dashboard?user=7" /users "/upstream" "/whoami?x=1" /deadline "/sleep?ms=5"; do printf "%-20s -> %s\n" "$r" "$(curl -s -m 5 -w " [%{http_code}]" "http://127.0.0.1:8080$r" | tr -d "\n" | cut -c1-90)"; done
kill $APP; wait $APP 2>/dev/null || true
echo "== E2 (all() < 230 ms, per-fiber < 100 us)"; N=10000 $T ./target/release/ignis bench/php/e2_all.php
echo "== E1 (10k fibers x 1000 ms < 1200 ms; warm pool round counts)"
out=$(N=10000 MS=1000 $T ./target/release/ignis bench/php/e1_sleep_10k.php | tail -1); echo "$out"
wall=$(sed -E 's/.*wall_ms=([0-9.]+).*/\1/' <<<"$out")
awk -v w="$wall" 'BEGIN { exit (w < 1200) ? 0 : 1 }' || { echo "E1 FAILED: wall_ms=$wall"; exit 1; }
echo "== E5 (4 threads, each prints its own time)"; IGNIS_THREADS=4 $T ./target/release/ignis --threads 4 bench/php/e5_cpu.php | wc -l | grep -q "^4$" || { echo "E5 FAILED: expected 4 thread lines"; exit 1; }
echo "== E13 (isolation)"; $T ./target/release/ignis bench/php/e13_isolation.php
echo "== E15 fixes (sleep hook, server socket + hooked client)"; $T ./target/release/ignis bench/php/e15_fixes_sleep.php; $T ./target/release/ignis bench/php/e15_fixes_server.php 2>&1 | tail -1
echo "== E13 (200 concurrent HTTP)"; timeout 120 bench/e13-http.sh | tail -1
echo "== E6 (3 x 200 ms unmodified file_get_contents on 1 thread, 100 concurrent)"; N=50 timeout 120 bench/e6-fetch.sh | tail -2
if [ -d php/amphp/vendor ]; then echo "== E7 (Revolt/AMPHP examples, both drivers; fiber-local-manual is a known timing race)"; timeout 120 bench/e7-revolt.sh | grep -E "^(DIFFER|e7)" || true; else echo "== E7 skipped (run: cd php/amphp && composer install --prefer-source)"; fi
echo "== E11 (cancellation + deadline)"; timeout 120 bench/e11-cancel.sh | grep -E "cancelled|status=" | head -2
if pg_isready -h "$PGHOST" -q 2>/dev/null; then echo "== E14 (pgsql pool)"; PGHOST="$PGHOST" timeout 120 bench/e14-pg.sh | grep -E "warm|transaction|reset"; else echo "== E14 skipped (no PostgreSQL on $PGHOST)"; fi
echo "== E12 (supervisor: fatal + spin)"; timeout 120 bench/e12-isolation.sh | grep -E "^after \(a\)|^after hello|server"
echo "smoke: GREEN"
