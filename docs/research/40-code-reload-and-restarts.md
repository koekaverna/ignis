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

### O3 — what RoadRunner and Node actually do, which is not what I first proposed

Before designing a watcher, two implementations that have lived with this longer.

**RoadRunner removed its watcher.** The `reload` plugin *"has been removed from the default plugins
list. Use `*.pool.debug=true` instead"* (`intro/compatibility.md`). And `pool.debug: true` does not
watch anything — it **restarts the worker after every request** (`php/developer.md`: *"Debug mode in
RoadRunner automatically restarts workers after each request, eliminating the need for manual
reloads during development"*). The explicit path is `rr reset [plugin]`, which *"waits for active
requests to finish before reloading"* (`app-server/cli.md`).

So their answer to "how do I see my change" is not "detect the change" but **"stop keeping code
between requests while you develop"**. Nothing can be stale, because nothing survives.

**Node watches the module graph, not directories.** `node --watch entry.js` *"will watch the entry
point and any required or imported module"* (`doc/api/cli.md`) and restarts the **whole process**.
Directory watching is the fallback: `--watch-path=./src` *"disabl[es] automatic watching of required
or imported modules"*, and is *"supported only on macOS and Windows"*. The runtime already knows what
it loaded, so it watches exactly that — and when it cannot, it watches paths and says so.

**What this means for us is not what it means for RoadRunner**, and the owner named the reason:
*restarting the worker after each request destroys concurrency in development*. Our worker serves
several requests at once on one thread — that is the product — and a debug mode that recycles after
every request would make development behave unlike production in exactly the dimension this project
exists for. Every bug of the last week (V-68's token, V-69's identity map, V-85's connection) needs
two requests **overlapping** to appear. A per-request restart would hide all of them until staging.

RoadRunner can converge there because its worker handles one request at a time anyway; we cannot.

**So the primary is Node's model: watch what PHP actually loaded.** Measured on this repository's own
fixture — boot the kernel, handle one request, count:

```
included=283   vendor=258   var/cache=23   own=2
files on disk under vendor/: 4621
```

Three things fall out of those numbers:

- **16× fewer watches** than watching `vendor/`, and a real application's set grows with what it
  uses rather than with what it installed. inotify watches are a per-user resource; this is the
  difference between "fine" and "tune your sysctl".
- **The compiled container is in the set already** — 23 files from `var/cache`. The objection that
  it cannot be ignored is answered by never having an ignore list: those files are watched because
  the application loaded them, and the vendor files it does not use are absent for the same reason.
- **The set is the dependency graph, not a guess.** No globs, no lock-file proxy, no patterns to
  maintain as an application grows.

**The gap, and Node has the same one:** a file that has never been loaded is not watched — a new
class the autoloader has not needed yet, a new config file. Node answers it with `--watch-path`, and
so should we: an explicit list, added to the loaded set rather than replacing it.

**Shape in our architecture.** PHP reports `get_included_files()` after boot and after each request —
the first call is 283 strings, every later one is a delta that is almost always empty — through a zif
to the Rust side, which owns the notify watches and the debounce. A change marks the workers for
reload; they stop one at a time (O2), so requests keep overlapping *through* the reload as well.

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

### O5 — per-request kernel *rebuild* in worker mode (rejected — and note it is not O3's restart)

Not to be confused with restarting the worker per request (O3): that gets a **fresh engine** and is
sound. This is rebuilding the kernel *inside* the same engine, which is not.

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
3. **A watcher over `get_included_files()`** — Node's model, measured at 283 files against 4,621 in
   `vendor/` on our own fixture, with the compiled container included for free. This is the primary
   for us, not the fallback, because it is the only option that **keeps requests overlapping in
   development**, which is where our concurrency bugs are visible at all.
4. **Per-request restart as an opt-in**, RoadRunner's `pool.debug` for people who want it — and
   documented with its cost: it serialises development, so a fiber-scope bug will not appear there.
5. **opcache reset on an explicit reload**, and a line in the documentation for anyone running
   `validate_timestamps=0`.

Steps 1 and 2 are worth doing whatever the development story becomes: a reload without a restart is
an operations feature first — configuration changes, a new deployment on the same host, a stuck
worker recycled deliberately rather than by killing the process.
