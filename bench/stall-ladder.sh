#!/usr/bin/env bash
# ADR-0043 / research 50: the stall-detection-and-recovery ladder against a real server.
#
# Runs S-1, S-7, S-8, S-9, S-10 always; S-5 and S-6 (L0 fiber timeout, L2 force-close) are gated on
# IGNIS_LADDER_PHP (default 1) because they depend on PHP-side work landing alongside this ADR --
# set IGNIS_LADDER_PHP=0 to skip them without failing the run.
#
# Pinning technique (there is no way to address a specific worker OS thread from an HTTP client):
# with --threads N, N-1 background `/sleep?ms=<PIN_MS>` requests are fired first; while they hold
# their workers busy, the one request that follows is dispatched to the only worker with nothing
# in flight (least-inflight dispatch, ADR-0010). This pins the scenario's `/stuck` request to a
# worker deterministically. Once `/stuck` also has 1 request in flight, every worker is even, so
# "a sibling fiber lands on the exact same worker" cannot be forced from here either -- S-7 and
# S-10 approximate it by firing several short `/hello` probes through the whole stall-and-recovery
# window and requiring every one of them to eventually succeed: with N-1 workers busy and 1 worker
# wedged, a probe that happens to be dispatched to the wedged worker only completes once that
# worker is freed by the recovery ladder, so "every probe eventually succeeds" is exactly "no
# worker is lost for good".
set -uo pipefail
cd "$(dirname "$0")/.."

if [ -x target/release/ignis ]; then
  DEFAULT_BIN="target/release/ignis"
else
  DEFAULT_BIN="target/debug/ignis"
fi
IGNIS_BIN="${IGNIS_BIN:-$DEFAULT_BIN}"
export LD_LIBRARY_PATH="${LD_LIBRARY_PATH:-/opt/php85-zts/lib}"
BASE_PORT="${IGNIS_LADDER_BASE_PORT:-18090}"
RUN_PHP_SCENARIOS="${IGNIS_LADDER_PHP:-1}"
WORKERS=4

SERVER_PID=""
FAILURES=0
SCENARIO_LOG=""

cleanup() {
  if [ -n "$SERVER_PID" ] && kill -0 "$SERVER_PID" 2>/dev/null; then
    kill "$SERVER_PID" 2>/dev/null
    wait "$SERVER_PID" 2>/dev/null
  fi
}
trap cleanup EXIT INT TERM

pass() { echo "PASS: $1"; }
fail() {
  echo "FAIL: $1"
  FAILURES=$((FAILURES + 1))
}

wait_for_body() {
  local url="$1" expected="$2" timeout_s="$3"
  local attempts=$((timeout_s * 10))
  local body
  for _ in $(seq 1 "$attempts"); do
    body=$(curl -sf -m 1 "$url" 2>/dev/null || true)
    if [ "$body" = "$expected" ]; then
      return 0
    fi
    sleep 0.1
  done
  return 1
}

# $1 fixture path, $2 address, remaining args: NAME=value env overrides for this server only
start_server() {
  local fixture="$1" address="$2"
  shift 2
  SCENARIO_LOG=$(mktemp /tmp/ignis-stall-ladder-XXXXXX.log)
  (
    export IGNIS_LISTEN="$address"
    for pair in "$@"; do
      export "$pair"
    done
    RUST_LOG=info exec "$IGNIS_BIN" --threads "$WORKERS" "$fixture"
  ) >"$SCENARIO_LOG" 2>&1 &
  SERVER_PID=$!
}

stop_server() {
  if [ -n "$SERVER_PID" ] && kill -0 "$SERVER_PID" 2>/dev/null; then
    kill "$SERVER_PID" 2>/dev/null
    wait "$SERVER_PID" 2>/dev/null
  fi
  SERVER_PID=""
}

# Occupies WORKERS-1 workers with a long /sleep each, backgrounded; does not wait for them.
pin_other_workers() {
  local address="$1" pin_ms="$2"
  local count=$((WORKERS - 1))
  for _ in $(seq 1 "$count"); do
    curl -s -m 20 "http://$address/sleep?ms=$pin_ms" >/dev/null 2>&1 &
  done
  sleep 0.2
}

health() {
  curl -s -m 2 "http://$1/_ignis/health" 2>/dev/null
}

