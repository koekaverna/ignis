# Research 40 — reloading code without restarting the process

Date: 2026-09-18. Owner: dig into restarts and code reloading, especially for development.

## 1. What "reload" can mean in PHP at all

PHP cannot unload a class. Once `App\Controller\Foo` is declared in an engine context it is there
until that context dies. Every reload story in every PHP worker — ours, RoadRunner's, Swoole's,
FrankenPHP's — is therefore the same story: **get a fresh engine context and run the entry script
again**. What differs is only when you get one and what it costs.

That is why this document has no option called "hot swap the class". There is no such thing, and
proposing it would waste the next reader's afternoon.

## 2. What we already have, and it is most of it

`spawn_worker` (`main.rs:300`) is already a reload primitive:

```rust
install_thread_reactor(Reactor::new(&rt_handle));
let mut w = WorkerThread::attach()?;      // a fresh TSRM context: new class table, new statics
let status = w.run_file(&script);         // returns when the entry script ends
php::http_unregister_current();           // this thread leaves HTTP dispatch
```

and `--supervise` respawns a worker whose script ended, up to ten restarts a minute
(`main.rs:339`). Two properties make this the right foundation:

- **The listener is process-wide.** `http::start` binds once (`BOUND`), and threads register with it;
  a thread can die and come back without the socket closing or a connection dropping. V-17 measured
  a fatal ending one of four workers with recovery inside the 50 ms supervisor tick and throughput
  back to 95.7 % of baseline.
- **Dispatch is per thread.** `http_unregister_current()` takes one thread's reactor out of the
  registry, so the front door stops sending it work while the others keep serving.

**What is missing is the trigger.** A worker's script ends today only by fatal error or by returning
— and `Ignis\serve()` never returns (`S-SERVE-STOP`). There is no way to say "finish what you are
doing and come back new".

One ordering detail to fix while doing it: `http_unregister_current()` runs *after* `run_file`
returns, so during a deliberate restart the thread would still be receiving requests while it winds
down. A reload wants unregister → drain in flight → return.

## 3. Where the pain actually is

| mode | what happens when a file changes | why |
|---|---|---|
| **classic** (`Ignis\Classic`, legacy apps) | picked up within ~2 s, no restart | the script is included per request, and opcache's defaults (`validate_timestamps=1`, `revalidate_freq=2`) recompile it |
| **worker** (Symfony, `IgnisWorkerRunner`) | **nothing, ever** | the kernel is booted once per thread; the classes are in that engine and the file is never included again |

So development pain is specific: it is worker mode, and today the only answer is to restart the
process. That is the thing to fix.

## 4. Options

### O1 — `Ignis\stop()`: end the script on demand (the prerequisite)

Stop accepting on this thread, let in-flight requests finish, return from `serve()`. With
`--supervise` the supervisor then respawns the thread with a fresh engine; without it, the script
simply ends, which is what a test or a one-shot task wants. Filed as `S-SERVE-STOP`; everything
below needs it.

### O2 — `SIGHUP` = rolling restart of the workers

The signal handler sets a flag; each worker's loop sees it at its next turn, unregisters, drains,
returns. The supervisor respawns them **one at a time** — with N threads, N−1 keep serving while one
reloads, so a reload costs a fraction of capacity rather than an outage. This is the half of M4-5
(reload without restart) that has been waiting for O1, and it is the same shape as Swoole's
`Server::reload` and RoadRunner's `rr reset`.

The gate writes itself: `wrk` against `/` across a reload, zero non-2xx, and the new code answering
afterwards.

### O3 — a file watcher, development only

`notify` on the project directory, a debounce (a save often produces several events, and composer
rewrites hundreds), and then O2. FrankenPHP's watcher and RoadRunner's `reload` service are exactly
this. It belongs behind an explicit flag — `ignis --watch` or `watch = ["src", "config"]` in
`ignis.toml` — and must be off in production, where a stray `touch` reloading the fleet is an
incident, not a feature.

Debounce and ignore rules matter more than the watching: `var/cache`, `var/log`, `vendor` during an
install, and anything the app writes at runtime are what make a naive watcher restart in a loop.

### O4 — opcache is a separate decision, and the default is deliberately wrong for dev

A respawn leaves opcache SHM untouched on purpose: V-17 counts that as a **production** win —
php-fpm recycles a worker and pays recompilation, we do not. In development the same property is the
bug: a fresh engine can compile from a cache that still holds the old file if timestamps are not
being validated.

The defaults save us (`validate_timestamps=1`, `revalidate_freq=2`), but a production ini that sets
`validate_timestamps=0` — the recommended production setting everywhere — makes reload silently do
nothing. So a reload must either call `opcache_reset()` as part of the cycle, or document
`revalidate_freq=0` for dev. Recommended: **reset on an explicit reload, leave a respawn-after-fatal
untouched**, because the two events mean different things.

### O5 — per-request kernel rebuild in worker mode (rejected for now)

`IgnisWorkerRunner` could check `Kernel::isDebug()` and rebuild the kernel per request. It gives
instant feedback with no signal and no watcher, and it costs a kernel boot per request — turning the
one thing worker mode exists for into what php-fpm already does. Worse, it only reloads what the
kernel rebuilds: classes already declared in that engine stay as they were, so the illusion holds
until the moment it stops. Cheap to write, and it would teach the wrong model.

## 5. What other runtimes do

| | how a reload happens |
|---|---|
| php-fpm | nothing to do: a process per request, code loaded per request |
| Swoole | `Server::reload()` — rolling restart of the worker processes; connections survive because the master holds the socket |
| RoadRunner | `rr reset` / the reload service watches files and recycles the pool |
| FrankenPHP | a watcher restarts the workers; without it, worker mode has the same "nothing, ever" as ours |
| **Ignis** | the supervisor already restarts a worker with a fresh engine and a live listener — it just has no way to be asked |

The shape everyone converges on is the rolling restart, for the same reason: it is the only thing
PHP's inability to unload a class leaves available.

## 6. Recommendation

1. **`Ignis\stop()`** (`S-SERVE-STOP`), with the unregister-before-drain ordering. Nothing else is
   possible without it, and it is independently useful for tests.
2. **`SIGHUP` rolling restart**, one worker at a time, gated on "zero non-2xx across a reload under
   load" and on the new code actually answering.
3. **`--watch`, development only**, with the ignore list treated as part of the feature.
4. **opcache reset on an explicit reload**, and a line in the documentation for anyone running
   `validate_timestamps=0`.

Steps 1 and 2 are worth doing whatever the development story becomes: a reload without a restart is
an operations feature first — configuration changes, a new deployment on the same host, a stuck
worker recycled deliberately rather than by killing the process.
