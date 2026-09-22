#!/usr/bin/env bash
# E23 (R-STREAM, V-74): a streamed response reaches the client while it is still being produced.
#
# Three properties, and the first one is what every other PHP runtime gets from its SAPI and we had
# to build: the body is framed `chunked` with no Content-Length, the first byte arrives long before
# the last, and the PHP thread keeps serving other requests while the stream is open.
set -uo pipefail
cd "$(dirname "$0")/.."
PORT=${PORT:-8199}
case "${IGNIS_BIN:-}" in ""|./target/release/ignis) export LD_LIBRARY_PATH=${LD_LIBRARY_PATH:-/opt/php85-zts/lib};; esac
BIN=${IGNIS_BIN:-./target/release/ignis}
ss -ltn "sport = :$PORT" | grep -q LISTEN && { echo "port $PORT busy"; exit 2; }

IGNIS_LISTEN="127.0.0.1:$PORT" "$BIN" --threads 1 bench/php/stream_server.php >/tmp/ignis-e23.log 2>&1 & S=$!
trap 'kill $S 2>/dev/null' EXIT
for _ in $(seq 1 50); do curl -sf "http://127.0.0.1:$PORT/tick" >/dev/null 2>&1 && break; sleep 0.2; done

fail=0
echo "== framing"
hdr=$(curl -s -D- -o /dev/null "http://127.0.0.1:$PORT/?n=2&ms=100")
grep -qi "transfer-encoding: chunked" <<<"$hdr" || { echo "  no chunked framing:"; echo "$hdr" | head -5; fail=1; }
grep -qi "content-length" <<<"$hdr" && { echo "  Content-Length on a stream — it was buffered after all"; fail=1; }
grep -qi "transfer-encoding: chunked" <<<"$hdr" && echo "  transfer-encoding: chunked, no content-length  ok"

echo "== first byte arrives before the last"
read -r ttfb total <<<"$(curl -s -o /dev/null -w '%{time_starttransfer} %{time_total}' "http://127.0.0.1:$PORT/?n=3&ms=300")"
echo "  ttfb=${ttfb}s total=${total}s"
awk -v a="$ttfb" -v b="$total" 'BEGIN { exit (a < 0.2 && b > 0.8) ? 0 : 1 }' \
  || { echo "  the body did not stream: ttfb should be small and total ~0.9 s"; fail=1; }

echo "== the thread keeps serving while a stream is open"
curl -sN "http://127.0.0.1:$PORT/?n=3&ms=300" >/dev/null & SLOW=$!
sleep 0.3
ticks=0
for _ in 1 2 3; do curl -sf -m 2 "http://127.0.0.1:$PORT/tick" >/dev/null && ticks=$((ticks + 1)); done
wait $SLOW 2>/dev/null
echo "  ticks answered during the stream: $ticks/3"
[ "$ticks" = 3 ] || { echo "  the stream blocked the thread"; fail=1; }

echo "== a producer that fails before its first byte still answers 500"
# Nothing is sent until the first write, which is the only reason this can be a 500 at all: once the
# status line is out the client has been told 200 and an error can only truncate. Before the loop
# owned the producer's lifetime, an early failure answered 200 with an empty chunked body.
code=$(curl -s -o /dev/null -w '%{http_code}' "http://127.0.0.1:$PORT/?n=0&fail=early")
echo "  early failure -> HTTP $code"
[ "$code" = 500 ] || { echo "  an early failure must be a 500, not a truncated 200"; fail=1; }

echo "== another fiber's echo must not land in the stream"
kill $S 2>/dev/null; wait $S 2>/dev/null
IGNIS_LISTEN="127.0.0.1:$PORT" "$BIN" --threads 1 bench/php/stream_isolation.php >/tmp/ignis-e23-iso.log 2>&1 & S=$!
for _ in $(seq 1 50); do curl -sf "http://127.0.0.1:$PORT/noise" >/dev/null 2>&1 && break; sleep 0.2; done
curl -sN -m 10 "http://127.0.0.1:$PORT/stream" > /tmp/ignis-e23-iso.txt & C=$!
sleep 0.2
curl -s -m 5 "http://127.0.0.1:$PORT/noise" >/dev/null
wait $C 2>/dev/null
body=$(tr -d '\n' < /tmp/ignis-e23-iso.txt)
echo "  streamed body: $body"
# Both halves matter: the noise must be absent AND this response's own bytes must be present — an
# empty body would otherwise pass the first check while measuring nothing.
case "$body" in
  *NOISE*)    echo "  another request's echo was framed into this response"; fail=1;;
  A-1A-2)     echo "  only its own bytes  ok";;
  *)          echo "  expected 'A-1A-2', so this arm stopped measuring"; fail=1;;
esac
kill $S 2>/dev/null; wait $S 2>/dev/null

echo "== a graceful shutdown waits for a stream instead of cutting it"
# Before V-75 the reactor did not count streamed responses as pending, so drain() saw an idle thread
# and SIGTERM truncated a client's download — measured at 2 of 5 chunks.
kill $S 2>/dev/null; wait $S 2>/dev/null
IGNIS_LISTEN="127.0.0.1:$PORT" "$BIN" --threads 1 bench/php/stream_server.php >>/tmp/ignis-e23.log 2>&1 & S=$!
for _ in $(seq 1 50); do curl -sf "http://127.0.0.1:$PORT/tick" >/dev/null 2>&1 && break; sleep 0.2; done
curl -sN -m 10 "http://127.0.0.1:$PORT/?n=5&ms=200" > /tmp/ignis-e23-drain.txt & C=$!
sleep 0.3
kill -TERM $S
wait $C 2>/dev/null
got=$(wc -l < /tmp/ignis-e23-drain.txt)
echo "  chunks delivered across the shutdown: $got/5"
[ "$got" = 5 ] || { echo "  the shutdown cut a live stream"; fail=1; }
wait $S 2>/dev/null

[ "$fail" = 0 ] && echo "E23: GREEN" || echo "E23: FAILED"
exit $fail