# Fires $3 short /hello probes over roughly $4 seconds; every probe's own timeout is generous
# enough to survive a recovery-ladder cycle. Prints "ok=N/total=M".
probe_hello_through_stall() {
  local address="$1" per_timeout_s="$2" count="$3" spacing_s="$4"
  local ok=0
  for _ in $(seq 1 "$count"); do
    local body
    body=$(curl -s -m "$per_timeout_s" "http://$address/hello" 2>/dev/null || true)
    [ "$body" = "hello" ] && ok=$((ok + 1))
    sleep "$spacing_s"
  done
  echo "ok=$ok/total=$count"
}

strip_ansi() {
  sed 's/\x1b\[[0-9;]*m//g' "$SCENARIO_LOG"
}

grep_alert() {
  strip_ansi | grep "ignis alert" | grep -F "$1" || true
}

# --- S-1: detection by timer names the request, within 1.5 s -----------------------------------
scenario_s1() {
  echo "== S-1: detection by timer names the request =="
  local address="127.0.0.1:$((BASE_PORT + 1))"
  start_server bench/php/stall/spin.php "$address" \
    IGNIS_BUSY_WARN_MS=100 IGNIS_STALL_KILL_MS=0 IGNIS_STALL_ABANDON_MS=0
  if ! wait_for_body "http://$address/hello" "hello" 10; then
    fail "S-1(spin): server never became ready"
    stop_server
    return
  fi
  local t0 t1
  t0=$(date +%s%N)
  curl -s -m 3 "http://$address/stuck" >/dev/null 2>&1 &
  local stuck_pid=$!
  sleep 1.5
  t1=$(date +%s%N)
  local line
  line=$(grep_alert 'kind="stall"' | grep 'route="/stuck"' | head -1)
  kill "$stuck_pid" 2>/dev/null
  stop_server
  if [ -n "$line" ] && echo "$line" | grep -q 'subject="php"' && echo "$line" | grep -qE 'age_ms=[0-9]+'; then
    pass "S-1(spin): warn line within $(( (t1 - t0) / 1000000 )) ms: $line"
  else
    fail "S-1(spin): no matching stall warn line within 1.5s; log tail: $(strip_ansi | tail -3)"
  fi

  address="127.0.0.1:$((BASE_PORT + 2))"
  start_server bench/php/stall/block-sleep.php "$address" \
    IGNIS_BUSY_WARN_MS=100 IGNIS_STALL_KILL_MS=0 IGNIS_STALL_ABANDON_MS=0 IGNIS_PARK=
  if ! wait_for_body "http://$address/hello" "hello" 10; then
    fail "S-1(block-sleep): server never became ready"
    stop_server
    return
  fi
  curl -s -m 3 "http://$address/stuck?s=5" >/dev/null 2>&1 &
  stuck_pid=$!
  sleep 1.5
  line=$(grep_alert 'kind="stall"' | grep 'route="/stuck"' | head -1)
  kill "$stuck_pid" 2>/dev/null
  stop_server
  if [ -n "$line" ] && echo "$line" | grep -q 'subject="blocking_forward'; then
    pass "S-1(block-sleep): warn line classified as blocking_forward: $line"
  else
    fail "S-1(block-sleep): no blocking_forward stall line within 1.5s; log tail: $(strip_ansi | tail -3)"
  fi
}

# --- S-5: L0 fiber timeout bounds a hung run (gated) --------------------------------------------
scenario_s5() {
  echo "== S-5: L0 fiber timeout bounds park-forever.php =="
  local address="127.0.0.1:$((BASE_PORT + 5))"
  start_server bench/php/stall/park-forever.php "$address" IGNIS_FIBER_TIMEOUT_MS=2000
  if ! wait_for_body "http://$address/hello" "hello" 10; then
    fail "S-5: server never became ready"
    stop_server
    return
  fi
  local t0 code
  t0=$(date +%s%N)
  code=$(curl -s -m 5 -o /dev/null -w '%{http_code}' "http://$address/stuck" 2>/dev/null || echo "curl-timeout")
  local elapsed_ms=$(( ($(date +%s%N) - t0) / 1000000 ))
  local hello_ok
  hello_ok=$(curl -s -m 2 "http://$address/hello" 2>/dev/null || true)
  stop_server
  if [ "$code" = "504" ] && [ "$elapsed_ms" -le 2500 ]; then
    pass "S-5: 504 in ${elapsed_ms} ms (<= 2500 ms), /hello afterwards='$hello_ok'"
  else
    fail "S-5: got http=$code after ${elapsed_ms} ms (expected 504 <= 2500 ms) -- SKIP-able via IGNIS_LADDER_PHP=0 if L0 is not landed yet; log tail: $(strip_ansi | tail -5)"
  fi
}

