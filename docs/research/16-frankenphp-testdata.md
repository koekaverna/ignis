# 16 — FrankenPHP testdata ported to Ignis integration tests (E15d, H22d)

Source: `/home/user/frankenphp` (`frankenphp_test.go`, `statusline_test.go`, `finishrequest_realserver_test.go`,
`requestbodytimeout_test.go`, `worker_test.go`, `server_test.go`, `watcher_test.go`, `workerextension_test.go`,
`worker_internal_test.go`; `cli_test.go` runs no HTTP request and is out of scope).
Port: `php/classic.php` (classic-mode adapter), `examples/classic_server.php` (FrankenPHP-testdata shims),
`bench/e15-frankenphp.sh` (curl replay, `PASS|FAIL|SKIP` per script).

Result of `bench/e15-frankenphp.sh` (ignis `--threads 2`, PHP 8.5.10 ZTS embed): **passed=29 failed=4 skipped=33**.
Every FAIL is an Ignis runtime gap (none is an adapter bug); every SKIP is worker-mode API, a FrankenPHP-only
function, a Caddy/Init option, or a fixture that a plain HTTP request cannot reproduce.

## 1. Inventory: what the Go tests request and assert

Request column: method, path/query, extra headers/body/cookies. `i` is the per-goroutine index (0..99, 100 parallel
requests per test; the replay uses a few fixed values). Status column: expectation before running, then the
measured result.

