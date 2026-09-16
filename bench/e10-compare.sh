#!/usr/bin/env bash
# E10 (H21c): same ghz load against Ignis (PHP handler), pure tonic (Rust handler, the ceiling) and RoadRunner grpc plugin (PHP workers).
# ext-grpc has no server; its build cost is reported separately (VALIDATION V-20).
set -uo pipefail
cd "$(dirname "$0")/.."
PATH="$(go env GOPATH 2>/dev/null)/bin:$PATH"
CONNS="${CONNS:-64}"; N="${N:-100000}"; PROTO=examples/grpc/greeter.proto
RR="${RR:-/tmp/cmp/rr}"; RR_APP="${RR_APP:-/tmp/cmp/rr-app}"
run_ghz() { # addr label
  ghz --insecure --proto "$PROTO" --call ignis.Greeter/SayHello -d '{"name":"ada"}' -c "$CONNS" -n "$N" --connections 8 "$1" 2>&1 \
    | awk -v L="$2" '/Requests\/sec/{r=$2} /Average:/{a=$2} /99 % in/{p=$4} /Slowest:/{s=$2} /\[OK\]/{ok=$2} /Error distribution/{err=1} END{printf "| %s | %s | %s ms | %s ms | %s ms | %s/'"$N"' |\n", L, r, a, p, s, ok}'
}
echo "| server | req/s | avg | p99 | max | OK |"; echo "|---|---|---|---|---|---|"
./target/release/ignis --threads 1 examples/grpc_server.php > /tmp/e10-ignis.log 2>&1 & P=$!; sleep 0.7
run_ghz 127.0.0.1:8080 "Ignis, PHP handler, 1 PHP thread"; kill $P; wait $P 2>/dev/null
./target/release/ignis --threads 4 examples/grpc_server.php > /tmp/e10-ignis.log 2>&1 & P=$!; sleep 0.7
run_ghz 127.0.0.1:8080 "Ignis, PHP handler, 4 PHP threads"; kill $P; wait $P 2>/dev/null
ADDR=127.0.0.1:8090 ./examples/rust/grpc-baseline/target/release/grpc-baseline 2>/dev/null & P=$!; sleep 0.5
run_ghz 127.0.0.1:8090 "pure tonic, Rust handler (ceiling)"; kill $P; wait $P 2>/dev/null
if [ -x "$RR" ]; then
  for W in 1 4 16; do
    (cd "$RR_APP" && RR_WORKERS=$W "$RR" serve -c .rr.yaml > /tmp/e10-rr.log 2>&1) & P=$!; sleep 2
    run_ghz 127.0.0.1:9001 "RoadRunner grpc plugin, $W PHP worker(s)"
    pkill -x rr; wait $P 2>/dev/null; sleep 0.5
  done
else
  echo "| RoadRunner | not built | | | | |"
fi
