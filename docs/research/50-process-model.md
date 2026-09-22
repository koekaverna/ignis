# Research 50 — process model for a fork-based worker: today's startup order, php-fpm's, and what a fork changes

Date: 2026-09-23. Owner (2026-09-23): keep both engine ABIs (ZTS and NTS, S-NTS-MODE, V-113) and give
NTS a first-class deployment by adopting php-fpm's process model, built engine-agnostically so ZTS
gets it too — a master does `php_embed_init` + MINIT, binds the listen socket, `fork()`s N workers;
each worker builds its own tokio runtime and `Reactor` after the fork and accepts on the inherited
socket; the master supervises children. This note establishes the facts an ADR needs: the exact
startup order today (§a), php-fpm's own model as a parity table (§b), signals and drain (§c), the
accept model (§d), what per-worker facilities the master must instead own (§e), and fork-safety
specifics (§f). Sources are this repository (`grep -n`, cited by file:line) plus PHP internals
knowledge for §b and the general fork/mmap semantics in §f; anything not read here says so.

## (a) The exact startup order today, and what a fork could not survive

`crates/ignis/src/main.rs`, function `main` (`main.rs:29-90`), in order:

| step | line | what happens | creates an OS thread? |
|---|---|---|---|
| 1 | `main.rs:35-39` | `serve` bridges `ignis.toml`/CLI into `IGNIS_*` env vars (`config::serve_to_legacy_args`, `config.rs:1-8`: "before any other thread exists") | no |
| 2 | `main.rs:40` | `initialize_logging()` — `tracing_subscriber::fmt().init()` | no |
| 3 | `main.rs:42-51` | parse `--threads`/`--supervise`, take inline code, resolve the script path | no |
| 4 | `main.rs:53` | `tokio_runtime()` — `Builder::new_multi_thread().worker_threads(2)...build()` (`main.rs:291-293`) | **yes, 2 OS threads** ("ignis-tokio") |
| 5 | `main.rs:54` | `php::module::install_runtime(rt.handle().clone())` | no |
| 6 | `main.rs:55` | `install_signal_drain(&rt)` — `rt.spawn` of a SIGTERM/SIGINT task (`main.rs:192-222`) | no (runs on an existing tokio worker) |
| 7 | `main.rs:56` | `php::module::install_thread_reactor(reactor::Reactor::new(rt.handle()))` — reactor for thread 0 | no |
| 8 | `main.rs:60` | `watch::set_supervised(flags.supervise)` | no |
| 9 | `main.rs:61` | `install_reload_signal(&rt, flags.supervise)` — `rt.spawn` of a SIGHUP task (`main.rs:175-190`) | no |
| 10 | `main.rs:62-64` | `check_single_interpreter_flags` (NTS refuses `--threads`>1/`--supervise`, `main.rs:241-255`) | no |
| 11 | `main.rs:65` | `metrics::mark_start()` (`metrics.rs:63`, a timestamp in a `OnceLock`) | no |
| 12 | `main.rs:66` | `initialize_php_engine(&args)` → `php::embed::Engine::init` → **one** `php_embed_init()` FFI call that runs TSRM startup, `sapi_startup`, MINIT (with the `ignis` module) **and RINIT** (`embed.rs:44-95`, doc comment at `:45`: "Starts TSRM, the SAPI, MINIT (with the ignis module) and RINIT") | no (runs on the calling thread) |
| 13 | `main.rs:70-72` | `check_park_interposers()` → `php::park::selfcheck()` (`park.rs:1134`), a `feature = "universal-park"` probe that only checks third-party libraries the policy names (`libcurl`, `libpq`) are bound — "libphp is always loaded and its call sites are covered by the source audit" (`park.rs:1143-1148`) | no |
| 14 | `main.rs:74` | `print_ready_banner()` (serve only) | no |
| 15 | `main.rs:81` | `spawn_watchdog(&rt)` — `rt.spawn` of a polling task (`main.rs:334-346`) | no |
| 16 | `main.rs:82-86` | `supervise_workers`/`run_workers` — **this is where `spawn_worker` (`main.rs:312-331`) creates the first additional OS PHP thread**, each one calling `WorkerThread::attach()` (`ts_resource_ex` + `php_request_startup`, `embed.rs:181-203`) then `run_file` | **yes, one `std::thread::Builder::spawn` per extra thread** |

