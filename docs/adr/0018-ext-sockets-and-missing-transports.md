# ADR-0018 — ext/sockets parking and the missing stream transports

Status: accepted, with kill criterion 2 breached and a replacement proposed (Cycle 22; V-29)  (originally proposed Cycle 22, 2026-09-16; research 22, verified against php-src php-8.5.10 by the main agent). Affects pain-map items: Swoole 5 (incomplete hooks — `SOCKETS` is the largest blocked group in research 15, 46 + 9 tests). Depends on ADR-0007 (stream hook), ADR-0009 (cancellation), ADR-0016 (offload pool). Implements roadmap item A4.

## Context

`ext/sockets` is compiled in and at parity with the stock CLI in `main` mode (80/80 phpt), but it is *untouched*: `socket_create()` returns a `Socket` and every `socket_*` call blocks the OS thread, stalling every fiber on it. `ext/sockets` does not go through `php_stream` at all — there is no transport factory to replace, so ADR-0007's mechanism does not reach it.

Separately, `stream.rs::install()` replaces only `tcp` plus the TLS aliases, while stock PHP registers four transports against the same factory (`main/streams/streams.c:1920-1927`): `tcp`, `udp`, `unix`, `udg`. The three we do not replace fall through to the stock blocking factory inside fibers.

`php_sockets.h` exposes everything a hook needs: `php_socket { PHP_SOCKET bsd_socket; int type; int error; int blocking; zval zstream; zend_object std; }` with `socket_from_obj()` recovering the struct by `XtOffsetOf`, and `socket_ce` exported from libphp (`nm -D`: `B socket_ce`).

## Options

1. **Route `ext/sockets` through the offload pool** (ADR-0016), as `PDO`/`SQLite3` are routed. Rejected: sockets are precisely what the reactor exists for, and this would cost one OS thread per concurrently-blocked socket — the opposite of the thesis.
2. **Convert to a stream via `socket_export_stream()` and reuse the ADR-0007 hook.** Rejected: it changes object identity (`instanceof Socket`, resource vs object), which the phpt suite observes directly.
3. **Hook the internal function handlers and reuse the reactor's existing `Op::Watch`** — the "park, then delegate" pattern already proven by `accept.rs` for `stream_socket_accept` and `stream_select`. Chosen.

## Decision

1. A new `crates/ignis/src/php/sockets.rs` swaps `internal_function.handler` at MINIT for the blocking `socket_*` functions, modelled on `accept.rs`. Outside a fiber, without a reactor, or when the hook is disabled (`IGNIS_NO_SOCKETS_HOOK=1`), the original handler runs unchanged.
2. The hook never reimplements semantics. It parks the fiber on readiness (`Op::Watch { fd, write }`, plus an `Op::Sleep` for any applicable timeout), cancels the losing ops, and then calls the **original** handler, which returns at once because the fd is ready. Error texts, warnings and return shapes stay upstream's.
3. Scope, in three buckets (research 22, verified):
   - **Park-then-delegate, verbatim**: `socket_read`, `socket_recv`, `socket_recvfrom`, `socket_recvmsg` (read readiness); `socket_write`, `socket_send`, `socket_sendto`, `socket_sendmsg` (write readiness); `socket_accept` (read readiness on the listener).
   - **Select shape**: `socket_select`, following `hooked_select` in `accept.rs` (non-blocking probe first, array copies restored before parking, losing timer cancelled).
   - **Out of scope for this ADR**: `socket_connect` (needs `O_NONBLOCK` + `EINPROGRESS` + watch-writable + `SO_ERROR`, and must restore the blocking mode stock PHP never touches), `socket_addrinfo_connect` (`sockets.c:2903` creates the socket *and* `connect()`s it at `:2935` inside one call — no separable open step, so no wrapper can park it; it would have to be reimplemented), `socket_addrinfo_lookup` (`getaddrinfo()` at `sockets.c:2829` — blocking with no fd in existence; structurally the research-06 sqlite case, answerable only by ADR-0016 offload).
4. Blocking state is read from the **fd**, not from `php_socket.blocking`: `php_read()` itself derives it with `fcntl(F_GETFL) & O_NONBLOCK` and falls back to the struct field only when `fcntl` fails (`sockets.c`, `php_read`). A non-blocking socket must never park — the stock semantics are "return now with `EAGAIN`".
5. `SO_RCVTIMEO`/`SO_SNDTIMEO` are read back with `getsockopt` before parking and become the `Op::Sleep` bound. They live only in the kernel (`sockets.c:1910/2188` pass `setsockopt`/`getsockopt` straight through) and are mirrored nowhere Ignis can see, unlike `default_socket_timeout`, which `accept.rs` reads through `FG()`. Without this a parked read hangs where stock PHP would time out.
6. `MSG_WAITALL` disables parking-then-delegating for that call: `POLLIN` means one byte is available, while `MSG_WAITALL` keeps the kernel blocked until the full length arrives. Such calls run the original directly and are recorded as a known thread-blocking path.
7. Transports: `unix://` is added to the hooked factory (a path-addressed `Op::Connect` variant; actor, read and write are reused as-is). `udp://` and `udg://` are **not** added here — they are connectionless and addressed per packet, which does not fit the reactor's one-actor-per-connection model; they need `RecvFrom`/`SendTo`-shaped ops with one actor per *socket*, and get their own ADR.

