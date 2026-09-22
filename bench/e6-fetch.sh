#!/usr/bin/env bash
# E6: /fetch does 3 concurrent unmodified file_get_contents('http://$ADDR/sleep?ms=200') on ONE PHP thread.
set -uo pipefail
cd "$(dirname "$0")/.."
# Listen address for the server this script starts and every URL below. Override with
# IGNIS_LISTEN when :8080 is taken; the examples read the same variable, so the server and
# the client can never disagree and curl a stranger that happens to hold the port.
ADDR="${IGNIS_LISTEN:-127.0.0.1:8080}"; export IGNIS_LISTEN="$ADDR"
IGNIS_BIN="${IGNIS_BIN:-./target/release/ignis}"
"$IGNIS_BIN" --threads "${THREADS:-1}" examples/hello_server.php > /tmp/ignis-e6.log 2>&1 & PID=$!
up=0; for _ in $(seq 1 50); do curl -sf "http://$ADDR/" 2>/dev/null | grep -q "Hello, World!" && { up=1; break; }; sleep 0.1; done
if [ "${up:-0}" != 1 ] || ! kill -0 $PID 2>/dev/null; then
  echo "our server never answered on $ADDR (port taken? set IGNIS_LISTEN); see the server log"
  kill $PID 2>/dev/null; exit 1
fi
echo "single:"; for i in 1 2 3; do curl -s -m 10 "http://$ADDR/fetch?ms=200"; echo; done
N="${N:-100}"; OUT=$(mktemp -d); pids=()
t0=$(date +%s%N)
for i in $(seq 1 "$N"); do curl -s -m 20 "http://$ADDR/fetch?ms=200" > "$OUT/$i" & pids+=($!); done; wait "${pids[@]}"
t1=$(date +%s%N)
ok=$(grep -l '"bodies":\["slept\\n","slept\\n","slept\\n"\]' "$OUT"/* 2>/dev/null | wc -l)
echo "concurrent: n=$N ok=$ok wall_ms=$(( (t1 - t0) / 1000000 ))"
grep -h -o '"ms":[0-9.]*' "$OUT"/* | sort -t: -k2 -n | sed -n '1p;$p' | tr '\n' ' '; echo " (min/max per-request ms)"
kill $PID; wait $PID 2>/dev/null || true; rm -rf "$OUT"
[ "$ok" = "$N" ]
