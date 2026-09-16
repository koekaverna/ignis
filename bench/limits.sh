#!/usr/bin/env bash
# B8/M4-3 companion: proves the three http.rs limits actually bite. Small limits (set via env,
# same names ignis.toml will use once the main agent wires the keys) and short sleeps keep this
# under 30s.
#   1. body over IGNIS_MAX_BODY_BYTES        -> 413, not buffered in full
#   2. headers trickled slower than IGNIS_HEADER_TIMEOUT_MS -> connection dropped
#   3. idle keep-alive past IGNIS_IDLE_TIMEOUT_MS            -> connection closed
set -uo pipefail
cd "$(dirname "$0")/.."
ADDR="${IGNIS_LISTEN:-127.0.0.1:8082}"; HOST="${ADDR%%:*}"; PORT="${ADDR##*:}"
export IGNIS_LISTEN="$ADDR"
export IGNIS_MAX_BODY_BYTES=1024
export IGNIS_HEADER_TIMEOUT_MS=800
export IGNIS_IDLE_TIMEOUT_MS=800

./target/release/ignis --threads 1 bench/php/limits_probe.php >/dev/null 2>&1 & PID=$!
up=0; for _ in $(seq 1 50); do curl -sf "http://$ADDR/" 2>/dev/null | grep -q ok && { up=1; break; }; sleep 0.1; done
if [ "${up:-0}" != 1 ] || ! kill -0 $PID 2>/dev/null; then
  echo "server never answered on $ADDR (port taken? set IGNIS_LISTEN); see the server log"
  kill $PID 2>/dev/null; exit 1
fi

bad=0

# 1) body over the cap -> 413. -H 'Expect:' skips the 100-continue round trip we're not testing.
code=$(head -c 2048 /dev/zero | tr '\0' 'a' | curl -s -o /dev/null -w '%{http_code}' --max-time 5 -H 'Expect:' -X POST --data-binary @- "http://$ADDR/")
[ "$code" = "413" ] && echo "body cap: got 413" || { echo "FAIL body cap: expected 413, got $code"; bad=$((bad+1)); }

# 2) partial headers, never completed -> server must drop the connection instead of hanging.
# `timeout 2 head -c1` exits 124 only if it had to wait out the full 2s (i.e. still open).
if exec 3<>"/dev/tcp/$HOST/$PORT" 2>/dev/null; then
  printf 'GET / HTTP/1.1\r\nHost: x\r\n' >&3
  sleep 1.2
  timeout 2 head -c1 <&3 >/dev/null 2>&1; rc=$?
  exec 3<&- 3>&- 2>/dev/null
  [ "$rc" != 124 ] && echo "header timeout: closed as expected" || { echo "FAIL header timeout: connection still open past IGNIS_HEADER_TIMEOUT_MS"; bad=$((bad+1)); }
else
  echo "FAIL header timeout: could not open raw socket"; bad=$((bad+1))
fi

# 3) one request, then idle past IGNIS_IDLE_TIMEOUT_MS on the same keep-alive connection.
if exec 4<>"/dev/tcp/$HOST/$PORT" 2>/dev/null; then
  printf 'GET / HTTP/1.1\r\nHost: x\r\nConnection: keep-alive\r\n\r\n' >&4
  timeout 2 head -c 64 <&4 >/dev/null 2>&1
  sleep 1.2
  timeout 2 head -c1 <&4 >/dev/null 2>&1; rc=$?
  exec 4<&- 4>&- 2>/dev/null
  [ "$rc" != 124 ] && echo "idle timeout: closed as expected" || { echo "FAIL idle timeout: connection still open past IGNIS_IDLE_TIMEOUT_MS"; bad=$((bad+1)); }
else
  echo "FAIL idle timeout: could not open raw socket"; bad=$((bad+1))
fi

echo "limits_probe bad=$bad"
kill $PID 2>/dev/null; wait $PID 2>/dev/null || true
[ "$bad" = 0 ]
