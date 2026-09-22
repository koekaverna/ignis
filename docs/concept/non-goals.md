# What it is not

Ignis holds thousands of requests in flight per process, which invites expectations it does not
meet. These are stated deliberately, so the next reader doesn't build them by accident and so the
docs can say them plainly — [ADR-0024](../adr/0024-non-goals.md) is the source of record.

## No durability across crashes

In-flight requests live in memory only. A worker thread that dies answers its in-flight requests
with 500 and the process respawns the thread (V-17); a process that dies loses everything it was
holding. Applications need idempotency keys with upstreams and their own reconciliation; a queue
(Kafka and kin) remains the right tool for replay, audit and fan-out — but not for "don't hold a
worker while waiting", which the fiber model already answers on its own.

## Not a web server

Static files and ACME are optional extras at most. A reverse proxy in front is still the normal
shape. The listener speaks HTTP/1, HTTP/2 and gRPC (V-6, V-20); inbound TLS termination and HTTP/3
are deferred ([ADR-0032](../adr/0032-inbound-tls-and-http3.md)).

## One application per process

The PHP kernel boots once per thread (V-16); routing lives in the application, not the runtime —
there are no per-route limits or per-route configuration inside Ignis itself.

## No NTS support

Ignis requires PHP built ZTS (Zend Thread Safety) with the embed SAPI. Distribution packages are
NTS and will not link — checked directly with `nm` against `libphp8.5-embed`. See
[Install](../getting-started/install.md) for building the ZTS interpreter Ignis needs.

## No multi-tenant isolation

`memory_limit` is enforced per OS thread, not per fiber, so one fiber's OOM ends its siblings on
that thread ([ADR-0025](../adr/0025-memory-model-under-zts.md)); a fatal error ends one thread,
which the supervisor respawns (V-17). Isolation is at the thread boundary, not the request or
tenant boundary.

## No sub-millisecond scheduling

Timers ride tokio's 1 ms timer wheel ([ADR-0026](../adr/0026-timer-contract.md)) — this is not
a real-time scheduler.

## Blocking calls on regular files are never made async

Epoll refuses regular files, so universal park forwards a plain `read`/`write`/`fsync` on one and
the OS thread waits for it. This covers `file_get_contents()` reading a local file and the opcache
file cache. (`flock()` is the exception with a mechanism of its own — see "Locks on files", below.)
It is not a gap that got missed — making file I/O itself async needs a third mechanism (io_uring,
or a pool of synchronous worker threads to run file I/O on) and would get its own ADR before
landing; see [The two mechanisms](mechanisms.md) for what the existing two already cover. Such a
pool existed until 2026-09-22 and was deleted with the MVP cut (DECISIONS.md), so today a regular
file, `SQLite3`, a file-backed `PDO` or a CPU-bound extension call blocks its PHP thread.

## Linux-only

The park mechanism depends on epoll, raw `syscall()` numbers per architecture, and `dladdr`
semantics that are constructs of the Linux/glibc ABI ([ADR-0020](../adr/0020-universal-park.md),
[ADR-0037](../adr/0037-three-mechanisms.md) §5). This is an accepted constraint, not a
near-term roadmap item.

## Name resolution is not asynchronous

`getaddrinfo()` has no file descriptor, so universal park — which waits for readiness and then makes
the real call — has nothing to wait on. A hostname lookup blocks the OS thread that makes it.
libpq and PHP's own `fsockopen`/`gethostbyname` go through it; libcurl does not, because this build
uses curl's threaded resolver.

Run a **local caching resolver** (nscd, systemd-resolved, dnsmasq) and lookups return from cache in
microseconds, which is the practical answer and needs no code. Ignis will not ship its own resolver:
reproducing `nsswitch.conf`, `/etc/hosts`, `resolv.conf`'s search list and `ndots`, the `AI_*` flags
and RFC 6724 address sorting is a surface where being almost right means connecting to the wrong
address or failing to resolve a Kubernetes service — a worse failure than a slow one.

## Locks on files, held across a yield, used to deadlock a thread — closed by default now

A regular file cannot be registered with `epoll`, so before this cycle a blocking `flock()` held
across a yield deadlocked the OS thread outright: the waiter could not park, and the holder — also
parked on that same thread — could never be resumed to release it (V-58,
[ADR-0038](../adr/0038-locks-across-a-fiber-boundary.md)).

`flock()` is itself interposed now (V-81): a genuinely blocking call becomes non-blocking, retried
on a parked sleep that doubles from 200 µs to a 20 ms ceiling, so by default the fiber pays the
wait, not the thread. Turning `libphp:flock` off in `IGNIS_PARK` (or setting
`IGNIS_NO_UNIVERSAL_PARK=1`) brings the old deadlock back.

PHP's default (files) session handler was the case ADR-0038 named as reaching this the most, and
measurement found it does not, independent of the fix above (V-80): on one thread `ext/session` is
a per-thread singleton, so a second fiber sharing a session joins the first fiber's session instead
of taking a second lock; across threads, two concurrent requests on the same session id simply
serialise and both complete — exactly like php-fpm. The boot-time refusal ADR-0038 considered for
`session.save_handler=files` was therefore never built, and files-based sessions are not refused or
unsupported.

Non-blocking locks with a `usleep` poll remain the recommended shape for application code that
locks a file directly (Symfony's cache already does this) — the general rule from ADR-0038, don't
hold a lock across a yield without a mechanism under it, is what `flock` itself now follows too.
