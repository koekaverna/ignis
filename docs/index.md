# Ignis

Ignis is an application server for PHP. One Rust process embeds PHP 8.5 (ZTS) and runs many
requests per OS thread on native Fibers. Every wait — a timer, a socket, TLS, a PostgreSQL query —
is owned by a tokio reactor, so the thread serves other requests while one is stuck waiting.
Unmodified synchronous PHP becomes non-blocking: `file_get_contents`, `fsockopen`, `sleep()` and
`ext/sockets` and `curl_*` park the fiber instead of the thread — libcurl's own blocking calls are
interposed too, so there is no worker thread and no copy. What genuinely cannot be parked
(`SQLite3`, a file-backed `PDO` — `epoll` refuses regular files) is routed to a pool of synchronous
worker threads with no code change. It replaces
php-fpm, FrankenPHP or RoadRunner in front of a Symfony or Laravel app.

## The pitch

Every other PHP application server answers "more concurrency" with more processes or more OS
threads holding whole PHP request contexts. Ignis answers it with Fibers: a request is a
cooperative, resumable stack a few kilobytes wide, not a process or a thread, and the only place a
PHP thread ever waits is one channel read from a Rust reactor that owns every timer, socket and
connection in the process. The result is a request server that scales concurrency independently of
threads — and the numbers below are what that architecture measures out to today.

## Three numbers that matter

- **128,072 req/s** on a hello-world route with one PHP thread, against FrankenPHP worker mode's
  27,627 req/s on the same box and the same libphp build — **4.6× the throughput, p99 5.8× lower**
  (1.11 ms vs 6.42 ms) (V-6).
- **10,000 concurrent fibers**, each doing `Ignis\sleep(1000)`, finish in **1,168–1,178 ms wall**
  on a single OS thread — the 1-second sleep plus about 170 ms of fiber lifecycle overhead, not
  10,000× it (V-2).
- RSS stayed **flat within 3%** (26.9 → 26.2 MB) over **4.6 million requests** in worker mode, with
  the PHP heap flat to the byte — no leak hiding behind a long-lived process (V-10).

## Where to go next

- [Why Ignis](concept/why.md) — the problem with a blocking PHP worker, and how Ignis answers it.
- [Architecture](concept/architecture.md) — one process, two worlds, one bridge.
- [Install](getting-started/install.md) and [Quickstart](getting-started/quickstart.md) — running
  it in minutes.
- [Compatibility](compatibility.md) — what works unchanged, and what does not.
