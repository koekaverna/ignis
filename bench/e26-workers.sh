#!/usr/bin/env bash
# E26 (ADR-0044 draft): compares deployment shapes for examples/hello_server.php on the hello
# ("/") and cpu ("/cpu", ~0.3 ms of md5 work per request) routes -- process-per-worker
# (--workers N) against thread-per-worker (--threads M) against the NTS engine ABI. For every
# arm this measures wrk throughput and latency on both routes and resident memory (the master
# process plus every forked worker), REPS times, cycling through all arms once per rep so
# machine drift lands on every arm evenly rather than piling onto whichever arm runs last.
#
# Usage: bench/e26-workers.sh
# Env: IGNIS_LISTEN (default 127.0.0.1:8260), BIN_ZTS (default ./target/release/ignis),
#      BIN_NTS (default ./target-nts/release/ignis), ZTS_LIBRARY_PATH (default
#      /opt/php85-zts/lib), WRK_THREADS (default 2), CONNS (default 64), DUR (default 10s),
#      ROUTES (default "/ /cpu"), REPS (default 3)
set -uo pipefail
cd "$(dirname "$0")/.."

ADDR="${IGNIS_LISTEN:-127.0.0.1:8260}"
BIN_ZTS="${BIN_ZTS:-./target/release/ignis}"
BIN_NTS="${BIN_NTS:-./target-nts/release/ignis}"
ZTS_LIBRARY_PATH="${ZTS_LIBRARY_PATH:-/opt/php85-zts/lib}"
WRK_THREADS="${WRK_THREADS:-2}"
CONNS="${CONNS:-64}"
DUR="${DUR:-10s}"
ROUTES="${ROUTES:-/ /cpu}"
REPS="${REPS:-3}"

ARMS_SPEC=(
  "zts-t4|zts|--workers 1 --threads 4"
  "zts-w4|zts|--workers 4 --threads 1"
  "nts-w4|nts|--workers 4"
  "zts-t1|zts|--workers 1 --threads 1"
  "nts-w1|nts|--workers 1"
)

binaryForEngine() { [ "$1" = zts ] && echo "$BIN_ZTS" || echo "$BIN_NTS"; }

reportEngineVersion() {  # $1=engine $2=binary
  local engine="$1" binary="$2"
  if [ ! -x "$binary" ]; then
    echo "  $binary: not built"
    return
  fi
  if [ "$engine" = zts ]; then
    echo "  $binary: $(LD_LIBRARY_PATH="$ZTS_LIBRARY_PATH" "$binary" --version 2>&1 | tr '\n' ' ')"
  else
    echo "  $binary: $(env -u LD_LIBRARY_PATH "$binary" --version 2>&1 | tr '\n' ' ')"
  fi
}

startServer() {  # $1=label $2=engine $3=flags(space separated) $4=logfile; sets SERVER_PID
  local label="$1" engine="$2" flags="$3" logFile="$4"
  local binary; binary=$(binaryForEngine "$engine")
  local flagArray=(); read -ra flagArray <<< "$flags"
  if [ "$engine" = zts ]; then
    env LD_LIBRARY_PATH="$ZTS_LIBRARY_PATH" IGNIS_LISTEN="$ADDR" \
      "$binary" "${flagArray[@]}" examples/hello_server.php >"$logFile" 2>&1 &
  else
    env -u LD_LIBRARY_PATH IGNIS_LISTEN="$ADDR" \
      "$binary" "${flagArray[@]}" examples/hello_server.php >"$logFile" 2>&1 &
  fi
  SERVER_PID=$!
}

waitForServerReady() {  # $1=logfile; polls up to 10s
  local logFile="$1"
  for _ in $(seq 1 100); do
    curl -sf -m 2 "http://$ADDR/" 2>/dev/null | grep -q "Hello, World!" && return 0
    kill -0 "$SERVER_PID" 2>/dev/null || { echo "  server exited before answering"; tail -5 "$logFile"; return 1; }
    sleep 0.1
  done
  echo "  server did not answer within 10s"
  tail -5 "$logFile"
  return 1
}

stopServer() {
  kill "$SERVER_PID" 2>/dev/null
  wait "$SERVER_PID" 2>/dev/null
  sleep 1
}

residentMemoryKilobytes() {  # $1=pid
  awk '/VmRSS/{print $2; found=1} END{if (!found) print 0}' "/proc/$1/status" 2>/dev/null || echo 0
}

measureResidentMemory() {  # sets RSS_MASTER_KB RSS_WORKERS_KB RSS_TOTAL_KB WORKER_COUNT
  RSS_MASTER_KB=$(residentMemoryKilobytes "$SERVER_PID")
  RSS_WORKERS_KB=0
  WORKER_COUNT=0
  for workerPid in $(pgrep -P "$SERVER_PID" 2>/dev/null); do
    RSS_WORKERS_KB=$((RSS_WORKERS_KB + $(residentMemoryKilobytes "$workerPid")))
    WORKER_COUNT=$((WORKER_COUNT + 1))
  done
  RSS_TOTAL_KB=$((RSS_MASTER_KB + RSS_WORKERS_KB))
}

