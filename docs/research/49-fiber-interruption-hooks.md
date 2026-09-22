# 49 — Interrupting a fiber with the engine's own hooks: the owner's draft checked against php-src 8.5.10 and this tree, and the shortest ladder that covers the most states

Date: 2026-09-23, main agent. Status: **research, no code**. Inputs: the owner's draft "Research —
Interrupting a fiber" (M1–M9, H-INT-1..9), php-src at tag `php-8.5.10` (commit `34308a6`, cloned
into the scratchpad for this reading — the `/opt/php85-zts` prefix is not on this box), the tree at
`e909c86`. Every "verify:" marker in the draft is resolved below with a file and line at that tag.
No number in this document is a measurement; line counts are estimates and say so.

Related: ADR-0009 (cancellation, addendum (2) unbuilt), ADR-0012 (supervisor, watchdog), ADR-0020/0037
(park, the two-mechanism budget), ADR-0030 (preemption, proposed, opt-in), ADR-0034 (GC and
destructors, §8.7 of the draft), ADR-0038 (locks, R-SESS closed), BACKLOG M4-7, S-FIBER-TIMEOUT,
R-FOREIGN-FIBER, R-DNS.

## 0. Verdict in one screen

The draft's question is right and its ladder is mostly right, but it reaches for two mechanisms the
engine already provides for free and for two it cannot provide without patching `zend_fibers.c`:

1. **Killing a suspended fiber is `unset($fiber)`.** `zend_fiber_object_destroy` resumes a
   suspended fiber with a `GracefulExit` error transfer, sets `ZEND_FIBER_FLAG_DESTROYED`, runs its
   `finally` blocks, refuses any further `Fiber::suspend()` inside it, and cannot be caught by
   `catch (Throwable)` because `GracefulExit` implements nothing. That is the draft's M2
   ("kill-pending + graceful exit, uncatchable") with **zero engine code**: the loop drops its
   references and the engine does the rest. Verified §1.
2. **Killing a running (CPU-bound) fiber is one atomic store plus one C function of ~30 lines.**
   `EG(vm_interrupt)` is a `zend_atomic_bool` the engine itself stores from another context, the VM
   and the JIT check it on every loop back-edge and every call, and `zend_interrupt_function` may
   throw. Throw the same graceful exit and set the same DESTROYED flag on `EG(active_fiber)`, and the
   running fiber dies exactly like a destroyed one. That is M3-kill. Verified §1.
3. **Cancelling a fiber blocked in a shimmed syscall is a signal plus an `errno` rewrite.** The
   interposer already owns the retry path of every caller that matters (PHP streams, libcurl,
   libpq all retry `EINTR` through the same exported symbol), so `EINTR` → `ECANCELED` on re-entry
   needs no unwinding through C. That is M4, ~40 lines, and it completes ADR-0009's decision (2).
4. **What state the stuck thread is in can be read from `/proc`, not from a signal handler.**
   `/proc/self/task/<tid>/syscall` says `running` or the syscall number and its arguments; `wchan`
   names the kernel wait; `stat` gives `R`/`S`/`D` and CPU ticks. Demonstrated on this box (§4 O1).
   The draft's PC classifier (M6 step 1) is unnecessary for the ladder — only M6 itself needs it.
