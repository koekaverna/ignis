#!/usr/bin/env bash
# E12': a request in flight on a thread that dies (fatal) gets a 500 at once, not a hang; the supervisor respawns the thread.
set -uo pipefail
cd "$(dirname "$0")/.."
# Listen address for the server this script starts and every URL below. Override with
# IGNIS_LISTEN when :8080 is taken; readiness is the expected body, never "something answered".
ADDR="${IGNIS_LISTEN:-127.0.0.1:8080}"; export IGNIS_LISTEN="$ADDR"
./target/release/ignis --supervise --threads 1 examples/hello_server.php > /tmp/e12-inflight.log 2>&1 & S=$!
up=0; for _ in $(seq 1 50); do curl -sf "http://$ADDR/" 2>/dev/null | grep -q "Hello, World!" && { up=1; break; }; sleep 0.1; done
if [ "${up:-0}" != 1 ] || ! kill -0 $S 2>/dev/null; then
  echo "our server never answered on $ADDR (port taken? set IGNIS_LISTEN); see the server log"
  kill $S 2>/dev/null; exit 1
fi
T0=$(date +%s%N)
curl -s -m 10 -o /dev/null -w "in-flight /sleep?ms=3000 -> %{http_code} after %{time_total}s\n" "http://$ADDR/sleep?ms=3000" & C=$!
sleep 0.3
curl -s -m 5 -o /dev/null -w "/fatal -> %{http_code} after %{time_total}s\n" "http://$ADDR/fatal"
wait $C
sleep 0.5
curl -s -m 5 -o /dev/null -w "after respawn: / -> %{http_code} after %{time_total}s\n" "http://$ADDR/"
echo "wall: $(( ($(date +%s%N) - T0) / 1000000 )) ms (a hang would be >= 3000 ms for the sleeping request)"
kill $S; wait $S 2>/dev/null || true
grep -c "answered 500" /tmp/e12-inflight.log
