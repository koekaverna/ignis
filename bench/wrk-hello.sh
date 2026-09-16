#!/usr/bin/env bash
# Hello-world throughput against a running server. Usage: bench/wrk-hello.sh URL [threads] [conns] [secs]
set -euo pipefail
URL="${1:-http://${IGNIS_LISTEN:-127.0.0.1:8080}/}"; T="${2:-2}"; C="${3:-64}"; D="${4:-10s}"
wrk -t"$T" -c"$C" -d"$D" --latency "$URL"
