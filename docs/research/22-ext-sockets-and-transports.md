# Research 22 — ext/sockets blocking surface, and the transports Ignis still hasn't hooked

Date: 2026-09-16. Sources: `/home/koe/php-src` (tag php-8.5.10) `ext/sockets/sockets.c`,
`ext/sockets/sockaddr_conv.c`, `ext/sockets/sendrecvmsg.c`, `ext/sockets/php_sockets.h`;
`main/streams/streams.c` (`php_init_stream_wrappers`), `main/streams/xp_socket.c`
(`php_stream_generic_socket_factory`, `php_tcp_sockop_set_option`); `main/network.c`
(`php_network_gethostbyname`); `/opt/php85-zts/include/php/ext/sockets/php_sockets.h`
(installed headers, same struct); `crates/ignis/src/php/accept.rs` (the park-then-delegate
template, `stream_socket_accept`/`stream_select`); `crates/ignis/src/php/stream.rs` (`install()`,
the `Sock.blocking` field, `op_read`/`op_write`); `crates/ignis/src/reactor.rs` (`Op`/`Outcome`);
`docs/research/06-stream-transport-hook.md`, `docs/research/15-swoole-hooks.md` (read as a
starting point, not truth — its SSL/TCP-server rows predate ADR-0017/E6'').

This is read-only research: no code was written except this note.

## 1. Every `socket_*` function that can block, from the actual syscalls

All line numbers are `ext/sockets/sockets.c` unless noted. "Readiness" means: watching the fd
with `Op::Watch{fd, write}` and then re-running the original handler makes it return at once,
exactly like `accept.rs` does for `stream_socket_accept`.

| PHP function | Blocking syscall (file:line) | Blocks on |
|---|---|---|
| `socket_read($type=PHP_NORMAL_READ)` | `php_read()` → `recv()` sockets.c:381 (called from :967) | READ |
| `socket_read($type=PHP_BINARY_READ)` | `recv()` sockets.c:969 | READ |
| `socket_recv` | `recv()` sockets.c:1461 | READ (see trap on `MSG_WAITALL`, §3) |
| `socket_recvfrom` | `recvfrom()` sockets.c:1562/1586/1613 (AF_UNIX/AF_INET/AF_INET6) | READ |
| `socket_recvmsg` | `recvmsg()` `sendrecvmsg.c:248` | READ |
| `socket_write` | `write()`/`send()` sockets.c:926 | WRITE |
| `socket_send` | `send()` sockets.c:1503 | WRITE |
| `socket_sendto` | `sendto()` sockets.c:1728/1745/1762 | WRITE (+ blocking DNS, see below) |
| `socket_sendmsg` | `sendmsg()` `sendrecvmsg.c:207` | WRITE |
| `socket_accept` | `accept4()`/`accept()` sockets.c:294/301 (`php_accept_connect`, called from `socket_accept` at :756) | READ (listening socket) |
| `socket_select` | `select()` sockets.c:692 | READ/WRITE/exceptfds, multiplexed |
| `socket_connect` | `connect()` sockets.c:1260 (AF_INET6) / :1279 (AF_INET) / :1293 (AF_UNIX) | WRITE, but **only after** the connect call itself returns (see §2c) |
| `socket_addrinfo_connect` | `connect()` sockets.c:2935 — builds its **own** fresh socket inline, then connects it | same shape as `socket_connect`, harder |
| `socket_addrinfo_lookup` | `getaddrinfo()` sockets.c:2829 | DNS — no fd exists yet |
| `socket_create_listen` | `php_network_gethostbyname("0.0.0.0"/"localhost")` sockets.c:249/251 (via `php_open_listen_sock` sockets.c:243) | DNS, then non-blocking `socket()`/`bind()`/`listen()` |
| `socket_connect`/`socket_sendto` with a hostname (not a literal IP) | `php_network_gethostbyname()` inside `php_set_inet_addr`/`php_set_inet6_addr`, `sockaddr_conv.c:95` (called from sockets.c:1256/1275/1741/1758) | DNS, ahead of the connect/sendto syscall |

