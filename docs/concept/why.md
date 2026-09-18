# Why Ignis

## The problem

A traditional PHP worker — php-fpm's child process, or a naive "one thread per request" model —
runs one request per OS-level execution context at a time. When that request does I/O (a socket
read, a database round trip, an upstream HTTP call), the *whole* worker blocks: the OS thread sits
in a syscall, doing nothing, until the kernel wakes it. PHP itself has no concept of "park this
request and go do something else" — the interpreter's request state (`$_SERVER`, the output
buffer, resource handles) is thread-global, not request-scoped, so nothing else can safely run on
that thread while a request is in flight.

php-fpm's answer is more processes: one `pm.max_children` worker per concurrent request in flight,
each a full PHP interpreter with its own memory, its own opcache attachment, its own OS process
overhead. Concurrency is bought by multiplying processes, which is why a slow upstream — a stalled
payment gateway, a chatty legacy SOAP call — can pin every worker and take the whole pool down with
it (the "pool exhaustion at low CPU" failure mode: none of the workers are doing CPU work, they are
all just waiting, and there is nothing left to accept the next connection).

## Ignis's answer

A request is a PHP [Fiber](https://www.php.net/manual/en/language.fibers.php) — a lightweight,
resumable stack, not a process or a thread. All waiting belongs to a Rust reactor built on tokio:
every timer, every socket readiness wait and every PostgreSQL query is a tokio-owned future — TLS
included, but only as a generic socket wait: the handshake and the records are PHP's own
`ext/openssl`, parked like any other syscall (see [The three mechanisms](mechanisms.md)) — and the
PHP thread's only wait point is one poll of a completion channel that reactor feeds. When a
request's Fiber reaches an I/O point, it suspends; the underlying OS thread picks up the next ready
Fiber and keeps working. A slow upstream stalls one Fiber — a few kilobytes of suspended stack —
never the thread, never the process (see [Architecture](architecture.md) for how the two sides talk).

The trade is explicit: PHP's synchronous, blocking programming model is kept exactly as written
(`file_get_contents`, `sleep()`, `fsockopen` — see [Compatibility](../compatibility.md)), but the
runtime is now a long-lived, multi-request-per-thread process, with everything that implies for
state: globals are thread-scoped, not request-scoped, unless a hook (superglobals, `Ignis\Scope`)
makes them fiber-scoped, and a mistake there is a cross-request leak instead of a fresh process
throwing it away. [What it is not](non-goals.md) is the honest accounting of that trade.

## Compared honestly

| | php-fpm | FrankenPHP (worker mode) | RoadRunner | Ignis |
|---|---|---|---|---|
| **Concurrency unit** | OS process (`pm.max_children`) | goroutine + a PHP thread pinned to it | OS process (PHP worker) driven by a Go RPC loop | PHP Fiber, pooled and reused |
| **What blocks on slow I/O** | the whole worker process | the Go side can keep serving other connections, but the *PHP thread behind that worker* blocks for the duration of the PHP-side call — worker mode does not give PHP itself non-blocking I/O | the worker process, same as php-fpm; RoadRunner's concurrency comes from running more worker processes, not from suspending one | one Fiber; the OS thread keeps running other Fibers |
| **How more concurrency is bought** | more processes | more workers (`num_threads`), each still one-request-at-a-time internally | more worker processes | more Fibers per thread, and more threads for CPU-bound work |
| **What Ignis trades away** | — | — | — | one app per process, no durability across crashes, Linux-only (see [What it is not](non-goals.md)) |

The measured gap on a hello-world route, one PHP thread, same box and same libphp build: Ignis
128,072 req/s vs FrankenPHP worker mode's 27,627 req/s — 4.6× the throughput, p99 1.11 ms vs
6.42 ms (5.8× lower); php-fpm + nginx on the same build measured 9,853–11,106 req/s (V-6). Part of
that gap is work Ignis does not do yet at that stage (a full superglobals array, request-startup
emulation); part of it is architectural — FrankenPHP's worker mode still hands each request a
cgo/thread round trip and a from-scratch request bootstrap, where Ignis pools the Fiber and pays
one channel op (V-6's own honesty note says as much). RoadRunner was not in that specific
comparison; see [Comparison](../comparison.md) for the numbers that do exist against it (gRPC,
V-20).
