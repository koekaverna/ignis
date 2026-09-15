#!/usr/bin/env bash
# N concurrent requests that each sleep MS on the server; prints total wall time.
# Usage: bench/ab-sleep.sh URL_BASE N MS
set -euo pipefail
BASE="${1:-http://127.0.0.1:8080}"; N="${2:-1000}"; MS="${3:-1000}"
ulimit -n 65535 2>/dev/null || true
ab -q -k -c "$N" -n "$N" "$BASE/sleep?ms=$MS" 2>&1 | grep -E "Time taken|Complete requests|Failed requests|Non-2xx|Requests per second|99%|100%"
