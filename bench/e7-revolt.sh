#!/usr/bin/env bash
# E7: Revolt/AMPHP examples unchanged under IgnisDriver vs StreamSelectDriver (both run by the ignis binary).
set -uo pipefail
cd "$(dirname "$0")/.."
# Listen address for the server this script starts and every URL below. Override with
# IGNIS_LISTEN when :8080 is taken; the examples read the same variable, so the server and
# the client can never disagree and curl a stranger that happens to hold the port.
ADDR="${IGNIS_LISTEN:-127.0.0.1:8080}"; export IGNIS_LISTEN="$ADDR"
BIN=./target/release/ignis; EX=php/packages/revolt/vendor/revolt/event-loop/examples; AEX=php/packages/revolt/examples
# The ini lives in this repo; it used to be an absolute path to another machine, so on any
# other checkout the ini silently did not apply.
mkdir -p /tmp/e7-revolt; sed "s#^auto_prepend_file=.*#auto_prepend_file=$PWD/php/packages/revolt/prepend.php#" php/packages/revolt/ignis.ini > /tmp/e7-revolt/ignis.ini
export IGNIS_PHP_INI=/tmp/e7-revolt/ignis.ini
SEL='Revolt\EventLoop\Driver\StreamSelectDriver'; IGN='Ignis\Revolt\IgnisDriver'

# Revolt's own examples do `require __DIR__ . '/../vendor/autoload.php'` — the layout of a source
# checkout OF revolt. Installed as a dependency it has no nested vendor/, and composer install
# --prefer-source does not make one, so every example fataled here and the comparison was between
# two identical fatals. The autoloader that actually has revolt (and amphp) is the outer one; point
# their expected path at it rather than composer-installing a dependency's dev tree.
NESTED=$EX/../vendor
if [ ! -f "$NESTED/autoload.php" ]; then
  mkdir -p "$NESTED"
  printf '<?php\n// written by bench/e7-revolt.sh: revolt examples expect a nested vendor/\nrequire __DIR__ . "/../../../autoload.php";\n' > "$NESTED/autoload.php"
fi

# RUST_LOG=error: without it the runtime's own WARN lines carry a timestamp, so two runs of the
# same program never compare equal (this is what `differing=6` used to be measuring).
#
# The control arm runs with universal park OFF, and that is not a convenience: park breaks
# StreamSelectDriver. The gate in park.rs is "we are in some fiber", so a fiber that Revolt's own
# driver started parks into our reactor, which nothing in that program is driving — Revolt then
# reports "Event loop terminated without resuming the current suspension". Measured in V-63; the
# defect is BACKLOG R-FOREIGN-FIBER and is fixed in park.rs, not here. The control's job is to say
# what the program does on a stock event loop, so park has no business in it either way.
run() { # driver script [stdin-file]
  local d="$1" s="$2" off=""; shift 2
  [ "$d" = "$SEL" ] && off=1
  if [ $# -gt 0 ]; then IGNIS_NO_UNIVERSAL_PARK="$off" RUST_LOG=error REVOLT_DRIVER="$d" timeout 30 $BIN "$s" < "$1" 2>&1
  else IGNIS_NO_UNIVERSAL_PARK="$off" RUST_LOG=error REVOLT_DRIVER="$d" timeout 30 $BIN "$s" 2>&1 </dev/null; fi
}
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
