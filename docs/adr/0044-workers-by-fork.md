# ADR-0044 — Workers by fork: one master binds and forks, the same way on both engines

Status: accepted 2026-09-23 pending V-123 (research 50; DECISIONS 2026-09-23). Built in
`crates/ignis/src/workers.rs`; the flag is `--workers N`, the config key is `workers`.

## Context

The owner kept both engine ABIs (DECISIONS 2026-09-23): ZTS, where one process fills the cores with
PHP threads (ADR-0004, ADR-0010), and NTS (S-NTS-MODE, V-113), where a process can hold exactly one
PHP thread and so, until now, one core. Every PHP a distribution ships is NTS, and V-122 showed the
two engines serve at the same speed on one thread, so NTS needs a way to use the other cores that
is not a second thread. php-fpm's answer is the process: a master runs MINIT once — opcache maps its
shared segment there with `mmap(MAP_SHARED | MAP_ANONYMOUS)` — binds the socket, forks, and the
children inherit both the segment and the socket. ADR-0004 rejected "process-per-core" for losing
the shared opcache and the in-process Table; the Table was never built and the opcache objection is
wrong for a fork, which inherits the mapping rather than copying it.

## Decision

`ignis --workers N` (config `workers = N`) makes the process a master: it initialises the engine as
today, binds `IGNIS_LISTEN` with `std::net::TcpListener` before any runtime thread exists, and forks
N workers. A worker is exactly what a single-process `ignis` was: it creates its own tokio runtime,
reactor, signal tasks and watchdog after the fork and runs the script; `http::start` adopts the
inherited socket instead of binding, and refuses an address that differs from the master's. The
master never runs PHP after the fork; it reaps, respawns under `serve` or `--supervise` (restart
budget 10/min, then a 5 s pause, like the thread supervisor), forwards `SIGTERM`/`SIGINT` with a
`IGNIS_DRAIN_TIMEOUT_MS` + 2 s deadline before `SIGKILL`, and turns `SIGHUP` into a rolling reload:
one worker at a time is drained and replaced, so the socket is never without an accepter.

The model is engine-agnostic; `--threads M` inside a worker stays the thread-safe build's extra.
`serve` defaults follow the engine: ZTS `workers = 1, threads = cores, supervise = true`; NTS
`workers = cores, threads = 1, supervise = false` (the master is the supervisor there, and the thread
one is refused). A plain script run with `--workers` waits for every worker and answers with the
worst status, as `--threads` does for threads.

Research 50 expected the fork to need `Engine::init` split into MINIT and RINIT halves. It does not:
the master forks after a complete `php_embed_init`, which is the shape `pcntl_fork()` has always
had inside a running request, and the child continues with a copy-on-write copy of the request
state while sharing the opcache segment. Nothing at the FFI boundary changed for this ADR.

Rejected: N independent processes behind `SO_REUSEPORT` (no shared segment, N MINITs, N binds
that can each fail, and an operator can still run it that way with any supervisor); a fork model
for NTS only (the owner asked for one model, and nothing in the mechanism sees the engine).

## Consequences

- One opcache segment for N processes on either engine; the master's engine stays alive and idle so
  the segment outlives every worker.
- A worker's fatal, panic or kill costs one process, not the server; the boundary is a process, not
  a thread's TSRM context (ADR-0012).
- `/_ignis/health` and `/_ignis/metrics` are per worker, and development reload (`watch.rs`) reloads
  threads inside one worker, not workers — both are BACKLOG items, not part of this ADR.
- Per-worker cost is one tokio runtime, one reactor and one RINIT; the numbers are V-123 (E26).

## Kill criterion

E26 on this box, `wrk -t2 -c64 -d10s` on `/` and `/cpu` of `examples/hello_server.php`: if four NTS
workers serve under 90 % of four ZTS threads on either route, or the resident memory of four workers
exceeds three times that of one four-thread process, the fork model does not pay for its isolation
and NTS goes back to one core per process while a cheaper shape is found.