Not blocking on I/O readiness (excluded): `socket_create`, `socket_bind`, `socket_listen`,
`socket_close`, `socket_shutdown`, `socket_set_block`/`socket_set_nonblock`,
`socket_get_option`/`socket_set_option`, `socket_getsockname`/`socket_getpeername`,
`socket_create_pair` (`socketpair()` sockets.c:2407 is a kernel-local pair creation, not an I/O
wait), `socket_import_stream`/`socket_export_stream`, `socket_last_error`/`socket_strerror`,
`socket_addrinfo_bind`/`socket_addrinfo_explain`, `socket_cmsg_space`, the WSA\* Windows-only
functions (not applicable, this build is Linux).

## 2. The three buckets

### (a) park then delegate works verbatim — 8 functions

`socket_read`, `socket_recv`, `socket_recvfrom`, `socket_recvmsg` (watch READ, then delegate),
`socket_write`, `socket_send`, `socket_sendto`, `socket_sendmsg` (watch WRITE, then delegate),
and `socket_accept` (watch READ on the *listening* socket, then delegate — this is functionally
identical to `stream_socket_accept` in `accept.rs`, down to reusing `php_accept_connect`'s own
`accept4()`/`fcntl()` cloexec dance once the listener is readable). All eight take an already-open
`Socket` object with a live `bsd_socket` fd (`Z_SOCKET_P` / `socket_from_obj`, `php_sockets.h:75`),
so the fd needed for `Op::Watch` is already there before the call — exactly the precondition the
template requires.

### (b) needs the select treatment — 1 function

`socket_select`. Same shape as `stream_select` in `accept.rs`: three arrays of `Socket` objects
(`php_sock_array_to_fd_set`, sockets.c:553) instead of one array of streams; would need the same
copy-probe-park-reprobe dance `hooked_select` already does, reading `bsd_socket` directly instead
of going through `_php_stream_cast`.

### (c) needs a different shape — 4 functions, 3 distinct reasons

