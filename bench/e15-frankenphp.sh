#!/usr/bin/env bash
# E15d / H22d: FrankenPHP's testdata (frankenphp_test.go, statusline_test.go, finishrequest_realserver_test.go,
# worker_test.go non-worker cases) replayed with curl against Ignis classic mode (examples/classic_server.php).
# Inventory and per-test rationale: docs/research/16-frankenphp-testdata.md.
# Prints one `PASS|FAIL|SKIP <script> <detail>` line per test and a final `passed=N failed=N skipped=N`.
set -uo pipefail
cd "$(dirname "$0")/.."
REPO=$(cd "$(dirname "$0")/.." && pwd)
BIN=${BIN:-$REPO/target/release/ignis}
FP=${FP:-/home/user/frankenphp}
DOCROOT=$FP/testdata
PORT=${PORT:-8087}
BASE=http://127.0.0.1:$PORT
D=/tmp/fp-e15
mkdir -p "$D/sessions"; rm -f "$D"/jar* "$D/server.log"
# php_embed_init() pins SG(headers_sent)=1, so sessions only work with cookie handling delegated to the adapter.
printf 'session.use_cookies=0\nsession.cache_limiter=\nsession.save_path=%s\n' "$D/sessions" > "$D/php.ini"
# Environment the Go tests prepare (TestMain / WithRequestEnv / testEnv).
export FRANKENPHP_TEST_PHP_SERVER_ENV_IN_GETENV=hello FRANKENPHP_PREPARED=prepared_value
export CUSTOM_OS_ENV_VARIABLE=custom_env_variable_value LITERAL_ZERO=0 EMPTY= test=

IGNIS_PHP_INI=$D/php.ini DOCROOT=$DOCROOT IGNIS_ADDR=127.0.0.1:$PORT \
  timeout 600 "$BIN" --threads "${THREADS:-2}" examples/classic_server.php > "$D/server.log" 2>&1 &
SRV=$!
for _ in $(seq 1 50); do curl -s -o /dev/null "$BASE/index.php" && break; sleep 0.1; done
if ! curl -s -o /dev/null "$BASE/index.php"; then
  echo "server did not start on $BASE"; cat "$D/server.log"; kill "$SRV" 2>/dev/null; exit 1
fi
trap 'kill "$SRV" 2>/dev/null; wait "$SRV" 2>/dev/null' EXIT

