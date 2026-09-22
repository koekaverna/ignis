#!/usr/bin/env bash
# E12: --threads 4 --supervise; /fatal kills one worker (respawned); /spin stalls one worker; hello keeps flowing.
set -uo pipefail
cd "$(dirname "$0")/.."
# Listen address for the server this script starts and every URL below. Override with
# IGNIS_LISTEN when :8080 is taken; the examples read the same variable, so the server and
# the client can never disagree and curl a stranger that happens to hold the port.
ADDR="${IGNIS_LISTEN:-127.0.0.1:8080}"; export IGNIS_LISTEN="$ADDR"
IGNIS_BIN="${IGNIS_BIN:-./target/release/ignis}"
RUST_LOG=warn "$IGNIS_BIN" --threads 4 --supervise examples/hello_server.php > /tmp/ignis-e12.log 2>&1 & PID=$!
up=0; for _ in $(seq 1 60); do curl -sf "http://$ADDR/" 2>/dev/null | grep -q "Hello, World!" && { up=1; break; }; sleep 0.1; done
if [ "${up:-0}" != 1 ] || ! kill -0 $PID 2>/dev/null; then
  echo "our server never answered on $ADDR (port taken? set IGNIS_LISTEN); see the server log"
  kill $PID 2>/dev/null; exit 1
fi
stats() { curl -s http://$ADDR/stats | grep -o '"runtime":{[^}]*}'; }
echo "== baseline: $(stats)"; base=$(wrk -t2 -c64 -d5s http://$ADDR/ | awk '/Requests\/sec/{print $2}'); echo "baseline hello rps=$base"
echo "== (a) /fatal on one worker (during a 5 s hello load)"
wrk -t2 -c64 -d5s --latency http://$ADDR/ > /tmp/ignis-e12-wrk.txt & W=$!
sleep 1; curl -s -m 3 -o /dev/null -w "fatal request http=%{http_code}\n" http://$ADDR/fatal; sleep 1.5; echo "during: $(stats)"
wait $W; grep -E "Requests/sec|99%|Non-2xx|Socket" /tmp/ignis-e12-wrk.txt
echo "after (a): $(stats)"
echo "== (b) /spin?s=5 on one worker while hello runs on the rest"
curl -s -m 10 "http://$ADDR/spin?s=5" > /dev/null & S=$!
sleep 0.5; wrk -t2 -c64 -d3s --latency http://$ADDR/ | grep -E "Requests/sec|99%|Non-2xx|Socket"; echo "during spin: $(stats)"; wait $S
echo "== (c) recovery"; after=$(wrk -t2 -c64 -d5s http://$ADDR/ | awk '/Requests\/sec/{print $2}'); echo "after hello rps=$after (baseline $base)"; echo "final: $(stats)"
grep -E "respawning|budget|busy" /tmp/ignis-e12.log | sed 's/\x1b\[[0-9;]*m//g' | cut -c1-160 | head -6
kill -0 $PID 2>/dev/null && echo "server alive" || echo "server DIED"; kill $PID 2>/dev/null; wait $PID 2>/dev/null || true
