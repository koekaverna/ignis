#!/usr/bin/env bash
# E6: /fetch does 3 concurrent unmodified file_get_contents('http://127.0.0.1:8080/sleep?ms=200') on ONE PHP thread.
set -uo pipefail
cd "$(dirname "$0")/.."
./target/release/ignis --threads "${THREADS:-1}" examples/hello_server.php > /tmp/ignis-e6.log 2>&1 & PID=$!
for _ in $(seq 1 50); do curl -sf http://127.0.0.1:8080/ >/dev/null && break; sleep 0.1; done
echo "single:"; for i in 1 2 3; do curl -s -m 10 "http://127.0.0.1:8080/fetch?ms=200"; echo; done
N="${N:-100}"; OUT=$(mktemp -d); pids=()
t0=$(date +%s%N)
for i in $(seq 1 "$N"); do curl -s -m 20 "http://127.0.0.1:8080/fetch?ms=200" > "$OUT/$i" & pids+=($!); done; wait "${pids[@]}"
t1=$(date +%s%N)
ok=$(grep -l '"bodies":\["slept\\n","slept\\n","slept\\n"\]' "$OUT"/* 2>/dev/null | wc -l)
echo "concurrent: n=$N ok=$ok wall_ms=$(( (t1 - t0) / 1000000 ))"
grep -h -o '"ms":[0-9.]*' "$OUT"/* | sort -t: -k2 -n | sed -n '1p;$p' | tr '\n' ' '; echo " (min/max per-request ms)"
kill $PID; wait $PID 2>/dev/null || true; rm -rf "$OUT"
[ "$ok" = "$N" ]
