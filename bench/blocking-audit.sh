#!/usr/bin/env bash
# ADR-0043 §5 / research 50 S-2: the blocking-detector load-test instrument, run against a real
# server script. Starts $1 (default examples/app.php) under `profile = load-test`, `IGNIS_BLOCKING=
# strict`, drives every route in $2 (one path per line; default: routes grepped out of the script
# plus `/`) with `wrk` when it is installed or a `curl` loop otherwise, asks the running process for
# its blocking report over `SIGUSR2`, and diffs the report's sites against $ALLOW (default
# bench/results/blocking-allow.txt, created empty if missing; ADR-0043 §8 `library:symbol[@route-
# prefix]` patterns). Exits non-zero on any site/route not covered by the allow file -- research
# 50's "the allow file starts empty and must stay empty" gate for `examples/app.php`.
set -uo pipefail
cd "$(dirname "$0")/.."

SCRIPT="${1:-examples/app.php}"
ROUTE_FILE="${2:-}"
DURATION="${DURATION:-10}"
CONCURRENCY="${CONCURRENCY:-8}"
ALLOW="${ALLOW:-bench/results/blocking-allow.txt}"
REPORT_PATH="${IGNIS_BLOCKING_REPORT_PATH:-/tmp/ignis-blocking-report.json}"

if [ -x target/release/ignis ]; then
  DEFAULT_BIN="target/release/ignis"
else
  DEFAULT_BIN="target/debug/ignis"
fi
IGNIS_BIN="${IGNIS_BIN:-$DEFAULT_BIN}"
export LD_LIBRARY_PATH="${LD_LIBRARY_PATH:-/opt/php85-zts/lib}"
ADDR="${IGNIS_LISTEN:-127.0.0.1:18199}"

[ -f "$ALLOW" ] || { mkdir -p "$(dirname "$ALLOW")"; : >"$ALLOW"; }

SERVER_PID=""
cleanup() {
  if [ -n "$SERVER_PID" ] && kill -0 "$SERVER_PID" 2>/dev/null; then
    kill "$SERVER_PID" 2>/dev/null
    wait "$SERVER_PID" 2>/dev/null
  fi
}
trap cleanup EXIT INT TERM

# Route keys of a `match`/array table, single- or double-quoted. A router that builds its paths
# some other way is not found here: pass a route file as $2, or the audit drives `/` alone and
# says so -- an audit that never drove a route cannot vouch for it.
routes_from_script() {
  grep -oE "^\s*['\"]/[^'\"]*['\"]\s*=>" "$SCRIPT" 2>/dev/null | grep -oE "['\"]/[^'\"]*['\"]" | tr -d "'\"" | sort -u
}

if [ -n "$ROUTE_FILE" ] && [ -f "$ROUTE_FILE" ]; then
  mapfile -t ROUTES <"$ROUTE_FILE"
else
  mapfile -t ROUTES < <(routes_from_script)
  if [ "${#ROUTES[@]}" -eq 0 ]; then
    echo "WARNING: no route keys found in $SCRIPT; auditing / only -- pass a route file (one path per line) as \$2 to drive the real routes" >&2
  fi
  ROUTES+=("/")
fi
if [ "${#ROUTES[@]}" -eq 0 ]; then
  ROUTES=("/")
fi
echo "routes under audit: ${ROUTES[*]}"

LOG_FILE=$(mktemp /tmp/ignis-blocking-audit-XXXXXX.log)
rm -f "$REPORT_PATH"
IGNIS_LISTEN="$ADDR" IGNIS_PROFILE=load-test IGNIS_BLOCKING=strict IGNIS_BLOCKING_TRACE=1 \
  IGNIS_BLOCKING_REPORT="$REPORT_PATH" RUST_LOG=warn "$IGNIS_BIN" --threads 4 "$SCRIPT" \
  >"$LOG_FILE" 2>&1 &
SERVER_PID=$!

up=0
for _ in $(seq 1 100); do
  if curl -s -o /dev/null -m 1 -w '%{http_code}' "http://$ADDR/" 2>/dev/null | grep -qE '^[0-9]+$'; then
    up=1
    break
  fi
  sleep 0.1
done
if [ "$up" != 1 ] || ! kill -0 "$SERVER_PID" 2>/dev/null; then
  echo "server on $ADDR never answered (see $LOG_FILE)"
  cat "$LOG_FILE"
  exit 1