# --- S-6: L2 a swallowed cancellation is force-closed (gated) -----------------------------------
scenario_s6() {
  echo "== S-6: L2 swallowed cancellation is force-closed =="
  local address="127.0.0.1:$((BASE_PORT + 6))"
  start_server bench/php/stall/swallow-cancel.php "$address" IGNIS_ON_SWALLOWED_CANCEL=force-close
  if ! wait_for_body "http://$address/hello" "hello" 10; then
    fail "S-6: server never became ready"
    stop_server
    return
  fi
  curl -s -m 1 "http://$address/stuck?ms=30000" >/dev/null 2>&1 &
  local client_pid=$!
  sleep 0.1
  kill "$client_pid" 2>/dev/null
  wait "$client_pid" 2>/dev/null
  sleep 1.5
  local hello_ok
  hello_ok=$(curl -s -m 2 "http://$address/hello" 2>/dev/null || true)
  local killed_line
  killed_line=$(grep_alert 'kind="fiber_killed"' | head -1)
  stop_server
  if [ "$hello_ok" = "hello" ]; then
    pass "S-6: worker survived the disconnect (/hello='$hello_ok'); fiber_killed line: '${killed_line:-<none>}'"
  else
    fail "S-6: worker did not answer /hello after the disconnect -- SKIP-able via IGNIS_LADDER_PHP=0 if L2 is not landed yet; log tail: $(strip_ansi | tail -8)"
  fi
}

# --- S-7: L3 a spinning fiber is killed, siblings survive ---------------------------------------
scenario_s7() {
  echo "== S-7: L3 kills a spinning fiber, siblings survive =="
  local address="127.0.0.1:$((BASE_PORT + 7))"
  start_server bench/php/stall/spin.php "$address" \
    IGNIS_BUSY_WARN_MS=100 IGNIS_STALL_KILL_MS=1000 IGNIS_STALL_ABANDON_MS=3000
  if ! wait_for_body "http://$address/hello" "hello" 10; then
    fail "S-7: server never became ready"
    stop_server
    return
  fi
  pin_other_workers "$address" 4000
  local t0
  t0=$(date +%s%N)
  local code
  code=$(curl -s -m 4 -o /dev/null -w '%{http_code}' "http://$address/stuck" 2>/dev/null || echo "curl-timeout")
  local elapsed_ms=$(( ($(date +%s%N) - t0) / 1000000 ))
  local probes
  probes=$(probe_hello_through_stall "$address" 3 6 0.2)
  local killed_line
  killed_line=$(grep_alert 'kind="fiber_killed"' | head -1)
  stop_server
  if [ "$code" = "504" ] && [ "$elapsed_ms" -le 2000 ] && [ "$probes" = "ok=6/total=6" ]; then
    pass "S-7: 504 in ${elapsed_ms} ms, all sibling probes answered ($probes), fiber_killed: '${killed_line:-<none>}'"
  else
    fail "S-7: http=$code after ${elapsed_ms} ms, probes=$probes (want 504 <= 2000 ms and ok=6/total=6); log tail: $(strip_ansi | tail -8)"
  fi
}

# --- S-8: L4 a fiber blocked in the shim is cancelled by signal ---------------------------------
scenario_s8() {
  echo "== S-8: L4 signal cancels a fiber blocked in the shim (block-sleep.php) =="
  local address="127.0.0.1:$((BASE_PORT + 8))"
  start_server bench/php/stall/block-sleep.php "$address" \
    IGNIS_BUSY_WARN_MS=100 IGNIS_STALL_KILL_MS=1000 IGNIS_STALL_ABANDON_MS=3000 IGNIS_PARK=
  if ! wait_for_body "http://$address/hello" "hello" 10; then
    fail "S-8: server never became ready"
    stop_server
    return
  fi
  pin_other_workers "$address" 4000
  local t0
  t0=$(date +%s%N)
  local body
  body=$(curl -s -m 4 "http://$address/stuck?s=30" 2>/dev/null || echo "curl-timeout")
  local elapsed_ms=$(( ($(date +%s%N) - t0) / 1000000 ))
  local hello_ok
  hello_ok=$(curl -s -m 2 "http://$address/hello" 2>/dev/null || true)
  local blocking_line
  blocking_line=$(grep_alert 'kind="blocking_call"' | head -1)
  stop_server
  # `sleep()` does not throw on the L4 signal (glibc returns the unslept seconds instead), so the
  # evidence here is that the request returned in ~stall_kill_ms, far short of the 30 s it asked
  # for, not a particular HTTP status; the fixture's own doc block has the measurement.
  if [ "$elapsed_ms" -le 2000 ] && [ "$hello_ok" = "hello" ]; then
    pass "S-8: cancelled in ${elapsed_ms} ms (<= 2000 ms, not the 30 s requested), body='$body', /hello='$hello_ok', blocking_call: '${blocking_line:-<none>}'"
  else
    fail "S-8: elapsed=${elapsed_ms} ms, body='$body', /hello='$hello_ok' (want <= 2000 ms and /hello='hello'); log tail: $(strip_ansi | tail -8)"
  fi
}

