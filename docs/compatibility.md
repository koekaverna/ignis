# Compatibility

What runs unmodified, what changes behavior, and what does not work — every row cites the
VALIDATION.md entry that measured it. Nothing here is a claim without a number behind it.

| PHP does | Ignis does | evidence |
|---|---|---|
| `Ignis\sleep`, `Ignis\all`, `Ignis\async` | parks the fiber on a tokio timer | V-2, V-3 |
| `file_get_contents('http://…')`, `fsockopen`, `stream_socket_client` over `tcp://`, `ssl://`, `tls://`, `unix://` | parks the fiber; STARTTLS supported | V-12, V-25, V-29 |
| `socket_read`/`recv`/`accept`/`write`/`send`… (`ext/sockets`) | parks the fiber | V-29 |
| `sleep()`, `usleep()` | park the fiber | V-22 |
| `stream_select()` on hooked streams | answered without blocking | V-26 (TLS read-ahead: open, B7) |
| `PDO`, `SQLite3`, `curl_*` | routed to the offload pool, the fiber sleeps | V-24 |
| PostgreSQL | runtime-owned pool with per-fiber leases (`Ignis\Pg`), or `ext/pgsql` async over the same reactor | V-21 |
| `$_SERVER`, `$_GET`, `$_POST`, `$_COOKIE` | fiber-scoped; two interleaved requests never see each other's | V-11 |
| a client disconnect | cancels the request fiber and its children within 1 ms; `Ignis\deadline()` per request | V-14, V-30 |
| a fatal error in a handler | ends one worker thread, which is respawned; other threads keep serving | V-17 |
| Symfony via `symfony/runtime` | worker mode, fiber-scoped `RequestStack`, sessions | V-16 |
| Revolt / AMPHP | `Ignis\Revolt\IgnisDriver` runs the examples unchanged (7/8 byte-identical, 8th a timing race in the example itself; `DriverTest` passes) | V-13, V-23 |
| gRPC unary + server-streaming handlers, client calls | runs on the shared hyper/h2 listener; a client call parks the fiber | V-20 |
| Temporal workflows (2 activities + a timer) | run as suspended fibers with deterministic replay; a mutated workflow fails replay as expected | V-18, V-19 |
| a legacy docroot that keeps state in globals (`$wpdb` and friends) | **only in the top-level worker loop** — `examples/classic_worker.php`. In that shape `$GLOBALS` and `global $x` behave as under `php -S`; in the fiber-based `Ignis\Classic\serve()` they do not, because an entry included from a fiber has its top-level variables as locals | V-53, V-54 |
| a script that declares a function at top level without a guard | **fatals on the second request** (`Cannot redeclare`, uncatchable; the thread is respawned). Use `require_once` or `function_exists()` — the rule in every worker runtime | V-53, V-54 |
| PHP 8.5.10 ZTS + embed SAPI build itself | builds and links here, all target extensions present | V-0, V-1 |

## Hook-off controls

Every hook has an off switch — a claim about a hook is only made against a run with that hook
disabled, never assumed: `IGNIS_NO_STREAM_HOOK`, `IGNIS_NO_SLEEP_HOOK`, `IGNIS_NO_SUPERGLOBALS`,
`IGNIS_NO_OFFLOAD_ROUTE`, `IGNIS_NO_UNIVERSAL_PARK`. `IGNIS_PARK` is the universal-park policy
table ([ADR-0037](adr/0037-three-mechanisms.md)): comma-separated `lib` or `lib:symbol` rows
naming what may park; unset means the built-in seed
(`libphp:sleep,libphp:usleep,libphp:nanosleep,libcurl,libpq,libssl,libcrypto`), empty means
nothing parks.

## What does not carry over

See [What it is not](concept/non-goals.md) for the full, deliberate list — no durability across
crashes, not a web server, one application per process, no NTS build, no multi-tenant isolation, no
sub-millisecond scheduling, blocking regular-file I/O stays blocking, Linux-only.