**Where the listener actually binds is not in this list at all.** `ignis_serve($address)` is a
*userland* PHP call (`Ignis\Loop::serve()`, `php/packages/runtime/src/ignis.php`) reached through the
`ignis` zif `zif_ignis_serve` (`module.rs:406-429`), which every thread's copy of the entry script
calls once it starts running. The first caller binds (`http::start`, `http.rs:201-217`:
`rt.block_on(TcpListener::bind(address))`, guarded by the `BOUND` mutex), spawns
`accept_loop` (`http.rs:227`) on the tokio runtime, and registers its `Reactor`; every later caller —
another PHP thread running the same script — only registers (`http.rs:203-206`). So today the bind
happens **inside step 16**, deep in a running request-serving script, on whichever thread gets there
first, using an address `config.rs:21` already resolved at step 1 — the value is known before step 4,
the bind itself is not attempted until step 16.

**What must move after `fork()`, and why.** POSIX `fork()` in a multi-threaded process only
continues the calling thread in the child; every other thread, and anything it held a lock on, is
gone without notice in the child (glibc's own `fork(2)` man page: "the child... will deadlock if it
tries to lock a lock that ... was locked by another thread at the time fork was called"). A master
that has already reached step 4 (tokio's two worker threads) before forking hands each child a
tokio runtime whose worker threads do not exist there — `rt.spawn`, `rt.block_on` and everything
built on them (the SIGTERM/SIGINT and SIGHUP handlers at steps 6 and 9, the watchdog at 15, the
listener and `accept_loop` wherever binding moves to) is unusable post-fork in the child unless it
is entirely rebuilt there. Concretely, for a master+fork design:

- **Must move to after the fork, inside each child:** `tokio_runtime()` (step 4) and everything
  that depends on `rt` — `install_runtime` (5), `install_signal_drain` (6), `install_thread_reactor`
  (7), `install_reload_signal` (9), `spawn_watchdog` (15), and wherever `http::start`/`accept_loop`
  end up. Each child needs its **own** tokio runtime built fresh on its own OS threads, per the
  owner's brief.
- **Can stay before the fork, in the master:** steps 1–3 (config/args, no threads), step 2's logging
  init (stderr is inherited by every child unchanged), and — with the caveat in §f below — MINIT.
  **Cannot stay as coded**: step 12 as written also runs RINIT in the same FFI call; see §f.
- **The listener bind moves to before the fork, in the master**, which is a **change from today's
  architecture**, not a relocation of an existing line: nothing in `main.rs` binds a socket today,
  `ignis_serve()` does, from userland, after a request-serving script is already running. The address
  is already known at step 1 (`config.rs:21`, `IGNIS_LISTEN`), so binding it in the master needs no
  new information, but `http::start`'s "first caller wins the bind" logic (`http.rs:201-217`) has to
  become "accept a pre-bound socket" instead — a real code change to `http.rs`, not just a call moved
  earlier. §d has the mechanics (`TcpListener::from_std` after the child's runtime exists).
- **Must run once per child, after the fork:** RINIT (`php_request_startup`) and `WorkerThread`-shaped
  setup, because Ignis's "request" already spans a whole serving lifetime, not one HTTP request (see
  §f) — each child needs its own, exactly as each ZTS worker thread does today via
  `WorkerThread::attach()` (`embed.rs:181-203`).
- **`check_park_interposers` (step 13) can run once in the master**, after MINIT (extensions are
  loaded by then, which is what the self-check needs — `park.rs:1134` comment: "after MINIT
  (extensions loaded, so RTLD_NOLOAD sees them), before any worker thread exists"), since it inspects
  process-wide symbol bindings that fork's copy-on-write preserves identically in every child; nothing
  requires repeating it per child, though repeating it is cheap and not wrong.

## (b) How php-fpm does it, as a parity table

From general knowledge of `sapi/fpm` in php-src (not read against this repository's checkout — no
php-src clone is attached to this task; flagged here rather than cited by line).

php-fpm's `fpm_run()` (`sapi/fpm/fpm_main.c`) sequence: `sapi_startup`, `php_module_startup` (MINIT,
which runs opcache's own MINIT — `zend_shared_alloc_startup()` allocates opcache's shared-memory
segment via `mmap(MAP_SHARED | MAP_ANONYMOUS)` or a SysV/POSIX shm segment depending on
`opcache.shared_memory_mechanism`; kept as `mmap` here since that is the mechanism `MAP_SHARED` is
named for and the one the owner's brief specifies) — then, if `opcache.preload` is set, the **master**
compiles and executes the preload script once (so every child starts with those files already resident
in the shared opcache) — then `fpm_children_make()` `fork()`s the pool. Each child then does its own
per-request `php_request_startup()`/`php_request_shutdown()` cycle in its accept loop, one request at a
time (`pm.max_children`, `pm.start_servers`, `pm.min/max_spare_servers` for `pm.dynamic`, a fixed count
for `pm.static`, on-demand spawn-on-first-request for `pm.ondemand`). Restart coordination:
`SIGUSR2` triggers `fpm_children_bury()`-driven graceful reload — finish in-flight requests, spawn
replacements, then kill the old generation; `SIGTERM`/`SIGINT` are a fast (non-graceful) full-pool
stop, `SIGQUIT` a graceful full-pool stop.

| what fpm gives | Ignis MVP (async platform for Symfony: HTTP, gRPC, SSE, websockets, Messenger, Temporal) | needed for the MVP? |
|---|---|---|
| shared opcache across children (one `mmap` segment, inherited by fork) | same mechanism works for us unchanged — MINIT before fork, in the master | **yes** — this is the point of forking after MINIT rather than before it |
| `opcache.preload` compiled once in the master | not built; today each ZTS worker thread compiles the entry script itself on first run (`run_file_on_current_thread`, `embed.rs:245`) and shares the *compiled* result via the same-process opcache the moment a second thread requests it | not required for MVP — a nice-to-have that shortens child startup, `not measured` whether it matters at Ignis's own startup cost |
| `pm.static`/`pm.dynamic`/`pm.ondemand` (scale worker *processes* with load) | fibers already give in-process concurrency **within** a worker (ADR-0019's admission budget, ADR-0002's fiber pool) — a worker process is not sized per concurrent request the way an fpm child is | **`pm.dynamic` specifically: no** — the reason to run N processes here is CPU parallelism and NTS's one-interpreter-per-process rule, not concurrency, which fibers already supply. A fixed `N = threads`/`N = cores` process count (fpm's `pm.static` shape) is the right analogue, not scale-on-demand |
| graceful reload, `SIGUSR2` (spawn new generation, drain old, then kill it) | `--supervise` + `SIGHUP` already reloads worker **threads** one at a time without closing the listener (`main.rs:175-190`, `watch.rs`, V-90) | **yes, translated** — §c |
| per-request slowlog | not built; `M4-7`'s watchdog answers a narrower question (a thread/reactor idle in PHP past a threshold, `http.rs:88-97`) | not required for MVP; recorded as a gap, not a blocker |
| status page (`pm.status_path`) | `/_ignis/health`, `/_ignis/metrics` (`http.rs:320-380`), process-local today | **yes, but needs rework** — §e: each child answers for itself only; an aggregate view is a master responsibility this ADR does not build |
| `pm.max_requests` (recycle a worker after N requests, leak mitigation) | no equivalent — Ignis's leak mitigation today is the supervisor respawning a worker whose *script* ends (ADR-0012), not a request counter | not required for MVP, a reasonable later addition, `not measured` whether it is needed once RSS is bounded (ADR-0019's open RSS gap, M4-3) |

## (c) Signals and drain: today (threads) versus the fork model (processes)

**Today, `--supervise` (ADR-0012, `docs/adr/0012-supervisor.md`).** Thread 0 stays idle (it owns the
SAPI and cannot be respawned — `php_embed_init` owns it); workers 1..N run the script and are
respawned when their script ends, capped at 10 restarts/minute/slot with a 5 s backoff once exhausted
(`main.rs:351-405`, `supervise_workers`/`Worker`). `SIGTERM`/`SIGINT` (`install_signal_drain`,
`main.rs:192-222`) drain in two phases (`http::drain`, `http.rs:128-153`, V-56/V-75): phase 1 sets
`SHUTTING_DOWN` so `/_ignis/health` answers 503 while the listener keeps accepting, for
`IGNIS_DRAIN_DELAY_MS` (default 0 — "a single instance has nobody to tell"); phase 2 sets
`LISTENER_STOP` and notifies the accept loop, which stops accepting (`accept_loop`'s `tokio::select!`,
`http.rs:230-244`), then the process waits up to `IGNIS_DRAIN_TIMEOUT_MS` (default 10 s) for
`totals().pending == 0` before `std::process::exit(0)` (`main.rs:209-220`). `SIGHUP`
(`install_reload_signal`, `main.rs:175-190`, V-90) is refused unless `--supervise` is set, then calls
`watch::request()` — `watch.rs`'s `RELOADING` counter reloads **one worker thread at a time** ("the
front door always has somewhere to send a request", `watch.rs:47-50` comment), never closing the
listener, so nothing in flight is dropped.

**Fork model equivalent.** A supervised worker *thread* becomes a supervised worker *process*: the
master's `waitpid`-driven respawn loop replaces `supervise_workers`'s `JoinHandle::is_finished` polling
(`main.rs:351-370`), with the same restart-budget shape (10/minute/slot, 5 s backoff) — this is a
faithful translation, not a new policy. Drain becomes two-level: the master receives
`SIGTERM`/`SIGINT`, forwards the same signal to every child (`kill(pid, SIGTERM)`), and each child runs
**exactly today's** `install_signal_drain`/`http::drain` in its own process — nothing in that code
depends on being thread 0 of a shared process, and it already closes its own listener copy (or, under
`SO_REUSEPORT`, its own private one) and waits for its own pending count before exiting. The one new
piece is the master's own wait-with-timeout: it must not exit until every child has (bounded by the
same `IGNIS_DRAIN_TIMEOUT_MS`, or a `SIGKILL` past it, which fpm's fast-shutdown path also does).
`SIGHUP` becomes the master telling `watch::request()`'s process-level analogue to reload children one
at a time — exactly `--supervise`'s reload loop, translated from "one thread out of dispatch, then
respawned" to "one child stops accepting, drains, exits; the master respawns it" — never closing the
shared listener fd itself, since the fd is inherited by every other child unaffected by the reload.

## (d) The accept model

**Inherited listener, every child calls `accept`.** One `std::net::TcpListener` bound in the master
before `fork()`; every child inherits the same file descriptor (fork duplicates fds; the underlying
open file description, and therefore the listen backlog, is shared) and each child's
`accept_loop` (`http.rs:227-244`, unchanged) calls `.accept()` on its own `tokio::net::TcpListener`
wrapping that fd. Without `EPOLLEXCLUSIVE` this is the classic "thundering herd" — every idle child
wakes on each new connection and all but one immediately go back to sleep — but Linux 4.5+ supports
`EPOLLEXCLUSIVE` on a shared listener, which wakes (at most) one waiter per event, and tokio's epoll
reactor is exactly the mechanism that would need to register with that flag to get it (`not measured`
here whether tokio's `mio`/epoll layer sets it by default — this needs checking against the pinned
tokio version before relying on it, not assumed).

**`SO_REUSEPORT` per child** is the alternative: each child binds its own socket to the same
`ip:port` with `SO_REUSEPORT` set, and the kernel load-balances new connections across the sockets
(a hash of the four-tuple, not round-robin) without every child touching the same fd or backlog.
`grep -n "SO_REUSEPORT\|EPOLLEXCLUSIVE\|reuse_port" crates/` finds nothing in this tree today — neither
is built.

**Recommendation: inherited listener with the master binding once, not `SO_REUSEPORT`.** Two reasons,
both from constraints this repository already states rather than from a general preference. First,
the owner's own criterion is "minimum code, maximum compatibility" — an inherited fd needs no change
to how the master resolves `IGNIS_LISTEN` (`config.rs:21`) and no per-child bind-and-retry logic;
`SO_REUSEPORT` needs the master to pass the *address*, not a *socket*, to each child, and needs
per-child error handling for the bind (a port already taken by an unrelated process now fails at
child-count granularity instead of once in the master). Second, `SO_REUSEPORT`'s hash-based balancing
does not consider a child's own admitted-fiber count (ADR-0019) or in-flight requests the way
`Registry::pick`'s least-inflight dispatch already does *inside* a ZTS worker process that runs
`--threads M` (`http.rs:66-83`, ADR-0010) — an inherited listener with one `accept_loop` per process at
least distributes new *connections* by whichever child's kernel-level wait wins, which is closer to
fair than a four-tuple hash that has no idea which child is already loaded. Neither model sees
per-request load across processes (a connection, once accepted by a child, sends every request on it
to that one child — see §e's per-process metrics implication), which is a real gap either way and not
solved by picking between them.

**Hyper/tokio need the listener converted from `std` after the runtime exists in the child.** The
master's `std::net::TcpListener` (bound with `std::net::TcpListener::bind`, *not* `tokio::net::TcpListener::bind`,
since no tokio runtime exists yet in the master under this design) survives `fork()` as an inherited fd
with no tokio reactor registration attached to it. Each child, after building its own tokio runtime
(the first thing it does post-fork per §a), must call `tokio::net::TcpListener::from_std(std_listener)`
**inside that runtime's context** to register the fd with tokio's own reactor before `accept_loop`
(`http.rs:227`) can `.await` on it — `http::start`'s current `rt.block_on(TcpListener::bind(address))`
(`http.rs:210`) needs a sibling entry point that takes an already-bound `std::net::TcpListener` instead
of an address string, for exactly this conversion.

## (e) Health, metrics, dev reload — what moves to the master, and what stays out of this ADR

**Today, per process (which today means: for the whole set of PHP threads in it).**
`/_ignis/health` and `/_ignis/metrics` (`http.rs:320-380`) are answered by the Rust side and sum over
every registered `Reactor` in `REGISTRY` (`http.rs:56-59`, `totals()` at `http.rs:155-162`) —
process-wide, because every PHP thread shares one process and one `REGISTRY`. The watchdog
(`spawn_watchdog`, `main.rs:334-346`) polls `http::stalled_threads` (`http.rs:88-97`) every 250 ms and
logs when the stalled count changes — again process-wide, across every thread's `Reactor`. Development
reload (`watch.rs`) lazily creates one `notify::RecommendedWatcher` per process the first time any
thread's running script calls `Ignis\watch()` (`watch.rs:78-104`, `state()`), reloads worker **threads**
one at a time via the supervisor (`RELOADING`, `watch.rs:47-50`), and resets opcache once per
generation, claimed by whichever thread gets there first (`RESET_GENERATION`, `watch.rs` comment at
top).

**What the master must own instead, kept to what the MVP needs.** A fork model turns "sum over every
registered `Reactor` in this process" into "sum over every registered `Reactor` in *this child*" —
each child can still answer its own `/_ignis/health` and `/_ignis/metrics` truthfully for itself
(unchanged code), but a single external view of the whole pool needs the master to aggregate, which is
new: either the master exposes its own `/_ignis/health`/`/_ignis/metrics` (a second listener, or a
`SO_REUSEPORT`-style extra bound socket just for the master, contradicting §d's recommendation) or an
operator points a load balancer at every child's own port and lets it aggregate (external, no new
Ignis code, and the honest fit for a design that already inherits one shared listener — a request
routed to any child gets *that child's* health, which is correct per-child and simply is not a
whole-pool number). This ADR does not decide which; both are consistent with "minimum code" and
neither is built here. Respawn-on-crash (fpm's job, today ADR-0012's) becomes the master's `waitpid`
loop per §c — that part is not optional, since nothing else in a fork model notices a dead child.
Development reload's per-thread pieces (`RELOADING`, one worker reloaded at a time so the listener
always has somewhere to send a request) translate directly to per-*child* reload the same way §c's
`SIGHUP` handling does; the file watcher itself (`notify::RecommendedWatcher`) is dev-only and cheapest
left exactly where it is — one per child, redundant across children but harmless, rather than a new
master-to-child IPC channel for file-change events that only matters outside production.

