#!/usr/bin/env bash
# `Ignis\Classic\finish()` ends the request, not the worker.
#
# It throws, which is how it ends a script from any depth, and in `listen()` mode the `include` is in
# the caller's own `while` loop. Until 2026-09-19 the documented loop had a bare `include`, so the
# throw unwound the loop and the thread stopped serving -- in the one mode legacy code most needs a
# replacement for `exit()`. The loop catches `Finished` now; the include stays at the top level,
# because that is the whole reason `listen()` exists (V-53: real globals).
#
# Two requests: the second one only answers if the first did not take the worker with it.
set -uo pipefail
cd "$(dirname "$0")/.."
ADDR="${IGNIS_LISTEN:-127.0.0.1:8080}"
BIN="${IGNIS_BIN:-./target/release/ignis}"
export LD_LIBRARY_PATH="${LD_LIBRARY_PATH:-/opt/php85-zts/lib}"

ROOT=$(mktemp -d)
trap 'rm -rf "$ROOT"' EXIT
cat > "$ROOT/index.php" <<'PHP'
<?php
echo "before-finish\n";
Ignis\Classic\finish();
echo "after-finish (must never appear)\n";
PHP

IGNIS_LISTEN="$ADDR" timeout 60 "$BIN" examples/classic_worker.php "$ROOT" > "$ROOT/server.log" 2>&1 &
SRV=$!
for _ in $(seq 1 60); do curl -sf "http://$ADDR/" >/dev/null 2>&1 && break; sleep 0.2; done

first=$(curl -s --max-time 5 "http://$ADDR/")
second=$(curl -s --max-time 5 "http://$ADDR/")
alive=$(kill -0 $SRV 2>/dev/null && echo yes || echo no)
kill $SRV 2>/dev/null; wait $SRV 2>/dev/null

fail=0
[ "$first" = "before-finish" ] || { echo "first request answered '$first'"; fail=1; }
[ "$second" = "before-finish" ] || { echo "second request answered '$second' -- finish() took the worker with it"; fail=1; }
[ "$alive" = yes ] || { echo "the worker died"; fail=1; }
printf "classic_finish first=%q second=%q worker_alive=%s\n" "$first" "$second" "$alive"
[ "$fail" = 0 ] && echo "CLASSIC-FINISH GREEN" || { echo "CLASSIC-FINISH FAILED"; sed -n '1,20p' "$ROOT/server.log"; }
exit $fail
