#!/usr/bin/env bash
# E13 (a): N concurrent /echo?x=i&ms=20 requests; every response must echo its own i, URI and Scope value.
set -uo pipefail
cd "$(dirname "$0")/.."
N="${N:-200}"
./target/release/ignis --threads "${THREADS:-1}" examples/hello_server.php >/dev/null 2>&1 & PID=$!
for _ in $(seq 1 50); do curl -sf http://127.0.0.1:8080/ >/dev/null && break; sleep 0.1; done
OUT=$(mktemp -d)
pids=(); for i in $(seq 1 "$N"); do curl -s "http://127.0.0.1:8080/echo?x=$i&ms=20" > "$OUT/$i" & pids+=($!); done; wait "${pids[@]}"
bad=0
for i in $(seq 1 "$N"); do [ "$(cat "$OUT/$i")" = "$i /echo?x=$i&ms=20 $i" ] || { bad=$((bad+1)); echo "mismatch $i: $(cat "$OUT/$i")"; }; done
echo "e13_http n=$N mismatches=$bad"
kill $PID; wait $PID 2>/dev/null || true; rm -rf "$OUT"
[ "$bad" = 0 ]
