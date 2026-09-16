#!/usr/bin/env bash
# B1 (ADR-0019): fiber budget — admission control with a queue and a 503 past it.
# Acceptance: a deep queue does not cost RSS (a queued request is data, not a ~34 KB Fiber),
# and the p99 of admitted requests is unchanged when the load fits inside the budget.
set -uo pipefail
cd "$(dirname "$0")/.."
ADDR="${IGNIS_LISTEN:-127.0.0.1:8097}"
BIN=${BIN:-./target/release/ignis}
export LD_LIBRARY_PATH=${LD_LIBRARY_PATH:-/opt/php85-zts/lib}

start() {  # $1..$n = env assignments; sets SRV
  env IGNIS_LISTEN="$ADDR" "$@" $BIN --threads 1 examples/hello_server.php >/tmp/b1-server.log 2>&1 &
  SRV=$!
  for _ in $(seq 1 60); do curl -sf "http://$ADDR/" 2>/dev/null | grep -q "Hello, World!" && return 0; sleep 0.1; done
  echo "server did not start"; tail -3 /tmp/b1-server.log; kill $SRV 2>/dev/null; return 1
}
stop() { kill $SRV 2>/dev/null; wait $SRV 2>/dev/null; sleep 0.5; }  # let the port clear before the next arm
rss()  { awk '/VmRSS/{print $2}' /proc/$SRV/status 2>/dev/null; }

echo "== (1) the budget serialises: 10 x 200 ms at budget 2 should take ~1000 ms, none lost"
start IGNIS_FIBER_BUDGET=2 || exit 1
t0=$(date +%s%N); pids=(); out=$(mktemp -d)
for i in $(seq 1 10); do curl -s -m 30 -o /dev/null -w "%{http_code}" "http://$ADDR/sleep?ms=200" > "$out/$i" & pids+=($!); done
for p in "${pids[@]}"; do wait "$p"; done
echo "   wall_ms=$(( ($(date +%s%N) - t0) / 1000000 ))  codes: $(cat "$out"/* | sort | uniq -c | tr '\n' ' ')"
rm -rf "$out"; stop

echo "== (2) past the queue it sheds: budget 2, depth 3, 12 concurrent => 5 served, 7 x 503"
start IGNIS_FIBER_BUDGET=2 IGNIS_QUEUE_DEPTH=3 || exit 1
pids=(); out=$(mktemp -d)
for i in $(seq 1 12); do curl -s -m 30 -o /dev/null -w "%{http_code}" "http://$ADDR/sleep?ms=300" > "$out/$i" & pids+=($!); done
for p in "${pids[@]}"; do wait "$p"; done
echo "   codes: $(cat "$out"/* | sort | uniq -c | tr '\n' ' ')"
rm -rf "$out"; stop

echo "== (3) what a held request costs, and how much of that the budget removes"
echo "   Both arms hold the same connections on a 30 s handler. The control has no budget, so every"
echo "   held request owns a Fiber; the test arm admits 4 and queues the rest as raw arrays. Running"
echo "   each at two connection counts isolates the MARGINAL cost from the fixed cost of the socket."
for arm in "IGNIS_FIBER_BUDGET=0" "IGNIS_FIBER_BUDGET=4 IGNIS_QUEUE_DEPTH=1000000"; do
  prev_c=0; prev_kb=0
  for c in "${QLOW:-10000}" "${QHIGH:-20000}"; do
    start $arm || exit 1
    idle=$(rss)
    wrk -t4 -c"$c" -d"${QDUR:-18s}" --timeout 25s "http://$ADDR/sleep?ms=30000" >/tmp/b1-wrk.log 2>&1 &
    W=$!
    # /stats would queue behind the load like any other request, so RSS is read from /proc.
    for _ in 1 2 3 4; do sleep 3; peak=$(rss); done
    kill $W 2>/dev/null; wait $W 2>/dev/null
    d=$((peak - idle))
    echo "   [$arm] conns=$c idle_kb=$idle held_kb=$peak delta_kb=$d per_request_b=$(( d * 1024 / c ))"
    if [ "$prev_c" != 0 ]; then
      echo "      marginal over $prev_c -> $c: $(( (d - prev_kb) * 1024 / (c - prev_c) )) B per extra held request"
    fi
    prev_c=$c; prev_kb=$d
    stop; sleep 1
  done
done

echo "== (4) p99 of admitted requests is unchanged when the load fits the budget"
for cfg in "IGNIS_FIBER_BUDGET=0" "IGNIS_FIBER_BUDGET=512"; do
  start $cfg || exit 1
  line=$(wrk -t2 -c64 -d10s --latency "http://$ADDR/" 2>/dev/null | awk '/Requests\/sec/{r=$2} /99%/{p=$2} END{printf "rps=%s p99=%s", r, p}')
  echo "   $cfg  $line"
  stop
done
