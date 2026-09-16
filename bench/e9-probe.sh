#!/usr/bin/env bash
# E9 step 1 (H20): temporal-sdk-core linked into a Rust binary polls one activation from a local dev server and completes it.
# Prereqs: /opt/gobin/temporal (built from temporalio/cli), the probe binary (scratchpad/tprobe or crates/ignis --features temporal).
set -uo pipefail
TEMPORAL="${TEMPORAL:-/opt/gobin/temporal}"; PROBE="${PROBE:-$(dirname "$0")/../examples/rust/temporal-probe/target/debug/temporal-probe}"; [ -x "$PROBE" ] || PROBE=/tmp/claude-0/-home-user-ignis/1a324a70-3e96-5249-98a3-65e34469a7f3/scratchpad/tprobe/target/debug/tprobe
"$TEMPORAL" server start-dev --headless --ip 127.0.0.1 --port 7233 --log-level error > /tmp/temporal-dev.log 2>&1 & TS=$!
for _ in $(seq 1 100); do "$TEMPORAL" operator namespace describe -n default >/dev/null 2>&1 && break; sleep 0.3; done
echo "dev server up (pid $TS)"
"$PROBE" > /tmp/ignis-e9-probe.log 2>&1 & PB=$!; sleep 2
"$TEMPORAL" workflow start --task-queue ignis --type IgnisProbeWorkflow --workflow-id ignis-probe-1 --input '"hello"' 2>&1 | tail -2
for _ in $(seq 1 40); do grep -q "completed run_id" /tmp/ignis-e9-probe.log && break; sleep 0.25; done
cat /tmp/ignis-e9-probe.log
"$TEMPORAL" workflow describe --workflow-id ignis-probe-1 2>&1 | grep -E "Status|Result" | head -3
kill $PB 2>/dev/null; kill $TS 2>/dev/null; wait $PB $TS 2>/dev/null || true
