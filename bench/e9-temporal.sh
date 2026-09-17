#!/usr/bin/env bash
# E9 step 2 (H20b): PHP workflow (2 activities + timer) on fibers completes on the dev server; then replays from its history.
set -uo pipefail
cd "$(dirname "$0")/.."
BIN="${BIN:-./target/release/ignis}"; TEMPORAL="${TEMPORAL:-/opt/gobin/temporal}"
"$TEMPORAL" server start-dev --headless --ip 127.0.0.1 --port 7233 --log-level error > /tmp/temporal-dev.log 2>&1 & TS=$!
for _ in $(seq 1 100); do "$TEMPORAL" operator namespace describe -n default >/dev/null 2>&1 && break; sleep 0.3; done
"$BIN" php/packages/temporal-prototype/bin/worker.php > /tmp/ignis-e9-worker.log 2>&1 & WK=$!; sleep 2
T0=$(date +%s%N)
"$TEMPORAL" workflow start --task-queue ignis --type Demo --workflow-id demo-1 --input '"ada"' 2>&1 | tail -1
for _ in $(seq 1 80); do "$TEMPORAL" workflow describe --workflow-id demo-1 2>/dev/null | grep -q "Status.*COMPLETED" && break; sleep 0.1; done
echo "== live run: $(( ($(date +%s%N) - T0) / 1000000 )) ms from start to COMPLETED (includes 500 ms timer, two activities, CLI polling at 100 ms)"
"$TEMPORAL" workflow describe --workflow-id demo-1 2>&1 | grep -E "Status|Result" | head -2
"$TEMPORAL" workflow result --workflow-id demo-1 2>&1 | tail -1
echo "history events: $("$TEMPORAL" workflow show --workflow-id demo-1 --output json 2>/dev/null | grep -o '"eventId"' | wc -l)"
kill $WK 2>/dev/null; wait $WK 2>/dev/null || true
echo "== replay (history fetched by Rust over gRPC) — must pass"; WORKFLOW_ID=demo-1 timeout 30 "$BIN" php/packages/temporal-prototype/bin/replay.php 2>&1 | tail -3
echo "== replay with the timer removed (DEMO_MUTATE=1) — must fail"; DEMO_MUTATE=1 WORKFLOW_ID=demo-1 timeout 30 "$BIN" php/packages/temporal-prototype/bin/replay.php 2>&1 | grep -E "evicted|REPLAY_" | tail -3
kill $TS 2>/dev/null; wait $TS 2>/dev/null || true
echo "== live worker: $(grep -c 'activation #' /tmp/ignis-e9-worker.log) activations, $(grep -c 'evicted' /tmp/ignis-e9-worker.log) evictions"
grep -iE "nondeterm|error|fatal" /tmp/ignis-e9-worker.log | head -3 || true
