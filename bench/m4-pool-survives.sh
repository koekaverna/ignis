#!/usr/bin/env bash
# M4-8: does the runtime-owned PG pool (ADR-0015) survive a --supervise worker
# thread's death (ADR-0012) while it holds a lease? --threads 2, pool max 2:
# fire TWO concurrent /lease-hold requests so BOTH worker threads are holding a
# lease (load-balanced least-inflight, crates/ignis/src/http.rs Registry::pick:
# "one target per request, not per connection") before ever touching /fatal —
# with only one hold in flight, /fatal deterministically always lands on the
# idle (non-holder) thread (its load is strictly lower), so a single hold plus
# retried /fatal never reaches the holder on a 2-thread box. Two concurrent
# holds mean whichever thread /fatal lands on IS a holder, on the first try.
set -uo pipefail
cd "$(dirname "$0")/.."
ADDR="${IGNIS_LISTEN:-127.0.0.1:8150}"; export IGNIS_LISTEN="$ADDR"
export PG_DSN="${PG_DSN:-host=/tmp/ignis-pgsock user=ignis password=ignis dbname=ignis}"
export POOL_MAX="${POOL_MAX:-2}"
IDFILE="/tmp/ignis-m4-pool-id.$$"
export IGNIS_M4_POOL_IDFILE="$IDFILE"
rm -f "$IDFILE" "$IDFILE.lock"

echo "load: $(uptime | sed 's/.*load average/load average/')"

RUST_LOG=warn ./target/release/ignis --threads 2 --supervise bench/php/m4_pool_survives.php \
  > /tmp/ignis-m4.log 2>&1 & PID=$!

up=0
for _ in $(seq 1 60); do
  body="$(curl -sf -m 2 "http://$ADDR/pool" 2>/dev/null)"
  if echo "$body" | jq -e '.pool_id' >/dev/null 2>&1; then up=1; break; fi
  sleep 0.1
done
if [ "$up" != 1 ] || ! kill -0 "$PID" 2>/dev/null; then
  echo "server never answered /pool on $ADDR (port taken? set IGNIS_LISTEN); see /tmp/ignis-m4.log"
  kill "$PID" 2>/dev/null; wait "$PID" 2>/dev/null
  rm -f "$IDFILE" "$IDFILE.lock"
  exit 1
fi

pool_boot="$(curl -sf -m 2 "http://$ADDR/pool")"
echo "at boot (no connection used yet): $pool_boot"
restarts0=$(echo "$pool_boot" | jq -r '.runtime.restarts')

# Two concurrent lease-holds: max time 8s (3s sleep + margin); either finishes
# with "held 3000 ms" or is cut short (its thread died) and curl fails/empty.
curl -s -m 8 -o /tmp/ignis-m4-hold1.out -w '%{http_code}' "http://$ADDR/lease-hold?ms=3000" > /tmp/ignis-m4-hold1.code &
H1=$!
curl -s -m 8 -o /tmp/ignis-m4-hold2.out -w '%{http_code}' "http://$ADDR/lease-hold?ms=3000" > /tmp/ignis-m4-hold2.code &
H2=$!

# Let both holds actually connect (SET + CREATE TEMP TABLE) before reading the
# "before" baseline C0 -- comparing pool-boot (0) to post-incident would always
# differ by the connections the workload itself needed, not by anything lost.
sleep 0.3
pool0="$(curl -sf -m 2 "http://$ADDR/pool")"
c0=$(echo "$pool0" | jq -r '.pool.created')
avail0=$(echo "$pool0" | jq -r '.pool.available')
echo "before /fatal (both holds live): created=$c0 available=$avail0 raw=$pool0"

sleep 0.2
fatal_code=$(curl -s -m 3 -o /dev/null -w '%{http_code}' "http://$ADDR/fatal")
echo "fatal request http=$fatal_code"

# Poll ignis_stats().restarts until it is 1 (a few tries; the respawn is inside
# the 50ms supervisor tick per V-17, so this should settle almost immediately).
restarts=$restarts0
for _ in $(seq 1 20); do
  r=$(curl -sf -m 2 "http://$ADDR/pool" 2>/dev/null | jq -r '.runtime.restarts // empty')
  [ -n "$r" ] && restarts=$r
  [ "$restarts" = "1" ] && break
  sleep 0.1
done
echo "restarts after /fatal: $restarts"

wait "$H1" 2>/dev/null; wait "$H2" 2>/dev/null
c1a="$(cat /tmp/ignis-m4-hold1.code 2>/dev/null)"; b1a="$(cat /tmp/ignis-m4-hold1.out 2>/dev/null)"
c2a="$(cat /tmp/ignis-m4-hold2.code 2>/dev/null)"; b2a="$(cat /tmp/ignis-m4-hold2.out 2>/dev/null)"
echo "hold1: http=$c1a body=$(echo "$b1a" | tr -d '\n')"
echo "hold2: http=$c2a body=$(echo "$b2a" | tr -d '\n')"
if [ "$c1a" = "200" ] && [ "$c2a" = "200" ]; then
  echo "NEITHER hold's thread died (both completed) -- /fatal killed a non-holder; test inconclusive this run"
elif [ "$c1a" != "200" ] && [ "$c2a" != "200" ]; then
  echo "WARNING: both holds failed -- more than one thread died?"
else
  echo "one holder's thread died (its /lease-hold connection was cut, http=$([ "$c1a" != 200 ] && echo "$c1a (hold1)" || echo "$c2a (hold2)")); the other holder's thread survived and released normally"
fi

pool1="$(curl -sf -m 2 "http://$ADDR/pool")"
c1=$(echo "$pool1" | jq -r '.pool.created')
avail1=$(echo "$pool1" | jq -r '.pool.available')
echo "after: created=$c1 available=$avail1 raw=$pool1"

probe="$(curl -sf -m 5 "http://$ADDR/probe")"
echo "probe: $probe"
sp=$(echo "$probe" | jq -r '.search_path')
tp=$(echo "$probe" | jq -r '.temp_probe')

echo "=== verdict ==="
[ "$c0" = "$c1" ] && echo "created unchanged: PASS ($c0 == $c1)" || echo "created CHANGED: FAIL ($c0 -> $c1)"
[ "$avail1" = "$POOL_MAX" ] && echo "available back to max: PASS ($avail1 == $POOL_MAX)" || echo "available NOT back to max: FAIL ($avail1 != $POOL_MAX)"
case "$sp" in
  *leaked*) echo "search_path leaked: FAIL ($sp)";;
  *) echo "search_path default: PASS ($sp)";;
esac
case "$tp" in
  error*) echo "temp table gone: PASS ($tp)";;
  *) echo "temp table still visible: FAIL ($tp)";;
esac

grep -E "respawning|budget|busy" /tmp/ignis-m4.log | sed 's/\x1b\[[0-9;]*m//g' | cut -c1-160 | head -6

kill -0 "$PID" 2>/dev/null && echo "server alive" || echo "server DIED"
kill "$PID" 2>/dev/null
wait "$PID" 2>/dev/null || true
rm -f "$IDFILE" "$IDFILE.lock" /tmp/ignis-m4-hold1.out /tmp/ignis-m4-hold1.code /tmp/ignis-m4-hold2.out /tmp/ignis-m4-hold2.code
