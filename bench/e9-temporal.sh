#!/usr/bin/env bash
# E9 step 2 (H20b): PHP workflow (2 activities + timer) on fibers completes on the dev server; then replays from its history.
set -uo pipefail
cd "$(dirname "$0")/.."
BIN="${BIN:-./target/release/ignis}"; TEMPORAL="${TEMPORAL:-/opt/gobin/temporal}"
"$TEMPORAL" server start-dev --headless --ip 127.0.0.1 --port 7233 --log-level error > /tmp/temporal-dev.log 2>&1 & TS=$!
for _ in $(seq 1 100); do "$TEMPORAL" operator namespace describe -n default >/dev/null 2>&1 && break; sleep 0.3; done
"$BIN" php/temporal/worker.php > /tmp/ignis-e9-worker.log 2>&1 & WK=$!; sleep 2
"$TEMPORAL" workflow start --task-queue ignis --type Demo --workflow-id demo-1 --input '"ada"' 2>&1 | tail -1
for _ in $(seq 1 80); do "$TEMPORAL" workflow describe --workflow-id demo-1 2>/dev/null | grep -q "Status.*COMPLETED" && break; sleep 0.25; done
echo "== live run"; "$TEMPORAL" workflow describe --workflow-id demo-1 2>&1 | grep -E "Status|Result" | head -2
"$TEMPORAL" workflow show --workflow-id demo-1 --output json > /tmp/ignis-e9-history.json 2>/dev/null; echo "history events: $(grep -o '"eventId"' /tmp/ignis-e9-history.json | wc -l)"
kill $WK 2>/dev/null; wait $WK 2>/dev/null || true
echo "== replay"; HISTORY=/tmp/ignis-e9-history.json WORKFLOW_ID=demo-1 timeout 30 "$BIN" php/temporal/replay.php 2>&1 | tail -3
kill $TS 2>/dev/null; wait $TS 2>/dev/null || true
grep -iE "nondeterm|error|fatal" /tmp/ignis-e9-worker.log | head -3