passed=0; failed=0; skipped=0; FAILS=(); T=''; STATUS=''
req()        { STATUS=$(curl -s -o "$D/body" -D "$D/hdr" -w '%{http_code}' --max-time 20 "$@"); }
body_has()   { grep -qF -- "$1" "$D/body" || FAILS+=("body lacks '$1'"); }
body_re()    { grep -qzE -- "$1" "$D/body" || FAILS+=("body !~ /$1/"); }
body_not()   { grep -qF -- "$1" "$D/body" && FAILS+=("body has '$1'"); }
body_eq()    { printf '%s' "$1" | cmp -s - "$D/body" || FAILS+=("body != '$(printf '%s' "$1" | head -c 80)' (got '$(head -c 80 "$D/body")')"); }
status_is()  { [ "$STATUS" = "$1" ] || FAILS+=("status $STATUS != $1"); }
hdr_is()     { local v; v=$(grep -i "^$1:" "$D/hdr" | head -1 | cut -d: -f2- | tr -d '\r' | sed 's/^ *//'); [ "$v" = "$2" ] || FAILS+=("header $1='$v' != '$2'"); }
hdr_absent() { grep -qi "^$1:" "$D/hdr" && FAILS+=("header $1 present"); }
log_has()    { grep -qF -- "$1" "$D/server.log" || FAILS+=("server log lacks '$1'"); }
begin()      { T=$1; FAILS=(); }
end()        { if [ ${#FAILS[@]} -eq 0 ]; then passed=$((passed+1)); echo "PASS $T ${1:-}"; else failed=$((failed+1)); echo "FAIL $T: $(IFS=';'; echo "${FAILS[*]}")"; fi; }
skip()       { skipped=$((skipped+1)); echo "SKIP $1 $2"; }

# --- frankenphp_test.go -------------------------------------------------------------------------------------
begin index.php   # TestHelloWorld_module
for i in 1 2 3; do req "$BASE/index.php?i=$i"; body_eq "I am by birth a Genevese ($i)"; done
end

skip ini.php "needs per-test php.ini override with env-var expansion (WithPhpIni opcache.enable=\${LITERAL_ZERO}); Init option, not a request"

begin finish-request.php   # TestFinishRequest_module + TestFinishRequestRealHTTPServerDoesNotAbort
for i in 1 2; do req "$BASE/finish-request.php?i=$i"; body_eq "This is output $i"$'\n'; sleep 0.2; log_has "reached after finish_request $i"; done
end

begin server-variable.php   # TestServerVariable_module (fragment '#hash' is never sent on the wire; Host: example.com as in the Go request)
i=5; req -X POST -u kevin:password -H 'Host: example.com' -H 'Content-Type: text/plain' --data foo "$BASE/server-variable.php/baz/bat?foo=a&bar=b&i=$i"
for s in '[REMOTE_HOST]' '[REMOTE_USER] => kevin' '[PHP_AUTH_USER] => kevin' '[PHP_AUTH_PW] => password' \
  '[HTTP_AUTHORIZATION] => Basic a2V2aW46cGFzc3dvcmQ=' '[DOCUMENT_ROOT]' '[PHP_SELF] => /server-variable.php/baz/bat' \
  '[CONTENT_TYPE] => text/plain' "[QUERY_STRING] => foo=a&bar=b&i=$i" "[REQUEST_URI] => /server-variable.php/baz/bat?foo=a&bar=b&i=$i" \
  '[CONTENT_LENGTH]' '[REMOTE_ADDR]' '[REMOTE_PORT]' '[REQUEST_SCHEME] => http' '[DOCUMENT_URI]' '[AUTH_TYPE]' '[REMOTE_IDENT]' \
  '[REQUEST_METHOD] => POST' '[SERVER_NAME] => example.com' '[SERVER_PROTOCOL] => HTTP/1.1' '[SCRIPT_FILENAME]' '[SERVER_SOFTWARE]' \
  '[REQUEST_TIME_FLOAT]' '[REQUEST_TIME]' '[SERVER_PORT] => 80'; do body_has "$s"; done
end "(SERVER_SOFTWARE => FrankenPHP checked as presence only)"

skip server-variable.php "TestPathInfo: needs a request rewrite (URL /pathinfo/N served as /server-variable.php/pathinfo with REQUEST_URI override); Caddy/WithRequestEnv feature"

begin headers.php   # TestHeaders_module
for i in 1 2; do req "$BASE/headers.php?i=$i"; body_eq Hello; status_is 201; hdr_is Foo bar; hdr_is Foo2 bar2; hdr_is Foo3 bar3; hdr_absent Invalid; hdr_is I "$i"; done
end

begin response-headers.php   # TestResponseHeaders_module: i%3!=0 -> status 100+i, else 200. The Go recorder accepts 1xx final
for i in 101 102 105; do   # statuses (i<100); a real HTTP/1.1 server cannot send them, so i>=101 (201/202/200) is used here.
  req "$BASE/response-headers.php?i=$i"
  if [ $((i % 3)) -ne 0 ]; then status_is $((100 + i)); else status_is 200; fi
  body_has "'X-Powered-By' => 'PH"; body_has "'Foo' => 'bar',"; body_has "'Foo2' => 'bar2',"; body_has "'I' => '$i',"; body_not Invalid
done
end "(i=101,102,105: 1xx final statuses of the Go test are not valid on the wire)"

begin input.php   # TestInput_module
for i in 1 2; do req -X POST --data-binary "post data $i" "$BASE/input.php"; body_eq "post data $i"; hdr_is Foo bar; done
end

begin super-globals.php   # TestPostSuperGlobals_module
i=4; req -X POST -H 'Content-Type: application/x-www-form-urlencoded' --data "baz=bat&i=$i" "$BASE/super-globals.php?foo=bar&iG=$i"
body_has "'foo' => 'bar'"; body_has "'i' => '$i'"; body_has "'baz' => 'bat'"; body_has "'iG' => '$i'"
end

begin request-superglobal.php   # TestRequestSuperGlobal_module
for i in 1 2; do req -X POST -H 'Content-Type: application/x-www-form-urlencoded' --data "post_key=post_value_$i" "$BASE/request-superglobal.php?get_key=get_value_$i"
  body_has "'get_key' => 'get_value_$i'"; body_has "'post_key' => 'post_value_$i'"; done
end

begin request-superglobal-conditional.php   # TestRequestSuperGlobalConditional_worker (JIT $_REQUEST re-arm), replayed in classic mode
for i in 1 2 3 4; do
  if [ $((i % 2)) -eq 0 ]; then req "$BASE/request-superglobal-conditional.php?val=$i"; body_has SKIPPED; body_has "'val' => '$i'"
  else req "$BASE/request-superglobal-conditional.php?use_request=1&val=$i"; body_has 'REQUEST:'; body_has 'REQUEST_COUNT:2'; body_has "'val' => '$i'"; body_has "'use_request' => '1'"; body_has 'VAL_CHECK:MATCH'; fi
done
end

begin cookies.php   # TestCookies_module
i=7; req -H "Cookie: foo=bar; i=$i" "$BASE/cookies.php"; body_has "'foo' => 'bar'"; body_has "'i' => '$i'"
end

begin cookies.php   # TestMalformedCookie (the NUL byte of the Go test is not sendable through curl/hyper and is left out)
req -H 'Cookie: foo =bar; ===;;==;  .dot.=val  ; PHPSESSID=1234' -H 'Cookie: secondCookie=test; secondCookie=overwritten' "$BASE/cookies.php"
body_has "'foo_' => 'bar'"; body_has "'_dot_' => 'val  '"; body_has "'PHPSESSID' => '1234'"; body_has "'secondCookie' => 'test'"
end "(malformed cookies)"

begin session.php   # TestSession_module (cookie jar)
rm -f "$D/jar"; req -c "$D/jar" -b "$D/jar" "$BASE/session.php"; body_eq "Count: 0"$'\n'
grep -qi '^set-cookie: PHPSESSID=' "$D/hdr" || FAILS+=("no PHPSESSID cookie")
req -c "$D/jar" -b "$D/jar" "$BASE/session.php"; body_eq "Count: 1"$'\n'
end

begin phpinfo.php   # TestPhpInfo_module ("frankenphp" in the output is the product name: not applicable)
i=9; req "$BASE/phpinfo.php?i=$i"; body_has "i=$i"
end "(product-name assertion 'frankenphp' not applicable)"

begin persistent-object.php   # TestPersistentObject_module ("object id: 1" assumes a fresh process; a resident script has live objects)
for i in 1 2; do req "$BASE/persistent-object.php?i=$i"; body_re "^request: $i
class exists: 1
id: obj1
object id: [0-9]+\$"; done
end "(object id checked as numeric, not 1)"

begin autoloader.php   # TestAutoloader_module
for i in 1 2; do req "$BASE/autoloader.php?i=$i"; body_eq "request $i"$'\n'"my_autoloader"; done
end

begin log-error_log.php   # TestLog_error_log_module (error_log() -> embed SAPI log -> server stderr)
i=11; req "$BASE/log-error_log.php?i=$i"; sleep 0.2; log_has "request $i"
end

skip log-frankenphp_log.php "FrankenPHP-specific API frankenphp_log()/FRANKENPHP_LOG_LEVEL_* (structured logger)"
skip connection_status.php "needs a request whose client aborted before the handler ran (Go cancels the context) plus connection_status() wired to the transport"

begin exception.php   # TestException_module
for i in 1 2; do req "$BASE/exception.php?i=$i"; body_has hello; body_has "Uncaught Exception: request $i"; done
end

skip early-hints.php "FrankenPHP-specific API headers_send(103): Ignis has no channel for 1xx informational responses"

begin flush.php   # TestFlush_module: Go asserts two write chunks ("He", "llo N"); Ignis answers in one write, only the body is checked
i=3; req "$BASE/flush.php?i=$i"; body_eq "Hello $i"
end "(chunk boundaries not observable: single-write response)"

begin large-request.php   # TestLargeRequest_module: 6,048,576-byte POST body
[ -s "$D/large" ] || head -c 6048576 /dev/zero | tr '\0' f > "$D/large"
i=2; req -X POST --data-binary "@$D/large" "$BASE/large-request.php?i=$i"; body_has "Request body size: 6048576 ($i)"
end

begin fiber-no-cgo.php   # TestFiberNoCgo_module (a PHP Fiber nested inside the request fiber)
for i in 1 2; do req "$BASE/fiber-no-cgo.php?i=$i"; body_eq "Fiber $i"; done
end

begin fiber-basic.php   # TestFiberBasic_module
for i in 1 2; do req "$BASE/fiber-basic.php?i=$i"; body_eq "Fiber $i"; done
end

begin request-headers.php   # TestRequestHeaders_module (apache_request_headers())
i=6; req -H 'Content-Type: text/plain' -H "Frankenphp-I: $i" "$BASE/request-headers.php?i=$i"
body_has '[Content-Type] => text/plain'; body_has "[Frankenphp-I] => $i"
end

skip failing-worker.php "worker startup failure (WithWorkers/WithWorkerMaxFailures Init option)"

begin env/test-env.php   # TestEnv_module: output must equal the CLI run of the same script
i=3; expected=$(/opt/php85-zts/bin/php "$DOCROOT/env/test-env.php" "$i" 2>&1; printf x); expected=${expected%x}
req "$BASE/env/test-env.php?var=$i"; body_eq "$expected"
end

begin env/putenv.php   # TestEnvIsResetInNonWorkerMode: putenv() must not leak into the next request
i=8; req "$BASE/env/putenv.php?key=test&put=$i"; body_eq "test=$i"; req "$BASE/env/putenv.php?key=test"; body_eq "test="
end

skip env/remember-env.php "worker-mode test (TestEnvIsNotResetInWorkerMode): state kept in a resident closure across requests"

begin env/prepared-env-getenv.php   # TestPreparedEnvIsVisibleToGetenv_module (env passed to the server process)
req "$BASE/env/prepared-env-getenv.php"; body_eq "getenv='hello'"$'\n'"server='hello'"$'\n'"env='hello'"$'\n'
end

skip env/prepared-env-getenv.php "TestPreparedEnvIsNotInEnvWithoutVariablesOrderE: needs php.ini variables_order=GPCS variant"

begin env/prepared-env-survives-putenv.php   # TestPreparedEnvSurvivesPutenv_module
req "$BASE/env/prepared-env-survives-putenv.php"; body_eq "before='prepared_value'"$'\n'"prepared='prepared_value'"$'\n'"put='put_value'"$'\n'
end

skip env/overwrite-env.php "worker-mode test (TestModificationsToEnvPersistAcrossRequests): \$_ENV mutation must persist in a resident script"

begin file-upload.php   # TestFileUpload_module (multipart/form-data -> $_FILES)
printf bar > "$D/foo.txt"; req -F "file=@$D/foo.txt" "$BASE/file-upload.php"; body_has 'Upload OK'
end

begin headers.php   # TestRejectInvalidHeaders_module: Content-Length -1 / non-numeric must be rejected with 400 ("invalid" is FrankenPHP's own message)
for cl in -1 something; do req -H "Content-Length: $cl" "$BASE/headers.php"; status_is 400; done
end "(invalid Content-Length; body text not compared)"

begin only-headers.php   # TestFlushEmptyResponse_module: header('HTTP/1.1 204 No Content', true, 204)
req "$BASE/only-headers.php"; status_is 204
end

skip file-stream.php "worker-mode test: a stream opened once must survive across frankenphp_handle_request() iterations"
skip fuzz-response-header.php "FrankenPHP-specific API frankenphp_response_headers() (FuzzResponseHeaders)"
skip fuzz-persist-roundtrip.php "FRANKENPHP_TEST-only hook frankenphp_test_persist_roundtrip (script prints SKIP itself)"
skip session-handler.php "worker-mode test: user session handler set in request 1 must survive into request 2 (TestSessionHandlerReset_worker)"
skip worker-with-session-handler.php "worker-mode test: session handler installed before the frankenphp_handle_request() loop"

begin session-leak.php   # TestSessionNoLeakBetweenRequests_worker replayed in classic mode (no cookie jar: three distinct clients)
req "$BASE/session-leak.php?action=set&value=secret_A&client_id=clientA"; body_has SESSION_SET; body_has 'secret=secret_A'
req "$BASE/session-leak.php?action=check_empty"; body_has SESSION_CHECK; body_has 'SESSION_EMPTY=true'; body_not secret_A
req "$BASE/session-leak.php?action=get"; body_has SESSION_READ; body_has 'secret=NOT_FOUND'; body_has 'client_id=NOT_FOUND'
end "(no leak between clients)"
skip session-leak.php "TestSessionNoLeakAfterExit_worker: exit(1) inside a request restarts a FrankenPHP worker; in Ignis exit() would end the resident script"
skip preload-check.php "needs opcache.preload=testdata/preload.php + opcache.preload_user at startup (Init option)"

# --- statusline_test.go -------------------------------------------------------------------------------------
begin short-status-line.php   # TestShortStatusLineDoesNotOverflow: header('HTTP/') must not crash, status 200
req "$BASE/short-status-line.php"; status_is 200; body_eq ok
end
begin custom-status-line.php   # TestCustomStatusLineIsHonored
req "$BASE/custom-status-line.php"; status_is 418; body_eq ok
end
begin headers.php   # regression: the status line left by the previous scripts must not leak into this thread's next request
for i in 1 2 3 4; do req "$BASE/headers.php?i=$i"; status_is 201; body_eq Hello; done
end "(after custom status lines)"

# --- requestbodytimeout_test.go / worker_test.go / others ---------------------------------------------------
skip read-input.php "needs a request-body read timeout (TestRequestBodyTimeout sends a 1 MiB Content-Length and stalls); server option"
skip finish-then-read-input.php "needs HTTP/2 + php.ini enable_post_data_reading=Off and frankenphp_finish_request() before reading the body"
skip non-worker.php "asserts FrankenPHP's own fatal 'frankenphp_handle_request() called while not in worker mode'"
skip worker.php "worker mode (frankenphp_handle_request loop, 'Requests handled: N' counter across requests)"
skip die.php "worker mode (worker restart after die())"
skip worker-env.php "worker mode + WithWorkerEnv"
skip worker-getopt.php "worker mode + getopt()/argv of the worker script"
skip crashing-worker.php "worker mode (crash-restart logging)"
skip worker-with-counter.php "worker mode (watcher reload / connection abort counters)"
skip worker-counter-persistent.php "worker mode (max_requests restart accounting)"
skip worker-sleep.php "worker mode (force-kill of a thread stuck in sleep() during RestartWorkers)"
skip message-worker.php "worker extension API (SendMessage)"
skip transition-worker-1.php "phpmainthread_test: internal worker/regular thread transitions"
skip mercure-publish.php "needs a Mercure hub (mercure_test.go)"
skip env/env.php "server_test.go: per-server WithServerEnv on a worker"
skip server-globals.php "server_test.go: per-server document roots (frankenphp.NewServer); the classic adapter has one docroot"
skip split-path.custom "server_test.go: WithServerSplitPath('.custom'); the classic adapter resolves the longest existing file prefix"

echo "passed=$passed failed=$failed skipped=$skipped"
