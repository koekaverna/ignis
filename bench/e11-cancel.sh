#!/usr/bin/env bash
# E11: client disconnect cancels the request fiber + child within 10 ms; deadline returns 504 on time; E6 still green.
set -uo pipefail
cd "$(dirname "$0")/.."
./target/release/ignis --threads 1 examples/hello_server.php > /tmp/ignis-e11.log 2>&1 & PID=$!
for _ in $(seq 1 50); do curl -sf http://127.0.0.1:8080/ >/dev/null && break; sleep 0.1; done
echo "== (a) 20 clients hit /slow and disconnect after 200 ms"
pids=(); for i in $(seq 1 20); do curl -s -m 0.2 http://127.0.0.1:8080/slow >/dev/null 2>&1 & pids+=($!); done; wait "${pids[@]}"
sleep 0.3
curl -s http://127.0.0.1:8080/stats; echo
echo "== (b) /deadline?ms=100 around a 1000 ms sleep"
for i in 1 2 3; do curl -s -o /dev/null -w "status=%{http_code} time_s=%{time_total}\n" "http://127.0.0.1:8080/deadline?ms=100"; done
echo "== (c) E6 still works after cancellations"
curl -s "http://127.0.0.1:8080/fetch?ms=100"; echo
echo "== (d) no phantom work: /slow fibers are gone (idle == fibers), stats 5.5 s after the disconnects"
sleep 5.3; curl -s http://127.0.0.1:8080/stats; echo
kill $PID; wait $PID 2>/dev/null || true
