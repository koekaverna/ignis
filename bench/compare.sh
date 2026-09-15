#!/usr/bin/env bash
# E4: hello-world throughput, Ignis vs FrankenPHP worker mode vs php-fpm+nginx, same box, same libphp build.
# Usage: bench/compare.sh [wrk_threads] [conns] [duration]   (results appended to bench/results/compare.md)
set -uo pipefail
cd "$(dirname "$0")/.."
T="${1:-2}"; C="${2:-64}"; D="${3:-10s}"
ONLY="${ONLY:-ignis franken fpm}"   # space-separated subset to run
URL_PATH="${URL_PATH:-/}"            # e.g. /cpu for the CPU-bound route
IGNIS_THREADS_LIST="${IGNIS_THREADS_LIST:-1}"
OUT=bench/results/compare.md
SCRATCH="${SCRATCH:-/tmp}"
FRANKEN="${FRANKEN:-/opt/frankenphp-bin}"
FPM="${FPM:-/opt/php85-fpm/sbin/php-fpm}"

wait_port() { for _ in $(seq 1 100); do curl -sf "$1" >/dev/null 2>&1 && return 0; sleep 0.1; done; echo "server on $1 did not come up" >&2; return 1; }
run_wrk()   { wrk -t"$T" -c"$C" -d"$D" --latency "$1" | awk '/Requests\/sec/{rps=$2} /^ +50%/{p50=$2} /^ +99%/{p99=$2} /Non-2xx|Socket errors/{err=err" "$0} END{printf "%s | %s | %s |%s", rps, p50, p99, err}'; }
row()       { echo "| $1 | $2 |" | tee -a "$OUT"; }

{
  echo; echo "### $(date -u +%Y-%m-%dT%H:%M:%SZ) wrk -t$T -c$C -d$D, $(nproc) vCPU, $(grep -m1 'model name' /proc/cpuinfo | cut -d: -f2 | xargs)"
  echo "path: $URL_PATH"; echo; echo "| server | req/s | p50 | p99 | errors |"; echo "|---|---|---|---|---|"
} >> "$OUT"

# --- Ignis, 1 PHP thread
[[ " $ONLY " == *" ignis "* ]] && for IT in $IGNIS_THREADS_LIST; do
./target/release/ignis --threads "$IT" examples/hello_server.php >"$SCRATCH/ignis-bench.log" 2>&1 & PID=$!
wait_port http://127.0.0.1:8080/ && row "ignis ($IT PHP thread(s) + 2 tokio) $URL_PATH" "$(run_wrk http://127.0.0.1:8080$URL_PATH)"
kill $PID; wait $PID 2>/dev/null
done

# --- FrankenPHP worker mode, 1 and N worker threads
[[ " $ONLY " == *" franken "* ]] && for W in 1 $(nproc); do
  NUM_THREADS=$((W + 1)) WORKER_NUM=$W "$FRANKEN" run --config bench/frankenphp/Caddyfile >"$SCRATCH/franken-bench.log" 2>&1 & PID=$!
  wait_port http://127.0.0.1:8081/ && row "frankenphp worker (num=$W, num_threads=$((W + 1))) $URL_PATH" "$(run_wrk http://127.0.0.1:8081$URL_PATH)"
  kill $PID; wait $PID 2>/dev/null
done

# --- php-fpm (NTS) + nginx, 1 and N children
[[ " $ONLY " == *" fpm "* ]] && for CH in 1 $(nproc); do
  sed "s/\${FPM_CHILDREN}/$CH/" bench/fpm/php-fpm.conf > "$SCRATCH/php-fpm.conf"
  "$FPM" -R -y "$SCRATCH/php-fpm.conf" -p "$SCRATCH" >"$SCRATCH/fpm-bench.log" 2>&1 & FPID=$!
  nginx -c "$PWD/bench/fpm/nginx.conf" >"$SCRATCH/nginx-bench.log" 2>&1 & NPID=$!
  wait_port http://127.0.0.1:8082/ && row "php-fpm (pm.max_children=$CH) + nginx $URL_PATH" "$(run_wrk http://127.0.0.1:8082$URL_PATH)"
  kill $NPID $FPID; wait $NPID $FPID 2>/dev/null
done
echo "results appended to $OUT"