| Function | Why park-then-delegate fails |
|---|---|
| `socket_connect` | Same as the documented `socket_connect`-class problem: there is nothing to watch *before* calling `connect()` — the watchable event (the fd becoming writable) only exists *after* the nonblocking `connect()` call has been issued and returned `EINPROGRESS`. A verbatim park-then-delegate would just call the original, which blocks in `connect()` itself (sockets.c:1260/1279/1293) before any fd is watchable. Needs: set `O_NONBLOCK` on the fd first (or trust it's already there — but then the *whole point* of the hook is to make a **blocking** socket non-blocking under the hood), call `connect()`, on `EINPROGRESS` watch WRITE, then read `SO_ERROR` via `getsockopt()` to learn whether it actually connected — and, on success, restore the fd's original blocking mode the caller expects (`php_sock->blocking` is untouched by `socket_connect`; the caller's own `socket_set_nonblock` calls are what set it, and stock PHP leaves a `socket_connect()`'d blocking socket blocking afterward — a hook that leaves `O_NONBLOCK` set would silently change the semantics of every future `socket_send`/`socket_recv` on that socket unless it clears the flag again after the handshake settles). |
| `socket_addrinfo_connect` | The same problem as `socket_connect`, but worse: the function creates the `socket()` **inside itself** (sockets.c:2922) and immediately `connect()`s it (sockets.c:2935) with no separate "open" step a hook could intercept first. A hook would have to reimplement the whole function body (build the fd, set `O_NONBLOCK`, connect, watch, `SO_ERROR`, populate the `Socket` object) rather than wrap the original handler at all. |
| `socket_addrinfo_lookup` | `getaddrinfo()` (sockets.c:2829) blocks on DNS resolution and there is no socket object and no fd anywhere in scope — nothing to `Op::Watch`. This needs the offload pattern (ADR/E16 thread-pool offload — run the blocking call on a worker thread, complete the op when it returns), not a park-then-delegate hook, structurally identical to what research 06 already found for PDO sqlite. |
| `socket_connect` / `socket_sendto` with a hostname argument | Before the `connect()`/`sendto()` syscall even runs, `php_set_inet_addr`/`php_set_inet6_addr` (`sockaddr_conv.c:87-121`) call `php_network_gethostbyname()` (`sockaddr_conv.c:95`, defined `main/network.c:1410`) whenever the address string isn't a literal IP (`inet_pton` fails first, `sockaddr_conv.c:92`). This is a second, independent blocking point *inside* what looks like a single PHP call — a hook that only watches the fd after entering the syscall never even gets there if DNS is slow. Same offload shape as the `socket_addrinfo_lookup` case, stacked in front of the connect-specific problem above. |

`socket_create_listen`'s `gethostbyname("0.0.0.0")`/`"localhost")` call (sockets.c:249/251) is
the same DNS shape in principle, but resolves a literal/loopback name that never leaves
`/etc/hosts` in practice — noted for completeness, not treated as a priority case.

## 3. Correctness traps, with evidence

- **The `blocking` field must gate parking, and the field can lag the fd.** `php_socket.blocking`
  (`php_sockets.h:75`) is bookkeeping PHP updates on `socket_set_block`/`socket_set_nonblock`
  (sockets.c:783/790/821/828), at creation (sockets.c:261,332,734,1217,2430-2431,2891,2933,3108),
  and when importing an fd (`socket_import_file_descriptor`, sockets.c:2579, reads it back via
  `fcntl(F_GETFL)`). But `php_read()` — the function `socket_read($type=PHP_NORMAL_READ)` actually
  calls — does **not** trust `php_sock->blocking` at all: it re-derives non-blocking state itself
  with its own `fcntl(sock->bsd_socket, F_GETFL)` call (sockets.c:349-353) and returns after one
  pass if that flag is set, without ever calling `recv()` a second time. A hook that parks based on
  the `Socket` object's `->blocking` field alone, without also checking the live fd flag, can
  diverge from `php_read`'s own logic if something changed `O_NONBLOCK` on the fd directly (e.g.
  through `socket_import_stream`/`socket_export_stream`, sockets.c:2587/2633, which share the fd
  with a `php_stream`). Ignis's own `stream.rs` already tracks a parallel `blocking: bool` field on
  its `Sock` (`stream.rs:51`, checked at `stream.rs:307` before choosing `Op::Read` vs
  `Op::TryRead`) — an ext/sockets hook needs the equivalent check (`fcntl(F_GETFL) & O_NONBLOCK`,
  matching `php_read`'s own source of truth) before ever parking, or a socket that was explicitly
  set non-blocking would incorrectly block a fiber waiting for readiness the caller never asked to
  wait for.
- **`SO_RCVTIMEO`/`SO_SNDTIMEO` live only in the kernel, never in `php_socket`.**
  `socket_get_option`/`socket_set_option` pass these straight to `getsockopt()`/`setsockopt()`
  (sockets.c:1910-1930 get, sockets.c:2188-2214 set) with **no** mirroring into any Ignis-visible
  struct field the way `default_socket_timeout` is mirrored into `FG()` for streams (`accept.rs:36`
  reads `(*fg()).default_socket_timeout` for exactly this reason). A stock blocking `recv()`/`send()`
  on a socket with `SO_RCVTIMEO` set returns `EAGAIN`/`EWOULDBLOCK` after that many seconds — a
  park-then-delegate hook that just does `Op::Watch{fd, write:false}` with no timer races nothing
  against it and can park the fiber **forever** past where stock PHP would have returned. To match
  stock behaviour the hook would have to `getsockopt(SO_RCVTIMEO/SO_SNDTIMEO)` itself before parking
  and race an `Op::Sleep` against the watch, the same pattern `hooked_accept`/`hooked_select` already
  use for PHP-level timeout arguments (`accept.rs:126`, `:297`) — except here the timeout is not a
  call argument, it is ambient socket state that has to be read out explicitly.
- **`MSG_WAITALL` breaks the "one readiness event ⇒ the delegate call returns at once" assumption.**
  `socket_recv`/`socket_recvfrom`/`socket_recvmsg` pass the caller's `$flags` straight to
  `recv()`/`recvfrom()`/`recvmsg()` (sockets.c:1461, :1562 etc., `sendrecvmsg.c:248`) with no
  filtering. `MSG_WAITALL` tells the kernel to block until the **entire** requested length has
  arrived (or EOF/error/signal) — POLLIN readiness only guarantees "at least one byte is available."
  A hook that watches READ once and then delegates can still have that delegate call block the OS
  thread for an arbitrarily long time if the peer trickles data in slowly, exactly defeating the
  hook's purpose. `accept.rs`'s template never hits this because `stream_socket_accept` has no
  analogous flag — this is new to ext/sockets.
- **No EINTR retry anywhere in ext/sockets.** Grepped `EINTR` across `sockets.c`/`sendrecvmsg.c`:
  zero hits (the only `EINTR` handling in this area is `main/network.c:401`, in the generic
  connect-with-timeout helper `ext/sockets` does not use). A signal that interrupts a blocking
  `recv()`/`send()`/`accept()`/`connect()`/`select()` surfaces as `-1`/`errno=EINTR` straight to
  userland (`PHP_SOCKET_ERROR`, `PHP_IS_TRANSIENT_ERROR` at sockets.c:975). This is not a trap for
  the hook to fix — it means a park-then-delegate hook does not need to add retry logic that stock
  PHP itself doesn't have; parking and then calling the original once matches stock semantics
  exactly here.
- **`socket_connect`'s post-connect state must be restored.** Stock PHP never sets `O_NONBLOCK`
  during `socket_connect` (sockets.c:1260 etc. call `connect()` on the fd exactly as the caller left
  it — `php_sock->blocking` is untouched by the function, sockets.c:1222-1310 has no `blocking`
  write at all). A hook implementing the nonblocking-connect/watch-writable/`SO_ERROR` dance (§2c)
  must flip `O_NONBLOCK` off again after the connect settles if the socket was blocking before,
  or every subsequent `socket_recv`/`socket_send` on that `Socket` silently becomes non-blocking —
  a regression `php_read`'s own `fcntl(F_GETFL)` check (trap 1) would then also propagate into.

## 4. Transports Ignis does not replace

From `crates/ignis/src/php/stream.rs::install()` (:546-572): it reads the stock `tcp` factory out
of the process-wide transport hash (`php_stream_xport_get_hash()`), replaces `tcp` with
`ignis_tcp_factory`, then (ADR-0017, guarded by `IGNIS_NO_SSL_HOOK`) replaces `ssl`, `tls`,
`tlsv1.2`, `tlsv1.3` with the **same** `ignis_tcp_factory` (the `tls: Option<TlsOpts>` branch
inside it does the handshake). Nothing else is touched.

`main/streams/streams.c:1908-1930` (`php_init_stream_wrappers`) registers four protocols against
the **same** stock factory, `php_stream_generic_socket_factory` (`main/streams/xp_socket.c:935`):
`tcp`, `udp`, `unix`, `udg` — dispatching internally on the protocol string
(`xp_socket.c:946-957`) to one of four `php_stream_ops` tables (`php_stream_socket_ops`,
`php_stream_udp_socket_ops`, `php_stream_unix_socket_ops`, `php_stream_unixdg_socket_ops`).
Ignis intercepts the factory only for `tcp` (+ TLS aliases); `udp://`, `unix://`, `udg://` still
resolve to the stock factory and block the thread — this matches what research 15 flagged as
missing and is still current.

What each would need beyond copying the `tcp` pattern:

- **`unix://`** is the closest to `tcp`: `AF_UNIX`/`SOCK_STREAM`, connection-oriented, same
  connect/read/write/close shape. The only structural difference is the address: a filesystem
  path instead of host:port, so `Op::Connect` (`reactor.rs:33`) would need a path-based variant (or
  a `tokio::net::UnixStream::connect(path)` branch alongside the existing
  `tokio::net::TcpStream::connect((host, port))` at `reactor.rs:410`) — the connection-actor
  (`conn_actor`, `reactor.rs:274`) and read/write/close ops are otherwise reusable verbatim since
  it already boxes the stream as `dyn AsyncRead + AsyncWrite` (`BoxStream`, `reactor.rs:88`).
  Server-side `unix://` (`stream_socket_server`) and `stream_socket_pair` (`socketpair(2)` +
  stock socket ops, flagged as a hang in research 15) are separate work reusing the accept.rs
  pattern once a unix listener op exists.
- **`udp://`** is structurally different, not just a new address family: it is connectionless.
  `xp_socket.c`'s `php_stream_udp_socket_ops` reads/writes via `recvfrom`/`sendto` with a peer
  address attached to *each* packet, not a persistent per-connection byte stream. Ignis's
  `Op`/`Outcome` model is built entirely around a `conn: u64` that names one actor owning one
  `AsyncStream` (`reactor.rs:76-88`, `ConnMap`, `conn_actor`); there is no "connection" to open for
  UDP before the first send, and a single bound UDP socket can legitimately talk to many peers.
  A UDP hook needs new `Op` variants shaped like `RecvFrom{sock, max}`/`SendTo{sock, peer, data}`
  over a `tokio::net::UdpSocket` (one actor per *socket*, not per *peer*), plus readiness watching
  for the common "connected" UDP usage the same way `Op::Watch` already works for reads.
- **`udg://`** is the AF_UNIX analogue of `udp://` — `tokio::net::UnixDatagram` in place of
  `UdpSocket`, same `RecvFrom`/`SendTo`-shaped op split, same "no persistent connection actor"
  structural break from the `tcp`/`unix` model.

## 5. Surprises

- `php_read()` re-deriving non-blocking state via its own `fcntl()` call instead of trusting
  `php_sock->blocking` was not obvious going in — it means the struct field name is misleading for
  exactly one of the eight bucket-(a) functions (`socket_read($type=PHP_NORMAL_READ)`), and any
  hook that only checks the struct field would be technically wrong there even though it would be
  right for the other seven.
- `socket_addrinfo_connect` builds and connects its own socket in one call with no separable
  "create" step — this is worse than `socket_connect` for a hook, not just "the same problem
  again." Worth flagging separately rather than lumping it with `socket_connect` in a table cell,
  which is why §2c gives it its own row.
- The DNS-blocks-inside-connect trap (`php_set_inet_addr` calling `php_network_gethostbyname`)
  applies to `socket_sendto` too, which was not expected — `sendto()` for `AF_INET`/`AF_INET6`
  re-resolves the address on **every call** (sockets.c:1741/1758 are inside the per-call address
  family switch, not a one-time connect step), so a UDP script calling `socket_sendto()` in a loop
  with a hostname argument pays a blocking DNS lookup on every iteration, not just once.

## 6. What this rules out

- A single "watch fd, delegate" wrapper cannot cover ext/sockets the way `accept.rs` covers
  `stream_socket_accept`/`stream_select`: 4 of the ~13 blocking functions structurally need either
  the nonblocking-connect dance (`socket_connect`, `socket_addrinfo_connect`) or a thread-pool
  offload for DNS (`socket_addrinfo_lookup`, and the hostname path through `socket_connect`/
  `socket_sendto`). Confirms the "needs a different shape" bucket the task asked to look for is
  real and larger than just the known `socket_connect` case.
- Extending `stream.rs`'s `install()` to `udp://`/`udg://` is not a copy of the `tcp://`/`unix://`
  pattern — it needs new `Op` variants and a different actor shape (per-socket, not
  per-connection) before any hooking work starts. `unix://` and `stream_socket_pair`, by contrast,
  are a straightforward extension of the existing `tcp` machinery.
- A park-then-delegate hook for `socket_recv`/`socket_recvfrom`/`socket_recvmsg` cannot ignore the
  `$flags` argument: `MSG_WAITALL` (and, by the same logic, any other flag that changes how much
  the kernel waits for) means "watch once, delegate once" does not always give "the delegate call
  returns immediately" — that assumption, true for `stream_socket_accept`, is not universally true
  in ext/sockets.

## 7. What I could not determine from source alone

- Whether stock PHP's `MSG_WAITALL` behavior actually matters in practice for any real Ignis
  workload (I did not find or run a reproducer; this is a structural read of the flag's kernel
  semantics via `recv(2)`, not an observed hang).
- The exact `SO_RCVTIMEO`/`SO_SNDTIMEO` default on a freshly created `Socket` object — sockets.c
  never sets these at creation (no `setsockopt(SO_RCVTIMEO...)` call found in `socket_create`,
  sockets.c:1163-1222), so the effective default is whatever the kernel's socket default is
  (unset = block forever), but I did not verify this against a running kernel.
- Whether `EINTR` can actually reach a PHP userland `socket_recv` call in Ignis's threading model
  (worker threads with signals largely masked/handled elsewhere in `embed.rs`) — the trap is real
  at the ext/sockets source level regardless, but I did not check what signal delivery looks like
  on an Ignis worker thread specifically.
