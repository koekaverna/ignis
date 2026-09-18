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
| `curl_*` (including `CURLOPT_WRITEFUNCTION` and `curl_multi_*`) | **parks the fiber** — libcurl's own blocking calls are interposed, no worker thread and no copy, and the write callback runs in the calling fiber | V-45, V-59 |
| `PDO` on a socket-backed driver (`pgsql`; `mysql` is not compiled into this build) | **parks the fiber** — 303 ms for 100 × 200 ms queries on one thread, against 2,753 ms through an 8-worker offload pool | V-45, V-59 |
| `SQLite3` | routed to the offload pool, the fiber sleeps — a regular file cannot be parked (ADR-0024), so offload is the only mechanism it has | V-24 |
| `PDO` on `sqlite:` | **blocks the OS thread** for the length of the file access, like every other regular-file call (ADR-0024). Routing is by class name and the driver is in the DSN, which the runtime cannot see when it decides — set `IGNIS_OFFLOAD_CLASSES=PDO,SQLite3` to send every `PDO` to the pool instead | V-59 addendum |
| PostgreSQL | `pdo_pgsql` and `ext/pgsql` unchanged — the driver's socket parks the fiber; under Symfony, `ignis/doctrine` gives every fiber its own connection and can pool them per thread | V-85, V-86 |
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
| a hostname lookup (`fsockopen('tcp://host:port')`, `gethostbyname()`, libpq connecting by name) | **blocks the OS thread** for the whole resolve — `getaddrinfo()` has no fd to park on. `curl_*` is unaffected (threaded resolver). Run a local caching resolver; tracked as R-DNS | research 26, 27, 31 |
| `session.save_handler = files` (PHP's and Symfony's default) | **works** — `flock` is interposed (V-81): a blocking lock inside a fiber parks (`LOCK_NB` plus a backing-off retry) instead of blocking the thread, closing the deadlock this row used to warn about. Two fibers on the same thread sharing a session id never even contend (`ext/session` is a per-thread singleton, V-80); across threads a shared session id serialises and completes, exactly like php-fpm | V-80, V-81, V-58, ADR-0038 |
| Symfony's cache (`LockRegistry` stampede protection) | works, and only because `usleep` parks: non-blocking `flock` + a 100 ms poll, thread stays free | V-58 |

## Hook-off controls

Every hook has an off switch — a claim about a hook is only made against a run with that hook
disabled, never assumed: `IGNIS_NO_UNIVERSAL_PARK`, `IGNIS_NO_SUPERGLOBALS`, `IGNIS_NO_OFFLOAD_ROUTE`.
(`IGNIS_NO_STREAM_HOOK` and `IGNIS_NO_SLEEP_HOOK` stood here until 2026-09-18; the hooks they turned
off are deleted — V-46 and V-49 — and `IGNIS_NO_UNIVERSAL_PARK` is the one control for all of
read/write/connect/sleep today.) `IGNIS_PARK` is the universal-park policy
table ([ADR-0037](adr/0037-three-mechanisms.md)): comma-separated `lib` or `lib:symbol` rows
naming what may park; unset means the built-in seed — 16 `libphp:` symbols (the sleep family;
`select`, `accept`, `poll`, `recv`, `send`, `recvfrom`, `sendto`, `recvmsg`, `sendmsg`, `connect`,
`read`, `write`; `flock` since V-81) plus `libcurl`, `libpq`, `libssl`, `libcrypto` — confirmed by
running `ignis serve`, which prints the resolved policy in its startup banner
(`park=libcurl,libpq,libssl,libcrypto,libphp:16 symbols`); empty means nothing parks.

## Databases: what parks, what is pooled, and the one choice you have to make

| driver | mechanism | why |
|---|---|---|
| `pdo_pgsql`, `ext/pgsql` | **parks the fiber** | a socket — there is readiness to wait for. 100 concurrent 200 ms queries on one thread: **303 ms** (V-45, V-59). One handle must belong to one fiber: libpq is not reentrant per connection, and two fibers inside one connection swap result sets (V-85) — under Symfony that is what `IgnisDoctrineBundle` guarantees |
| `pdo_mysql`, `mysqli` | **parks the fiber**, by construction | also a socket, and `mysqlnd` goes through `php_stream`. **Not compiled into the current build**, so this is design, not measurement |
| `SQLite3` | **offload pool** | a regular file. `epoll` refuses regular files, so nothing can park it ([ADR-0024](concept/non-goals.md)); a worker thread blocks instead of your request thread |
| `PDO` on `sqlite:` | **blocks the OS thread** by default | see below — this is the one case the runtime cannot decide for you |

### Why `PDO` on SQLite is different

The runtime chooses the mechanism when the VM executes `new`, and it only knows the **class name**
at that moment: the driver lives in the DSN, which does not exist yet. `SQLite3` is always
file-backed, so it is routed; `PDO` is not, so routing it would send `pgsql` to a worker as well —
nine times slower than parking (2,753 ms against 303 ms for the same 100 queries).

So the default routes `SQLite3` and leaves `PDO` alone, and an application that uses
`new PDO('sqlite:…')` blocks its thread for the length of the file access. That is the same rule
already in force for `file_get_contents()`, opcache and file sessions — SQLite is not special, it
was merely exempt by accident until 2026-09-17.

**What to do, depending on your application:**

- **SQLite only in tests, PostgreSQL/MySQL in production** — change nothing. The default is right:
  production parks, and a blocked thread in a test run costs nothing.
- **SQLite in production, no socket database** — set `IGNIS_OFFLOAD_CLASSES=PDO,SQLite3` and give
  the pool some workers (`offload = 4`). Every `PDO` then goes to a worker, which is what you want
  when every `PDO` is SQLite.
- **Both, under concurrency** — there is no configuration that is right for both, because the
  setting is per class and not per driver. Use the `SQLite3` class for the SQLite side where you
  can (it is routed on its own), or accept the blocking on the SQLite side. This is the one real
  gap, and the fix for it — teaching the proxy to decide from the DSN inside the constructor — is
  designed but not built (BACKLOG R-PDO-SQLITE).
- **Read-heavy SQLite on a warm page cache** — measure before you configure anything. A read served
  from the page cache takes microseconds; the blocking that hurts is `fsync` on write under
  concurrency.

## What does not carry over

See [What it is not](concept/non-goals.md) for the full, deliberate list — no durability across
crashes, not a web server, one application per process, no NTS build, no multi-tenant isolation, no
sub-millisecond scheduling, blocking regular-file I/O stays blocking, Linux-only.