**Explicitly not in this ADR:** a slowlog, `pm.max_requests`-style recycling, `opcache.preload` in the
master (§b lists it as a nice-to-have, not required), and the aggregated `/_ignis/health`/`/_ignis/metrics`
question above — named so the ADR's own "what this ADR does not decide" list is not silently empty.

## (f) Fork-safety specifics

**Correction after the build (2026-09-23, main agent).** The MINIT/RINIT split this section asks for
was not needed: `workers.rs` forks after a complete `php_embed_init`, exactly the shape `pcntl_fork()`
has inside a running request, and the child continues with a copy-on-write copy of the request state
while sharing opcache's segment. Both engines serve, respawn, reload on `SIGHUP` and drain on
`SIGTERM` this way (ADR-0044, V-123). The rest of this section stands as the analysis it was.


**mimalloc across fork.** `crates/ignis/Cargo.toml:12` pulls in `mimalloc = "0.1"` as the global
allocator (`main.rs:23-25`), with no explicit feature flags set — the crate's defaults are what ships.
POSIX `fork()` only guarantees a consistent allocator state in the child when the parent was
single-threaded at the moment of the call, or when every allocator lock happened to be unheld by
another thread at that instant — mimalloc, like every allocator with internal locks, is
fork-safe *exactly under that condition* and not in general. Under this design the master forks
**before** `tokio_runtime()` (step 4 in §a) ever runs, so at fork time the process has exactly the one
thread that called `main()` — the condition holds, provided nothing else spins up a thread first
(logging init, `tracing_subscriber::fmt().init()`, is not known to spawn one; `not measured` here).
One open item flagged rather than assumed: newer mimalloc versions can run an optional background
purge/decommit thread, gated by an option (`mi_option_background_commit`/`MIMALLOC_BACKGROUND_THREAD`
in upstream mimalloc); whether the vendored `libmimalloc-sys` version here enables one by default is
**not measured** and should be checked (e.g. by listing threads right after `main()` starts, before
step 4) before relying on "the master is single-threaded" as a fact rather than an assumption.

