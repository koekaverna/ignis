# Ignis

Ignis is an application server for PHP. One Rust process embeds PHP 8.5 (ZTS) and runs many
requests per OS thread on native Fibers. Every wait — a timer, a socket, TLS, a PostgreSQL query —
is owned by a tokio reactor, so the thread serves other requests while one is stuck waiting.
Unmodified synchronous PHP becomes non-blocking: `file_get_contents`, `fsockopen`, `sleep()` and
`ext/sockets` and `curl_*` park the fiber instead of the thread — libcurl's own blocking calls are
interposed too, so there is no worker thread and no copy. What genuinely cannot be parked because
`epoll` refuses regular files — `SQLite3` — is routed to a pool of synchronous worker threads with
no code change; a file-backed `PDO` (`sqlite:`) blocks the OS thread by default instead, because the
driver only appears in the DSN, after routing has already decided — set
`IGNIS_OFFLOAD_CLASSES=PDO,SQLite3` to route it too. It replaces php-fpm, FrankenPHP or RoadRunner
in front of a Symfony app today; Laravel support is on the roadmap, not yet built.

## The pitch

Every other PHP application server answers "more concurrency" with more processes or more OS
threads holding whole PHP request contexts. Ignis answers it with Fibers: a request is a
cooperative, resumable stack a few kilobytes wide, not a process or a thread, and the only place a
PHP thread ever waits is one channel read from a Rust reactor that owns every timer, socket and
connection in the process. The result is a request server that scales concurrency independently of
threads — and the numbers below are what that architecture measures out to today.

## Three numbers that matter

- **4.6× FrankenPHP worker mode's throughput** on a hello-world route with one PHP thread, p99
  **5.8× lower** — 128,072 vs 27,627 req/s and 1.11 vs 6.42 ms, on the same box and the same libphp
  build (V-6). The ratio is the claim: the absolute figure was measured on the machine of 2026-09-16
  and this project's current box reads 58–62k req/s for the same code (V-82), so quoting 128k as a
  number you should expect is a promise nobody made.
- **10,000 concurrent fibers**, each doing `Ignis\sleep(1000)`, finish in **1,168–1,178 ms wall**
  on a single OS thread — the 1-second sleep plus about 170 ms of fiber lifecycle overhead, not
  10,000× it (V-2).
- RSS stayed **flat within 1.2%** (44.4 → 43.9 MB) over **1.15 million** hello requests in worker
  mode, PHP heap flat to the byte across seven samples — no leak hiding behind a long-lived
  process. (The original absolutes here predate an engine rebuild and are stale; the drift is what
  this gates on, and it reproduced — V-10 + addendum, V-82.)

## Where to go next

- [Why Ignis](concept/why.md) — the problem with a blocking PHP worker, and how Ignis answers it.
- [Architecture](concept/architecture.md) — one process, two worlds, one bridge.
- [Install](getting-started/install.md) and [Quickstart](getting-started/quickstart.md) — running
  it in minutes.
- [Compatibility](compatibility.md) — what works unchanged, and what does not.
- [Legacy apps](getting-started/legacy.md) — running a legacy docroot in the classic worker loop,
  and why only a top-level `include` gives an entry script real top-level globals (V-53, V-54).