fi
echo "server up, pid=$SERVER_PID, log=$LOG_FILE"

if command -v wrk >/dev/null 2>&1; then
  # The whole drive takes ~$DURATION seconds regardless of how many routes there are: each route
  # gets an even slice of it, one after another, rather than $DURATION seconds each (which would
  # make the drive take route-count * $DURATION and starve the later routes of a fair audit).
  PER_ROUTE_DURATION=$(( DURATION / ${#ROUTES[@]} ))
  [ "$PER_ROUTE_DURATION" -lt 1 ] && PER_ROUTE_DURATION=1
  echo "driving ${#ROUTES[@]} routes with wrk, ${PER_ROUTE_DURATION}s each at concurrency $CONCURRENCY"
  for route in "${ROUTES[@]}"; do
    wrk -t2 -c"$CONCURRENCY" -d"${PER_ROUTE_DURATION}s" "http://$ADDR$route" >/dev/null 2>&1 || true
  done
else
  echo "wrk not installed; driving routes with a curl loop for ${DURATION}s at concurrency $CONCURRENCY"
  END=$((SECONDS + DURATION))
  PIDS=()
  for _ in $(seq 1 "$CONCURRENCY"); do
    (
      while [ "$SECONDS" -lt "$END" ]; do
        for route in "${ROUTES[@]}"; do
          curl -s -m 3 -o /dev/null "http://$ADDR$route" 2>/dev/null || true
        done
      done
    ) &
    PIDS+=($!)
  done
  for pid in "${PIDS[@]}"; do
    wait "$pid" 2>/dev/null || true
  done
fi

kill -USR2 "$SERVER_PID" 2>/dev/null
for _ in $(seq 1 30); do
  [ -s "$REPORT_PATH" ] && break
  sleep 0.1
done

if ! kill -0 "$SERVER_PID" 2>/dev/null; then
  echo "server died during the drive (IGNIS_BLOCKING=strict, mode=fatal would exit non-zero on the first unallowed site); log:"
  tail -30 "$LOG_FILE"
fi
kill "$SERVER_PID" 2>/dev/null
wait "$SERVER_PID" 2>/dev/null
SERVER_PID=""

if [ ! -s "$REPORT_PATH" ]; then
  echo "no blocking report was written at $REPORT_PATH; log tail:"
  tail -30 "$LOG_FILE"
  exit 1
fi

echo
echo "== blocking sites =="
python3 - "$REPORT_PATH" "$ALLOW" <<'PYEOF'
import json
import sys

report_path, allow_path = sys.argv[1], sys.argv[2]
with open(report_path) as handle:
    report = json.load(handle)
with open(allow_path) as handle:
    allow_lines = [line.strip() for line in handle if line.strip() and not line.strip().startswith('#')]

def parse_allow(line):
    if '@' in line:
        pattern, prefix = line.split('@', 1)
        return pattern, prefix
    return line, None

allow_patterns = [parse_allow(line) for line in allow_lines]

def route_allowed(site, route):
    for pattern, prefix in allow_patterns:
        if pattern != site:
            continue
        if prefix is None or route.startswith(prefix):
            return True
    return False

violations = []
print(f"{'site':<28}{'count':>8}{'max_us':>10}  routes")
for entry in report.get('blocking_sites', []):
    site = entry['site']
    routes = entry.get('routes', {})
    route_list = ', '.join(f"{route}:{count}" for route, count in routes.items())
    print(f"{site:<28}{entry['count']:>8}{entry['max_us']:>10}  {route_list}")
    trace = entry.get('first_trace') or []
    if trace:
        print(f"  first trace: {' <- '.join(trace)}")
    for route in routes:
        if not route_allowed(site, route):
            violations.append(f"{site}@{route}")

print()
print(f"mode={report.get('mode')} threshold_us={report.get('threshold_us')}")
if violations:
    print()
    print(f"NOT in {allow_path}:")
    for violation in violations:
        print(f"  {violation}")
    sys.exit(1)
print()
print(f"every site is covered by {allow_path}")
PYEOF
STATUS=$?

echo
if [ "$STATUS" -eq 0 ]; then
  echo "PASS: blocking-audit ($SCRIPT)"
else
  echo "FAIL: blocking-audit ($SCRIPT) -- unallowed blocking sites found"
fi
exit "$STATUS"
