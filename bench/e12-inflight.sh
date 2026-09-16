#!/usr/bin/env bash
# E12': a request in flight on a thread that dies (fatal) gets a 500 at once, not a hang; the supervisor respawns the thread.
set -uo pipefail
cd "$(dirname "$0")/.."
./target/release/ignis --supervise --threads 1 examples/hello_server.php > /tmp/e12-inflight.log 2>&1 & S=$!
for _ in $(seq 1 50); do curl -sf http://127.0.0.1:8080/ >/dev/null && break; sleep 0.1; done
T0=$(date +%s%N)
curl -s -m 10 -o /dev/null -w "in-flight /sleep?ms=3000 -> %{http_code} after %{time_total}s\n" "http://127.0.0.1:8080/sleep?ms=3000" & C=$!
sleep 0.3
curl -s -m 5 -o /dev/null -w "/fatal -> %{http_code} after %{time_total}s\n" http://127.0.0.1:8080/fatal
wait $C
sleep 0.5
curl -s -m 5 -o /dev/null -w "after respawn: / -> %{http_code} after %{time_total}s\n" http://127.0.0.1:8080/
echo "wall: $(( ($(date +%s%N) - T0) / 1000000 )) ms (a hang would be >= 3000 ms for the sleeping request)"
kill $S; wait $S 2>/dev/null || true
grep -c "answered 500" /tmp/e12-inflight.log
