# ADR-0016 — Offload pool: run blocking PHP on synchronous worker threads with their own TSRM context

Status: proposed (Cycle 18, 2026-09-16). Affects pain-map items: Swoole 5 (incomplete hooks → anything not hooked can be offloaded), Swoole 6 (one blocking call stalls the process → it stalls one offload worker), RoadRunner 5 (pipe serialization → the same serialization, but in-process and only for the routed calls), FrankenPHP 3 (threads vs workers → two pools with distinct roles). Depends on ADR-0007 (parking), ADR-0013 (`Op::Custom`), V-22 (function-handler swap).

## Decision

1. A second pool of PHP threads, **offload workers**, each with its own TSRM context and heap, runs synchronous code; the fiber threads never block. Fiber threads and offload workers share nothing but serialized bytes and integer ids.
2. `Ignis\offload(callable|string $fn, mixed ...$args): mixed` copies scalar/array/`__serialize` arguments in, runs `$fn` on a worker, copies the result (or a `RemoteException`) back; the calling fiber is parked on one reactor op. `$fn` is a name the worker can resolve (function, `Class::method`, or something registered through `Ignis\Offload::register(file, name)`); closures are rejected with a message that says why.
3. **Auto-routing** for a configured list of functions and classes (`curl_*`, `PDO`/`PDOStatement` for pgsql, `SQLite3`, `Redis`): internal handlers/classes are wrapped at MINIT so that, inside a fiber, the real handle is created and lives on one offload worker (affinity) and the fiber holds a proxy; calls on the proxy are forwarded; arrays come back by copy; callbacks (`CURLOPT_WRITEFUNCTION`) run on the calling thread via a reverse request. Outside fibers nothing changes.
4. Not in scope: moving objects between workers, closures over the wire, streaming results (a `fetch()` loop is one round trip per row), MySQL (`pdo_mysql` is not built), TLS/curl-multi specifics.

## Consequences

- Any blocking extension becomes non-blocking for the fiber thread at the cost of two copies per call; the copy cost is measured and published next to the native-driver cost (E14), so the choice is informed.
- Two knobs instead of one: `--threads` (fiber threads = cores) and `--offload` (workers = how many blocking calls may be in flight). The 8-worker test in the brief demonstrates the second knob bounding wall time.
- Proxies are not the real objects (`instanceof`, `var_dump`); this is documented as the visible seam of auto-routing.
