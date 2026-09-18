# ADR-0016 — Offload pool: run blocking PHP on synchronous worker threads with their own TSRM context

Status: accepted (Cycle 18, 2026-09-16; V-24). Affects pain-map items: Swoole 5 (incomplete hooks → anything not hooked can be offloaded), Swoole 6 (one blocking call stalls the process → it stalls one offload worker), RoadRunner 5 (pipe serialization → the same serialization, but in-process and only for the routed calls), FrankenPHP 3 (threads vs workers → two pools with distinct roles). Depends on ADR-0007 (parking), ADR-0013 (`Op::Custom`), V-22 (function-handler swap).

## Decision

1. A second pool of PHP threads, **offload workers**, each with its own TSRM context and heap, runs synchronous code; the fiber threads never block. Fiber threads and offload workers share nothing but serialized bytes and integer ids.
2. `Ignis\offload(callable|string $fn, mixed ...$args): mixed` copies scalar/array/`__serialize` arguments in, runs `$fn` on a worker, copies the result (or a `RemoteException`) back; the calling fiber is parked on one reactor op. `$fn` is a name the worker can resolve (function, `Class::method`, or something registered through `Ignis\Offload::register(file, name)`); closures are rejected with a message that says why.
3. **Auto-routing** for a configured list of functions and classes — as decided here `curl_*`, `PDO`/`PDOStatement` for pgsql, `SQLite3` and `Redis`, but **the shipped default is `SQLite3` alone** (see the 2026-09-17 addendum below: universal park beats offload for everything that reaches a socket, and the sqlite driver is invisible to `create_object` because it lives in the DSN). The mechanism below is what `IGNIS_OFFLOAD_FUNCTIONS` / `IGNIS_OFFLOAD_CLASSES` still switch on: internal handlers/classes are wrapped at MINIT so that, inside a fiber, the real handle is created and lives on one offload worker (affinity) and the fiber holds a proxy; calls on the proxy are forwarded; arrays come back by copy; callbacks (`CURLOPT_WRITEFUNCTION`) run on the calling thread via a reverse request. Outside fibers nothing changes.
4. Not in scope: moving objects between workers, closures over the wire, streaming results (a `fetch()` loop is one round trip per row), MySQL (`pdo_mysql` is not built), TLS/curl-multi specifics.

## Consequences

- Any blocking extension becomes non-blocking for the fiber thread at the cost of two copies per call; the copy cost is measured and published next to the native-driver cost (E14), so the choice is informed.
- Two knobs instead of one: `--threads` (fiber threads = cores) and `--offload` (workers = how many blocking calls may be in flight). The 8-worker test in the brief demonstrates the second knob bounding wall time.
- Proxies are not the real objects (`instanceof`, `var_dump`); this is documented as the visible seam of auto-routing.

## Addendum (owner ADR sweep, 2026-09-17) — what crosses the boundary, and what offload is for

**Status: accepted** (main agent).

**What crosses.** Scalars and arrays are copied in and out (V-24: 13–67 µs per call, 17–45 µs per
auto-routed call); an exception on the worker returns as a `RemoteException` with the message.
**What does not:** objects and resources. A closure is not copied (`Ignis\offload('strtoupper', …)`
takes a function the worker can resolve — `examples/app.php`).

**Worker-pinned proxies.** A `PDO`, `SQLite3` or `CurlHandle` created through auto-routing lives on
one offload worker and every later call on it is routed to that worker (V-24 addendum: handles
"live on an offload worker and are pinned to it"). *Built.*

**Callbacks.** A callback the library invokes (`CURLOPT_WRITEFUNCTION`) runs on the **caller's
thread**, in the calling fiber, with a `HandleRef` standing in for the handle; inside the callback
only `curl_getinfo` is answered (V-24: `CURLOPT_WRITEFUNCTION` verified; other callbacks
unmeasured). *Built for WRITEFUNCTION; the `curl_getinfo`-only rule is the contract, not a fence
that exists in code — unbuilt as an enforced limit.*

**Offload as vendor quarantine.** A library with per-thread statics or one that must not be
interleaved runs on a worker one call at a time — ADR-0029's isolate ladder, step 3. *Unbuilt as
a policy switch; the mechanism is the existing router.*

**Offload as the universal answer for file I/O** until io_uring is measured: `SQLite3`/`PDO sqlite`
and anything that does `read`/`pread`/`fsync` on regular files — universal park forwards those
(ADR-0020: epoll refuses regular files). *Recorded; sqlite is routed today (V-24).*

**Pool size is the concurrency bound**, documented: 100 blocking 200 ms calls take 2,608 ms on 8
workers and 243 ms on 100 (V-24); docs/operate.md carries the sizing rule. With ADR-0020 on for a
library (V-45: libcurl, libpq), that library leaves the pool and the bound no longer applies to
it — BACKLOG E18-C item 1.

**Options rejected.** Running callbacks on the worker (rejected: the callback is the
application's code and expects its own fiber's scope, V-11); copying objects by serialisation
(rejected: resources and closures do not survive it, and the cost would dwarf V-24's 13–67 µs).

**Consequences.** Better: `curl_*`, `PDO` and `SQLite3` work unchanged today, with a bound the
operator can size. Worse: a worker thread per concurrent call — the reason ADR-0020 exists.
Affects E16, E18, M4-9 (cancelling an offload job on disconnect: unbuilt).

**Kill criterion.** A routed library whose handle cannot be pinned (it migrates its own state
between calls) — then it is `Ignis\offload()` only, never auto-routed.

## Addendum (2026-09-17, V-59) — the default routing list is empty

`curl_*` is no longer auto-routed here. It was, from before universal park existed; measured now,
park beats this pool 8× for the same workload (328 ms against 2,697 ms for 100 × 200 ms on one
thread), costs no worker thread and no copy, and keeps `CURLOPT_WRITEFUNCTION` in the calling fiber
instead of running it on a worker. `DEFAULT_FUNCTIONS` is empty; `IGNIS_OFFLOAD_FUNCTIONS` restores
the old behaviour.

What this ADR is still the answer for is unchanged and is now the whole of it: calls that **cannot**
park — `SQLite3` and a file-backed `PDO`, because `epoll` refuses regular files (ADR-0024) — and
CPU-bound work, which no readiness wait can help. `DEFAULT_CLASSES` is now **`SQLite3`** alone:
routing by class name sent every `PDO` driver to a worker, and `pgsql` parks 9× faster than it
routes (303 ms against 2,753 ms for 100 × 200 ms). The driver lives in the DSN and `create_object`
runs before the constructor's arguments exist, so the runtime cannot decide per driver; a
`pdo_sqlite` application sets `IGNIS_OFFLOAD_CLASSES=PDO,SQLite3` and gets the pool back.
