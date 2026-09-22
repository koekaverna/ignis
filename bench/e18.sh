#!/usr/bin/env bash
# E18-B driver: starts a hello_server target on IGNIS_LISTEN (default 127.0.0.1:8160, this box's
# 8160-8169 range), runs bench/php/e18_curl.php, e18_pgsql.php, e18_dns.php and bench/e18-overhead.sh
# prints the `e18: ...` lines, and kills everything it started. bench/e18-deadlock.sh is NOT this script's
# job (it waits on research 27's lock shim).
#
# Usage: IGNIS_LISTEN=127.0.0.1:8160 PG_DSN="host=/tmp/ignis-pgsock user=ignis password=ignis dbname=ignis" bash bench/e18.sh
BIN="${BIN:-./target/release/ignis}"   # the binary under test (E18-I: BIN=./target-park/release/ignis with IGNIS_PARK set)
set -uo pipefail
cd "$(dirname "$0")/.."

ADDR="${IGNIS_LISTEN:-127.0.0.1:8160}"
export IGNIS_LISTEN="$ADDR"
PG_DSN="${PG_DSN:-host=/tmp/ignis-pgsock user=ignis password=ignis dbname=ignis}"
# pdo_pgsql wants `pgsql:key=val;key=val`; PG_DSN is libpq keyword format (space-separated) -- see
# box facts / ADR-0020 kill-criterion note. Override with PG_PDO_DSN if this transform ever breaks.
PG_PDO_DSN="${PG_PDO_DSN:-pgsql:$(echo "$PG_DSN" | sed -E 's/ +/;/g')}"

echo "load: $(uptime | sed 's/.*load average/load average/')"

${HS_BIN:-./target/release/ignis} examples/hello_server.php > /tmp/e18-hello.log 2>&1 &
HS=$!
up=0
for _ in $(seq 1 50); do
    curl -sf "http://$ADDR/" 2>/dev/null | grep -q "Hello, World!" && { up=1; break; }
    sleep 0.1
done
if [ "$up" != 1 ] || ! kill -0 "$HS" 2>/dev/null; then
    echo "our server never answered on $ADDR (port taken? set IGNIS_LISTEN); see /tmp/e18-hello.log"
    kill "$HS" 2>/dev/null
    sleep 0.5
    kill -0 "$HS" 2>/dev/null && kill -9 "$HS" 2>/dev/null
    exit 1
fi

echo "== 1. curl_exec (H32)"
timeout 60 $BIN bench/php/e18_curl.php "http://$ADDR/sleep?ms=200" 100

echo "== 2. pdo_pgsql (H33)"
timeout 60 $BIN bench/php/e18_pgsql.php "$PG_PDO_DSN" 100

echo "== 3. getaddrinfo / libpq connect (H34), stub not wired -- see bench/php/e18_dns.php"
# A closed TCP port on this box's loopback does not answer ECONNREFUSED -- a bare connect() hangs
# indefinitely (verified: `exec 3<>/dev/tcp/127.0.0.1/5432` still blocked after 15 s). Each
# pg_connect's own connect_timeout=1 is what actually bounds it; on the single default PHP thread
# 50 fibers' connects serialize (no universal park yet), so this step is ~50 x 1 s, not instant.
timeout 90 $BIN bench/php/e18_dns.php localhost 50

# Item 1 leaves 100 keep-alive client connections that may still be open; a graceful hyper
# shutdown can then wait on them past SIGTERM, so give it a moment and SIGKILL by the same PID
# if it is still around (no pkill -f).
kill "$HS" 2>/dev/null
for _ in $(seq 1 20); do kill -0 "$HS" 2>/dev/null || break; sleep 0.2; done
kill -0 "$HS" 2>/dev/null && kill -9 "$HS" 2>/dev/null
wait "$HS" 2>/dev/null || true

echo "== 4. non-fiber read() overhead (H35), no interposer built yet"
IGNIS_E18_SKIP_BUILD=1 timeout 30 bash bench/e18-overhead.sh
