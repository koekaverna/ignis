#!/usr/bin/env bash
# E3: RSS flatness over >= 1M requests. Starts the server, warms up, samples /stats after each burst.
set -uo pipefail
cd "$(dirname "$0")/.."
T="${THREADS:-1}"; BURST="${BURST:-3s}"; TARGET="${TARGET:-1000000}"
./target/release/ignis --threads "$T" examples/hello_server.php >/dev/null 2>&1 & PID=$!
for _ in $(seq 1 50); do curl -sf http://127.0.0.1:8080/ >/dev/null && break; sleep 0.1; done
stats() { curl -s http://127.0.0.1:8080/stats; }
total=0
echo "warm-up 5s"; wrk -t2 -c64 -d5s http://127.0.0.1:8080/ | grep -E "requests in" ; echo "sample 0 (post warm-up): $(stats)"
i=0
while [ "$total" -lt "$TARGET" ]; do
  i=$((i+1)); n=$(wrk -t2 -c64 -d"$BURST" http://127.0.0.1:8080/ | awk '/requests in/{print $1}'); total=$((total + n))
  echo "sample $i: +$n requests (total $total): $(stats)"
done
echo "sleep workload: /sleep?ms=1 at 500 connections, 20s"; n=$(wrk -t2 -c500 -d20s "http://127.0.0.1:8080/sleep?ms=1" | awk '/requests in/{print $1}'); echo "sleep: $n requests: $(stats)"
echo "cpu workload: /cpu 5s"; n=$(wrk -t1 -c64 -d5s http://127.0.0.1:8080/cpu | awk '/requests in/{print $1}'); echo "cpu: $n requests: $(stats)"
echo "hello again 3s"; n=$(wrk -t2 -c64 -d3s http://127.0.0.1:8080/ | awk '/requests in/{print $1}'); echo "hello: $n requests: $(stats)"
kill $PID; wait $PID 2>/dev/null || true
