# Research 18 — Offload pool: synchronous PHP threads with their own TSRM context (E16)

Date: 2026-09-16T03:05Z (Cycle 18 prep). Sources: Zend/zend_alloc.c (per-thread heaps: `zend_mm_heap` is per `alloc_globals`, a block freed on another thread's heap is undefined behaviour), TSRM (`ts_resource`, one `EG`/`CG`/`SG` per thread; V-15 refcount corruption is what sharing a zval across threads looks like), ext/standard/var.c (`serialize`/`unserialize` as the only portable deep copy PHP ships), ext/curl/interface.c (`CurlHandle` is a zend_object with the `CURL*` inside; `CURLOPT_WRITEFUNCTION` is invoked from `curl_easy_perform` on the calling thread), ext/pdo_pgsql (libpq blocks in `PQexec` on the calling thread; V-12), the Swoole tests' "remote-object server" (research 15) and RoadRunner's worker pipes (pain-map RR 5) as the two prior arts for "run it elsewhere and copy the answer".

## The one rule

Nothing allocated by one PHP thread's heap may be touched by another PHP thread. So an offload is not "run the closure on another thread": it is (1) copy the inputs into the worker's heap, (2) run *code the worker can resolve on its own* there, (3) copy the result back, (4) meanwhile the calling fiber is parked on one reactor op (ADR-0007) and its thread keeps serving other fibers.

## What can cross

| kind | in | out | how |
|---|---|---|---|
| scalars, arrays, `null` | yes | yes | `serialize()` on the caller, `unserialize()` on the worker (and back). Cost is measured, not assumed (the brief asks for the copy overhead per call) |
| objects with `__serialize` | yes | yes | same path |
| closures | **no** | no | a closure is a zend_object bound to a thread; the worker receives a *name*: a function, `Class::method`, or a static method registered with `Ignis\Offload::register()` from a file the worker `require_once`s on first use |
| resources / internal objects (CurlHandle, PDO, SQLite3, Redis) | **never copied** | — | they are *born* on the worker: auto-routing proxies the whole object, pinned to one worker thread (handle affinity) |
| callbacks handed to the routed object (`CURLOPT_WRITEFUNCTION`, `PDO::FETCH_FUNC`) | by id | — | the worker invokes a stub that sends `(job, callback id, args)` back to the calling thread; the caller runs the real closure in a fiber and answers; the worker blocks meanwhile (it is synchronous by definition) |

## Config-driven auto-routing without code changes

- **Functions** (`curl_init`, `curl_setopt`, `curl_exec`, `curl_close`, `curl_getinfo`, …): the internal-function handler is swapped at MINIT exactly like `sleep()` in V-22 (`crates/ignis/src/php/sleep.rs`); the trampoline calls the original when not in a fiber or when the first argument is not a proxy, otherwise forwards `(function name, serialized args, proxy ids)` to the worker that owns the proxy (or to any free worker for constructors like `curl_init`) and parks the fiber. `curl_init()` in a fiber returns an `Ignis\Offload\Proxy` (a userland object with `worker`, `id`) instead of a `CurlHandle`; every routed function accepts either.
- **Classes** (`PDO`, `PDOStatement`, `SQLite3`, `SQLite3Result`, `Redis`): a `new PDO(...)` inside a fiber must yield a proxy. The internal class entry is renamed in the class table at MINIT (`PDO` → `Ignis\Real\PDO`) and a userland `PDO` proxy class with `__call`/`__get` takes its name; the worker side uses `Ignis\Real\PDO`. Methods returning internal objects (`PDO::query` → `PDOStatement`) return proxies; methods returning arrays (`fetchAll`) copy back.
- The list of routed functions/classes is configuration (`IGNIS_OFFLOAD=curl,pdo_pgsql,sqlite3,redis` or an ini entry); nothing in user code changes.
- What auto-routing cannot hide: identity checks (`$h instanceof CurlHandle` is false for the proxy), `var_dump` of the handle, passing the proxy to a function that is not routed (it gets a proxy object; the trampoline's fallback raises a clear `Ignis\Offload\NotRouted` error naming the function).

## Wire shape

- Worker threads: `WorkerThread::attach()` (own TSRM context, own reactor-less loop) running `php/offload/worker.php`: `while ($job = ignis_offload_next()) { … ignis_offload_done($id, $serializedResult) }`. `ignis_offload_next()` blocks on a crossbeam channel; the pool is N threads, N = `--offload N`.
- Caller: `ignis_offload_submit(string $fn, string $serializedArgs, int $affinityWorker = -1): int` → `Op::Custom` future resolving when the worker answers (`Outcome::Blob`); a callback request arrives as a `['kind' => 'offload_cb', 'job' => …, 'cb' => …, 'args' => …]` payload the loop routes to the owning request fiber's callback table; the answer goes back through `ignis_offload_cb_result(job, serialized)`.
- Errors: exceptions on the worker are serialized (`class`, `message`, `code`, trace as string) and rethrown on the caller as `Ignis\Offload\RemoteException`.

## Expectations (H24)

- 100 concurrent `PDO::query('SELECT pg_sleep(0.2)')` in fibers on one PHP thread with an 8-thread offload pool: wall ≈ ceil(100/8) × 200 ms = **2.6 s**, never 20 s; with a 100-thread pool ≈ 200 ms. The brief's "~200 ms wall … limited by pool size" is read as: the wall time is a function of the pool size only.
- `curl_exec` with `CURLOPT_WRITEFUNCTION` set to a caller-side closure: the closure runs on the caller thread, in a fiber, once per chunk, and the response bytes are correct.
- Copy overhead per call: `serialize` + channel + `unserialize` both ways for a 1 KB array, measured in µs, against the 112 µs a native pool query costs (V-21) — the honest comparison is "offload = the compatibility path, native driver = the fast path".
- Needs libphp rebuilt with `--with-pdo-pgsql --with-pgsql --with-curl --with-sqlite3` (sqlite3 is already in). The rebuild waits until no suite is using the current libphp.
