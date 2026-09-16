#!/usr/bin/env bash
# app-check: "is this deployment actually working?" — the MVP acceptance check for any
# Ignis-served Symfony app. Point it at a running instance (arg 1 = base URL) or let it start
# one itself from APP_DIR exactly as a deploy would (APP_ENV=test so it never touches a running
# dev instance's cache). See CLAUDE.md, bench/e8-symfony.sh, bench/e13-http.sh, V-27/V-40/V-53.
set -uo pipefail
cd "$(dirname "$0")/.."
REPO="$(pwd)"
BIN="$REPO/target/release/ignis"
export LD_LIBRARY_PATH=${LD_LIBRARY_PATH:-/opt/php85-zts/lib}

APP_DIR="${APP_DIR:-/home/koe/projects/symfony-ignis}"
PORT="${PORT:-8189}"
ROUTE="${ROUTE:-/}"                          # the app's own route to check
ROUTE_EXPECT="${ROUTE_EXPECT:-\"hello\":\"ignis\"}"  # substring expected in its body
ROUTE_404="${ROUTE_404:-/__app_check_missing_route__}"

STARTED_SERVER=0
if [ $# -ge 1 ]; then
  BASE="$1"
else
  BASE="http://127.0.0.1:$PORT"
  STARTED_SERVER=1
fi

SRV=""
LOG=$(mktemp)
cleanup() {
  if [ "$STARTED_SERVER" = 1 ] && [ -n "$SRV" ]; then
    kill "$SRV" 2>/dev/null
    wait "$SRV" 2>/dev/null || true
  fi
  rm -f "$LOG"
}
trap cleanup EXIT INT TERM

pass=0 fail=0
check() {  # check <label> <ok:0/1> <detail>
  if [ "$2" = 1 ]; then echo "PASS $1: $3"; pass=$((pass+1))
  else echo "FAIL $1: $3"; fail=$((fail+1)); fi
}

if [ "$STARTED_SERVER" = 1 ]; then
  echo "== starting server: $APP_DIR (APP_ENV=test) on $BASE"
  (
    cd "$APP_DIR" && \
    APP_ENV=test APP_DEBUG=0 APP_RUNTIME='Ignis\Symfony\IgnisRuntime' \
    IGNIS_LISTEN="127.0.0.1:$PORT" LD_LIBRARY_PATH="$LD_LIBRARY_PATH" \
    "$BIN" public/index.php
  ) > "$LOG" 2>&1 &
  SRV=$!
  up=0
  for _ in $(seq 1 100); do
    curl -sf "$BASE/_ignis/health" 2>/dev/null | grep -q '"status":"ok"' && { up=1; break; }
    kill -0 "$SRV" 2>/dev/null || break
    sleep 0.1
  done
  if [ "$up" != 1 ]; then
    echo "server never answered /_ignis/health on $BASE; log:"
    tail -n 40 "$LOG"
    exit 1
  fi
  echo "server pid=$SRV"
fi

# 1. health
body=$(curl -s "$BASE/_ignis/health"); code=$(curl -s -o /dev/null -w '%{http_code}' "$BASE/_ignis/health")
[ "$code" = 200 ] && echo "$body" | grep -q '"status":"ok"'; ok=$([ "$code" = 200 ] && echo "$body" | grep -q '"status":"ok"' && echo 1 || echo 0)
check "health" "$ok" "code=$code body=$body"

# 2. the app's own route
body=$(curl -s "$BASE$ROUTE"); code=$(curl -s -o /dev/null -w '%{http_code}' "$BASE$ROUTE")
ok=$([ "$code" = 200 ] && echo "$body" | grep -qF "$ROUTE_EXPECT" && echo 1 || echo 0)
check "route $ROUTE" "$ok" "code=$code body=$body"

# 3. a 404 route
code=$(curl -s -o /dev/null -w '%{http_code}' "$BASE$ROUTE_404")
ok=$([ "$code" = 404 ] && echo 1 || echo 0)
check "404 route" "$ok" "code=$code"

# 4. a 1 MB body does not kill the server
BIG=$(mktemp); head -c 1000000 /dev/urandom > "$BIG"
code=$(curl -s -o /dev/null -w '%{http_code}' -X POST --data-binary "@$BIG" "$BASE$ROUTE")
rm -f "$BIG"
alive=1
if [ "$STARTED_SERVER" = 1 ]; then kill -0 "$SRV" 2>/dev/null || alive=0; fi
still_ok=$(curl -sf "$BASE/_ignis/health" 2>/dev/null | grep -q '"status":"ok"' && echo 1 || echo 0)
ok=$([ "$alive" = 1 ] && [ "$still_ok" = 1 ] && echo 1 || echo 0)
check "1MB body survives" "$ok" "post_code=$code process_alive=$alive health_after=$still_ok"

# 5. 200 sequential requests, all succeed; RSS first/last (report only, no threshold)
rss() { [ -n "$SRV" ] && awk '/VmRSS/{print $2}' "/proc/$SRV/status" 2>/dev/null; }
rss_first=$(rss)
seq_ok=0
for i in $(seq 1 200); do
  c=$(curl -s -o /dev/null -w '%{http_code}' "$BASE$ROUTE")
  [ "$c" = 200 ] && seq_ok=$((seq_ok+1))
done
rss_last=$(rss)
ok=$([ "$seq_ok" = 200 ] && echo 1 || echo 0)
check "200 sequential requests" "$ok" "succeeded=$seq_ok/200"
if [ -n "$rss_first" ] && [ -n "$rss_last" ]; then
  echo "INFO rss: first=${rss_first}kB last=${rss_last}kB delta=$((rss_last - rss_first))kB"
fi

# 6. 50 concurrent requests, all 200
OUT=$(mktemp -d)
pids=()
for i in $(seq 1 50); do
  curl -s -o /dev/null -w '%{http_code}' "$BASE$ROUTE" > "$OUT/$i" &
  pids+=($!)
done
for p in "${pids[@]}"; do wait "$p"; done
conc_ok=0
for i in $(seq 1 50); do [ "$(cat "$OUT/$i" 2>/dev/null)" = 200 ] && conc_ok=$((conc_ok+1)); done
rm -rf "$OUT"
ok=$([ "$conc_ok" = 50 ] && echo 1 || echo 0)
check "50 concurrent requests" "$ok" "succeeded=$conc_ok/50"

# 7. still alive at the end, answers health
alive=1
if [ "$STARTED_SERVER" = 1 ]; then kill -0 "$SRV" 2>/dev/null || alive=0; fi
final=$(curl -sf "$BASE/_ignis/health" 2>/dev/null | grep -q '"status":"ok"' && echo 1 || echo 0)
ok=$([ "$alive" = 1 ] && [ "$final" = 1 ] && echo 1 || echo 0)
check "alive + healthy at end" "$ok" "process_alive=$alive health=$final"

echo "app_check pass=$pass fail=$fail"
[ "$fail" = 0 ]
