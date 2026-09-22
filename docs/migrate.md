# Migrating to Ignis

For a team replacing php-fpm, FrankenPHP or RoadRunner with `ignis serve` in front of an existing
Symfony or Laravel app. [docs/pain-map.md](pain-map.md) is the index this guide follows — each row
below is one pain-map item, "what you had → what it becomes". Every number links a `V-n` in
[VALIDATION.md](https://github.com/koekaverna/ignis/blob/main/VALIDATION.md); see [docs/operate.md](operate.md) for sizing and the full config
reference.

**Try it first**, no app of your own required:

```
docker run -p 8080:8080 ghcr.io/koekaverna/ignis
curl http://127.0.0.1:8080/
```

returns `200 Hello, World!` — that's [examples/hello_server.php](https://github.com/koekaverna/ignis/blob/main/examples/hello_server.php),
the image's built-in entry (V-39: image builds and serves cold-start from nothing but itself).
Point `entry` in `ignis.toml` at your app's front controller to serve it instead (see
[README.md § Install](https://github.com/koekaverna/ignis/blob/main/README.md#install) and § Symfony for the worked recipe, V-40).

## From php-fpm

| what you had | what it becomes | evidence |
|---|---|---|
| `pm.max_children` in `www.conf` | `threads × budget.fibers` — threads = OS worker threads (cores), `budget.fibers` = concurrent request fibers admitted per thread; a request past that waits as data, not a blocked worker | ADR-0019; sizing in [operate.md](operate.md) |
| `ignore_user_abort` (opt-in, and even then PHP itself doesn't know until it hits `connection_aborted()`) | automatic: a client disconnect cancels the request fiber and every child it spawned, unwinding through `finally` | V-14: 0.78 ms worst-case cancel latency (poll wake-up dominates it), 20/20 `finally` blocks ran |
| `max_execution_time` (counts **CPU** time, not wall-clock, and fires as an uncatchable fatal) | `Ignis\deadline($ms)` — one wall-clock deadline per request, inherited by children, throws a catchable `DeadlineExceededException` | V-14: `deadline(100)` around a 1000 ms handler → 504 in 102.1–102.3 ms |
| opcache reset when `pm.max_requests` recycles a worker, or on a fatal | worker thread respawn with opcache SHM **untouched** — no recompilation | V-17: a fatal ended one of 4 worker threads; respawn completed inside the 50 ms supervisor tick, recovery to 95.7% of baseline throughput |
| worker pool sized by RAM per process (30–80 MB/worker rule of thumb) | `memory ≈ fibers × 14.7 kB + held connections × 33 kB` — see [operate.md § Sizing](operate.md) | V-37 (marginal, measured on one box) |
| nginx (or Apache) fronting php-fpm over a FastCGI socket | `ignis serve` listens on `listen`/`IGNIS_LISTEN` directly (hyper, h1/h2) | V-38 |
| one OS process per in-flight request | N OS threads, each running many requests on pooled Fibers; a warm fiber pool costs no PHP-heap growth over millions of requests | V-5 (122k–130k req/s hello-world, 1 thread); V-10 (RSS flat, in one case *falling* 2.5%, over 1.5M requests; PHP heap byte-identical across samples) |

## From FrankenPHP

| what you had | what it becomes | evidence |
|---|---|---|
| worker mode: rewrite the entry to a `while(true)` loop, adapt the app to not leak between iterations | not required for unmodified synchronous I/O — `file_get_contents`, `fsockopen`, `stream_socket_client` over `tcp://`/`ssl://`/`tls://`/`unix://` already park the fiber instead of blocking the thread | V-12 (tcp, 202.5–202.9 ms for 3×200 ms concurrent fetches on 1 thread); V-25 (ssl/tls/https, 210–231 ms hooked vs 613–623 ms unhooked control) |
| Symfony under worker mode via a custom runner | `symfony/runtime`'s own `extra.runtime.class` hook, untouched skeleton, kernel booted once | V-40 (recipe); V-16 (0/100 `RequestStack` mismatches across interleaved fibers; 7,205–7,770 req/s at 1 thread / 25,201 req/s at 4 threads with sessions on) |
| sizing formula `num_threads × memory_limit` plus `GOMEMLIMIT` for the Go heap | one axis for concurrency (`threads` = cores, `budget.fibers` = concurrency) and a separate marginal-memory formula — no Go heap to budget for | ADR-0019; V-37 |
| aborted connections stall the worker without `ignore_user_abort` | same as php-fpm above: disconnect is a cancellation event | V-14 |
| Caddy in front for TLS termination, static files, the `worker` directive | **still needed for TLS**: Ignis's own listener does not yet terminate TLS (client-side `https://`/`ssl://` fetches are hooked, V-25, but the *listener* is plaintext HTTP only) — keep an edge (Caddy, nginx, a load balancer) in front for TLS and static assets; it is otherwise not required for request dispatch | pain-map "FrankenPHP" item 4; ADR-0017 "not covered: … server-side TLS on the Ignis listener" |

## From RoadRunner

| what you had | what it becomes | evidence |
|---|---|---|
| `rr.yaml` `num_workers` per pool | `threads × budget.fibers`, same formula as php-fpm above | ADR-0019 |
| requests serialized over a pipe to a Go process, PSR-7 bridge objects | request built once as a small PHP array inside the same process — no pipe hop, no bridge | V-6 (128,072 req/s hello-world on 1 Ignis thread vs php-fpm+nginx's 9,853–11,106); pain-map "RoadRunner" item 5 cites V-20 for the gRPC case: RoadRunner's pipe hop + protobuf-in-PHP gives 5.1k req/s per worker against Ignis's 16.7k req/s on one thread with the same PHP-side codec work |
| memory-limit restarts + `gc_collect_cycles()` called per request to control leaks | runtime PHP heap stays flat without a per-request GC call; a per-thread supervisor restarts a dead worker instead of a memory-limit trip | V-10 (heap byte-identical across 1.5M requests, no `gc_collect_cycles()` call); V-17 (supervisor respawn) |
| opcache reset on worker restart | thread respawn without opcache reset, same as php-fpm above | V-17; ADR-0012 |
| shared state via RPC to Go (a KV round trip for anything shared between workers) | not yet available in-process — see "not supported yet" below | pain-map "RoadRunner" item 4 (NOT STARTED) |
| `ignore_user_abort` / worker-mode discipline (close descriptors, avoid state pollution) | a disconnect cancels the fiber and its children, and per-request state is fiber-scoped rather than yours to reset. A database connection is still the application's to own: give each fiber its own by marking the service that holds it `scoped` (ADR-0042), since two fibers inside one connection swap result sets (V-85) | V-14 (a disconnect cancels the fiber and its children); V-85 (what a shared connection does). V-21 stood here for the runtime-owned pool, which is deleted (V-87) |

## What stays the same

Ordinary, unmodified PHP keeps working — no code change to adopt Ignis:

- `Ignis\sleep()`, `Ignis\all()`, `Ignis\async()` — park the fiber on a tokio timer (V-2, V-3);
  unmodified `sleep()`/`usleep()` do the same (V-22).
- `file_get_contents`, `fsockopen`, `stream_socket_client` over `tcp://`/`ssl://`/`tls://`/`unix://`,
  `ext/sockets` — park the fiber, STARTTLS supported (V-12, V-25, V-29).
- `curl_*` — parks the fiber, no worker and no copy, the write callback stays in the calling fiber (V-45, V-59);
- `PDO` on `pgsql` — parks the fiber (V-45, V-59);
- `SQLite3` and `pdo_sqlite` — **block the PHP thread** for the length of the call: a regular file
  cannot be parked (ADR-0024), and the worker pool that used to take such calls off the request
  thread was deleted on 2026-09-22 (DECISIONS.md). Other threads keep serving.
- PostgreSQL — `pdo_pgsql` parks like any other syscall, so an unmodified query suspends the fiber
  instead of the thread (V-45, V-59 addendum: 303 ms for 100 × 200 ms queries on one thread).
  Connections are the application's, not the runtime's: one handle belongs to one fiber, so mark the
  service holding it `scoped` (ADR-0042) — sharing one connection between fibers swaps result sets
  (V-85). The runtime-owned pool described here until 2026-09-18 is deleted — V-86 measured it
  against parked `pdo_pgsql` and the speed case did not survive (ADR-0015 closed, V-87).
- `$_SERVER`, `$_GET`, `$_POST`, `$_COOKIE` — fiber-scoped; two interleaved requests never see each
  other's (V-11: 0 mismatches across 300 checks and over HTTP).
- Symfony via `symfony/runtime`, Revolt/AMPHP via `Ignis\Revolt\IgnisDriver` — unchanged skeletons
  and examples (V-16, V-40, V-13).

## What is not supported yet

- **Laravel.** No runtime adapter yet (Laravel does not use `symfony/runtime`); the route is
  researched but not implemented — BACKLOG M3-5 (blocked on the M3-4 research note).
- **`/_ignis/metrics` (Prometheus).** `/_ignis/stats`/`/_ignis/health` exist; a Prometheus text
  endpoint does not — BACKLOG M4-4.
- **Graceful reload of *configuration*.** Code reload is built (`[watch] enabled` + `supervise`, or
  `SIGHUP`): the workers come back one at a time with the new code and nothing in flight is dropped
  (V-90). `ignis.toml` is still read once at startup, so changing a setting is a process restart —
  BACKLOG M4-5.
- **A static binary.** The shipped artifact is the Docker image; `libphp.so` pulls in ~35 shared
  libraries through libcurl, so there is no dependency-free binary yet — BACKLOG M5-5 (research
  first).