| script | request | assertion | needs | expected → measured |
|---|---|---|---|---|
| index.php | GET `/index.php?i=N` | body == `I am by birth a Genevese (N)` | `$_GET`, include | LIKELY OK → PASS |
| ini.php | GET `/ini.php?key=opcache.enable` with `WithPhpIni{opcache.enable: ${LITERAL_ZERO}}` | body == `opcache.enable:0` | per-test php.ini + env expansion (Init option) | NOT APPLICABLE → SKIP |
| finish-request.php | GET `/finish-request.php?i=N` (recorder and a real net/http server) | body == `This is output N\n`; logger eventually contains `reached after finish_request N` | `frankenphp_finish_request()` (fastcgi_finish_request analogue), `error_log()` to server log | NEEDS finish_request shim → PASS |
| server-variable.php | POST `/server-variable.php/baz/bat?foo=a&bar=b&i=N#hash`, basic auth kevin:password, `Content-Type: text/plain`, body `foo`, Host example.com | body contains `[REMOTE_HOST]`, `[REMOTE_USER] => kevin`, `[PHP_AUTH_USER] => kevin`, `[PHP_AUTH_PW] => password`, `[HTTP_AUTHORIZATION] => Basic a2V2aW46cGFzc3dvcmQ=`, `[DOCUMENT_ROOT]`, `[PHP_SELF] => /server-variable.php/baz/bat`, `[CONTENT_TYPE] => text/plain`, `[QUERY_STRING] => foo=a&bar=b&i=N#hash`, `[REQUEST_URI] => /server-variable.php/baz/bat?foo=a&bar=b&i=N#hash`, `[CONTENT_LENGTH]`, `[REMOTE_ADDR]`, `[REMOTE_PORT]`, `[REQUEST_SCHEME] => http`, `[DOCUMENT_URI]`, `[AUTH_TYPE]`, `[REMOTE_IDENT]`, `[REQUEST_METHOD] => POST`, `[SERVER_NAME] => example.com`, `[SERVER_PROTOCOL] => HTTP/1.1`, `[SCRIPT_FILENAME]`, `[SERVER_SOFTWARE] => FrankenPHP`, `[REQUEST_TIME_FLOAT]`, `[REQUEST_TIME]`, `[SERVER_PORT] => 80` | `$_SERVER` CGI vars incl. PATH_INFO, PHP_AUTH_*, REMOTE_* | NEEDS peer address → FAIL (REMOTE_HOST/ADDR/PORT/IDENT missing; 21/25 sub-assertions pass; `#hash` is never on the wire, SERVER_SOFTWARE checked as presence) |
| server-variable.php (TestPathInfo) | GET `/pathinfo/N` rewritten by the Go handler to path `/server-variable.php/pathinfo` with `REQUEST_URI` env override | `[PATH_INFO] => /pathinfo`, `[REQUEST_URI] => /pathinfo/N`, `[PATH_TRANSLATED] =>`, `[SCRIPT_NAME] => /server-variable.php` | request rewrite (WithRequestEnv) | NOT APPLICABLE (Caddy-style rewrite) → SKIP |
| headers.php | GET `/headers.php?i=N` | body == `Hello`; status 201; `Foo: bar`, `Foo2: bar2`, `Foo3: bar3`, no `Invalid`, `I: N` | `header()`, `http_response_code()` | LIKELY OK → PASS |
| headers.php (TestRejectInvalidHeaders) | GET `/headers.php` with `Content-Length: -1` / `something` | status 400, body contains `invalid` | transport rejects bad Content-Length | LIKELY OK (hyper) → PASS (400; body text is FrankenPHP's) |
| response-headers.php | GET `/response-headers.php?i=N` | status `100+i` when `i%3!=0` else 200; body contains `'X-Powered-By' => 'PH`, `'Foo' => 'bar',`, `'Foo2' => 'bar2',`, `'I' => 'N',`, not `Invalid` | `apache_response_headers()`, `http_response_code()` | NEEDS apache_response_headers shim → PASS with i=101,102,105 (see §3: 1xx final statuses) |
| input.php | POST `/input.php` body `post data N` | body == `post data N`; `Foo: bar` | `php://input` | NEEDS php://input → PASS via adapter shim |
| super-globals.php | POST `/super-globals.php?foo=bar&iG=N`, form `baz=bat&i=N` | body contains `'foo' => 'bar'`, `'i' => 'N'`, `'baz' => 'bat'`, `'iG' => 'N'` | `$_GET`, `$_POST` | LIKELY OK → PASS |
| request-superglobal.php | POST `/request-superglobal.php?get_key=get_value_N`, form `post_key=post_value_N` | body contains both `'get_key' => 'get_value_N'` and `'post_key' => 'post_value_N'` | `$_REQUEST` | NEEDS $_REQUEST rebuild → PASS |
| request-superglobal-conditional.php (worker test) | GET `?val=N` (even) / `?use_request=1&val=N` (odd), `auto_globals_jit=1` | even: `SKIPPED`, `'val' => 'N'`; odd: `REQUEST:`, `REQUEST_COUNT:2`, `'val' => 'N'`, `'use_request' => '1'`, `VAL_CHECK:MATCH` | JIT `$_REQUEST` re-arm per request | NEEDS $_REQUEST rebuild → PASS (replayed in classic mode) |
| cookies.php | GET `/cookies.php`, cookies `foo=bar; i=N` | body contains `'foo' => 'bar'`, `'i' => 'N'` | `$_COOKIE` | LIKELY OK → PASS |
| cookies.php (TestMalformedCookie) | two Cookie headers: `foo =bar; ===;;==;  .dot.=val  ;\x00 ; PHPSESSID=1234` and `secondCookie=test; secondCookie=overwritten` | `'foo_' => 'bar'`, `'_dot_' => 'val  '`, `'PHPSESSID' => '1234'`, `'secondCookie' => 'test'` | PHP cookie parsing (name mangling, first-wins, multi-header join) | NEEDS runtime cookie parser → FAIL (Ignis keeps only the last Cookie header and the last duplicate: `['secondCookie' => 'overwritten']`) |
| session.php | GET `/session.php` twice with a cookie jar (real server) | bodies `Count: 0\n` then `Count: 1\n` | sessions, setcookie | NEEDS session workaround → PASS (startup ini `session.use_cookies=0`, adapter emits the cookie) |
| phpinfo.php | GET `/phpinfo.php?i=N` | body contains `frankenphp` and `i=N` | phpinfo | LIKELY OK → PASS (`frankenphp` is the product name, not checked) |
| persistent-object.php | GET `/persistent-object.php?i=N` | body == `request: N\nclass exists: 1\nid: obj1\nobject id: 1` | class persistence across requests, `require_once` | LIKELY OK → PASS (`object id` checked as numeric: a resident script has live objects) |
| autoloader.php | GET `/autoloader.php?i=N` | body == `request N\nmy_autoloader` | `spl_autoload_register` persistence | LIKELY OK → PASS |
| log-error_log.php | GET `/log-error_log.php?i=N` | logger contains `request N` | `error_log()` → server log | LIKELY OK (embed logs to stderr) → PASS |
| log-frankenphp_log.php | GET `?i=N` | slog lines `level=DEBUG msg="some debug message N" "key int"=1` etc. | `frankenphp_log()`, `FRANKENPHP_LOG_LEVEL_*` | NOT APPLICABLE (FrankenPHP API) → SKIP |
| connection_status.php | GET `?i=N&finish=0|1` with the request context cancelled before serving; `ignore_user_abort(true)` | logger contains `request N: 1` (connection_status() == ABORTED) | client abort visible to `connection_status()`, `flush()` | NEEDS pre-aborted request + connection_status wiring → SKIP |
| exception.php | GET `/exception.php?i=N` | body contains `hello` and `Uncaught Exception: request N` | uncaught exception rendered after partial output | LIKELY OK → PASS |
| early-hints.php | GET `/early-hints.php?i=N` | a 103 with `Link: </style.css>; rel=preload; as=style` and `Request: N`; final response has `Request: N` and no `Link` | `headers_send(103)` (FrankenPHP API), 1xx informational responses | NOT APPLICABLE (FrankenPHP API; no 1xx channel in Ignis) → SKIP |
| flush.php | GET `/flush.php?i=N` | exactly two writes: `He` then `llo N` | `flush()` streaming, `ob_end_flush()` loop | NEEDS output streaming → PASS on body `Hello N` only (single-write response; chunking not observable) |
| large-request.php | POST `/large-request.php?i=N`, body 6,048,576 × `f` | body contains `Request body size: 6048576 (N)` | `php://input`, large bodies | NEEDS php://input → PASS |
| fiber-no-cgo.php | GET `?i=N` | body == `Fiber N` (value passed through `Fiber::suspend`) | nested Fiber inside the request fiber | LIKELY OK → PASS |
| fiber-basic.php | GET `?i=N` | body == `Fiber N` | nested Fiber | LIKELY OK → PASS |
| request-headers.php | GET `?i=N`, `Content-Type: text/plain`, `Frankenphp-I: N` | body contains `[Content-Type] => text/plain`, `[Frankenphp-I] => N` | `apache_request_headers()` | NEEDS getallheaders shim → PASS |
| failing-worker.php | none (Init with `WithWorkerMaxFailures(1)`) | `Init()` returns an error | worker startup | NOT APPLICABLE → SKIP |
| env/test-env.php | GET `/env/test-env.php?var=N`, `variables_order=EGPCS` | body == output of `php testdata/env/test-env.php N` | `putenv()/getenv()`, `$_ENV`, `$_SERVER` | LIKELY OK → PASS |
| env/putenv.php | GET `?key=test&put=N` then `?key=test` | `test=N` then `test=` (putenv reset between requests) | per-request environment reset | NEEDS env reset → FAIL (`test=8` persists; FrankenPHP worker mode behaves the same) |
| env/remember-env.php | GET `?index=N` then no query (worker) | `success` twice | resident closure state | NOT APPLICABLE (worker) → SKIP |
| env/prepared-env-getenv.php | GET, `WithRequestEnv{FRANKENPHP_TEST_PHP_SERVER_ENV_IN_GETENV: hello}`, `variables_order=EGPCS` | body == `getenv='hello'\nserver='hello'\nenv='hello'\n` | env visible to getenv/$_SERVER/$_ENV | LIKELY OK (env exported to the process; adapter merges `getenv()` into `$_SERVER`) → PASS |
| env/prepared-env-getenv.php (GPCS variant) | same with `variables_order=GPCS` | `env=NULL` | php.ini variant | NOT APPLICABLE → SKIP |
| env/prepared-env-survives-putenv.php | GET, `WithRequestEnv{FRANKENPHP_PREPARED: prepared_value}` | body == `before='prepared_value'\nprepared='prepared_value'\nput='put_value'\n` | putenv coexisting with prepared env | LIKELY OK → PASS |
| env/overwrite-env.php | GET ×3 (worker) | `custom_value` each time | resident `$_ENV` mutation | NOT APPLICABLE (worker) → SKIP |
| file-upload.php | POST multipart, field `file` = foo.txt (`bar`) | body contains `Upload OK` | `$_FILES` (rfc1867) | NEEDS multipart/$_FILES → FAIL (form is echoed back: `$_FILES` empty) |
| only-headers.php | GET `/only-headers.php` | status 204 | `header('HTTP/1.1 204 No Content', true, 204)` | LIKELY OK → PASS |
| file-stream.php | GET ×3 (worker, 1 worker) | `word1`, `word2`, `word3` | stream kept open across `frankenphp_handle_request()` iterations | NOT APPLICABLE (worker) → SKIP |
| fuzz-response-header.php | GET `?h=<base64 header line>` (fuzz) | status 200 and valid JSON | `frankenphp_response_headers()` | NOT APPLICABLE (FrankenPHP API) → SKIP |
| fuzz-persist-roundtrip.php | GET `?depth=D&width=W` (fuzz) | `SKIP` unless `FRANKENPHP_TEST`; else no `MISMATCH` | FrankenPHP test hook | NOT APPLICABLE → SKIP |
| session-handler.php | GET `?action=set_handler_and_start&value=test1` then `?action=start_without_handler` (worker) | `HANDLER_SET_AND_STARTED`, `session.save_handler=user`; `SESSION_START_RESULT=true`, no `ERROR:`/`EXCEPTION:` | user session handler persisting across worker requests | NOT APPLICABLE (worker) → SKIP |
| worker-with-session-handler.php | GET `?action=check|use_session|check` (worker) | `HANDLER_PRESERVED`, `save_handler=user`, `SESSION_OK` | handler installed before the worker loop | NOT APPLICABLE (worker) → SKIP |
| session-leak.php | GET `?action=set&value=secret_A&client_id=clientA`, `?action=check_empty`, `?action=get`, three clients (worker) | `SESSION_SET`, `secret=secret_A`; `SESSION_CHECK`, `SESSION_EMPTY=true`, no `secret_A`; `SESSION_READ`, `secret=NOT_FOUND`, `client_id=NOT_FOUND` | no `$_SESSION`/`PS(id)` leak between requests on a thread | NEEDS PS(id)/$_SESSION reset → PASS (replayed in classic mode) |
| session-leak.php (after exit) | `?action=set_and_exit…` then the same checks | `BEFORE_EXIT`; worker restarts; no leak | `exit(1)` inside a request restarts the worker | NOT APPLICABLE (worker restart; exit() ends the resident Ignis script) → SKIP |
| preload-check.php | GET, `opcache.preload=testdata/preload.php` | body == `I am preloaded` | opcache.preload at startup | NOT APPLICABLE (Init option) → SKIP |
| short-status-line.php | GET | status 200 (no crash on `header('HTTP/')`) | status line parsing | LIKELY OK → PASS |
| custom-status-line.php | GET | status 418 | `header('HTTP/1.1 418 I am a teapot')` | LIKELY OK → PASS |
| read-input.php | POST, `Content-Length: 1048576`, body never sent (raw socket / HTTP/2 pipe) | `200 OK` within 4 s, body `read=0` | request-body read timeout | NOT APPLICABLE (server option) → SKIP |
| finish-then-read-input.php | POST over HTTP/2, `enable_post_data_reading=Off` | status 200, no crash reading the body after finishing | HTTP/2 + ini + finish_request | NOT APPLICABLE → SKIP |
| non-worker.php | GET | body contains `<b>Fatal error</b>:  Uncaught RuntimeException: frankenphp_handle_request() called while not in worker mode` | FrankenPHP's own fatal | NOT APPLICABLE → SKIP |
| worker.php, die.php, worker-env.php, worker-getopt.php, crashing-worker.php, worker-with-counter.php, worker-counter-persistent.php, worker-sleep.php, message-worker.php, transition-*.php, mercure-publish.php, env/env.php, server-globals.php, split-path.custom | worker-mode / server-scoped / Mercure requests (see the SKIP lines of the bench) | counters (`Requests handled: N`, `requests:N`), restart logs, per-server env | `frankenphp_handle_request()` loop, WithWorkers/NewServer options, Mercure hub | NOT APPLICABLE → SKIP |

Not requested by any Go test (no entry): `echo.php`, `hello.php`, `hello.txt` (benchmarks and worker names only), `dirindex/`, `command*.php` (CLI), `_executor.php`, `*.Caddyfile`, `integration/`, `performance/`.

## 2. What the adapter had to do (facts about the embed SAPI under a resident script)

Measured with `target/release/ignis` on probe scripts before writing `php/classic.php`:

1. `php_embed_init()` sets `SG(headers_sent)=1` and `SG(request_info).no_headers=1` (sapi/embed/php_embed.c:244).
   Consequence: `header()`, `headers_list()`, `header_remove()`, `http_response_code()`, `setcookie()` all work
   (the `headers_sent` guard is skipped when `no_headers`), so the adapter reads `headers_list()` at the end of the
   request. But every session guard checks `SG(headers_sent)` without `no_headers`: `session_start()`,
   `session_id()`, `session_regenerate_id()`, `session_set_cookie_params()`, `ini_set('session.*')` all fail with
   "headers already sent". The only escape is a startup php.ini with `session.use_cookies=0` and
   `session.cache_limiter=` (the guards are `PS(use_cookies) && SG(headers_sent)` and the limiter aborts the
   session when headers are sent); the adapter then adopts the `PHPSESSID` cookie via `session_id()` and emits
   `Set-Cookie` itself. `bench/e15-frankenphp.sh` writes that ini and passes it through `IGNIS_PHP_INI`.
2. `exit()` inside a request fiber is an unwind_exit that escapes the fiber and terminates the resident script
   (probe: output stops at the `exit(0)` inside a Fiber, process exits 0). FrankenPHP's `_executor.php` ends its
   non-worker branch with `exit(0)`, so `examples/classic_server.php` sets `FRANKENPHP_WORKER=1` and provides a
   one-shot `frankenphp_handle_request()` that runs the handler and throws `Ignis\Classic\Finished`, which the
   adapter swallows. Scripts written for Ignis classic mode call `Ignis\Classic\finish()` instead of `exit()`.
3. `EG(included_files)` is never reset: `require_once __DIR__.'/_executor.php'` runs only on the first request per
   thread; afterwards the script returns its handler closure to the `include`. The FrankenPHP runner in
   `classic_server.php` therefore calls a returned `Closure`. Same root cause makes `persistent-object.php` and
   `autoloader.php` behave like worker mode (class and autoloader registered once).
4. `SG(sapi_headers)` is never re-activated between requests: the header list, the `X-Powered-By` header, the
   response code and, worse, the `http_status_line` set by `header('HTTP/1.1 418 …')` leak into the next request on
   the same thread; `http_response_code()` then warns "has no effect" into the body. The only userland path that
   frees the status line is a code *change* through `sapi_update_response_code()`, so `reset()` does
   `header('X-Ignis: reset', true, 599); header('X-Ignis: reset', true, 200); header_remove('X-Ignis')`.
   Verified by running `headers.php` (expects 201) four times after the status-line scripts: PASS.
5. `php://input` is empty: the embed SAPI has no `read_post`, so `SG(request_info).request_body` is never
   created. The adapter registers a userland `php://` wrapper that serves the request body for `php://input` and
   re-opens any other `php://` path with the built-in wrapper (`stream_wrapper_restore` → `fopen` →
   `unregister` + `register`). Measured cost: ~160 B of request heap per delegated open (each
   `stream_wrapper_register` allocates a zend resource freed only at RSHUTDOWN), i.e. a slow leak under a resident
   script for `php://memory`/`php://temp` users. A native `ignis_set_request_body()` (or a `read_post` hook fed by
   the reactor) would remove both the wrapper and the leak.
6. `$_REQUEST` is a JIT auto-global built from `PG(http_globals)` on the first compile (or opcache load) of a file
   referencing it, and never re-armed without RINIT. `ignis_set_superglobals` writes `EG(symbol_table)` only, so
   the JIT copy would be empty/stale. The adapter assigns `$_REQUEST = array_merge($_GET, $_POST, $_COOKIE)` per
   request; referencing `$_REQUEST` in `classic.php` disarms the JIT hook once per thread at load, so later
   compiles cannot overwrite the assignment. `request-superglobal-conditional.php` (FrankenPHP's re-arm regression
   test) passes on 4 alternating requests over 2 threads.
7. `PS(id)` and the `$_SESSION` symbol survive `session_write_close()`; without a reset every client on a thread
   would share the previous session. The adapter runs `unset($_SESSION); session_id($_COOKIE['PHPSESSID'] ?? '')`
   before the script (an empty id makes `php_session_initialize()` create a fresh one). `session-leak.php`
   (three clients, no jar) passes.
8. `ignis_respond()` takes `name => value` headers, so a repeated header (two `Set-Cookie`) cannot be expressed.
   The adapter emits repeats under case variants of the name (`Set-Cookie`, `Set-cookie`, …); hyper lower-cases
   names on the wire. A list-valued header API in the runtime would make this unnecessary.
9. Output buffering: `output_buffering=0`, so the adapter installs one permanent, non-removable
   (`PHP_OUTPUT_HANDLER_CLEANABLE` only) buffer per thread; `flush.php`'s `while (@ob_end_flush());` stops at it
   instead of dumping the response to the process stdout. Output buffers are per thread, not per fiber: a classic
   script must not suspend inside the include (documented assumption in `classic.php`).
10. No peer address in `ignis_poll()`'s request payload (`method`, `uri`, `headers`, `body` only), so
    `REMOTE_ADDR`/`REMOTE_PORT` cannot be set truthfully.

## 3. Failures and observations

| script | cause | class |
|---|---|---|
| server-variable.php | `[REMOTE_HOST]`, `[REMOTE_ADDR]`, `[REMOTE_PORT]`, `[REMOTE_IDENT]` missing: the reactor does not expose the peer socket address (fact 10). The other 21 assertions pass. | Ignis runtime gap: expose peer addr/port in the request payload |
| cookies.php (malformed) | `Request::superglobals()` parses cookies as `trim(name)=urldecode(trim(value))` on one header value; the runtime's header map keeps only the last `Cookie` header, and the last duplicate name wins. PHP/FrankenPHP: join multiple `Cookie` headers with `; `, keep the first duplicate, mangle ` `/`.` to `_` in names, keep trailing spaces in values, drop NUL bytes. Result here: `['secondCookie' => 'overwritten']`. | Ignis runtime gap (`php/ignis.php` cookie parsing + multi-header join in the transport) |
| env/putenv.php | `putenv()` is process-wide and never reset between requests (`test=8` on the second request). FrankenPHP module mode restores the environment per request; its worker mode does not either (`TestEnvIsNotResetInWorkerMode`). | worker-mode semantics; not fixable in userland |
| file-upload.php | no rfc1867 handling: the runtime sets `$_SERVER/$_GET/$_POST/$_COOKIE` only, `$_FILES` stays empty, `$_POST` is not parsed for `multipart/form-data`. | Ignis runtime gap (multipart parsing + temp files) |

Observations that are not failures:

- `response-headers.php`: for `i%3 != 0` the script answers status `100+i`; with the Go test's `i < 100` those are
  1xx codes, which the Go recorder accepts but a real HTTP/1.1 server cannot send as a final response (Ignis sends
  `HTTP/1.1 101 Switching Protocols` with no body for 101, and a 500 for 102). The replay uses i=101,102,105.
- `flush.php`: the two-chunk assertion (`He`, `llo N`) needs streaming writes; Ignis answers in one
  `ignis_respond()`. Body checked only (`Hello N`). A streaming response API is the missing piece.
- `only-headers.php` returns 204 while echoing a JSON body; hyper drops the body for 204.
- Invalid `Content-Length` requests are rejected by hyper with 400 before any PHP runs.
- Exposing the process environment in `$_SERVER` (php-cgi/FrankenPHP behaviour, required by the prepared-env
  tests) also exposes secrets present in the environment; classic deployments should scrub the environment.

## 4. SKIP reasons, grouped

- Worker-mode API (`frankenphp_handle_request()` loop, resident state, worker restart): worker.php, die.php,
  worker-env.php, worker-getopt.php, crashing-worker.php, worker-with-counter.php, worker-counter-persistent.php,
  worker-sleep.php, message-worker.php, transition-worker-1.php, file-stream.php, env/remember-env.php,
  env/overwrite-env.php, session-handler.php, worker-with-session-handler.php, session-leak.php (exit variant),
  failing-worker.php, env/env.php.
- FrankenPHP-only functions: log-frankenphp_log.php (`frankenphp_log`), early-hints.php (`headers_send(103)`),
  fuzz-response-header.php (`frankenphp_response_headers`), fuzz-persist-roundtrip.php (test hook),
  non-worker.php (its own fatal message).
- Caddy directive / Init option / php.ini variant: ini.php, preload-check.php, env/prepared-env-getenv.php
  (`variables_order=GPCS`), server-variable.php (TestPathInfo rewrite), server-globals.php and split-path.custom
  (per-server roots and split path), read-input.php (body read timeout), finish-then-read-input.php (HTTP/2 +
  `enable_post_data_reading=Off`).
- Fixture not reproducible over plain HTTP: connection_status.php (pre-cancelled request context),
  mercure-publish.php (Mercure hub).

## 5. Recommendations for the runtime (ordered by what they unlock)

1. Per-request SAPI reset hook (`sapi_deactivate`/`sapi_activate` equivalent, or `ignis_reset_request()`):
   clears the header table, status line, `included_files`? (no: keep), `PS(id)`, `$_SESSION`, putenv scope. Removes
   facts 4, 7 and the env/putenv failure without userland tricks.
2. Feed `SG(request_info).request_body` (or a `read_post` hook) from the reactor: native `php://input`, no wrapper,
   no 160 B/open leak, and rfc1867 upload parsing could then run through `php_default_post_reader`/`$_FILES`.
3. Add peer address/port to the request payload (`REMOTE_ADDR`/`REMOTE_PORT`).
4. Multi-value headers in both directions: join repeated request headers (`Cookie` per RFC 7540 §8.1.2.5) and
   accept list values in `ignis_respond()`.
5. Streaming responses (`ignis_respond_start`/`write`/`end`) for `flush()` and 103 early hints.
6. Unpin `SG(headers_sent)` after `php_embed_init()` (Ignis owns the SAPI struct: setting `headers_sent=0` per
   request lets stock `session_start()` work with `use_cookies=1`).
