# ADR-0038 — A lock a fiber can hold across a yield must not live on a file

Status: **accepted for the rule** (measured, V-58); the session-handler choice below is
**proposed** and unbuilt. Depends on ADR-0020/0037 (universal park), ADR-0024 (blocking calls on
regular files are a non-goal), ADR-0015 (the PostgreSQL pool), ADR-0012 (the supervisor is what
notices a dead thread). Affects BACKLOG R-SESS.

## Context

Universal park made every wait a fiber's wait — except the ones it structurally cannot reach.
`epoll` refuses regular files, so a blocking call on a file is forwarded and the **OS thread**
waits (research 30 group (d), ADR-0024). Combine that with a lock and it stops being a slowdown:

    [   0 ms] A: holds the lock
    [  51 ms] B: asking for the lock (blocking flock on a regular file)
    <nothing further; killed at 12 s>

Fiber A takes a blocking `flock(LOCK_EX)` and yields. Fiber B, on the same thread, asks for the
same lock; its `flock` cannot park, so it blocks the thread; so the loop can never resume A; so the
lock is never released. **A permanent deadlock of that thread** (V-58), until ADR-0012's supervisor
notices and respawns it, losing every in-flight fiber on it.

Two facts make this sharper than it looks:

- **Park widens the window.** `usleep`, `sleep`, `stream_select`, every socket read and every
  `ext/sockets` call now suspend the fiber. Code that "holds the lock only briefly" in php-fpm
  holds it across a yield here. V-58's second arm demonstrates it: the holder awaits nothing, it
  merely calls `usleep(400_000)` — and that parks.
- **It is not hypothetical.** `ext/session`'s files handler — Symfony's and PHP's default — takes
  `flock(LOCK_EX)` in `mod_files.c:210` and holds it from `session_start()` to
  `session_write_close()`. Two requests sharing a session on one thread is the shape above.

The converse is worth recording because it is the only reason this is not already a fire:
**Symfony's cache stampede lock is safe, and safe *because* of park.** `LockRegistry` never blocks —
the winner races with `flock(LOCK_EX|LOCK_NB)` and a loser polls `flock(LOCK_SH|LOCK_NB)` with
`usleep(100_000)` between attempts. Measured (V-58): under a 600 ms contended wait the losers all
proceeded and a 10 ms ticker ran 60 of 60, i.e. the thread kept serving. With
`IGNIS_NO_UNIVERSAL_PARK=1` the same program never finished — `usleep` blocked the thread, the
winner's timer was never polled, and nothing was ever released.

## Options considered

- **Make file locks parkable.** Rejected: `epoll` refuses regular files by design; it would need a
  fourth mechanism (io_uring, or routing file I/O through offload), which ADR-0024 already declares
  a non-goal and ADR-0037 a budget breach.
- **Detect and forbid at boot.** Refuse to start when a known-deadlocking configuration is set
  (`session.save_handler=files` with sessions enabled). Cheap, and turns a silent hang into a
  sentence.
- **Move the lock onto a socket.** Redis, or PostgreSQL advisory locks over the runtime's own pool:
  the *wait* then happens server-side and the client waits on a socket, which parks. The fiber
  waits; the thread does not.
- **Move the lock into the runtime.** A session store owned by Rust, shared across threads, whose
  lock is a reactor op. Fastest and dependency-free, but new code on the FFI boundary and no
  durability across a restart (ADR-0024).
- **Detect at runtime instead of forbidding.** A watchdog that reports a thread blocked in `flock`.
  Useful, but it reports a deadlock rather than preventing one — complementary, not an answer.

## Decision

1. **The rule, accepted:** a lock that can be held across a yield lives on a socket or inside the
   runtime — never on a file. This is an invariant of the runtime, not a style preference: breaking
   it deadlocks a thread, and park makes yields common enough that "briefly held" is not a defence.
2. **Non-blocking file locks are fine** and need no change: `LOCK_NB` plus a `usleep` poll is the
   shape Symfony's cache uses, and park turns the poll into a fiber wait. Any code that must use a
   file lock is expected to take this shape.
3. **The runtime refuses to start** when sessions are enabled with the files handler (unbuilt;
   BACKLOG R-SESS). A clear refusal beats a thread that stops answering.
4. **The supported session backends** are those whose wait is a socket: `Ignis\Pg` with
   `pg_advisory_xact_lock` (to be shipped with the Symfony adapter — it reuses the pool the runtime
   already owns), Redis via Symfony's own handler, or `PdoSessionHandler`, which works today because
   `PDO` is auto-routed to the offload pool and therefore occupies a worker rather than a PHP
   thread. A stateless application should carry no server-side session at all.
5. **Our own code obeys the rule**: the PostgreSQL pool's leases (ADR-0015) are runtime-owned and
   their waits are reactor ops; the offload pool's job slots likewise. Neither may ever be
   implemented with a file lock.

## Consequences

Better: the failure mode is named and bounded, the cache path is measured safe, and the fix for the
one broken default is configuration rather than code. Worse: a PHP application cannot use the
platform's default session handler, which is a real compatibility cut and belongs in the
compatibility table, the runbook and the deploy recipe — all three now say so. Third-party code
that takes a blocking file lock (a library's own `flock`-based mutex, a `symfony/lock` `FlockStore`)
remains a hazard nothing detects yet.

**Unmeasured, and stated as such:** whether an actual Symfony session reaches that `flock` under the
embed SAPI at all — the probe stopped at `Session cannot be started after headers have already been
sent` (`php_embed_init()` pins `SG(headers_sent)`, which is why `php/packages/runtime/src/classic.php` handles session
cookies itself). V-58 measures the lock mechanism directly with `flock`; the end-to-end Symfony
session path needs its own test before any claim is made about it, and that test is the first step
of decision 4.

## Kill criterion

If a blocking file lock is ever found on a path the runtime itself needs — not application code —
this ADR is wrong about the budget and the fourth mechanism has to be reconsidered with its own
ADR. Research 30 group (d) found exactly one candidate, opcache's `fcntl(F_SETLKW)` under the TSRM
mutex, and it is safe only because `fcntl` is not interposed; `crates/ignis/build.rs` carries that
warning next to the symbol list.
