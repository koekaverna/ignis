# ADR-0028 — Laravel compatibility model

Status: **accepted** for the model (main agent, owner ADR sweep 2026-09-17); implementation open
(BACKLOG M3-5a now, M3-5b later). Affects M3, pain-map Swoole 1 / FrankenPHP 1, ADR-0006.

## Context

Research 25 read `laravel/octane` v2.19.1 (commit 68a2516) from source: every shipped Octane
server (Swoole, RoadRunner, FrankenPHP) runs `Worker::handle()` **one request at a time per
engine**; `Worker` swaps a process-static `Illuminate\Container\Container::$instance` per request
and ~28 listeners re-point Laravel's manager singletons — safe only because nothing interleaves.
Ignis interleaves fibers on one thread, so an unmodified Octane worker inside interleaved fibers
corrupts the container the way the pre-ADR-0006 superglobals did. `octane:start --server=` is a
closed `match`; a fourth server is its own `octane:ignis` command. Ignis already has the
request-marshalling half: `IgnisWorkerRunner` is structurally `FrankenPhpClient::marshalRequest`.
`php/classic.php` runs one `include` per request but only *assumes* no suspension ("a classic
script must not suspend").

## Options considered

(a) An Octane client for Ignis with interleaving — rejected *today*: it needs a fiber-scoped
`Container::$instance` and Facade caches first (ADR-0006 addendum item 5). (b) A hand-rolled
runner booting `bootstrap/app.php` and calling `Kernel::handle` per fiber — rejected: strictly
dominated by (a), same unsolved problem, no reference implementation. (c) Classic mode with the
budget set to one request per thread — safe by configuration, not by discipline; it is Octane's
own model. (d) Classic mode relying on classic.php's assumption alone — rejected: a hooked
`file_get_contents` inside a request would suspend and let a neighbour in.

## Decision

1. **Now (M3-5a): Laravel runs in classic mode with `budget.fibers = 1` per thread.** ADR-0019's
   admission makes the serialisation a guarantee: the second request waits as data. Hooks stay
   on; `threads` gives the parallelism; this is exactly "one request per worker at a time".
2. **The control run at `budget.fibers = 2` must fail** the container-identity test (two clients
   hitting a route that does a hooked fetch mid-request; `spl_object_id(app())` before and after
   must match in both). If it does not fail, the hazard model is wrong and that is the finding.
3. **Later (M3-5b): a fiber-scoped `Container::$instance` and Facade caches on the ADR-0006
   observer**, its own ADR with research 25's option-(a) kill criterion; then an `octane:ignis`
   command and the interleaved model.

## Consequences

Better: Laravel works on day one with the same guarantees Octane gives, and the compat table can
say so honestly. Worse: no fiber concurrency inside a Laravel thread until M3-5b — the throughput
story for Laravel is threads, not fibers, for now. Affects M3, E8'.

## Kill criterion

M3-5a's control run passing at budget 2 (the hazard is not real for the tested Laravel version),
or M3-5b's interleaving test failing after the container is scoped (the scoping is incomplete —
research 25 lists the ~28 listeners that would each need a slot).
