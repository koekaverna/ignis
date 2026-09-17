# ADR-0024 — What the runtime does not promise

Status: **accepted** (main agent, owner ADR sweep 2026-09-17). Non-goals, so the next reader does
not build them by accident and so the docs can say them plainly (docs/migrate.md, docs/operate.md).

## Context

An application server that holds thousands of requests in flight per process invites the wrong
expectations: that in-flight work survives a crash, that it is also a web server, that it hosts
several tenants. Each of these was asked or implied during the night and answered by an existing
measurement or by the architecture.

## Decision

1. **No durability across crashes.** In-flight requests are in memory. A worker that dies answers
   its in-flight requests with 500 (E12', V-17) and the process respawns the thread; a process
   that dies loses them all. Applications use idempotency keys with upstreams and reconcile;
   queues (Kafka and kin) remain the tool for replay, audit and fan-out — **not** for "don't hold
   a worker", which the fiber model already answers (V-5, V-24).
2. **Not a web server.** Static files and ACME are optional extras at most; a reverse proxy in
   front remains fine (docs/migrate.md). The listener does HTTP/1-2 and gRPC (V-6, V-20); inbound
   TLS and HTTP/3 are deferred (ADR-0032).
3. **One application per process.** The kernel boots once per thread (V-16); routing lives in the
   application (ADR-0011, and the B2 rewrite: no per-route limits in the runtime).
4. **No NTS support.** The runtime requires PHP ZTS with the embed SAPI; distribution packages
   are NTS and do not work (`libphp8.5-embed` checked with `nm`, 2026-09-16; README "Build").
5. **No multi-tenant isolation.** `memory_limit` is per thread, so one fiber's OOM ends its
   siblings on that thread (ADR-0025); a fatal ends one thread (V-17). Isolation is per thread,
   not per request or per tenant.
6. **No sub-millisecond scheduling.** Timers ride tokio's 1 ms wheel (ADR-0026).

**Added 2026-09-17 (research 30 group (d)):** blocking calls on *regular files* are not made
asynchronous by anything in this runtime — epoll refuses regular files, so universal park forwards
them and the OS thread waits. That covers `file_get_contents()` on disk, the opcache file cache,
and `ext/session`'s `flock` on the session file (BACKLOG R-SESS: a session-lock collision stalls a
thread, not a fiber). Anything needing that would be a fourth mechanism (io_uring, or offload for
file I/O) and gets its own ADR.

**Added 2026-09-17 (research 31, owner decision):** **name resolution is not made asynchronous
either.** `getaddrinfo()` has no file descriptor, so there is no readiness to wait for and universal
park cannot apply — a lookup blocks its OS thread for its full duration. libpq and PHP's own
`fsockopen`/`gethostbyname` are affected; libcurl is not (threaded resolver, research 26). The
runtime will not ship its own resolver: reproducing `nsswitch`, `resolv.conf` search/ndots, the
`AI_*` flags and RFC 6724 sorting fails as *wrong answers*, not as slowness. The planned fix is to
run glibc's own `getaddrinfo` on a blocking pool and suspend the fiber on it (BACKLOG R-DNS); until
then the answer is operational — a local caching resolver.

## Options considered

Promising durability through a write-ahead request log (rejected: it reinvents a queue in the
wrong place); a static-file layer as a first-class feature (rejected: FrankenPHP's Caddy heritage
is a different product); per-request memory accounting to isolate tenants (rejected: Zend's
allocator is per thread; ADR-0025 records the marginal costs instead).

## Consequences

Better: the docs can state the guarantees in one screen. Worse: users who expected "like a queue
worker, but faster" need the idempotency conversation early — docs/migrate.md has it. Affects E3,
E12, M5-2, M5-3.

## Trigger

A user with a concrete need for one of these becomes a proposed ADR of its own; none of them
is added as a flag.
