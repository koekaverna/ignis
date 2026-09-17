#!/usr/bin/env bash
# E22: our multipart parsing must agree with PHP's own, case for case.
#
# The embed SAPI has no `read_post` (php-src/sapi/embed/php_embed.c:145), so `main/rfc1867.c` never
# runs and the runtime parses the body itself. That parser reached main with no tests at all, and
# the first thing an oracle found was a body PHP accepts and it did not. So the oracle is the test:
# php-cli's built-in server DOES have a read_post, parses natively, and answers the same dump.
#
# Any divergence is a defect in ours — PHP's behaviour is the specification here, quirks included.
set -uo pipefail
cd "$(dirname "$0")/../.."
PHP=${IGNIS_STOCK_PHP:-/opt/php85-zts/bin/php}
BIN=${IGNIS_BIN:-./target/release/ignis}
ORACLE_PORT=${ORACLE_PORT:-8197}
IGNIS_PORT=${IGNIS_PORT:-8198}
export LD_LIBRARY_PATH=${LD_LIBRARY_PATH:-/opt/php85-zts/lib}

for p in "$ORACLE_PORT" "$IGNIS_PORT"; do
  ss -ltn "sport = :$p" | grep -q LISTEN && { echo "port $p busy"; exit 2; }
done

"$PHP" -S "127.0.0.1:$ORACLE_PORT" -t bench/e22 >/dev/null 2>&1 & ORACLE=$!
IGNIS_LISTEN="127.0.0.1:$IGNIS_PORT" "$BIN" --threads 1 bench/e22/server.php >/tmp/ignis-e22.log 2>&1 & OURS=$!
trap 'kill $ORACLE $OURS 2>/dev/null' EXIT

for _ in $(seq 1 50); do curl -sf "http://127.0.0.1:$ORACLE_PORT/dump.php" >/dev/null 2>&1 && break; sleep 0.2; done
for _ in $(seq 1 50); do curl -sf "http://127.0.0.1:$IGNIS_PORT/" >/dev/null 2>&1 && break; sleep 0.2; done

ORACLE_PORT=$ORACLE_PORT IGNIS_PORT=$IGNIS_PORT "$PHP" bench/e22/compare.php
rc=$?
[ $rc = 0 ] && echo "E22: GREEN" || echo "E22: FAILED"
exit $rc