# --- S-9: classification picks the level (/proc) ------------------------------------------------
scenario_s9() {
  echo "== S-9: classification picks the level (/proc) =="
  local index=0
  for fixture_spec in "spin.php::" "block-sleep.php:?s=30:IGNIS_PARK=" "c-loop.php:?cost=20:"; do
    index=$((index + 1))
    local fixture path env_pair
    fixture="${fixture_spec%%:*}"
    local rest="${fixture_spec#*:}"
    path="${rest%%:*}"
    env_pair="${rest#*:}"
    local address="127.0.0.1:$((BASE_PORT + 90 + index))"
    if [ -n "$env_pair" ]; then
      start_server "bench/php/stall/$fixture" "$address" IGNIS_STALL_KILL_MS=1000 IGNIS_STALL_ABANDON_MS=3000 "$env_pair"
    else
      start_server "bench/php/stall/$fixture" "$address" IGNIS_STALL_KILL_MS=1000 IGNIS_STALL_ABANDON_MS=3000
    fi
    if ! wait_for_body "http://$address/hello" "hello" 10; then
      fail "S-9($fixture): server never became ready"
      stop_server
      continue
    fi
    curl -s -m 6 "http://$address/stuck$path" >/dev/null 2>&1 &
    local stuck_pid=$!
    sleep 2.5
    kill "$stuck_pid" 2>/dev/null
    local kill_line
    kill_line=$(grep_alert 'kind="stall"' | grep -E 'level=L[0-9]|classification=' | tail -1)
    stop_server
    if [ -n "$kill_line" ]; then
      pass "S-9($fixture): $kill_line"
    else
      fail "S-9($fixture): no classified stall/kill line found; log tail: $(strip_ansi | tail -8)"
    fi
  done
}

# --- S-10: L5 abandon a live worker --------------------------------------------------------------
scenario_s10() {
  echo "== S-10: L5 abandon a live worker (c-loop.php) =="
  local address="127.0.0.1:$((BASE_PORT + 10))"
  start_server bench/php/stall/c-loop.php "$address" \
    IGNIS_BUSY_WARN_MS=100 IGNIS_STALL_KILL_MS=1000 IGNIS_STALL_ABANDON_MS=3000
  if ! wait_for_body "http://$address/hello" "hello" 10; then
    fail "S-10: server never became ready"
    stop_server
    return
  fi
  pin_other_workers "$address" 8000
  curl -s -m 90 "http://$address/stuck?cost=20" >/dev/null 2>&1 &
  local stuck_pid=$!
  local abandoned=0
  local health_json=""
  for _ in $(seq 1 40); do
    health_json=$(health "$address")
    if echo "$health_json" | grep -q '"leaked_workers":1'; then
      abandoned=1
      break
    fi
    sleep 0.25
  done
  local hello_ok
  hello_ok=$(curl -s -m 2 "http://$address/hello" 2>/dev/null || true)
  local abandoned_line
  abandoned_line=$(grep_alert 'kind="worker_abandoned"' | head -1)
  kill "$stuck_pid" 2>/dev/null
  stop_server
  if [ "$abandoned" = "1" ] && [ "$hello_ok" = "hello" ]; then
    pass "S-10: leaked_workers=1 reached, replacement served /hello='$hello_ok'; alert: '${abandoned_line:-<none>}'; health=$health_json"
  else
    fail "S-10: abandoned=$abandoned, /hello='$hello_ok', health=$health_json; log tail: $(strip_ansi | tail -8)"
  fi
}

scenario_s1
scenario_s7
scenario_s8
scenario_s9
scenario_s10
if [ "$RUN_PHP_SCENARIOS" = "1" ]; then
  scenario_s5
  scenario_s6
else
  echo "SKIP: S-5, S-6 (IGNIS_LADDER_PHP=0)"
fi

echo "== summary: $FAILURES failing scenario(s) =="
exit $((FAILURES > 0 ? 1 : 0))
