#!/usr/bin/env bash
# E3: RSS flatness over >= 1M requests. Starts the server, warms up, samples /stats after each burst.
set -uo pipefail
cd "$(dirname "$0")/.."
# Listen address for the server this script starts and every URL below. Override with
# IGNIS_LISTEN when :8080 is taken; readiness is the expected body, never "something answered".
ADDR="${IGNIS_LISTEN:-127.0.0.1:8080}"; export IGNIS_LISTEN="$ADDR"
T="${THREADS:-1}"; BURST="${BURST:-3s}"; TARGET="${TARGET:-1000000}"
./target/release/ignis --threads "$T" examples/hello_server.php >/dev/null 2>&1 & PID=$!
up=0; for _ in $(seq 1 50); do curl -sf "http://$ADDR/" 2>/dev/null | grep -q "Hello, World!" && { up=1; break; }; sleep 0.1; done
if [ "${up:-0}" != 1 ] || ! kill -0 $PID 2>/dev/null; then
  echo "our server never answered on $ADDR (port taken? set IGNIS_LISTEN); see the server log"
  kill $PID 2>/dev/null; exit 1
fi
stats() { curl -s "http://$ADDR/stats"; }
total=0
echo "warm-up 5s"; wrk -t2 -c64 -d5s "http://$ADDR/" | grep -E "requests in" ; echo "sample 0 (post warm-up): $(stats)"
i=0
while [ "$total" -lt "$TARGET" ]; do
  i=$((i+1)); n=$(wrk -t2 -c64 -d"$BURST" "http://$ADDR/" | awk '/requests in/{print $1}'); total=$((total + n))
  echo "sample $i: +$n requests (total $total): $(stats)"
done
echo "sleep workload: /sleep?ms=1 at 500 connections, 20s"; n=$(wrk -t2 -c500 -d20s "http://$ADDR/sleep?ms=1" | awk '/requests in/{print $1}'); echo "sleep: $n requests: $(stats)"
echo "cpu workload: /cpu 5s"; n=$(wrk -t1 -c64 -d5s "http://$ADDR/cpu" | awk '/requests in/{print $1}'); echo "cpu: $n requests: $(stats)"
echo "hello again 3s"; n=$(wrk -t2 -c64 -d3s "http://$ADDR/" | awk '/requests in/{print $1}'); echo "hello: $n requests: $(stats)"
kill $PID; wait $PID 2>/dev/null || true
