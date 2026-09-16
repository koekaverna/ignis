#!/usr/bin/env bash
# 4-thread soak: /cpu + hello + /echo isolation + /fetch, 10 s each; the server must stay alive with 0 socket errors.
set -uo pipefail
cd "$(dirname "$0")/.."
./target/release/ignis --threads "${THREADS:-4}" examples/hello_server.php > /tmp/ignis-soak.log 2>&1 & PID=$!
for _ in $(seq 1 50); do curl -sf http://127.0.0.1:8080/ >/dev/null && break; sleep 0.1; done
for url in /cpu / "/fetch?ms=1"; do echo "== $url"; wrk -t1 -c64 -d10s --latency "http://127.0.0.1:8080$url" | grep -E "Requests/sec|99%|Socket|Non-2xx"; done
kill -0 $PID 2>/dev/null && alive=1 || alive=0
echo "server alive=$alive corrupted=$(grep -c corrupted /tmp/ignis-soak.log)"
kill $PID 2>/dev/null; wait $PID 2>/dev/null || true
[ "$alive" = 1 ]
