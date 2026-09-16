#!/usr/bin/env bash
# E7: Revolt/AMPHP examples unchanged under IgnisDriver vs StreamSelectDriver (both run by the ignis binary).
set -uo pipefail
cd "$(dirname "$0")/.."
# Listen address for the server this script starts and every URL below. Override with
# IGNIS_LISTEN when :8080 is taken; the examples read the same variable, so the server and
# the client can never disagree and curl a stranger that happens to hold the port.
ADDR="${IGNIS_LISTEN:-127.0.0.1:8080}"; export IGNIS_LISTEN="$ADDR"
BIN=./target/release/ignis; EX=php/amphp/vendor/revolt/event-loop/examples; AEX=php/amphp/examples
# The ini lives in this repo; it used to be an absolute path to another machine, so on any
# other checkout the ini silently did not apply.
mkdir -p /tmp/e7-revolt; sed "s#^auto_prepend_file=.*#auto_prepend_file=$PWD/php/amphp/prepend.php#" php/amphp/ignis.ini > /tmp/e7-revolt/ignis.ini
export IGNIS_PHP_INI=/tmp/e7-revolt/ignis.ini
run() { # driver script [stdin-file]
  local d="$1" s="$2"; shift 2
  if [ $# -gt 0 ]; then REVOLT_DRIVER="$d" timeout 30 $BIN "$s" < "$1" 2>&1; else REVOLT_DRIVER="$d" timeout 30 $BIN "$s" 2>&1 </dev/null; fi
}
SEL='Revolt\EventLoop\Driver\StreamSelectDriver'; IGN='Ignis\Revolt\IgnisDriver'
$BIN --threads 1 examples/hello_server.php >/dev/null 2>&1 & SRV=$!; up=0; for _ in $(seq 1 50); do curl -sf "http://$ADDR/" 2>/dev/null | grep -q "Hello, World!" && { up=1; break; }; sleep 0.1; done
if [ "${up:-0}" != 1 ] || ! kill -0 $SRV 2>/dev/null; then
  echo "our server never answered on $ADDR (port taken? set IGNIS_LISTEN); see the server log"
  kill $SRV 2>/dev/null; exit 1
fi
printf 'hello world\nsecond line\n' > /tmp/ignis-stdin.txt
fail=0
for s in $EX/timers.php $EX/ticks.php $EX/fiber-local-automatic.php $EX/fiber-local-manual.php $EX/invalid-callback-return.php $EX/consume-stdin.php $AEX/amp-delay-async.php $AEX/amp-socket-client.php; do
  in=""; [[ "$s" == *consume-stdin* ]] && in=/tmp/ignis-stdin.txt
  a=$(run "$SEL" "$s" $in); b=$(run "$IGN" "$s" $in)
  if [ "$a" == "$b" ]; then echo "SAME   $(basename "$s"): $(echo "$b" | tr '\n' '|' | cut -c1-110)"; else fail=$((fail+1)); echo "DIFFER $(basename "$s")"; echo "  select: $(echo "$a" | tr '\n' '|' | cut -c1-200)"; echo "  ignis : $(echo "$b" | tr '\n' '|' | cut -c1-200)"; fi
done
echo "== timer/tick benchmarks (wall seconds, select vs ignis)"
for s in $EX/benchmark-timers.php $EX/benchmark-ticks-delay.php $EX/benchmark-timers-delay.php; do
  t0=$(date +%s%N); run "$SEL" "$s" >/dev/null; t1=$(date +%s%N); run "$IGN" "$s" >/dev/null; t2=$(date +%s%N)
  echo "$(basename "$s"): select=$(( (t1 - t0) / 1000000 ))ms ignis=$(( (t2 - t1) / 1000000 ))ms"
done
kill $SRV; wait $SRV 2>/dev/null || true
echo "e7 differing=$fail"; [ "$fail" = 0 ]
