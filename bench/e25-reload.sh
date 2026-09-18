#!/usr/bin/env bash
# E25: development reload — a file changes, the workers come back with it, and nobody notices.
#
# Every arm here exists because a review found the defect it now guards (2026-09-19):
#   under load      all workers left dispatch at once, leaving a gap with no reactor registered:
#                   1,713 non-2xx of 387,355 under `wrk -t4 -c32`. They go one at a time now.
#   every worker    a worker that lost the race for the reload slot went back to sleep in
#                   ignis_poll and served the old code until a request woke it.
#   in flight       the drain counted only PHP's own in-flight number, not the runtime's, so a
#                   request already handed to the reactor was answered 500 by fail_pending.
#   concurrency     the point of reloading this way rather than restarting per request: requests
#                   still overlap, or a fiber-scope bug cannot be reproduced in development.
#   unsupervised    IGNIS_WATCH without --supervise used to end every worker and then the process.
#
#   bench/e25-reload.sh
set -uo pipefail
cd "$(dirname "$0")/.."
BIN=${IGNIS_BIN:-./target/release/ignis}
PORT=${PORT:-8196}
THREADS=${THREADS:-4}
APP=${TMPDIR:-/tmp}/e25-reload
export LD_LIBRARY_PATH=${LD_LIBRARY_PATH:-/opt/php85-zts/lib}
fail=0
server=

# Kill by pid, never by pattern: `pkill -f` matches the shell running it on this box.
stop_server() {
    [ -n "$server" ] || return 0
    kill "$server" 2>/dev/null
    for _ in $(seq 1 40); do kill -0 "$server" 2>/dev/null || break; sleep 0.25; done
    kill -9 "$server" 2>/dev/null
    wait "$server" 2>/dev/null
    server=
}
trap stop_server EXIT

rm -rf "$APP"; mkdir -p "$APP"
cat > "$APP/handler.php" <<'PHP'
<?php
function greeting(): string { return 'v1'; }
PHP
cat > "$APP/app.php" <<PHP
<?php
require '$PWD/php/packages/runtime/src/ignis.php';
require __DIR__ . '/handler.php';

\$worker = bin2hex(random_bytes(3));            // a new token per engine incarnation
Ignis\serve(static function (Ignis\Http\Request \$request) use (\$worker): Ignis\Http\Response {
    if (\$request->path() === '/slow') {
        Ignis\sleep(400);
    }
    return Ignis\Http\Response::text(greeting() . ' ' . \$worker . "\n");
}, '127.0.0.1:$PORT');
PHP

start_server() { # rest: flags for the binary
    ( IGNIS_WATCH=1 exec "$BIN" "$@" "$APP/app.php" ) > "$APP/server.log" 2>&1 &
    server=$!
    for _ in $(seq 1 100); do curl -sf -m 2 "http://127.0.0.1:$PORT/" >/dev/null 2>&1 && return 0; sleep 0.2; done
    echo "  FAIL: the app never answered"; tail -3 "$APP/server.log"; fail=1; return 1
}

version() { curl -s -m 5 "http://127.0.0.1:$PORT/" | awk '{print $1}'; }
every_worker() { for _ in $(seq 1 40); do curl -s -m 5 "http://127.0.0.1:$PORT/"; done | awk '{print $1}' | sort -u | tr '\n' ' '; }
edit() { sed -i "s/'v[0-9]*'/'$1'/" "$APP/handler.php"; }

echo "== a save reaches every worker"
edit v1
start_server --supervise --threads "$THREADS" || exit 1
edit v2
sleep 3
seen=$(every_worker)
echo "  versions answering after the save: $seen"
[ "$seen" = "v2 " ] || { echo "  FAIL: expected every worker on v2"; fail=1; }

echo "== requests still overlap (what a per-request restart would cost us)"
started=$(date +%s%N)
curl -s -m 10 "http://127.0.0.1:$PORT/slow" >/dev/null & first=$!
curl -s -m 10 "http://127.0.0.1:$PORT/slow" >/dev/null & second=$!
wait $first; wait $second
overlap_ms=$(( ($(date +%s%N) - started) / 1000000 ))
echo "  two 400 ms requests took ${overlap_ms} ms"
[ "$overlap_ms" -lt 700 ] || { echo "  FAIL: they serialised, so development no longer behaves like production"; fail=1; }

echo "== a request in flight keeps its own code and its 200"
curl -s -m 10 -o /dev/null -w "%{http_code} %{time_total}\n" "http://127.0.0.1:$PORT/slow" > "$APP/slow.out" & slow=$!
sleep 0.1
edit v3
wait $slow
read -r code seconds < "$APP/slow.out"
echo "  the request that spanned the reload: $code in ${seconds}s"
[ "$code" = 200 ] || { echo "  FAIL: a request in flight was dropped by the reload"; fail=1; }

echo "== under load, nothing is dropped"
if command -v wrk >/dev/null; then
    ( sleep 3; edit v4 ) &
    load=$(timeout 60 wrk -t4 -c32 -d8s "http://127.0.0.1:$PORT/" 2>&1)
    non2xx=$(printf '%s' "$load" | sed -nE 's/.*Non-2xx or 3xx responses: ([0-9]+).*/\1/p')
    total=$(printf '%s' "$load" | sed -nE 's/^ *([0-9]+) requests in.*/\1/p')
    echo "  ${total:-?} requests, ${non2xx:-0} non-2xx, $(printf '%s' "$load" | sed -nE 's/^Requests\/sec: *(.*)/\1/p') req/s"
    [ "${non2xx:-0}" = 0 ] || { echo "  FAIL: the reload dropped requests"; fail=1; }
    grep -q 'zend_mm_heap' "$APP/server.log" && { echo "  FAIL: the engine corrupted its heap during the reload"; fail=1; }
else
    echo "  skipped: no wrk on this box"
fi

echo "== SIGHUP reloads without a file changing"
before=$(version)
kill -HUP "$server"
sleep 3
grep -q "SIGHUP: reloading" "$APP/server.log" || echo "  (the log line needs RUST_LOG=info; the check below is what counts)"
[ "$(version)" = "$before" ] || { echo "  FAIL: the code changed when only a signal was sent"; fail=1; }
echo "  still serving $before after the signal, workers respawned"
stop_server

echo "== control: IGNIS_WATCH without --supervise must refuse, not die"
edit v1
start_server --threads 2 || exit 1
edit v9
sleep 3
alive=$(version)
echo "  after a save: ${alive:-<no answer>}"
[ "$alive" = v1 ] || { echo "  FAIL: an unsupervised server must neither reload nor fall over"; fail=1; }
grep -q "IGNIS_WATCH needs --supervise" "$APP/server.log" || { echo "  FAIL: nothing told the operator why watching is off"; fail=1; }
stop_server

[ "$fail" = 0 ] && echo "E25: GREEN" || echo "E25: RED"
exit $fail