5. **M5 (locks as parks) is already built and gated** (`ignis_park_flock`, V-58; R-SESS closed
   2026-09-19). M1 exists (V-14). The watchdog exists but only counts (ADR-0012, V-17). M8 exists
   for a *dead* thread (supervisor respawn, E12') and does not exist for a *live stuck* one.
6. **M6 and M7 are out.** M6 needs `zend_fiber_switch_context`'s post-jump bookkeeping
   (`zend_fiber_restore_vm_state`, `EG(current_fiber_context)`, the fcontext handle update), which
   is file-static in `zend_fibers.c`; doing it from a signal handler is a patched engine, a third
   mechanism in a two-mechanism budget, and hazards 2–3 of the draft stay. M7 presupposes the NTS
   carrier model that V-122 gave no reason to adopt.
7. **Three claims in the draft are wrong for this build**: `max_execution_time` is *wall-clock* on
   ZTS Linux (and takes `SIGRTMIN`); GC does *not* block fiber switching (it runs destructors on a
   dedicated fiber since 8.4); and there is a use-after-free hazard in `wait.rs` that the GC
   destructor fiber exposes today, before any of this is built (§7 H1).

The proposal (§6) is one escalation ladder built from items 1–4 and the existing M8/M9, about
**250 lines new** (estimate: ~80 PHP, ~120 Rust, ~40 C) plus ~100 for abandoning a live thread,
and one configuration knob per level. It is inside the mechanism budget: everything is either the
fiber lifecycle the `context` mechanism already owns or a row attribute of `park`.

## 1. The draft against php-src 8.5.10 — every "verify:" resolved

| draft | claim | verdict | where (tag `php-8.5.10`) |
|---|---|---|---|
| §3.4, §7, H-INT-8 | `max_execution_time` counts CPU time | **wrong for this build.** ZTS + Linux enables `ZEND_MAX_EXECUTION_TIMERS` by default (`Zend.m4:452`: `ZEND_MAX_EXECUTION_TIMERS=$ZEND_ZTS`); it is `timer_create(CLOCK_BOOTTIME, SIGEV_THREAD_ID)` — wall clock, the source says so: "Measure wall time instead of CPU time as originally planned". It is useless for fibers for a different reason: `EG(timed_out)` → `zend_timeout()` → `E_ERROR` → bailout of the whole thread's script. Never armed in embed (`max_execution_time=0`) | `Zend/zend_max_execution_timer.c:46-76`, `Zend/zend_execute_API.c:1421-1441`, `sapi/embed/php_embed.c:30` |
| §6 | must not collide with PHP's own timer signal | **sharper than stated**: PHP owns `SIGRTMIN` on every PHP thread (`SA_ONSTACK\|SA_SIGINFO`, no `SA_RESTART`) and chains a foreign `sival_ptr` to the previous handler. Use `SIGRTMIN+2` or later. `--disable-zend-signals` in our build, so no deferral machinery exists to interfere or to help | `Zend/zend_execute_API.c:1586-1595`, `Zend/zend_signal.c:74`, `scripts/build-php.sh:34` |
| §3.5, §8.6 | fiber switching can be blocked (GC, shutdown) | **exists, but the set is different.** `zend_fiber_switch_blocked()` is a counter. Blocked by: `ZEND_TICKS` around `zend_ticks_function`, pcntl's signal dispatch, `zend_fiber_shutdown`. **Not by GC** — see next row. `wait.rs` already checks it before every park | `Zend/zend_fibers.c:402-416, 1156`, `Zend/zend_vm_def.h:8032`, `ext/pcntl/pcntl.c:1388` |
| §8.7, §11 | does PHP ≥ 8.4 run GC destructors in their own fiber, can they suspend | **yes and yes.** `gc_call_destructors_in_fiber` creates `GC_G(dtor_fiber)`; a destructor that suspends parks *that* fiber, GC releases its own reference ("It may be collected if the application does not reference it") and starts a fresh destructor fiber for the rest. A park inside `__destruct` therefore suspends the GC fiber, not the user fiber — and creates hazard H1 (§7) | `Zend/zend_gc.c:1872-1990`, NEWS 8.5: "Infinite loop in GC destructor fiber" fix |
| §5 M2 | `zend_throw_graceful_exit`, `zend_is_graceful_exit`; fiber destruction path | **exist, and destruction is the path.** `GracefulExit` is an internal class that implements nothing (`catch (Throwable)` cannot match it); `finally` blocks run for it (only `UnwindExit` skips them); fast-call cleanup discards a pending user exception in its favour; the uncaught-exception path prints nothing for it | `Zend/zend_exceptions.c:852, 1005, 1069-1084`, `Zend/zend_vm_def.h:8116-8119, 8143` |
| §5 M2 | fiber destroy semantics | `zend_fiber_object_destroy`: only a `SUSPENDED` fiber is touched; `flags \|= ZEND_FIBER_FLAG_DESTROYED`; resumed with the graceful exit as an error transfer; on return, an error transfer that is *not* the graceful exit is rethrown into the destroying frame. `zend_fiber_execute` swallows a graceful/unwind exit **only when DESTROYED is set** — otherwise it becomes an error transfer to the resumer. `Fiber::suspend()` inside a DESTROYED fiber throws `FiberError` "Cannot suspend in a force-closed fiber" | `Zend/zend_fibers.c:769-806` (destroy), `:590-600` (trampoline), `:940` (suspend) |
| §5 M3 | `EG(vm_interrupt)` atomic, set from outside | **yes.** `zend_atomic_bool vm_interrupt` next to `timed_out`; PHP's own timer handler stores both from a signal context on the thread. Address stable for the thread's life (TSRM slot) | `Zend/zend_globals.h:220-221`, `Zend/zend_execute_API.c:1517-1518, 1542-1543` |
| §5 M3 | `zend_interrupt_function` runs at the next opcode boundary | **yes, and it may throw.** `zend_interrupt_helper` clears the flag, `SAVE_OPLINE`, tests `timed_out` first (fatal path), then calls the function; an `EG(exception)` it leaves goes to `HANDLE_EXCEPTION`. Also consulted on the way *into* a call from C (`zend_call_function`). One process-global pointer: chain it (pcntl's pattern) | `Zend/zend_vm_def.h:10526-10560`, `Zend/zend_execute_API.c:1053-1058`, `ext/pcntl/pcntl.c:228-229` |
| §5 M3, §8.8, §11 | how often the VM and the JIT check it | **loop back-edges and every call, in both.** VM: `ZEND_VM_LOOP_INTERRUPT_CHECK` on the JMP family (`zend_vm_execute.skl:28`, generated at `zend_vm_gen.php:2331`), `ZEND_VM_FCALL_INTERRUPT_CHECK` after `DO_FCALL`/`DO_ICALL`/`DO_UCALL` (`zend_vm_def.h:4141, 4270, 4398`). JIT: `zend_jit_check_timeout` at every loop header (`zend_jit.c:1578`), at calls (`zend_jit_ir.c:10587`), inside trace loops (`:17454, :17500`). H-INT-1's "within 2 × watchdog period" is a bound the engine already guarantees for both; a C internal that loops (`password_hash` cost 31, a PCRE explosion) is the exception and is "Running C" in the taxonomy |
| §5 M6 | saved context lives on the fiber's stack; VM state captured/restored around the jump | **true, and that is the problem.** `zend_fiber_switch_context` does `zend_observer_fiber_switch_notify`, `zend_fiber_capture_vm_state`, `jump_fcontext`, then `to->handle = data.handle`, `EG(current_fiber_context) = from`, `zend_fiber_restore_vm_state`, and destroys a dead `to`. `zend_fiber_vm_state` and both helpers are file-static. A jump from a signal handler that skips them leaves `EG(current_execute_data)`, the VM stack pointers and the fcontext handle of the scheduler stale. M6 = a patched engine | `Zend/zend_fibers.c` (`zend_fiber_switch_context`, `zend_fiber_capture_vm_state`) |
| §5 M4 | libraries retry `EINTR` | **PHP: yes, through our symbol.** `php_pollfd_for` (`main/network.c:401`), socket read/write/connect (`main/streams/xp_socket.c:99, 154, 242`), plain files (`main/streams/plain_wrapper.c:391, 459, 470`). opcache's SHM lock retries `EINTR` too (`ext/opcache/zend_shared_alloc.c:509-517`), so a stray signal cannot break it. **libcurl (`lib/select.c`, `Curl_poll`) and libpq (`fe-misc.c`, `pqSocketCheck`) retry `EINTR` in upstream source — not on this box, verify at image build (H-INT-3 reports it per library)** |
| §5 M4 | "installed without `SA_RESTART`" | **install it *with* `SA_RESTART`.** Linux never restarts `poll`, `ppoll`, `select`, `pselect`, `epoll_wait`, `nanosleep`, `clock_nanosleep`, `usleep` after any handler, so the shim's blocking waits still get `EINTR`; `read`/`recv` without `SO_RCVTIMEO`, `flock`, `fcntl(F_SETLKW)` and `waitpid` are restarted transparently — no collateral on code we do not own. Cost: a bare `recv` under policy `block` is not cancellable by signal; it is cancellable by its socket timeout, which the shim already reads |
| §5 M8 | `pthread_cancel` rejected | agreed; nothing to verify |
| §8.7 | "which fiber is current" during a destructor | answered by the GC row: the GC fiber, whose object the loop never sees. Any per-fiber slot (`reserved[]`) on it is empty — the interposer must treat "no request id" as "not ours to kill" |

Also verified, because the proposal rests on them: `zend_fiber` is a public struct (`Zend/zend_fibers.h`)
with `flags` (`:41-43`), `execute_data` (`:123`, the suspension frame — set in
`zend_fiber_suspend_internal`), `stack_bottom` (`:126`), and `context.reserved[]` (`:99`); the
fiber observer API is three registrations (`Zend/zend_observer.h:166-168`) whose notify runs on the
switching thread before the jump; `zend_fiber_suspend()` (the C API `wait.rs` calls) only
*asserts* `!DESTROYED` — in a release build it suspends a force-closed fiber (§7 H2).

## 2. The draft against the tree at `e909c86`

| draft | status in the tree | evidence |
|---|---|---|
| M1 cancel parked | **built** for userland parks (`Fiber::throw`) and C parks (`ignis_cancel_parked_any` → `zend_fiber_resume_exception`); absorbed on the way back | `Loop.php:951-1033`, `wait.rs:131-169`, V-14, V-30 |
| M1 under universal park | **half built**: the cancelled fiber is resumed with the exception, `await_op` sees `EG(exception)` and returns `None`, and the interposer then **makes the blocking call anyway** — the cancellation surfaces only when the library returns on its own | `wait.rs:52-56`, `park.rs:358-375` ("the caller then makes the blocking call as before"), ADR-0009 addendum (2) |
| M2 kill-pending + graceful exit | **not built**; the engine primitive is `unset` (§1) | — |
| M3 interrupt | **not built**; ADR-0030 proposes the preempt half as opt-in; the kill half is not in any ADR | ADR-0030 |
| M4 signal + `EINTR` | **not built**; no `sigaction` anywhere in `crates/ignis/src`; SIGHUP/SIGTERM are tokio signal streams | `main.rs:175-200` |
| M5 lock waits as parks | **built and gated**: `flock` is `LOCK_NB` + timer park with backoff inside a fiber, forwarded outside; `fcntl(F_SETLKW)` deliberately not interposed because opcache's SHM lock is one | `park.rs:987-1041`, `park.c:56-57`, V-58, R-SESS closed |
| M8 abandon thread | **built for a dead thread only**: a worker whose script ends is deregistered and respawned, its in-flight requests fail fast (E12'). A *live* stalled thread is counted by the watchdog and health, and dispatch's least-inflight rule routes new work elsewhere, but nothing deregisters it, fails its in-flight requests or spawns a replacement | `main.rs:334-345, 348-440`, `http.rs:93-101, 324-328`, V-17, V-26/V-28 (E12') |
| M9 restart | **built** (health from the runtime; `--supervise`) | ADR-0012 |
| watchdog (§6) | **built as a counter**: per-thread `last_active_us` touched by `ignis_poll`; a tokio task logs the count every second. It names no fiber, no request, no symbol (M4-7) and has no per-fiber clock | `reactor.rs:470-479`, `main.rs:334-345` |
| per-fiber wall-clock ceiling | **not built** (S-FIBER-TIMEOUT); `Ignis\deadline()` is opt-in per request and is the mechanism to reuse | `Loop.php:1036-1071` |
| parked-fiber identity in C | ADR-0020 says no PHP-level identity reaches the interposer *by design*; the `reserved[]` slot already used by `superglobals.rs` is readable from C on `EG(active_fiber)`, so this is a choice that can be reversed in one field | `superglobals.rs:27-30`, `park.rs:110-117` |

## 3. Terms, kept from the draft, with one addition

Parked / Blocked / Running PHP / Running C / D-state as in the draft §2. Added: **Force-closed** —
a fiber carrying `ZEND_FIBER_FLAG_DESTROYED`: it is unwinding, its `finally` blocks run, it cannot
suspend again, and its termination is silent to the resumer. The engine's word for it is
"force-closed" (`zend_fibers.c:941`); this document uses it for the state M2 and M3-kill produce.

## 4. Hook inventory — what the process already gives us

Each row: what it is, what it buys, its cost, who may call it, and where it is verified. "used"
means the tree uses it today.

### Engine (Zend, public API at 8.5.10)

| # | hook | buys | cost | caller | used |
|---|---|---|---|---|---|
| E1 | **Fiber object destruction = graceful exit** (`zend_fiber_object_destroy`) | kills a *suspended* fiber: `finally` runs, `catch (Throwable)` cannot intercept, further `Fiber::suspend()` throws `FiberError`, termination is silent to the loop. From userland it is `unset()` once the loop holds the last reference | zero mechanism; the unwinding runs synchronously inside the `unset` | the loop, on its own thread | no |
| E2 | **`EG(vm_interrupt)` + `zend_interrupt_function`** | an opcode-boundary callback on a *running* fiber, reached from any thread by one relaxed atomic store; may throw | one branch per loop back-edge and call, already paid by every PHP build; the store is ~1 ns | watchdog thread stores; the PHP thread runs the callback | no |
| E3 | **`zend_fiber.flags \|= ZEND_FIBER_FLAG_DESTROYED` on the running fiber, then `zend_throw_graceful_exit()`** from the E2 callback | turns E2 into E1 for a running fiber: the same force-close, the same silence to the resumer, the same refusal to re-suspend | ~10 lines C | the E2 callback | no |
| E4 | **fiber observers** (`zend_observer_fiber_init/switch/destroy_register`) | the exact instant a fiber gets or loses the CPU, before the jump, on the switching thread; per-fiber slot lifecycle | one indirect call per switch (measured under ADR-0006, V-11) | engine, on switch | **used** (`superglobals.rs`, `park.rs::on_switch`, `scoped.rs`) |
| E5 | **`zend_fiber_context.reserved[slot]`** (`zend_get_resource_handle`) | one pointer per fiber readable from C via `EG(active_fiber)->context.reserved[slot]` — request id, deadline instant, kill flag, running-since, all reachable from the interposer and the E2 callback | one pointer read | anyone on the thread | **used** for superglobal snapshots |
| E6 | **`zend_fiber.execute_data` of a suspended fiber** | function/file/line of the suspension point without resuming: walk `prev_execute_data`, read `func->op_array.filename` and `opline->lineno`. "Where it was parked" for the S-FIBER-TIMEOUT message | a pointer walk | the loop, on its own thread | no |
| E7 | **`zend_fiber_switch_block/unblock/blocked`** | (a) the check `wait.rs` already makes; (b) we may block switching ourselves around any C region where a park would be wrong (a destructor we run, a callback from a library that holds a lock) — the engine's own answer to ADR-0034's "must never resume from inside a destructor" | a counter increment | the thread | (a) **used** |
| E8 | **GC destructor fiber** (`zend_gc.c`) | destructors that park no longer suspend a user fiber; the loop never owns the GC fiber. Answers ADR-0034's open policy: option 2 is what the engine does since 8.4 | none | engine | n/a — see H1 |
| E9 | **`zend_observer_fcall_register`** (per-function begin/end) | enforcement of the table's "PHP function" column without touching internals: mark, warn or deny a call inside a fiber (`SQLite3::query` under policy `block` gets a log line naming the request). Not a hang-recovery hook; listed because the table names it | per observed function only; must be registered before the script compiles | engine, at call | no |
| E10 | not usable: `zend_set_timeout`/`EG(timed_out)` (thread-fatal, §1 row 1), `zend_signal()` (built out), `zend_ticks_function` (needs `declare(ticks)`), `zend_execute_ex`/`zend_execute_internal` (global per-call cost), `zend_on_timeout` (runs before a `noreturn` fatal) | — | — | — | — |

### Kernel and libc (Linux 6.18, glibc 2.39 on this box)

| # | hook | buys | cost | caller | verified |
|---|---|---|---|---|---|
| O1 | **`/proc/self/task/<tid>/syscall`, `/wchan`, `/stat`** | read-only classification of a stuck thread from *another* thread, no signal: `running` = user code (PHP or C); a number = blocked in that syscall, with its arguments (the fd of a `read`/`poll` is argument 0); `wchan` names the kernel wait (`hrtimer_nanosleep`, `futex_wait_queue` = a lock, `nfs_…`); `stat` field 3 is `R`/`S`/`D` and fields 14–15 are CPU ticks (spin vs. wait). Same thread group, so `ptrace_scope` does not apply | three small reads per stalled thread per tick | watchdog thread | **on this box**: a `sleep` child shows `syscall: 230 …`, `wchan: hrtimer_nanosleep`, state `S`, 0 CPU ticks; a Python spin shows `running`, `wchan: 0`, state `R` |
| O2 | **`pthread_kill(tid, SIGRTMIN+k)`** with a no-op `SA_SIGINFO\|SA_RESTART\|SA_ONSTACK` handler | `EINTR` in exactly the non-restartable waits (`poll`, `ppoll`, `select`, `nanosleep`, `usleep`, `epoll_wait`) — which is what the shim forwards when it cannot park — and transparent restart of everything else (`read` without timeout, `flock`, `fcntl(F_SETLKW)`, `waitpid`), so no collateral outside the shim | one signal per escalation | watchdog thread | signal semantics: `signal(7)`; PHP's own `SIGRTMIN` handler flags at `zend_execute_API.c:1591` |
| O3 | **`EINTR` → `ECANCELED` in the interposer** | the retry loops of PHP streams, libcurl and libpq re-enter the *same exported symbol*; when the fiber's kill flag (E5) is set, the shim returns `-1`/`ECANCELED` on re-entry instead of forwarding again. The library fails the operation the way it fails any socket error; PHP sees an ordinary exception. ADR-0009 decision (2), verbatim | one thread-local read per interposed call on the `EINTR` path only | the shim | PHP call sites in §1; libcurl/libpq at image build |
| O4 | **per-thread CPU clock** (`pthread_getcpuclockid`, or `stat` fields 14–15) | spin vs. wait without touching the thread | one read | watchdog | standard |
| O5 | `timer_create(SIGEV_THREAD_ID)` — PHP's own per-thread timer pattern | nothing we lack: the watchdog is a thread and can store `vm_interrupt` directly | — | — | `zend_max_execution_timer.c:60` |
| O6 | not proposed: `sigaltstack` + context jump (M6), `_Fork` (M7, glibc ≥ 2.34 is here), `pthread_cancel` (rejected with the draft) | — | — | — | — |

### Linker (what universal park already is)

The executable's exported symbols are first in every library's lookup scope (research 28,
ADR-0020), and the shim captures the return address per call. Two things that gives for free and the
draft does not list: **(L1)** a per-call-site "we are inside a blocking forward for fiber X since T"
cell, written before the raw syscall and cleared after — the "parked-in-block flag" of the draft's §6,
~10 lines; **(L2)** the library name of that call site (`dladdr`, cached) for the watchdog's log line
("stalled 1.2 s in libpq:poll, request 4711 GET /report") — M4-7 closed at the C level, where the
draft says it cannot be.

## 5. Coverage — state by state, with what closes it and what it costs

| state | detected by | action | hooks | status / estimate |
|---|---|---|---|---|
| Parked, userland op (`Ignis\sleep`, `await`, gRPC) | deadline op, client disconnect, per-fiber ceiling | `Fiber::throw` (M1); if the fiber swallows it and parks again → force-close (E1) | E1 | throw **built** (V-14); force-close ~25 PHP lines |
| Parked, C interposer (`file_get_contents`, `PDO`, `curl_exec` under `park`) | same | `ignis_cancel_parked_any` (M1) **and the shim answers `ECANCELED` instead of blocking** | E1, O3 | half built; ~15 Rust lines in `wait.rs`/`park.rs` |
| Running PHP, VM or JIT (`while (true)`, a hot loop) | watchdog: no poll for `T`; O1 says `running`; CPU ticks advance | E2 store from the watchdog; the callback force-closes `EG(active_fiber)` (E3), answers 504 for its request | E2, E3, E5 | not built; ~30 lines C + ~30 Rust (per-thread `*mut zend_atomic_bool` registry) + ~15 PHP |
| Blocked in the shim: policy `block`, park-failed fallback, timeout-less socket | L1 cell set; O1 shows the syscall | O2 signal → `EINTR` → O3 `ECANCELED` → ordinary exception → M1's unwinding | L1, O2, O3 | not built; ~40 Rust + ~5 C |
| Blocked in an unshimmed syscall (`getaddrinfo`, regular-file read, `fcntl`) | O1 shows the syscall; L1 cell clear | O2 helps only if the retry lands in the shim (glibc's resolver: no); otherwise abandon the thread | O1, O2, M8-live | M8-live not built; ~100 Rust |
| Running C, no return (extension loop, `password_hash` cost 31, a futex deadlock: `wchan: futex_wait_queue`) | O1 `running` (or `futex`), E2 store not acknowledged within `T2` | abandon the thread: deregister, fail its in-flight requests (E12' path), spawn a replacement; after `N` leaked threads health goes non-ok → orchestrator restart (M9) | M8-live, M9 | M8-live not built; M9 **built** |
| D-state | O1 state `D` | nothing; abandon the thread; never signal | M8-live | same |
| Destructor that parks during GC | — | today: hazard H1; after the fix: the GC fiber parks and is resumed like any other, the user fiber is untouched | E8 | ~5 lines Rust |
| Fiber no Ignis loop drives (R-FOREIGN-FIBER) | E5 slot empty | not ours to kill: the ladder skips a fiber without a request id and logs it | E5 | ~3 lines |

Rows one to four are the draft's H-INT-1, -2, -3; row six is where the draft's M6/M7 would sit and
where this document says M8-live is enough for ZTS. Under ZTS, abandoning a thread loses the
fibers on *one thread of N*; under NTS it would lose the process — which is the strongest
argument in this document for staying on ZTS for the carrier model, independent of V-122.

## 6. Proposal — one ladder, in build order, with the budget

Every level is independent of the ones below it and each is a separate hypothesis. Line counts are
estimates, unmeasured, and the split is by file so the review can hold them to it.

**L0 — Per-fiber wall-clock ceiling (S-FIBER-TIMEOUT).** `Loop::admitRequest` arms `deadline()`'s
timer for every request when `fiber_timeout_ms` is set (default off in production, on under
`IGNIS_CHAOS` and in the compat benches); `spawn()` children inherit it as today. The expiry message
names the fiber's suspension point via E6 (`ignis_fiber_where($fiber)`, ~20 lines Rust). ~30 PHP,
~20 Rust. Closes the chaos gate's "the run hangs" (owner, 2026-09-22).

**L1 — M1 as built,** unchanged.

**L2 — Force-close on a swallowed cancellation (M2 by E1).** After `throwInto`, the fiber is marked
kill-pending in its E5 slot (or a PHP-side set keyed by `spl_object_id`). The next time it asks to
park — `parkOn()` in PHP, `await_op` in C — the loop refuses: it answers 504 for the request itself
(the graceful exit is not `Throwable`, so `poolBody`'s `catch` and `answerFailed` never see it and
the responder would otherwise drop with a closed connection, E12's `http 000`), removes the fiber
from `$waiting`, `$parkedOn`, `$children`, `$requestFibers` and `$idle`, and `unset`s it inside a
`try` (the destroy rethrows a non-graceful error transfer into the destroying frame). The engine
unwinds it; `poolBody`'s `for (;;)` is left through its `finally`s, and the pool refills lazily.
`wait.rs`: `GC_ADDREF` while parked (H1), and a `DESTROYED` check that returns `ECANCELED` through
the shim instead of `None` (H2). ~25 PHP, ~15 Rust. A fiber the application holds a reference to
(a stashed `Fiber::getCurrent()`) is not destroyed until that reference goes; it stays kill-pending,
is never parked again, and is logged.

**L3 — Kill a running fiber (M3-kill by E2+E3).** At MINIT, chain `zend_interrupt_function`. Each
worker publishes `&EG(vm_interrupt)` and its `tid` to a registry at attach. The watchdog, on a thread
stalled past `stall_kill_ms` whose O1 state is `running`, records `EG(active_fiber)` and the request
id from the E5 slot, sets the kill flag and stores `vm_interrupt`. The callback (on the PHP thread)
compares `EG(active_fiber)` with the recorded pointer (the stall may have ended a microsecond ago),
sets `DESTROYED`, throws the graceful exit. No acknowledgement within `T2` (the callback never ran)
means the PC is in C — escalate. ~30 C, ~40 Rust, ~15 PHP (504 for the request, pool bookkeeping —
the L2 code). The preempt half (ADR-0030) is not built: kill-on-deadline needs no atomicity
argument, preempt does (draft §8.1), and nothing in the MVP scope asks for time slicing.

**L4 — Cancel a fiber blocked in the shim (M4 by L1+O2+O3).** The shim writes the L1 cell around
every blocking forward. The watchdog, on a thread stalled with the cell set, sets the fiber's kill
flag and `pthread_kill`s `SIGRTMIN+2`. The wait returns `EINTR`; the shim sees the flag on the
retry and returns `ECANCELED`; the library errors; PHP throws; L2 finishes the job if the exception
is swallowed. ~40 Rust, ~5 C (the handler and its `sigaction`, in `park.c`). Reported per library
by H-INT-3: which retries re-entered the shim.

**L5 — Abandon a live thread (M8-live).** A thread stalled past `stall_abandon_ms` with no
acknowledgement from L3/L4 is deregistered from dispatch, its in-flight requests are answered 504
through the E12' fail-fast path, a replacement worker is spawned by the supervisor's existing
respawn, and the stuck thread is leaked and counted (`leaked_threads` in health and metrics). After
`N` leaks health is non-ok and the orchestrator restarts the process (M9). ~100 Rust. This is the
only cover for "Running C" and "D-state" and it is enough for ZTS; it is the reason not to build M6.

**Not proposed, and why:** M6 (patched engine, third mechanism, draft hazards 2–3 remain); M7
(NTS-only, and NTS is not adopted); M3-preempt (ADR-0030 stays proposed and opt-in; no MVP route
needs it); a runtime resolver for `getaddrinfo` (R-DNS, owner 2026-09-17 — L5 covers it as a leak,
not a hang); `pthread_cancel`.

**Configuration:** four knobs, all durations, all in `ignis.toml` with the precedence `config.rs`
already has: `fiber_timeout_ms` (L0, per request; default 0 = off), `stall_kill_ms` (L3/L4; default
10 000), `stall_abandon_ms` (L5; default 30 000), `leaked_threads_max` (L5; default 3). Per-route
overrides are ADR-0030's shape and can come later; nothing here needs them.

**Mechanism budget (CLAUDE.md):** L0–L3 are the fiber lifecycle the `context` mechanism owns
(observers, slots, destroy); L4 is an attribute of a `park` row (the cell, the errno rewrite); L5 is
the supervisor (ADR-0012). No new wait point, no new mechanism, no Zend pointer crosses to tokio
(the registry holds a `*mut zend_atomic_bool` and a `tid`, both plain data whose only use is one
atomic store and one `pthread_kill`).

**Observability (ADR-0022):** one `warn!` per escalation step with thread, fiber address, request
id, uri, age, O1 state, `wchan`, and — from L1/L2 — library and symbol; counters `fibers_killed`,
`fibers_cancelled_in_c`, `threads_abandoned`. The gate for each level is its hypothesis below plus
the existing E6/E11/E12 legs of `smoke.sh` unchanged.

## 7. Hazards found on the way (two are live today)

- **H1 — the GC destructor fiber can be collected while parked in C (use-after-free).** `wait.rs`
  keeps a raw `*mut zend_fiber` in `PARKED` without a reference. A `__destruct` that calls a parked
  function during GC parks `GC_G(dtor_fiber)`; GC then releases its reference (`zend_gc.c:1970`). If
  nothing else holds it, the object is destroyed while suspended → E1 resumes it with the graceful
  exit → `await_op` sees `EG(exception)`, returns `None` → the shim **makes the blocking call inside
  the unwinding fiber** → on return the object is freed and `efree`d → the `PARKED` entry is
  dangling; if the op ever completes, `resume_parked` resumes freed memory. Before this research
  the destructor-in-GC case was ADR-0034's "recorded, not decided"; it is now a concrete path. Fix:
  `GC_ADDREF` on park, `GC_DELREF` on resume (~5 lines), and H2.
- **H2 — `zend_fiber_suspend()` does not refuse a force-closed fiber.** Only the userland
  `Fiber::suspend()` checks `DESTROYED`; the C API asserts it in debug builds and proceeds in
  release. So L2's force-close would re-park a fiber whose `finally` does I/O through the shim,
  and `zend_fiber_object_destroy` would return with the fiber still suspended. `await_op` and
  `await_any` must check `(*fiber).flags & ZEND_FIBER_FLAG_DESTROYED` before suspending and answer
  `ECANCELED` through the shim.
- **H3 — the shim's "could not park → make the blocking call" fallback is wrong for an unwinding
  fiber.** `park.rs:358-375` treats "unwound by a cancellation" like "no reactor": it blocks the
  thread for the whole call. With O3 in place the fallback for that one case becomes
  `-1`/`ECANCELED`; the other two cases (no reactor, switch blocked) keep blocking, as ADR-0018
  wants.
- **H4 — `SIGRTMIN` is PHP's.** Any RT signal we take must be `SIGRTMIN+2` or later, and its
  handler must never be `SA_RESETHAND`.
- **H5 — `EG(timed_out)` is a thread killer.** Nothing must ever set it, and `max_execution_time`
  must stay 0 in every ini the runtime accepts (`IGNIS_PHP_INI`); a user ini that sets it arms a
  wall-clock fatal per thread. Worth one line in `docs/migrate.md` and a startup warning.
- **H6 — the E2 callback and pcntl.** Not in our build, but a PECL build could add it: chain the
  previous `zend_interrupt_function` and call it when the kill flag is clear.
- **H7 — the pool.** A force-closed pool fiber is gone for good; the pool must not hold it in
  `$idle`, and `resumeReady` must tolerate a fiber that is `isTerminated()` by the time its turn
  comes (a kill between "ready" and "resumed").

## 8. Hypotheses — the draft's list, revised

Falsifiable, time-boxed, each the acceptance of one level in §6. ASAN or `USE_ZEND_ALLOC=0` +
valgrind where memory is the question. The draft's H-INT-4/5 (M6/M7) are dropped with the
mechanisms; H-INT-9 is V-58; H-INT-7 waits with ADR-0030; H-INT-8 needs no run — the timer's clock
and its fatal are source facts (§1 row 1).

- **H-INT-0 (L0).** A fixture that parks a fiber forever under `fiber_timeout_ms=2000`: the run
  ends within 3 s with a 504 naming file:line of the park; `bench/e15-chaos.sh` with
  `SUITES=symfony-http-foundation` finishes in bounded time. Control: `fiber_timeout_ms=0` hangs
  until the job timeout, as on 2026-09-22.
- **H-INT-1 (L3).** A fiber in `while (true) {}` beside two parked siblings, `stall_kill_ms=1000`:
  killed within 2 s; its `finally` ran; a surrounding `catch (Throwable)` did not intercept; both
  siblings completed; the loop's `Fiber::resume` returned without an exception; the request got 504.
  Same with `opcache.jit=tracing` and `opcache.jit_buffer_size=64M`. Control: without L3 the thread
  never polls again (V-17's spin).
- **H-INT-2 (L2).** An app catches `CancelledException` from a cancelled `PDO` query and keeps
  going: the fiber is force-closed at its next park within 1 ms; `finally` ran exactly once; the C
  op was answered `ECANCELED` (no `could not park, blocking` in `IGNIS_PARK_TRACE`); 504 answered.
- **H-INT-3 (L4).** `IGNIS_PARK=libpq` (curl under `block`) and `curl_exec` against a blackhole
  address with a 30 s timeout: `curl_exec` returns an error within 10 ms of the signal; a fiber
  reading a regular file on another thread at the same instant is undisturbed (control for
  `SA_RESTART`). Report per library — libcurl, libpq, libphp streams — whether the retry re-entered
  the shim.
- **H-INT-4 (O1 classifier).** Three fixtures — a PHP spin, a `sleep(30)` under `IGNIS_PARK=`
  (block), `password_hash` with cost 31 — on `--threads 4`: within 1.5 s the watchdog logs
  `state=running ack=yes`, `state=syscall:230 wchan=hrtimer_nanosleep`, `state=running ack=no`
  respectively, and the ladder chose L3, L4, L5. Control: `sleep` under the default policy parks
  and is never logged.
- **H-INT-5 (H1).** 1 000 `gc_collect_cycles()` over cycles whose `__destruct` calls `usleep(1000)`
  under park, ASAN build: 0 reports; `gc_destructor_fiber_parked` counted ≥ 1 000. Control: the
  tree at `e909c86` under the same fixture reports a use-after-free or leaks the fiber.
- **H-INT-6 (L5).** `password_hash` cost 31 on 1 of 4 threads, `stall_abandon_ms=2000`: the thread
  is deregistered within 2.5 s, its three other in-flight requests get 504 within 0.5 s of that, a
  replacement serves within 1 s, health reports `leaked_threads=1`; after three such stalls health
  is non-ok. Control: today (V-17) the thread stays registered and its in-flight requests wait
  forever.

Kill criteria: H-INT-1 or H-INT-2 leaving the thread's script dead (the graceful exit reached the
loop's frame) → L2/L3 are wrong as designed, back to `Fiber::throw` semantics only. H-INT-3
showing a library that turns `ECANCELED` into corrupted state (a reused connection returning
another request's bytes) → that library's row is `block` and L4 skips it, per ADR-0009's kill
criterion.

## 9. Open questions

- Should the kill at L3 be a graceful exit (uncatchable, as the draft wants) or an
  `Ignis\KilledException` (catchable, loggable by the application)? The engine offers both from the
  same callback; the graceful exit is what PHP itself does at shutdown and what this document
  recommends, with the request id and the reason in the runtime's log rather than in an exception
  the application may never see.
- L2 answers 504 before the `unset`; if a `finally` in the dying fiber writes to the response
  (`echo` in a `finally`), the output goes to a request that already answered. `Output` per fiber
  (E13) already isolates it; confirm in H-INT-2 that nothing reaches the socket.
- Does a `Fiber::resume` of a fiber killed between "ready" and "resumed" (H7) throw
  `FiberError: Cannot resume a fiber that is not suspended`? Expected yes; `resumeReady` should
  test `isTerminated()` first.
- libcurl and libpq `EINTR` behaviour on the image's versions (H-INT-3 answers it).
- Whether `stall_kill_ms` wants a per-route override in the MVP; ADR-0030 assumed per-route for
  preempt, and nothing here needs it yet.
