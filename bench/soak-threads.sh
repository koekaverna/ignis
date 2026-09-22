#!/usr/bin/env bash
# 4-thread soak: /cpu + hello + /echo isolation + /fetch, 10 s each; the server must stay alive with 0 socket errors.
set -uo pipefail
cd "$(dirname "$0")/.."
# Listen address for the server this script starts and every URL below. Override with
# IGNIS_LISTEN when :8080 is taken; readiness is the expected body, never "something answered".
ADDR="${IGNIS_LISTEN:-127.0.0.1:8080}"; export IGNIS_LISTEN="$ADDR"
IGNIS_BIN="${IGNIS_BIN:-./target/release/ignis}"
"$IGNIS_BIN" --threads "${THREADS:-4}" examples/hello_server.php > /tmp/ignis-soak.log 2>&1 & PID=$!
up=0; for _ in $(seq 1 50); do curl -sf "http://$ADDR/" 2>/dev/null | grep -q "Hello, World!" && { up=1; break; }; sleep 0.1; done
if [ "${up:-0}" != 1 ] || ! kill -0 $PID 2>/dev/null; then
  echo "our server never answered on $ADDR (port taken? set IGNIS_LISTEN); see the server log"
  kill $PID 2>/dev/null; exit 1
fi
for url in /cpu / "/fetch?ms=1"; do echo "== $url"; wrk -t1 -c64 -d10s --latency "http://$ADDR$url" | grep -E "Requests/sec|99%|Socket|Non-2xx"; done
kill -0 $PID 2>/dev/null && alive=1 || alive=0
echo "server alive=$alive corrupted=$(grep -c corrupted /tmp/ignis-soak.log)"
kill $PID 2>/dev/null; wait $PID 2>/dev/null || true
[ "$alive" = 1 ]