## Consequences

- The largest blocked group of the Swoole shim (research 15: `sockets/basic/*` 46 tests + `sockets/*` 9) becomes reachable; AMPHP and any library using `ext/sockets` directly stops stalling the thread.
- Three documented thread-blocking paths remain after this ADR: `socket_connect`/`socket_sendto` with a **hostname** (both call `php_network_gethostbyname()` via `php_set_inet_addr`, `sockaddr_conv.c`, *before* the syscall — and for `socket_sendto` on every call), `socket_addrinfo_lookup`, and `MSG_WAITALL` receives. They must be named in STATUS, not left implicit.
- One more place that depends on Zend struct layout (`XtOffsetOf(php_socket, std)`), added to the existing set in `embed.rs`/`sleep.rs`. It is compile-time via bindgen over the installed `ext/sockets/php_sockets.h`, so a libphp rebuild that changes the layout is caught by the build, not at runtime.
- `wrapper.h` gains `#include <ext/sockets/php_sockets.h>`, which couples `ignis-sys` to a build that has `--enable-sockets`. Every build recipe in the repo already passes it.

## Pain-map items affected

- **Swoole 5** (incomplete coroutine hooks): improved — the `SOCKETS` row of research 15 moves from MISSING to PARTIAL (the three paths above stay).
- **RoadRunner 1** (workers blocking on I/O): improved for direct socket users.
- **Made worse**: nothing measured. The added per-call cost is one `fcntl` plus, when a timeout is set, one `getsockopt` before parking; on the non-parking path (already-ready fd) it is the probe only. This is a claim to falsify in the hypothesis, not an assumption.

## Kill criterion

Reversed if any of the following holds after implementation:
1. Fiber-mode `ext/sockets` phpt drops below the local baseline taken in Cycle 22 — that is, the hook breaks parity it was supposed to preserve.
2. The per-call overhead on an already-ready socket exceeds 10 % of the warm round trip **measured on this box** — 3.6 µs via the `sleep(0)` fast path, 3.9 µs via a real timer (C22 baseline, JOURNAL 2026-09-16T08:15:50Z) — i.e. the hook costs more than ~0.36 µs when it does not park. (The 4.5 µs this criterion originally cited is the old 4 vCPU box's figure and is not a valid bound here.)
3. The Swoole shim does not improve on the `sockets/*` group at all, which would mean the blocked group was blocked by something other than the missing hooks.

## Addendum (C22, after implementation — V-29)

1. **Kill criterion 2 is breached.** Measured non-parking overhead is ~1-2 µs, against the 0.36 µs
   bar. The bar itself was wrong: it was set at 10 % of the *fiber round trip*, but the hook adds one
   `poll` syscall to an operation that is already syscall-bound, and a syscall on this WSL2 box costs
   ~0.5-1 µs — no readiness-probe design can meet it. The bench also cannot resolve the difference
   (within-group spread 7.9-13.7 µs against a ~1 µs effect), so no single figure from it is quotable.
   Proposed replacement, **for the owner to accept or reject**: the added cost must stay under 25 % of
   the wrapped operation, measured where a syscall is resolvable, with the comparison of record being
   against blocking the whole thread. Not applied unilaterally; the original criterion stands as
   breached until then.
2. **One design rule was learned the hard way and is now in the code.** Readiness is not the same as
   "the call would succeed": on a listening socket, or an unconnected or unbound one, the original
   fails at once while `poll` never fires, and the original validates its arguments *before* any
   syscall. The first implementation hung six php-src tests on exactly this. `can_block()` now
   refuses to park in those states, under the rule **delegating is always semantically correct;
   parking wrongly is a hang** — anything uncertain reaches the original unparked.
3. **`fcntl` moved off the hot path**: readiness is probed first, and the blocking-mode check is paid
   only when about to park. The ready path is one syscall, not two.
4. Parity after the fix: fiber-mode `ext/sockets` **86 passed / 6 failed** against the C22 baseline of
   85 / 7 — zero new failures, one recovered. Kill criterion 1 is satisfied.