runRouteAndReport() {  # $1=label $2=route $3=rep; appends to RESULTS_FILE
  local label="$1" route="$2" rep="$3"
  local output; output=$(wrk -t"$WRK_THREADS" -c"$CONNS" -d"$DUR" --latency "http://$ADDR$route" 2>&1)
  local requestsPerSecond p50Latency p99Latency non2xxCount
  requestsPerSecond=$(awk '/Requests\/sec:/{print $2}' <<< "$output")
  p50Latency=$(awk '/^[[:space:]]*50%/{print $2}' <<< "$output")
  p99Latency=$(awk '/^[[:space:]]*99%/{print $2}' <<< "$output")
  non2xxCount=$(awk '/Non-2xx or 3xx responses/{print $NF}' <<< "$output")
  non2xxCount="${non2xxCount:-0}"
  echo "e26: arm=$label route=$route rep=$rep rps=${requestsPerSecond:-0} p50=${p50Latency:-?} p99=${p99Latency:-?} non2xx=$non2xxCount"
  echo "$label|$route|$rep|${requestsPerSecond:-0}" >> "$RESULTS_FILE"
}

median() {  # reads one number per line on stdin
  sort -n | awk '{values[NR]=$1; count=NR} END{
    if (count == 0) { print "n/a"; exit }
    if (count % 2 == 1) { printf "%s", values[(count + 1) / 2] }
    else { printf "%.2f", (values[count / 2] + values[count / 2 + 1]) / 2 }
  }'
}

echo "== e26-workers: deployment shapes on $ADDR, nproc=$(nproc)"
echo "load before: $(uptime | sed 's/.*load average/load average/')"
reportEngineVersion zts "$BIN_ZTS"
reportEngineVersion nts "$BIN_NTS"

availableArms=()
for armSpec in "${ARMS_SPEC[@]}"; do
  IFS='|' read -r label engine flags <<< "$armSpec"
  binary=$(binaryForEngine "$engine")
  if [ -x "$binary" ]; then
    availableArms+=("$armSpec")
  else
    echo "e26: arm=$label skipped: $binary not found (build it first)"
  fi
done

if [ "${#availableArms[@]}" -eq 0 ]; then
  echo "e26: no binaries available, nothing to measure"
  exit 1
fi

RESULTS_FILE=$(mktemp)
RSS_FILE=$(mktemp)
trap 'rm -f "$RESULTS_FILE" "$RSS_FILE"' EXIT

for rep in $(seq 1 "$REPS"); do
  for armSpec in "${availableArms[@]}"; do
    IFS='|' read -r label engine flags <<< "$armSpec"
    logFile="/tmp/e26-${label}-rep${rep}.log"
    startServer "$label" "$engine" "$flags" "$logFile"
    if ! waitForServerReady "$logFile"; then
      echo "e26: arm=$label rep=$rep skipped: server failed to start"
      stopServer
      continue
    fi
    serverAlive=1
    for route in $ROUTES; do
      if ! kill -0 "$SERVER_PID" 2>/dev/null; then
        echo "e26: arm=$label rep=$rep route=$route server died mid-run, continuing with the next arm"
        serverAlive=0
        break
      fi
      runRouteAndReport "$label" "$route" "$rep"
    done
    if [ "$serverAlive" = 1 ]; then
      measureResidentMemory
      echo "e26: arm=$label rep=$rep rss_master_kb=$RSS_MASTER_KB rss_workers_kb=$RSS_WORKERS_KB rss_total_kb=$RSS_TOTAL_KB workers=$WORKER_COUNT"
      echo "$label|$rep|$RSS_TOTAL_KB" >> "$RSS_FILE"
    fi
    stopServer
  done
done

echo "load after: $(uptime | sed 's/.*load average/load average/')"

echo "== e26 summary (median across $REPS reps)"
echo "| arm | route | median rps | median rss_total_kb |"
echo "|---|---|---|---|"
for armSpec in "${availableArms[@]}"; do
  IFS='|' read -r label engine flags <<< "$armSpec"
  medianRssTotalKilobytes=$(awk -F'|' -v arm="$label" '$1 == arm {print $3}' "$RSS_FILE" | median)
  for route in $ROUTES; do
    medianRequestsPerSecond=$(awk -F'|' -v arm="$label" -v routePath="$route" '$1 == arm && $2 == routePath {print $4}' "$RESULTS_FILE" | median)
    echo "| $label | $route | $medianRequestsPerSecond | $medianRssTotalKilobytes |"
  done
done
