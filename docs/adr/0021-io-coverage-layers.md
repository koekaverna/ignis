# ADR-0021 — I/O coverage is layered, not per-function

Status: **accepted** (main agent, owner ADR sweep 2026-09-17). Affects pain-map items Swoole 5
(incomplete hooks), PHP-FPM 1, RoadRunner 1. Depends on ADR-0007/0017 (stream hooks), ADR-0008
(Revolt driver), ADR-0016 (offload), ADR-0020 (universal park, the fourth layer once it is on).

## Context

"Make blocking PHP non-blocking" was never one mechanism. What exists and is measured:

| layer | what it catches | evidence |
|---|---|---|
| 1. Revolt driver (`Ignis\Revolt\IgnisDriver`) | AMPHP and anything written against Revolt — unchanged | V-13 (7/8 examples byte-identical), V-23 (DriverTest = StreamSelectDriver, gate revolt.pass 80) |
| 2. php_stream / ext/sockets hooks | anything on php_stream: `file_get_contents('http://…')`, `fsockopen`, `ssl://`/`tls://`/`unix://`, STARTTLS, `stream_select`, `stream_socket_accept`, mysqlnd, the nine `ext/sockets` calls, `sleep()`/`usleep()` | V-12, V-25, V-26, V-29, V-22 |
| 3. offload workers | what never touches php_stream: `curl_*`, `PDO`/`SQLite3`, C SDKs, CPU-heavy calls | V-24 (100 × 200 ms bounded by the pool size, copy 13–67 µs) |
| 4. universal park (ADR-0020) | the syscall layer under any C library on a `park` policy | V-45 (curl_exec, pdo_pgsql ≈ 300 ms for 100 × 200 ms; feature off by default) |

## Options considered

- **Per-function hooks for every extension** (Swoole's path: reimplement ext-curl, ext-pdo, …
  inside the runtime). Rejected: every hooked function is a fork of upstream behaviour that must
  track PHP releases, and research 15 ranked the missing hooks as the largest compatibility gap
  Swoole has — the approach does not converge.
- **One universal mechanism only** (ADR-0020 alone). Rejected as *the only* layer: it cannot cover
  disk I/O (epoll refuses regular files), libraries that hold a lock across a blocking call
  (research 27), or code that is not a C library at all (a CPU-heavy PHP loop).
- **Library-level adapters** (a Symfony HttpClient transport, a Guzzle handler, a Doctrine driver
  on `Ignis\Pg`) — preferred over C-level hooks wherever the library exposes a transport seam:
  the adapter is plain PHP, testable in the library's own suite, and no engine hook is needed.
- **Layers, with a stated order of preference** — this ADR.

## Decision

Coverage is the union of the four layers, chosen per call in this order: a library adapter if
the library has a seam (Symfony HttpClient, Guzzle, Doctrine — Phase C); otherwise layer 2 if the
call is on php_stream; otherwise layer 4 if the library is on a `park` policy; otherwise layer 3.
A user-facing call falls into exactly one layer at a time, and the compat table in README names
which.

## What each layer cannot cover

- Layer 1: nothing outside Revolt's event loop.
- Layer 2: in-process C I/O that never touches php_stream (libpq, libcurl, sqlite — V-12 refuted
  the sqlite case); TLS read-ahead invisible to `stream_select` (research 23, B7).
- Layer 3: cost — a worker thread per concurrent call, arguments copied (V-24); objects and
  resources do not cross (ADR-0016 addendum); per-thread statics inside the worker.
- Layer 4: disk I/O (regular files), libraries on `block` (research 27: libphp itself), the
  `glibc`-internal aliases a library may call instead of the exported name (`__read_chk` and kin
  are interposed only where research 26 found them imported), cancellation inside the C call (stage
  2: `ECANCELED`).

## Consequences

Better: a user never has to rewrite for the runtime; the README table says what parks and what
routes. Worse: four mechanisms to keep honest — every hook claim needs its off switch
(`IGNIS_NO_*`), and BACKLOG E18-C decides, layer by layer and only with the wrapper's own V-n
re-measured, what universal park makes redundant. Affects E6, E7, E15, E16, E18.

## Kill criterion

A blocking call that no layer can reach and that a user needs — the day that happens the answer
is a fifth layer with its own ADR, not a special case in one of these four.
