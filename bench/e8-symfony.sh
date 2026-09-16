#!/usr/bin/env bash
# E8: symfony/skeleton in worker mode on Ignis; RequestStack fiber-scoped under 100 interleaved requests; hello throughput.
set -uo pipefail
cd "$(dirname "$0")/.."
./target/release/ignis --threads "${THREADS:-1}" php/symfony/worker.php > /tmp/ignis-sf.log 2>&1 & PID=$!
for _ in $(seq 1 60); do curl -sf http://127.0.0.1:8080/ >/dev/null && break; sleep 0.1; done
echo "== /"; curl -s -i http://127.0.0.1:8080/ | sed -n '1p;$p'
N="${N:-100}"; OUT=$(mktemp -d); pids=()
for i in $(seq 1 "$N"); do curl -s "http://127.0.0.1:8080/whoami?tag=$i&ms=200" > "$OUT/$i" & pids+=($!); done; wait "${pids[@]}"
bad=0; for i in $(seq 1 "$N"); do body=$(cat "$OUT/$i"); [[ "$body" == *"\"tag\":\"$i\""* && "$body" == *"tag=$i\\u0026ms=200"* && "$body" == *"\"tag_after\":\"$i\""* ]] || { bad=$((bad+1)); [ $bad -le 3 ] && echo "mismatch $i: $(head -c 200 "$OUT/$i")"; }; done
echo "e8 whoami n=$N mismatches=$bad"
echo "== hello throughput (wrk -t2 -c64 -d10s)"; wrk -t2 -c64 -d10s --latency http://127.0.0.1:8080/ | grep -E "Requests/sec|99%|Non-2xx|Socket"
kill -0 $PID 2>/dev/null && echo "server alive" || echo "server DIED"
kill $PID 2>/dev/null; wait $PID 2>/dev/null || true; rm -rf "$OUT"; grep -ciE "critical|fatal" /tmp/ignis-sf.log | sed 's/^/log critical\/fatal lines: /'
[ "$bad" = 0 ]
