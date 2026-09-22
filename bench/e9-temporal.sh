#!/usr/bin/env bash
# E9 on the official PHP SDK (ADR-0040): the sdk-php demo workflow (one activity + a timer) served by
# Ignis completes on the dev server; its history then replays through the same code (must pass) and
# through the code with the timer removed (must fail with a nondeterminism eviction, V-19's negative
# control). Needs bench/e20-sdkphp.sh's vendor/ and a build with --features temporal.
set -uo pipefail
cd "$(dirname "$0")/.."
BIN="${BIN:-./target/release/ignis}"; TEMPORAL="${TEMPORAL:-/opt/gobin/temporal}"
export SDKPHP_VENDOR="${SDKPHP_VENDOR:-$PWD/php/packages/temporal-core-transport/vendor/autoload.php}"
export LD_LIBRARY_PATH="${LD_LIBRARY_PATH:-/opt/php85-zts/lib}"
[ -f "$SDKPHP_VENDOR" ] || { echo "no sdk-php at $SDKPHP_VENDOR (run bench/e20-sdkphp.sh first)"; exit 2; }
WID="demo-$$"
"$TEMPORAL" server start-dev --headless --ip 127.0.0.1 --port 7233 --log-level error > /tmp/temporal-dev.log 2>&1 & TS=$!
for _ in $(seq 1 100); do "$TEMPORAL" operator namespace describe -n default >/dev/null 2>&1 && break; sleep 0.3; done
"$BIN" php/packages/temporal/bin/worker.php > /tmp/ignis-e9-worker.log 2>&1 & WK=$!; sleep 2
T0=$(date +%s%N)
"$TEMPORAL" workflow start --task-queue ignis --type GreetWorkflow --workflow-id "$WID" --input '"ada"' 2>&1 | tail -1
for _ in $(seq 1 100); do "$TEMPORAL" workflow describe --workflow-id "$WID" 2>/dev/null | grep -q "Status.*COMPLETED" && break; sleep 0.1; done
echo "== live run: $(( ($(date +%s%N) - T0) / 1000000 )) ms from start to COMPLETED (includes a 1 s timer, one activity, CLI polling at 100 ms)"
"$TEMPORAL" workflow describe --workflow-id "$WID" 2>&1 | grep -E "Status|Result" | head -2
"$TEMPORAL" workflow result --workflow-id "$WID" 2>&1 | tail -1
echo "history events: $("$TEMPORAL" workflow show --workflow-id "$WID" --output json 2>/dev/null | grep -o '"eventId"' | wc -l)"
kill $WK 2>/dev/null; wait $WK 2>/dev/null || true
echo "== replay (history fetched by Rust over gRPC) — must pass"
WORKFLOW_ID="$WID" timeout 30 "$BIN" php/packages/temporal/bin/replay.php 2>&1 | tail -3
echo "== replay with the timer removed (DEMO_MUTATE=1) — must fail"
DEMO_MUTATE=1 WORKFLOW_ID="$WID" timeout 30 "$BIN" php/packages/temporal/bin/replay.php 2>&1 | grep -E "REPLAY_" | tail -1
kill $TS 2>/dev/null; wait $TS 2>/dev/null || true
grep -iE "nondeterm|error|fatal" /tmp/ignis-e9-worker.log | head -3 || true
