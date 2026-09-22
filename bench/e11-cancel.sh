#!/usr/bin/env bash
# E11: client disconnect cancels the request fiber + child within 10 ms; deadline returns 504 on time; E6 still green.
set -uo pipefail
cd "$(dirname "$0")/.."
# Listen address for the server this script starts and every URL below. Override with
# IGNIS_LISTEN when :8080 is taken; the examples read the same variable, so the server and
# the client can never disagree and curl a stranger that happens to hold the port.
ADDR="${IGNIS_LISTEN:-127.0.0.1:8080}"; export IGNIS_LISTEN="$ADDR"
IGNIS_BIN="${IGNIS_BIN:-./target/release/ignis}"
"$IGNIS_BIN" --threads 1 examples/hello_server.php > /tmp/ignis-e11.log 2>&1 & PID=$!
up=0; for _ in $(seq 1 50); do curl -sf "http://$ADDR/" 2>/dev/null | grep -q "Hello, World!" && { up=1; break; }; sleep 0.1; done
if [ "${up:-0}" != 1 ] || ! kill -0 $PID 2>/dev/null; then
  echo "our server never answered on $ADDR (port taken? set IGNIS_LISTEN); see the server log"
  kill $PID 2>/dev/null; exit 1
fi
echo "== (a) 20 clients hit /slow and disconnect after 200 ms"
pids=(); for i in $(seq 1 20); do curl -s -m 0.2 http://$ADDR/slow >/dev/null 2>&1 & pids+=($!); done; wait "${pids[@]}"
sleep 0.3
curl -s http://$ADDR/stats; echo
echo "== (b) /deadline?ms=100 around a 1000 ms sleep"
for i in 1 2 3; do curl -s -o /dev/null -w "status=%{http_code} time_s=%{time_total}\n" "http://$ADDR/deadline?ms=100"; done
echo "== (c) E6 still works after cancellations"
curl -s "http://$ADDR/fetch?ms=100"; echo
echo "== (d) no phantom work: /slow fibers are gone (idle == fibers), stats 5.5 s after the disconnects"
sleep 5.3; curl -s http://$ADDR/stats; echo
kill $PID; wait $PID 2>/dev/null || true
