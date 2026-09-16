# Pain map — known production failure modes and the Ignis answer

Not a task list. See BRIEF.md "Pain map" rules. Status per item: ADDRESSED / DESIGNED / NOT STARTED, with a link to the hypothesis or ADR.

### PHP-FPM
1. Pool exhaustion at low CPU: workers block on I/O, listen queue grows, 502/504 → concurrency decoupled from process count; an I/O wait costs a Fiber, not a worker. — **ADDRESSED** for `Ignis\sleep` (V-2/V-5) and for **unmodified tcp stream I/O** (V-12: `file_get_contents('http://…')` suspends the fiber); NOT for sqlite/libpq-style in-process I/O (V-12).
2. Slow-dependency cascade drains the whole pool → per-endpoint fiber budget and circuit breaker on the connection pool. — NOT STARTED.
3. Phantom workers: nginx times out, PHP keeps building a response nobody reads → client disconnect cancels the Fiber and its pending futures. — **ADDRESSED** (ADR-0009, V-14: 0.78 ms cancel latency, children included, `finally` runs).
4. Three unaligned timeouts; max_execution_time counts CPU time, not wall-clock → one wall-clock deadline per request, inherited by child fibers and futures. — **ADDRESSED** (ADR-0009, V-14: `Ignis\deadline()` → 504 at 102 ms, inherited by children).
5. Reactive fork-based scaling lag → fibers spawn in microseconds; threads start once. — **ADDRESSED** (V-4: 4.5 µs per job on a warm pool).
6. 30–80 MB per worker; pool sized by RAM → per-request memory is a fiber stack. — ADDRESSED with a caveat (V-5: ~34 KB RSS per parked fiber; 16 KiB of it is Zend's fixed VM stack page).
7. Framework bootstrap per request → worker mode, kernel boots once. — **ADDRESSED** for the Ignis API (H6) and for Symfony (ADR-0011, V-16: kernel booted once, 7.8k req/s through it).
8. No connection pool; pconnect leaks transactions → runtime-owned pool with lease and session reset on return. — **ADDRESSED** (ADR-0015, V-21): runtime-owned tokio-postgres pool, lease per fiber, transaction pins the lease, one-round-trip session reset on return, second acquire in a fiber = LeaseError.

### RoadRunner
1. State discipline pushed to the developer: close descriptors, avoid state pollution, close connections after every iteration → connections belong to the runtime; nothing to close. — **ADDRESSED** for PostgreSQL (V-21: connections belong to the runtime; a lease released or destructed is reset before reuse); MySQL/Redis not started.
2. Leaks handled by memory-limit restarts and gc_collect_cycles per request → allocator-level leak detector in dev, per-thread supervisor in prod, GC scheduled by the runtime off the hot path. — **ADDRESSED** for the runtime itself (V-10: 4.6M requests, PHP heap flat to the byte, no gc_collect_cycles per request); leak detector and supervisor NOT STARTED (E12, E13).
3. One request per worker; I/O-bound apps sized by memory → fibers. — **ADDRESSED** (V-5). gRPC evidence in V-20: 100 concurrent 200 ms calls take 5.03 s on RoadRunner with 4 workers and 215 ms on one Ignis thread.
4. Shared state via RPC to Go (KV = network hop) → Table in process memory. — NOT STARTED.
5. Per-request serialization over pipes plus PSR-7 bridges → embedded PHP, request objects built on the Rust side without copies. — DESIGNED (ADR-0002: one array build per request, strings copied once; "without copies" not yet true). `$_SERVER`/`$_GET`/`$_POST`/`$_COOKIE` now exist per request (V-11), so `Request::createFromGlobals()` works unchanged. V-20 (gRPC): RoadRunner's pipe hop + protobuf-in-PHP gives 5.1k req/s per worker; Ignis serves 16.7k req/s on one PHP thread with the same PHP-side codec work, 79% of a Rust-only tonic server.
6. Bridge reset bugs (leaks on 404, reset broken on exception) → runtime performs reset at fiber end, including abnormal end. — DESIGNED (ADR-0002: handler exceptions become 500 and the pooled fiber survives; no state reset yet, E13).
7. musl/Alpine instability → single official artifact: static glibc build. — NOT STARTED.

### FrankenPHP
1. Worker mode requires app adaptation and a leak-free app → same requirement, with tooling: leak detector, fiber-scoped services. — NOT STARTED (E13).
2. Aborted connections stall the server without ignore_user_abort → disconnect is a cancellation event, not a Zend signal. — **ADDRESSED** (V-14).
3. Sizing formula num_threads × memory_limit + GOMEMLIMIT; workers vs threads confusion → one axis: threads = cores, fibers = concurrency, memory = fiber budget; no GC heap. — **ADDRESSED** for threads/fibers (ADR-0004, V-9: `--threads N`, 3.7–4× scaling); the fiber budget (pool cap + queueing) is NOT STARTED, see V-5 memory note.
4. Thread contention on small CPU: PHP thread yields to hand output to Caddy → buffered response channel, no thread switch per write. — **ADDRESSED** (ADR-0002: one `ignis_respond` per request, oneshot to hyper).
5. Hot reload drops custom extensions → modules registered once per process; thread restart never re-registers. — DESIGNED (ADR-0001: module registered in MINIT once).
6. RestartWorkers must restart all threads because of opcache → single-thread restart without opcache reset; SHM is process-level, thread only recreates its TSRM context. — **ADDRESSED** (ADR-0012, V-17: respawned thread recreates only its TSRM context).
7. cgo boundary cost and thread pinning → native FFI, no stack switch. — **ADDRESSED** (ADR-0001: bindgen; V-2 profile shows Rust at 0.3% of PHP-thread samples).

### Swoole
1. Own coroutines: statics, class state and superglobals change on switch → native Fibers plus superglobal swap on the fiber-switch observer and a fiber-scoped container. — **ADDRESSED** (ADR-0006, V-11: superglobals swapped per fiber at +100 ns/switch, `Ignis\Scope` WeakMap container); userland statics remain the app's (leak detector NOT STARTED).
2. Xdebug/Xhprof incompatibility → Fibers are supported by Xdebug natively. — ADDRESSED by construction (native `Fiber`, no custom context switching).
3. Forgotten $response->end() holds the connection → response is the Fiber's return value; return or exception closes the connection. — **ADDRESSED** (ADR-0002, `Ignis\serve` handler returns `Response`; exception → 500; cancellation → unwinds, V-14).
4. Deadlock when the only coroutine yields; CPU-heavy work starves others → per-thread watchdog logs long fibers with trace; work-stealing routes new requests to other threads. — **ADDRESSED** (least-inflight dispatch V-15 + watchdog V-17; the watchdog reports the thread, not yet the fiber's trace).
5. Incomplete hooks (curl_multi etc.) → native Rust drivers for HTTP, Postgres, MySQL, Redis; stream-layer hooks for the rest. — **ADDRESSED for `tcp://` streams** (ADR-0007, V-12); `ssl://`, curl, sqlite, libpq NOT STARTED; V-7 shows the engine ABI does not cover I/O either. gRPC client added as a native driver (ADR-0014, V-20: `Ignis\Grpc\Client` parks the fiber, 100 × 200 ms in 217 ms). E16 (ADR-0016, V-24): anything not hooked can be offloaded — `curl_*`, `PDO` and `SQLite3` are auto-routed to synchronous worker threads with no code changes; `Ignis\offload()` for the rest.
6. One blocking call stalls the whole process → stalls one thread of N; supervisor sees it via watchdog. — **ADDRESSED** (ADR-0012, V-17: one stalled thread, others at 90k req/s, watchdog reports it). With E16 a blocking call stalls one offload worker of N, never a fiber thread (V-24: 100 × 200 ms through 8 workers in 2604 ms while the fiber thread kept ticking).
7. Fatal kills the worker with every coroutine in it → fatal kills one thread; pools and Table survive. — **ADDRESSED** for the thread part (V-17: one thread dies and is respawned, service uninterrupted); pools/Table do not exist yet (E14).
8. Ecosystem fork (Hyperf, own clients, single listeners) → plain Symfony/Laravel via symfony/runtime, AMPHP via a Revolt driver. — **ADDRESSED for AMPHP** (ADR-0008, V-13: Revolt + amphp examples unchanged on `Ignis\Revolt\IgnisDriver`); Symfony ADDRESSED via symfony/runtime (V-16).

### Engine-level (added by the owner, 2026-09-15)
1. GC-triggered destructors may switch context: a `__destruct` running inside `gc_collect_cycles()` could call `Fiber::suspend()` → the scheduler must forbid or safely handle suspension during GC (Zend already calls `zend_fiber_switch_block()` around GC and destructors of dead fibers, zend_fibers.c/zend_gc.c; Ignis must never resume a fiber from inside a destructor and must treat `FiberError` from a blocked switch as a scheduler bug, not a user error). — DESIGNED (ADR-0003, V-7): on backend (b) the engine runs GC destructors in a dedicated GC coroutine via `defer` microtasks (zend_gc.c:2053-2088); on (a) `Ignis\Loop` never resumes from inside a destructor. NOT VALIDATED yet.

### Remains true for Ignis too
Leaks inside C extensions, global statics in third-party libraries (namespace-level constants leak in every worker runtime), thread-unsafe extensions under ZTS. Architecture gives detection, not immunity.