**PHP's own state at fork time — the central finding of this note.** php-fpm forks after MINIT and
before RINIT. `Engine::init` here does not offer that seam: `php_embed_init()` (`embed.rs:89`) is one
FFI call into libphp that performs TSRM startup, `sapi_startup`, MINIT **and** RINIT before returning
(`embed.rs:45` doc comment, confirmed by php-src's own `sapi/embed/php_embed.c`, which ends
`php_embed_init` with a `php_request_startup()` call) — there is no lower-level entry point exposed
from this crate today that does MINIT alone. This is compounded by a second fact that makes "just
call RINIT once, before fork, and let every child inherit the active request via copy-on-write" the
wrong fix rather than a shortcut: Ignis's notion of "a request" already does not mean one HTTP
request. One `php_request_startup()`/`php_request_shutdown()` pair spans a whole worker's serving
lifetime — thread 0 gets its RINIT from `php_embed_init` and keeps it for as long as `run_file`'s
`Ignis\Loop::serve()` runs (main.rs:66, embed.rs:99-101); a spawned worker thread gets its own
independent RINIT via `WorkerThread::attach()`'s explicit `php_request_startup()` call
(`embed.rs:181-203`) before running the *same* entry script as an independent copy. A forked child
needs exactly that: its own independent RINIT, not a shared one inherited from the master, because it
is about to run its own copy of the entry script and enter its own event loop, with its own
`SG(request_info)`, its own output-buffer stack, and its own per-request module state — sharing one
RINIT across children the way `php_embed_init` shares MINIT's opcache segment would conflate
process-lifetime state (correct to share) with worker-lifetime state (wrong to share, and exactly the
category php-fpm's per-child RINIT already keeps separate for its own, shorter-lived requests).

**The consequence for implementation, not decided here:** the master needs a lower-level engine-start
path that performs everything `php_embed_init` does *except* the trailing `php_request_startup()` —
`sapi_startup(&php_embed_module)`, the hook installation already at `embed.rs:67-88`, and
`php_module_startup` — with each child then calling `php_request_startup()` itself, mirroring
`WorkerThread::attach()`'s existing shape rather than inventing a new one. This is a real change to
`crates/ignis/src/php/embed.rs` (guarded, `unsafe`-bearing, main-agent-only per `CLAUDE.md`), sized as
"split one function into two phases using pieces the crate already calls elsewhere
(`php_request_startup`/`php_request_shutdown` are already direct FFI calls at `embed.rs:198`, `:215`,
`:259`)" — not a redesign, and not identified here as a blocker to the decision, but as the specific,
named precondition an implementation must satisfy before any child can safely be forked mid-startup.

**TSRM under ZTS, one thread at fork time.** `ts_resource_ex(0, ...)` (`embed.rs:186`) is what turns an
OS thread into a second PHP thread under ZTS; nothing in this design calls it before the fork (§a: the
first `spawn_worker` call is step 16, after the master's own MINIT-only phase would end), so TSRM's
own thread registry holds exactly one entry — the master's — at fork time. Each child, after forking,
is a process whose *only* thread is the one the master forked from, and that thread already is TSRM's
main thread (`php_tsrm_startup()`, run inside the (proposed, MINIT-only) engine-start call, "marks the
calling thread as the TSRM main thread" — research 03, `docs/research/03-zts-worker-threads.md:10-11`).
A ZTS child that additionally wants `--threads M` (an engine-agnostic extra per the owner's brief)
then calls `WorkerThread::attach()` from M−1 *new* OS threads it spawns itself, exactly as
`spawn_worker` does today (`main.rs:312-331`) — unchanged, because that machinery was never about
process boundaries. An NTS child never calls `ts_resource_ex` at all (`WorkerThread::attach` is
`#[cfg(php_nts)]`-refused, `embed.rs:176-179`), consistent with "one interpreter per process, no
threads" (V-113).

**File descriptors.** The listener fd exists (bound in the master, §d) and is inherited cleanly — this
is the one fd fork is being used *for*. Everything else that exists at fork time is either harmless to
share (stderr — every child's log lines interleave on the same fd, exactly as every PHP thread's do
today via one process's stderr) or does not exist yet and is therefore not a hazard: the reactor's own
resources (the tokio runtime, its internal epoll fd, any timer fds) are created *after* the fork inside
each child (§a), so there is nothing of the reactor's to duplicate, double-close, or leak across the
fork boundary — every child builds its reactor from nothing, the same way a fresh process always has.
The one thing to verify before shipping, not claimed here: that no third-party PHP extension loaded at
MINIT opens a socket, a file, or spawns a thread of its own as a side effect of extension init (`opcache`
does; its shared segment is the one fd/mapping this design depends on being shared. Others are
`not measured`).
