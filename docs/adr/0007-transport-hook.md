# ADR-0007 — `tcp://` transport replaced at MINIT; stream ops suspend the fiber from C; `ignis_poll` resumes them

Status: **superseded by ADR-0037 (cycle 3, V-49, 2026-09-18)** — the factory this ADR installs is deleted; a stream op inside a fiber now blocks in libc and the interposed call parks it (ADR-0020). Kept because V-12 is the measurement the whole stream story rests on and because the ADR states the ownership rules the park path inherited. Originally accepted (Cycle 6, 2026-09-16; accepted by V-12 — 3 × 200 ms unmodified `file_get_contents` in 202.5–202.9 ms on one thread, 100/100 concurrent, hook-off control stalls — and extended to TLS by ADR-0017/V-25; V-22 corrected the server-socket case)

## Context
E6. Research 06. Swoole proves the transport-factory swap; Ignis adds fiber suspension from inside
the stream op and resumption from the reactor's single wait point.

## Options
1. **Factory swap + C-side `zend_fiber_suspend` + resume inside `ignis_poll`** (chosen).
2. Userland-only: intercept `file_get_contents` etc. by function replacement — cannot reach PDO/
   sockets and is not "unmodified PHP".
3. `stream_select`-style readiness with fds (epoll on the PHP thread): keeps blocking syscalls on the
   PHP thread; contradicts "I/O waits owned by tokio".

## Decision
Option 1. ADR-0001's "scheduler stays in userland" is amended: C-parked fibers are resumed by Rust
inside `ignis_poll`; the userland loop remains the only caller of `ignis_poll` and the only place
fibers are started. Outside a fiber the original factory is used (blocking, unchanged behaviour).

## Consequences
- `zend_fiber_switch_blocked()` (GC, destructors) makes an in-op suspend impossible: the op then
  returns an error instead of blocking — the owner's pain-map item (destructors switching context)
  is handled by refusing, and logged.
- Only `tcp` is hooked this cycle (`ssl`/`tls` need a TLS stack on the tokio side: later).
- `cast` is unsupported (no fd): `stream_select()` on hooked streams fails loudly.

## Pain-map items affected
PHP-FPM 1 (I/O wait costs a fiber, not a worker): ADDRESSED for stream-based I/O once V-12 holds.
Swoole 5 (incomplete hooks): partially — tcp only.
Made worse: a blocking sqlite call now stalls a thread that also serves other fibers (was already
true; now more visible). Mitigation = offload pool (later).

## Kill criterion
If `file_get_contents` through the hook is > 2× slower than the stock transport for a local
request, or if resuming from inside `ignis_poll` corrupts fiber state under the `e13`/`e1` tests,
revert to userland resumption with an explicit `ignis_resume_c_waiters()` step.
