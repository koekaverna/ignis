#!/usr/bin/env bash
# E7: Revolt/AMPHP examples unchanged under IgnisDriver vs StreamSelectDriver (both run by the ignis binary).
set -uo pipefail
cd "$(dirname "$0")/.."
BIN=./target/release/ignis; EX=php/amphp/vendor/revolt/event-loop/examples; AEX=php/amphp/examples
export IGNIS_PHP_INI=/home/user/ignis/php/amphp/ignis.ini IGNIS_NO_STREAM_HOOK=1
run() { # driver script [stdin-file]
  local d="$1" s="$2"; shift 2
  if [ $# -gt 0 ]; then REVOLT_DRIVER="$d" timeout 30 $BIN "$s" < "$1" 2>&1; else REVOLT_DRIVER="$d" timeout 30 $BIN "$s" 2>&1 </dev/null; fi
}
SEL='Revolt\EventLoop\Driver\StreamSelectDriver'; IGN='Ignis\Revolt\IgnisDriver'
$BIN --threads 1 examples/hello_server.php >/dev/null 2>&1 & SRV=$!; for _ in $(seq 1 50); do curl -sf http://127.0.0.1:8080/ >/dev/null && break; sleep 0.1; done
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
