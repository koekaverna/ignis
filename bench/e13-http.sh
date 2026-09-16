#!/usr/bin/env bash
# E13 (a): N concurrent /echo?x=i&ms=20 requests; every response must echo its own i, URI and Scope value.
set -uo pipefail
cd "$(dirname "$0")/.."
# Listen address for the server this script starts and every URL below. Override with
# IGNIS_LISTEN when :8080 is taken; the examples read the same variable, so the server and
# the client can never disagree and curl a stranger that happens to hold the port.
ADDR="${IGNIS_LISTEN:-127.0.0.1:8080}"; export IGNIS_LISTEN="$ADDR"
N="${N:-200}"
./target/release/ignis --threads "${THREADS:-1}" examples/hello_server.php >/dev/null 2>&1 & PID=$!
up=0; for _ in $(seq 1 50); do curl -sf "http://$ADDR/" 2>/dev/null | grep -q "Hello, World!" && { up=1; break; }; sleep 0.1; done
if [ "${up:-0}" != 1 ] || ! kill -0 $PID 2>/dev/null; then
  echo "our server never answered on $ADDR (port taken? set IGNIS_LISTEN); see the server log"
  kill $PID 2>/dev/null; exit 1
fi
OUT=$(mktemp -d)
pids=(); for i in $(seq 1 "$N"); do curl -s "http://$ADDR/echo?x=$i&ms=20" > "$OUT/$i" & pids+=($!); done; wait "${pids[@]}"
bad=0
for i in $(seq 1 "$N"); do [ "$(cat "$OUT/$i")" = "$i /echo?x=$i&ms=20 $i" ] || { bad=$((bad+1)); echo "mismatch $i: $(cat "$OUT/$i")"; }; done
echo "e13_http n=$N mismatches=$bad"
kill $PID; wait $PID 2>/dev/null || true; rm -rf "$OUT"
[ "$bad" = 0 ]
