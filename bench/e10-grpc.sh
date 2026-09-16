#!/usr/bin/env bash
# E10 (H21/H21b): gRPC unary + server-streaming handlers in PHP on the shared h2 listener; client call parks the fiber.
set -uo pipefail
cd "$(dirname "$0")/.."
BIN="${BIN:-./target/release/ignis}"; GOBIN="$(go env GOPATH 2>/dev/null)/bin"; PATH="$GOBIN:$PATH"
ADDR="${ADDR:-127.0.0.1:8080}"; THREADS="${THREADS:-1}"; N="${N:-100000}"; CONNS="${CONNS:-64}"
PROTO=examples/grpc/greeter.proto
IGNIS_ADDR="$ADDR" "$BIN" --threads "$THREADS" examples/grpc_server.php > /tmp/ignis-e10.log 2>&1 & SRV=$!
for _ in $(seq 1 50); do curl -s "http://$ADDR/" >/dev/null 2>&1 && break; sleep 0.1; done
echo "== grpcurl unary";     grpcurl -plaintext -proto "$PROTO" -d '{"name":"ada"}' "$ADDR" ignis.Greeter/SayHello
echo "== grpcurl streaming"; grpcurl -plaintext -proto "$PROTO" -d '{"n":3}' "$ADDR" ignis.Greeter/Countdown | tr -d '\n '; echo
echo "== grpcurl unknown method (expect Unimplemented)"; grpcurl -plaintext -proto "$PROTO" -d '{}' "$ADDR" ignis.Greeter/Nope 2>&1 | head -2
echo "== HTTP on the same port"; curl -s "http://$ADDR/" | head -1
echo "== ghz unary SayHello c=$CONNS n=$N ($THREADS PHP thread(s))"
ghz --insecure --proto "$PROTO" --call ignis.Greeter/SayHello -d '{"name":"ada"}' -c "$CONNS" -n "$N" --connections 8 "$ADDR" 2>&1 | grep -E "Count:|Total:|Requests/sec|Average:|Fastest:|Slowest:|99 % in|OK|Unavailable|Internal|Unimplemented|Canceled|Unknown" | sed 's/^/  /'
echo "== ghz streaming Countdown n=3 c=$CONNS 5000 calls"
ghz --insecure --proto "$PROTO" --call ignis.Greeter/Countdown -d '{"n":3}' -c "$CONNS" -n 5000 --connections 8 "$ADDR" 2>&1 | grep -E "Count:|Requests/sec|Average:|99 % in|OK|Internal|Unknown" | sed 's/^/  /'
echo "== H21b: 100 Proxy calls at once (each awaits a 200 ms Slow call through the runtime client), 1 thread"
ghz --insecure --proto "$PROTO" --call ignis.Greeter/Proxy -d '{"name":"ada"}' -c 100 -n 100 --connections 4 "$ADDR" 2>&1 | grep -E "Count:|Total:|Average:|Slowest:|OK|Internal|Unknown|Unavailable" | sed 's/^/  /'
echo "== stats"; curl -s "http://$ADDR/stats"; echo
kill $SRV 2>/dev/null; wait $SRV 2>/dev/null || true
grep -iE "error|panic|fatal" /tmp/ignis-e10.log | head -3 || true
