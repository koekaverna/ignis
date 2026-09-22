#!/usr/bin/env bash
# A3: RSS over a long mixed-route soak. Acceptance (owner decision 2026-09-16): no monotonic
# trend past 5M requests; 0 errors; watchdog silent. Prints one checkpoint per chunk so the
# trend can be read, not just two endpoints — the criterion this replaces compared 1M with 10M
# and was unsatisfiable, since adjacent checkpoints swing +-10-12% on their own.
set -uo pipefail
cd "$(dirname "$0")/.."
ADDR="${IGNIS_LISTEN:-127.0.0.1:8099}"; export IGNIS_LISTEN="$ADDR"
BIN=${BIN:-./target/release/ignis}
TARGET=${TARGET:-10000000}      # total requests
DUR=${DUR:-30s}                 # per chunk
CONNS=${CONNS:-200}
export LD_LIBRARY_PATH=${LD_LIBRARY_PATH:-/opt/php85-zts/lib}

$BIN --threads 4 --supervise bench/php/a3-soak.php > /tmp/a3-soak-server.log 2>&1 & PID=$!
up=0; for _ in $(seq 1 80); do curl -sf "http://$ADDR/stats" >/dev/null 2>&1 && { up=1; break; }; sleep 0.25; done
if [ "${up:-0}" != 1 ] || ! kill -0 $PID 2>/dev/null; then
  echo "soak server never answered on $ADDR"; tail -5 /tmp/a3-soak-server.log; kill $PID 2>/dev/null; exit 1
fi
echo "pid=$PID addr=$ADDR target=$TARGET chunk=$DUR conns=$CONNS"
echo -e "requests\trss_kb\tfibers\tidle\trestarts\tstalled"
total=0
while [ "$total" -lt "$TARGET" ]; do
  # A mix of the routes: /whoami is cheap, /dashboard awaits children.
  for path in /whoami?x=1 /dashboard?user=7; do
    out=$(wrk -t4 -c"$CONNS" -d"$DUR" "http://$ADDR$path" 2>/dev/null)
    n=$(awk '/requests in/{print $1}' <<<"$out")
    err=$(awk '/Non-2xx/{print $NF}' <<<"$out")
    total=$((total + ${n:-0}))
    st=$(curl -s -m 5 "http://$ADDR/stats")
    printf "%s\t%s\t%s\t%s\t%s\t%s%s\n" "$total" \
      "$(grep -o '"rss_kb":[0-9]*' <<<"$st" | cut -d: -f2)" \
      "$(grep -o '"fibers":[0-9]*' <<<"$st" | cut -d: -f2)" \
      "$(grep -o '"idle":[0-9]*' <<<"$st" | cut -d: -f2)" \
      "$(grep -o '"restarts":[0-9]*' <<<"$st" | cut -d: -f2)" \
      "$(grep -o '"stalled":[0-9]*' <<<"$st" | cut -d: -f2)" \
      "${err:+  non2xx=$err}"
    kill -0 $PID 2>/dev/null || { echo "SERVER DIED at $total requests"; tail -20 /tmp/a3-soak-server.log; exit 1; }
    [ "$total" -ge "$TARGET" ] && break
  done
done
echo "done: $total requests"; tail -3 /tmp/a3-soak-server.log
kill $PID 2>/dev/null; wait $PID 2>/dev/null || true
