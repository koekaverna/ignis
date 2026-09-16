# 30 — libphp's blocking call sites: a symbol-level audit (placeholder; ADR-0037 §5)

Status: **groups (a), (b), (c), (d), (e) done.** This document exists so the gate in ADR-0037 §6
step 2 has a name. One `block` verdict found in (d)/(e): `fcntl(F_SETLKW)` at
`ext/opcache/zend_shared_alloc.c:510` (`zend_shared_alloc_lock()`), reached with the TSRM mutex
`zts_lock` held (`:499`) — and reachable **per-request**, on any opcache cache-miss compile
(`persistent_compile_file` → `cache_script_in_shared_memory`, `ZendAccelerator.c:1995`/`:2185`),
not just at RINIT the way group (b)'s `usleep` was. Everything else in (d)/(e) with an fd is a
regular file and forwards regardless of interposition (`would_block()`'s `S_IFREG` exclusion);
everything without an fd (DNS) is offload's, not park's, same as groups (a)/(c) already found.

## Group (b) — `sleep` / `usleep` / `nanosleep` in libphp (audited 2026-09-17, php-8.5.10)

`grep -rnE '\b(usleep|nanosleep|sleep)\(' main Zend TSRM ext/standard ext/sockets ext/openssl
ext/opcache ext/session ext/pcntl` over the compiled tree (`--disable-all` + ADR-0027 §4's list):

| symbol | call site | lock held on the path | verdict |
|---|---|---|---|
| `sleep` | `ext/standard/basic_functions.c` `PHP_FUNCTION(sleep)` → `php_sleep` = `sleep` (`main/php.h:264`) | none — PHP function body | park |
| `usleep` | `ext/standard/basic_functions.c:1154` `PHP_FUNCTION(usleep)` | none | park |
| `nanosleep` | `ext/standard/basic_functions.c:1182` (`time_nanosleep`), `:1233` (`time_sleep_until`) | none | park |
| `usleep` | `main/streams/plain_wrapper.c:446` | — | not compiled on Linux (`PeekNamedPipe`, Windows pipe read-ahead) |
| `usleep` | `ext/opcache/ZendAccelerator.c:863`, `:874` in `kill_all_lockers()` ← `accel_is_inactive()` (`:935`) ← `ZEND_RINIT_FUNCTION(zend_accelerator)` (`:2652`) between `zend_shared_alloc_lock()` (`:2713`) and unlock (`:2759`) | **yes, the opcache SHM lock** — but the site runs in RINIT on the main fiber (gate 0 → the real `usleep`), and only when *another process* holds the lock | park (unreachable inside a fiber under this runtime; revisit if RINIT ever runs in a fiber) |

Test: V-46 — V-22's gate through park with the hook deleted.

## Group (a) — `ext/sockets` (audited 2026-09-16, php-8.5.10)

Every call site in `ext/sockets/sockets.c` and `ext/sockets/sendrecvmsg.c` that reaches one of the
target libc symbols, read from source (`~/php-src` at tag `php-8.5.10`). `ext/sockets` is built in
(`--enable-sockets`, ADR-0027 §4). No lock (TSRM, opcache SHM, or otherwise) is held at any of
these call sites at the moment of the syscall — the one mutex found (`ancillary_mutex`, below) is
released well before it. Compared against `crates/ignis/src/php/sockets.rs`, which currently hooks
`socket_read/recv/recvfrom/recvmsg/accept/write/send/sendto/sendmsg` (park via
poll-then-delegate) and leaves `socket_connect`/`socket_addrinfo_connect`/`socket_addrinfo_lookup`
unhooked (ADR-0018).

| symbol | file:line (php-8.5.10) | enclosing function | reached from | lock held? | fd mode at the call | timeout handling | verdict |
|---|---|---|---|---|---|---|---|
| `accept`, `accept4` | `sockets.c:294` (`accept4`), `:301` (`accept` fallback) | `php_accept_connect` | `PHP_FUNCTION(socket_accept)` (`:739`) | none | `in_sock->blocking` (mirrors the fd's real O_NONBLOCK, set by `socket_set_nonblock`/`socket_set_block`); default is blocking | **none at all** — no `poll()`/timeout wrapper before the call, unlike `main/network.c`'s accept path below | park — needs `accept`/`accept4` interposed (not in the current 11); no stock timeout semantics to preserve, so a plain would-block-then-`Op::Watch` is a faithful match once interposed |
| `select` | `sockets.c:692` | `PHP_FUNCTION(socket_select)` | `socket_select()` | none | N/A (multi-fd `fd_set`) | yes — `tv_p` built from `$sec`/`$usec` and passed straight into `select()`; `select()` *is* the wait | park — needs `select` interposed (not in the current 11: only `poll` is); a fan-out like `ignis_park_poll`'s (one `Op::Watch` per fd) but keyed to `fd_set` bits, not `pollfd[]` |
| `read` (via `recv`) | `sockets.c:381` inside `php_read` (byte-at-a-time loop) | `php_read` ← `PHP_FUNCTION(socket_read)` `PHP_NORMAL_READ` path (`:340`, `:958`) | `socket_read($s, $len, PHP_NORMAL_READ)` | none | `fcntl(F_GETFL)` read at `:346` — used only to stop the "no data twice" retry, not to skip the call | none — relies on `SO_RCVTIMEO` if the caller set it | park — `recv` already interposed; **gap**: `ignis_park_recv` has no `SO_RCVTIMEO` awareness (see "semantic gaps" below), unlike `sockets.rs`'s `so_timeout_us`/`swap_so_timeout` |
| `recv` | `sockets.c:969` (`socket_read` `PHP_BINARY_READ`), `:1461` (`socket_recv`) | `PHP_FUNCTION(socket_read)`, `PHP_FUNCTION(socket_recv)` | `socket_read($s,$len,PHP_BINARY_READ)`, `socket_recv()` | none | fd's real blocking mode; `flags` param passed straight through (`MSG_DONTWAIT` honoured by `ignis_park_recv`'s own flag check) | none — `SO_RCVTIMEO` only | park — same `SO_RCVTIMEO` gap as above |
| `write` | `sockets.c:926` | `PHP_FUNCTION(socket_write)` (non-Windows branch) | `socket_write()` | none | fd's real blocking mode | none — `SO_SNDTIMEO` only | park — `write` already interposed; same timeout gap |
| `send` | `sockets.c:1503` | `PHP_FUNCTION(socket_send)` | `socket_send()` | none | fd's real blocking mode; `flags` passed through | none — `SO_SNDTIMEO` only | park — same gap |
| `recvfrom` | `sockets.c:1562` (AF_UNIX), `:1586` (AF_INET), `:1613` (AF_INET6), `:1641` (AF_PACKET) | `PHP_FUNCTION(socket_recvfrom)` | `socket_recvfrom()` | none | fd's real blocking mode | none | park — `recvfrom` already interposed |
| `sendto` | `sockets.c:1728` (AF_UNIX), `:1745` (AF_INET), `:1762` (AF_INET6), `:1777` (AF_INET fallback) | `PHP_FUNCTION(socket_sendto)` | `socket_sendto()` | none | fd's real blocking mode | none | park — `sendto` already interposed |
| `connect` | `sockets.c:1260` (AF_INET6), `:1279` (AF_INET), `:1293` (AF_UNIX) | `PHP_FUNCTION(socket_connect)` | `socket_connect()` | none | **fd left exactly as the user set it** — nothing here sets O_NONBLOCK first (unlike `main/network.c:352`, below) | none — no polling at all; a blocking-fd `connect()` blocks until the kernel completes or refuses it | park — this is precisely the case `ignis_park_connect` is built for (toggle O_NONBLOCK on, connect, park on writable, restore, read `SO_ERROR`); `connect` is already among the current 11, but `libphp` is not yet in the `IGNIS_PARK` `SEED` — a **policy** gap, not a missing symbol (`park.rs:56`) |
| `connect` | `sockets.c:2935` | `PHP_FUNCTION(socket_addrinfo_connect)` | `socket_addrinfo_connect()` | none | fd freshly created (`socket()` just above), always blocking | none | park — same as above, same policy gap |
| `sendmsg` | `ext/sockets/sendrecvmsg.c:207` | `PHP_FUNCTION(socket_sendmsg)` | `socket_sendmsg()` | see below | fd's real blocking mode | none | park — needs `sendmsg` interposed (not in the current 11) |
| `recvmsg` | `ext/sockets/sendrecvmsg.c:248` | `PHP_FUNCTION(socket_recvmsg)` | `socket_recvmsg()` | see below | fd's real blocking mode | none | park — needs `recvmsg` interposed |
| `getaddrinfo` | `sockets.c:2829` | `PHP_FUNCTION(socket_addrinfo_lookup)` | `socket_addrinfo_lookup()` | none | no fd — pure resolver call | none | **block** — no fd exists to park on; belongs to the *offload* mechanism (a worker thread), not park, regardless of interposition |

Near-miss lock, not a `block` verdict: `get_ancillary_reg_entry()` (`ext/sockets/sendrecvmsg.c:155`)
takes `ancillary_mutex` (a `tsrm_mutex_lock`/`unlock` pair, `sendrecvmsg.c:90`, `:162`, `:168`) to
lazily initialise the ancillary-cmsg registry, called from the `msghdr` conversion that runs
**before** `socket_sendmsg`/`socket_recvmsg`'s own `sendmsg()`/`recvmsg()` call — the mutex is
released long before the blocking syscall, so it is not held on the path park would suspend.

## Group (c) — `main/streams`, `main/network`, `ext/openssl` (audited 2026-09-16, php-8.5.10)

`main/network.c`, `main/streams/xp_socket.c`, `main/streams/plain_wrapper.c` (pipe paths only —
plain files are excluded from park by `would_block()`'s `S_IFREG` check regardless), the pipe
subset of `main/streams/streams.c` (which turned out to hold **no direct libc call sites** — every
read/write there is a `stream->ops->read`/`write` vtable dispatch that resolves into
`plain_wrapper.c`/`xp_socket.c`/Ignis's own `stream.rs`, already covered elsewhere),
`ext/standard/streamsfuncs.c`, `ext/standard/dns.c`, and `ext/openssl/xp_ssl.c`. As in group (a),
no lock is held at the moment of any of these calls — no `mutex`/`_lock` hit at all in
`xp_socket.c`, `xp_ssl.c`, or `streamsfuncs.c`, and `main/network.c` has none either.

| symbol | file:line (php-8.5.10) | enclosing function | reached from | lock held? | fd mode at the call | timeout handling | verdict |
|---|---|---|---|---|---|---|---|
| `getaddrinfo` | `main/network.c:192` | `php_network_getaddresses` | every `host:port` connect, via `php_network_connect_socket_to_host` | none | no fd | none | **block** — offload, not park (no fd) |
| `connect` | `main/network.c:352` | `php_network_connect_socket` | `php_tcp_sockop_connect` (`xp_socket.c:770`) ← `stream_socket_client()`/hooked-off `tcp://` | none | **already non-blocking** — `SET_SOCKET_BLOCKING_MODE` (`network.c:349`, `fcntl(F_SETFL, O_NONBLOCK)`) runs immediately before | yes, fully — see the `poll` row below | **forward** — `ignis_park_connect`'s own "already non-blocking: the library drives it itself" branch (`park.rs:337-340`) fires; `connect` is already interposed and does the right thing with zero new code |
| `poll` (via `php_pollfd_for`, macro `php_poll2`→`poll` on Linux/`HAVE_POLL`, `main/php_network.h:143` def, `:189` call site) | `network.c:399` (connect retry loop), `:774` (accept wait), `xp_socket.c:144` (`php_sock_stream_wait_for_data`, read wait), `:81` (write wait), `xp_ssl.c:1880` (`SSL_connect`/`SSL_accept` handshake wait), `:2080`/`:2083`/`:2117`/`:2120` (`SSL_read`/`SSL_write` wait) | `php_network_connect_socket`, `php_network_accept_incoming`, `php_sock_stream_wait_for_data`, `php_sockop_write`, `php_openssl_enable_crypto`, `php_openssl_sockop_io` | `tcp://`/`ssl://` connect, accept, read, write, TLS handshake, TLS read/write | none | N/A — `poll(2)` itself | **yes, always** — every one of these builds an explicit `struct timeval`/ms timeout and passes it through; this is PHP polling itself with a timeout before the data call, exactly the pattern ADR-0037 assumes | park — `poll` is already among the current 11 and already timeout-correct; only needs `libphp` added to the `IGNIS_PARK` `SEED` (policy, not code) |
| `accept` | `main/network.c:783` | `php_network_accept_incoming` | `php_tcp_sockop_accept` (`xp_socket.c:872`) | none | whatever the listener's fd carries | **already satisfied by the `poll` above** — `accept()` only runs after `php_pollfd_for` (line 774) reports readable | forward — no new interposition needed; the socket is ready by construction (barring a TOCTOU race, same as stock) |
| `select` (`PHP_USE_POLL_2_EMULATION`, `php_poll2`) | `network.c:1317` | `php_poll2` | (unused fallback) | — | — | — | **not compiled** on this build: `HAVE_POLL` is defined on Linux/glibc, so `php_poll2(...)` is `#define`d straight to `poll(...)` (`main/php_network.h:163`) and this `select()`-emulation body (guarded by `#if defined(PHP_USE_POLL_2_EMULATION)`, `network.c:1262`) never compiles in |
| `gethostbyname` | `network.c:1412` | `php_network_gethostbyname` | (unused fallback) | — | — | — | **not compiled**: guarded by `#if !defined(HAVE_GETHOSTBYNAME_R)`; glibc defines `HAVE_GETHOSTBYNAME_R`, so the real path is `gethostbyname_r` below |
| `gethostbyname_r` | `network.c:1352` (`HAVE_FUNC_GETHOSTBYNAME_R_6` variant — the one glibc matches) | `gethostname_re` ← `php_network_gethostbyname` (`:1424`) | `gethostbyname()`/`gethostbynamel()` (`ext/standard/dns.c:235`, `:270`) | none | no fd | none | **block** — offload, not park; matches the `gethostbyname*` family |
| `getnameinfo` | `ext/standard/dns.c:184` (AF_INET6), `:191` (AF_INET) | `php_gethostbyaddr` | `gethostbyaddr()` (`dns.c:171`) | none | no fd | none | **block** — offload, not park |
| `send` | `xp_socket.c:73` | `php_sockop_write` | `fwrite`/stream write on `tcp://`/`udp://` | none | `MSG_DONTWAIT` set explicitly whenever a timeout is configured (`(sock->is_blocked && ptimeout) ? MSG_DONTWAIT : 0`); the real wait happens in the `poll` row above, then the call retries via `goto retry` at line ~86 | see `poll` row | park — `send` already interposed; `MSG_DONTWAIT` makes `ignis_park_send` correctly skip parking on this call (the wait already happened at `poll`) |
| `recv` | `xp_socket.c:191` | `php_sockop_read` | `fread`/stream read on `tcp://`/`udp://` | none | same `MSG_DONTWAIT`-when-timed pattern, driven by `php_sock_stream_wait_for_data` (`:123`) before this call | see `poll` row | park — same as `send` above |
| `sendto`/`send` | `xp_socket.c:281` (`sendto`, has peer addr), `:286`/`:288` (`send`, no peer addr) | `sock_sendto` | `stream_socket_sendto()` | none | fd's real blocking mode — **no** `MSG_DONTWAIT`/poll wrapper here at all | none | park — matches stock's plain blocking wait exactly, no PHP-side timeout to preserve |
| `recvfrom`/`recv` | `xp_socket.c:303` (`recvfrom`, wants peer addr), `:324` (`recv`, no peer addr) | `sock_recvfrom` | `stream_socket_recvfrom()` | none | fd's real blocking mode, no wrapper | none | park — same as above |
| `recv` (liveness probe) | `xp_socket.c:377`, `xp_ssl.c:2498` | `php_sockop_set_option`/`PHP_STREAM_OPTION_CHECK_LIVENESS`, `php_openssl_sockop_set_option` (same option) | `feof()` on a socket/SSL stream | none | `MSG_PEEK|MSG_DONTWAIT` always | preceded by its own `php_pollfd_for` (`xp_socket.c:~369`, `xp_ssl.c:~2399`) | forward — `MSG_DONTWAIT` makes `ignis_park_recv` skip parking; the wait already happened at `poll` |
| `write`/`read` (pipe) | `plain_wrapper.c:385` (`write`), `:457`/`:463` (`read`) | `php_stdiop_write`, `php_stdiop_read` | `fwrite`/`fread` on any `php_stream_stdio_ops` stream — plain files **and** `proc_open()`/`popen()` pipes | none | fd's real blocking mode; no PHP-side poll/timeout wrapper on Linux (the `PeekNamedPipe`/`usleep(10)` loop just above, `:439`-`:448`, is `#ifdef PHP_WIN32` only) | none | park for a pipe fd (`S_IFIFO` — `would_block()`'s `fstat` check already admits it, `park.rs:166`); **forward** for a plain file fd (`S_IFREG` — the same check already excludes it) — `read`/`write` already interposed, no gap |
| `usleep` | `plain_wrapper.c:446` | — | — | — | — | — | already in group (b): Windows-only, not compiled on Linux |
| `select` | `ext/standard/streamsfuncs.c:826` (`php_select` macro `:32` = `select`) | `PHP_FUNCTION(stream_select)` | `stream_select()` | none | N/A (multi-fd `fd_set`) | yes — `tv_p` from `$seconds`/`$microseconds`, passed straight into `select()` | park — needs `select` interposed (same gap as group (a)); **and** cannot be a bare syscall wrap — see semantic gaps below (`stream_array_emulate_read_fd_set`) |

## Group (d) — opcache, session, script loading, plain-file I/O (audited 2026-09-16, php-8.5.10)

`ext/opcache/{ZendAccelerator.c,zend_shared_alloc.c,zend_file_cache.c,zend_accelerator_module.c}`,
`ext/session/mod_files.c` (the default `files` save handler — `mod_mm.c` is compiled but dead, see
below; `mod_user.c`/`mod_user_class.c`/`session.c` have no call site in the symbol list, they
delegate to userland), `Zend/zend_stream.c`, `Zend/zend_virtual_cwd.c`, and the plain-file paths of
`ext/standard/file.c` and `main/rfc1867.c`/`main/main.c`. All compiled in on this build (`opcache`,
`session` are both in ADR-0027 §4's list; `Zend/`, `main/` are core).

### opcache

| symbol | file:line (php-8.5.10) | enclosing function | reached from | lock held on the path | fd kind | verdict |
|---|---|---|---|---|---|---|
| `fcntl(F_SETLKW)` | `zend_shared_alloc.c:510` | `zend_shared_alloc_lock()` | every `zend_shared_alloc_lock()` call site: `ZendAccelerator.c:821,1248,1292,1404,1586` (`cache_script_in_shared_memory` ← `persistent_compile_file`, `:1995`/`:2185` — **the per-request opcache-cache-miss compile path, runs inside a fiber**, not RINIT-only), `:2601,2713` (RINIT restart check, group (b)'s `usleep` neighbour), `:2864,2946,3291,3306,3320,5068`; `zend_accelerator_module.c:930` (`opcache_reset()`); `zend_file_cache.c:2031` (file-cache-hit promoted into SHM) | **yes — `zts_lock`**, a `tsrm_mutex_lock` taken at `zend_shared_alloc.c:499` immediately before the `fcntl` loop, released only after `ZCG(locked)=1` is set past the call | regular file — `lock_file`, an `O_TMPFILE` fd opened at `zend_shared_alloc.c:104` | **block** — reached with `zts_lock` held (a process-wide, non-fiber-aware TSRM mutex): parking here would suspend the fiber with `zts_lock` still held, stalling every other fiber on the same OS thread that needs it too (e.g. a nested SHM-lock attempt); the fd being a regular file would forward anyway, but the lock is the harder reason and fires whether or not the fd rule does |
| `fcntl(F_SETLK)`/`fcntl(F_GETLK)` | `ZendAccelerator.c:288,309,326,358,384,406,890,923` | `accel_restart_enter/leave/is_active`, `accel_activate_add`, `accel_deactivate_sub`, `accel_unlock_all` (the restart/locker bookkeeping the task asked about) | opcache RINIT/RSHUTDOWN, restart scheduling | n/a | regular file (`lock_file`) | **not applicable** — `F_SETLK`/`F_GETLK` are the non-blocking lock variants: they return at once (`EAGAIN`/`EACCES` on contention, or the query result) and never invoke a wait, so no park/block question applies regardless of fd or lock state. Only `F_SETLKW` (above) blocks. |
| `usleep` | `ZendAccelerator.c:863,874` | `kill_all_lockers()` | already in group (b) | opcache SHM lock, RINIT-only | n/a | see group (b) — unchanged, listed here only so the restart/locker story is complete in one place |
| `writev`/`write` | `zend_file_cache.c:1104` (`writev`), `:1121,1127,1133` (`write`, no-`HAVE_SYS_UIO_H` fallback — dead on Linux, `writev` is the live path) | `zend_file_cache_script_write()` ← `zend_file_cache_script_store()` (`:1141`) ← `cache_script_in_shared_memory()` (`ZendAccelerator.c:1657`) / `store_script_in_file_cache()` — opcache's file-cache write, per cache-miss compile | the same cache-miss compile path as the SHM-lock row above, when `opcache.file_cache` is set | **the file's own `flock(LOCK_EX)`**, taken at `zend_file_cache.c:1178` (`zend_file_cache_flock` → `flock(2)`, `HAVE_FLOCK` is defined on Linux/glibc) and released at `:1229`/`close()` at `:1232` — **not** the opcache SHM lock (`zend_shared_alloc_lock` is not held anywhere inside this function; verified by reading the body) | regular file (opened via `zend_file_cache_open` = `open()`, `O_CREAT\|O_EXCL\|O_RDWR`) | **forwarded-anyway** — `would_block()`'s `S_IFREG` exclusion fires regardless of the flock; the lock is real but moot to the verdict since park was never on the table for this fd |
| `read` | `zend_file_cache.c:1931,1997` | `zend_file_cache_script_load_ex()` ← `zend_file_cache_script_load()` ← `persistent_compile_file()` (cache-hit path, file cache instead of/before SHM) | every request that resolves a script from the file cache | `flock(LOCK_SH)` at `:1925`, released at `:1967`/`:1980`/`:1999`/`:2008` depending on the early-return taken | regular file | forwarded-anyway — same `S_IFREG` reason |
| `flock` | `zend_file_cache.c:1178` (`LOCK_EX`), `:1925` (`LOCK_SH`), and the `LOCK_UN` sites at `:1229,1933,1945,1955,1967,1980,1999,2008` | `zend_file_cache_flock()` (`#elif defined(HAVE_FLOCK) # define zend_file_cache_flock flock`, `:98`) | file-cache store/load, both directions | n/a (this call *is* the lock) | regular file (the `.bin` cache file itself, not `lock_file`) | forwarded-anyway — `flock()`'s own target fd is `S_IFREG` too, so `would_block()` excludes it exactly like the read/write calls it brackets |

### session (`ext/session/mod_files.c`, the `files` save handler)

| symbol | file:line (php-8.5.10) | enclosing function | reached from | lock held on the path | fd kind | verdict |
|---|---|---|---|---|---|---|
| `flock` (`LOCK_EX`) | `mod_files.c:210` | `ps_files_open()` ← `ps_files_write()`/`ps_files_read()` | `session_start()` and every session read/write while the session is open | none on the path *to* this call | regular file — the session data file | **forwarded-anyway** (`S_IFREG`) — but flag distinctly: this is PHP's well-known cross-request session serialization point. Two requests for the same session ID contend on this exact `flock()`, and the second one blocks for however long the first holds the session open — which, under Ignis, is the whole PHP OS thread stalling (not just the fiber), because a regular file can never park. `getaddrinfo`'s "no fd" case gets offload as an escape hatch; this one has an fd, so by the letter of the two rules it stays `forwarded-anyway`, but operationally it is the least excusable forward in this audit — worth its own line in ADR-0037 if session concurrency ever becomes a measured pain point. |
| `pwrite` | `mod_files.c:245` | `ps_files_write()` | `session_write_close()`/session shutdown | the `flock(LOCK_EX)` from `:210`, held for the life of the open fd | regular file | forwarded-anyway (`S_IFREG`) |
| `pread` | `mod_files.c:492` | `ps_files_read()` | `session_start()`'s initial read | same `flock(LOCK_EX)` from `:210` (session reads take the same exclusive lock as writes — `ps_files_open()` doesn't distinguish) | regular file | forwarded-anyway (`S_IFREG`) |
| `write`/`read` | `mod_files.c:264`/`:512` | same functions, `#else` fallback | — | — | — | **not compiled**: `HAVE_PWRITE`/`HAVE_PREAD` are defined on this Linux/glibc build (`build/php.m4`'s `AC_CACHE_CHECK([whether pwrite works] …)`, a real feature test, not a version guess), so the raw `write`/`read` branches are dead code |
| — | `ext/session/mod_mm.c` | (whole file) | — | — | — | **not active**: the entire file is `#ifdef HAVE_LIBMM` (`mod_mm.c:19`); `HAVE_LIBMM` needs `--with-mm`, which (a) is not in ADR-0027 §4's configure line and (b) `config.m4` refuses it combined with `--enable-zts`, which this build always uses — dead regardless |

### Zend/ script loading and compilation

| symbol | file:line (php-8.5.10) | enclosing function | reached from | lock held | fd kind | verdict |
|---|---|---|---|---|---|---|
| — (`fread`) | `Zend/zend_stream.c:27` (`zend_stream_stdio_reader`) | `zend_stream_open()`/`zend_stream_fixup()`'s raw-`FILE*` fallback | would be `include`/`require`/the main script, **if** no `stream_open_function` were installed | — | — | **unreachable on this build**: `main/main.c:2242` unconditionally sets `zuf.stream_open_function = php_stream_open_for_zend` during `php_module_startup()`, so `zend_stream_open()`'s first line (`if (zend_stream_open_function) return zend_stream_open_function(handle);`, `zend_stream.c:87`) always takes that branch instead. The real read path for the top-level script *and* every `include`/`require` is `main/main.c:1685` (`php_stream_open_for_zend_ex`) → `php_stream_open_wrapper()` → `main/streams/plain_wrapper.c`'s stream ops — **already audited in group (c)** (`plain_wrapper.c:457`/`:463`, `php_stdiop_read`, verdict `forward` for `S_IFREG`). No new call site here; this row exists to record that the naive reading of `zend_stream.c` is a dead end. |
| `open` | `Zend/zend_virtual_cwd.c:1463` (`O_CREAT` variant), `:1469` (plain) | `virtual_open()` (the `VCWD_OPEN` macro) | `include_path` search, realpath-cache population, `tempnam()`, any cwd-relative file open | none | regular file/dir | forwarded-anyway (`S_IFREG`) — and note `open` isn't even in `park.rs`'s stage-1 or stage-2 interposition scope today (its own doc comment lists only `read/write/recv/send/recvfrom/sendto/poll/connect/nanosleep/usleep/sleep` for stage 1 and `getaddrinfo/select/accept*/vectored/__poll_chk` for stage 2), so this forwards unconditionally regardless of fd kind; only a FIFO opened without `O_NONBLOCK` would make `open()` itself capable of blocking on data, and `virtual_open()`'s normal callers never hit one |

### plain-file I/O (`ext/standard`, `main/rfc1867.c`, `main/main.c`)

| symbol | file:line (php-8.5.10) | enclosing function | reached from | lock held | fd kind | verdict |
|---|---|---|---|---|---|---|
| `flock` | `main/streams/plain_wrapper.c:783` | `php_stdiop_set_option()` (`PHP_STREAM_OPTION_LOCKING`) ← `main/streams/streams.c`'s `php_stream_lock()` ← `ext/standard/file.c:203` (`PHP_FUNCTION(flock)`) | userland `flock()` on any local-file stream | none | regular file (the wrapped local file, the common case) | forwarded-anyway (`S_IFREG`) — this is the userland `flock()` function's own call site, distinct from opcache's/session's internal locking above |
| `write` | `main/rfc1867.c:1043,1045` | `rfc1867_post_handler()` | any multipart file upload — writes the uploaded body to the temp upload file | none | regular file (`php_open_temporary_fd`-created tmp file) | forwarded-anyway (`S_IFREG`) — a real per-request hot-path write, worth naming even though the verdict is unremarkable |
| `write` | `main/main.c:972,975` | `php_log_err_with_severity()` (`main.c:923`) | any warning/error while `error_log` ini points to a plain file (not syslog, not the SAPI logger) | none on Linux — the `php_flock(fd, LOCK_EX)` wrap around this write is `#ifdef PHP_WIN32` only (`:968`-`:970`) | regular file | forwarded-anyway (`S_IFREG`) |
| `close` | `ext/standard/file.c:697` (`tempnam()`), `main/php_open_temporary_file.c:375` | — | temp-file creation cleanup | — | regular file | no verdict needed — `close()` doesn't wait on data the way the other symbols do; listed only because the grep matched it |

## Group (e) — the rest, and what stays `block` (audited 2026-09-16, php-8.5.10)

Everything else the symbol-list grep turned up across the compiled tree (`~/php-src` at
`php-8.5.10`, ADR-0027 §4's `--disable-all` + `mbstring, sockets, pdo, pdo_sqlite, sqlite3,
fibers, zlib, filter, ctype, tokenizer, session, iconv, pdo_pgsql, pgsql, curl, openssl, opcache`),
including places the task explicitly asked about that turned out to have nothing.

| area | finding |
|---|---|
| `TSRM/TSRM.c` | **zero call sites** for any symbol in the list. `tsrm_startup`/`tsrm_shutdown`/`tsrm_mutex_alloc` use `pthread_mutex_init`/`pthread_mutex_lock`/`pthread_key_create`, none of which are audited symbols. Nothing to report. |
| `ext/pcntl` | **not compiled.** Absent from ADR-0027 §4's extension list (`mbstring, sockets, pdo, pdo_sqlite, sqlite3, fibers, zlib, filter, ctype, tokenizer, session, iconv, pdo_pgsql, pgsql, curl, openssl, opcache`) — confirmed by grep against that list, nothing more to check. |
| `ext/standard/dns.c` — `dns_get_record()`/`checkdnsrr()`/`getmxrr()` | `dns.c:429,961,1102` all call `php_dns_search(...)` (`ext/standard/php_dns.h` macro → `res_nsearch`/`res_search`/`dns_search`). That function, and the `connect`/`send`/`recv`/`poll` it does internally, lives in **`libresolv.so`**, not compiled into `libphp.so` — out of this audit's scope by the same "only code compiled into this build" rule that kept research 27's libcurl/libpq audit separate from this one. `gethostbyname()`/`gethostbynamel()`/`gethostbyaddr()` (`dns.c:219,245,171`) were already resolved to group (c)'s `network.c:1352`/`dns.c:184,191` rows — no new call site there. If the park table is ever extended past libphp, libresolv needs its own research-27-shaped entry, not a row here. |
| `ext/mbstring`, `ext/pdo`, `ext/pdo_sqlite`, `ext/sqlite3`, `ext/zlib`, `ext/filter`, `ext/ctype`, `ext/tokenizer`, `ext/iconv` | swept with the full symbol list across every `.c` in each extension: **zero hits**. |
| `Zend/zend_fibers.c` | zero hits — the fiber context switch is `ucontext`/assembly, not a call to anything in the symbol list. |
| `Zend/zend_call_stack.c:725,733,737,771` | `open`/`close`/`pread` inside `zend_call_stack_get_solaris_proc_maps()` — the whole function is `#ifdef HAVE_LIBPROC_H` (Solaris `libproc`), **not compiled** on this Linux/glibc build. |
| `Zend/zend_gdb.c:114,118,143` | `open`/`read`/`close` inside `zend_gdb_present()` — compiled (`#if defined(__linux__)` is true here), but grepping the entire tree for callers of `zend_gdb_present`/`zend_gdb_register_code` finds **none** — dead code, unreachable at runtime. |
| `main/fastcgi.c` | Added via `PHP_ADD_SOURCES_X([main], [fastcgi.c], …, [PHP_FASTCGI_OBJS], [no])` (`configure.ac:1684`-`1689`) — a separate object group linked only into `sapi/cgi` and `sapi/fpm`. `scripts/build-php.sh` passes `--disable-cgi` and builds no fpm SAPI, so `PHP_FASTCGI_OBJS` is compiled (per the object rule in the Makefile) but never linked into `libphp.so`/the embed SAPI Ignis actually runs. |
| `main/main.c:2600,2667` | `open(".", 0)`/`close(old_cwd_fd)` bracketing `php_execute_script()` — cwd save/restore, a directory fd, no data transferred; not meaningfully blocking. |
| `main/fopen_wrappers.c:830` | `close(fdtest)` — trivial, part of an `open_basedir` probe. |

Nothing in group (e) needed a `park`/`block` verdict beyond what's already in group (d)'s table:
every real call site here either isn't compiled, has no caller, forwards for the same `S_IFREG`
reason as group (d), or (DNS) lives in a library outside libphp's scope.

## libphp symbols that stay `block`, with the reason

One row, because one real `block` verdict exists across the entire audit (groups (a)-(e)):

| symbol | file:line | reason |
|---|---|---|
| `fcntl(F_SETLKW)` | `ext/opcache/zend_shared_alloc.c:510` (`zend_shared_alloc_lock()`) | Reached with `zts_lock` (a TSRM mutex, `tsrm_mutex_lock` at `:499`) already held. Parking a fiber here would suspend it with a process-wide, non-fiber-aware mutex still locked, stalling every other fiber on the OS thread that also needs `zts_lock` — a real deadlock/stall risk, not a theoretical one, since this fires on every opcache cache-miss compile (`persistent_compile_file` → `cache_script_in_shared_memory`, reachable inside a request fiber, not just RINIT). |

Everything else that stays off `park` does so for one of two structural reasons, not a lock:

- **No fd to park on at all** (offload's territory, not park's): `getaddrinfo` (`sockets.c:2829`,
  `network.c:192`), `gethostbyname_r` (`network.c:1352`), `getnameinfo` (`dns.c:184,191`) — all
  already in groups (a)/(c). `dns_get_record`'s `res_nsearch`/`res_search` path belongs here too in
  spirit, but its syscalls are in `libresolv.so`, outside libphp — noted in group (e), not re-listed
  as a libphp symbol.
- **Regular file, so `would_block()`'s `S_IFREG` check refuses it regardless of policy**
  (forwarded-anyway, group (d)'s largest bucket): opcache file-cache `read`/`writev`/`write`/`flock`
  (`zend_file_cache.c`), session file `pread`/`pwrite`/`flock` (`mod_files.c`), userland `flock()`
  (`plain_wrapper.c:783`), script/`include`/`require` reads (`plain_wrapper.c:457`/`:463`, group
  (c)), `main/rfc1867.c`'s upload write, `main/main.c`'s `error_log` write, `Zend/zend_virtual_cwd.c`'s
  `virtual_open()`. None of these need a `block` verdict — they were never park candidates, lock or
  no lock — but they're listed here because the task asked for every symbol that stays off `park`,
  not just the lock-caused ones.

## Symbols still to interpose

Beyond the current eleven (`read write recv send recvfrom sendto poll connect nanosleep usleep
sleep`), reaching every group (a)/(c) call site above needs:

- **`accept`, `accept4`** — `ext/sockets`' `socket_accept()` calls these directly with no prior
  `poll()` (`sockets.c:294`, `:301`); `main/network.c`'s `accept()` (`:783`) does *not* need this —
  it only ever runs after an already-interposed `poll()` reports readable.
- **`select`** — `socket_select()` (`sockets.c:692`) and `stream_select()`
  (`streamsfuncs.c:826`) both call `select()` directly; PHP's own `select()`-based `poll()`
  emulation (`network.c:1317`) is dead code on this Linux/glibc build, so it contributes nothing.
- **`recvmsg`, `sendmsg`** — `ext/sockets/sendrecvmsg.c:248`, `:207`; no other libphp use found in
  either group.
- **`getaddrinfo`** — `sockets.c:2829`, `network.c:192`. Interposing the symbol is necessary but
  not sufficient: there is no fd to park on, so this (and the next row) need the *offload*
  mechanism (CLAUDE.md's three-mechanism table), not park's readiness model.
- **`gethostbyname`/`gethostbyname_r`/`getnameinfo`** — `network.c:1352` (the real path;
  plain `gethostbyname` at `:1412` is dead code on glibc), `dns.c:184`/`:191`. Same offload-not-park
  story as `getaddrinfo`.

Everything else needed for these two groups (`read write recv send recvfrom sendto poll connect`)
is **already** among the current eleven — the remaining gap for all of it is the `IGNIS_PARK`
`SEED` policy string (`park.rs:56`), which does not yet list `libphp` for any symbol but
`sleep`/`usleep`/`nanosleep`. No lock-related `block` verdict was found in either group.

## Semantics a point hook implements that a bare syscall interposition cannot

1. **`can_block()`'s listening/unconnected/unbound check** (`sockets.rs:232`). A generic
   `recv`/`send` interposition's `would_block()`+`ready_now()` only asks "is this fd a blocking
   socket, and is it ready now" — it has no notion that `POLLIN` on a **listening** socket means "a
   connection is queued", not "data is available", or that an unconnected/unbound socket's data ops
   fail at once (`ENOTCONN`) rather than ever becoming ready. Parking one of those on `Op::Watch`
   waits for an event that may never mean what the caller asked for, or never arrives at all. Real
   hazard only for `recv`/`send`-family ops on a listening or unconnected socket — `accept` has no
   equivalent problem (it only ever makes sense on a listener, and `accept()` on anything else
   fails immediately rather than blocking).
2. **`SO_RCVTIMEO`/`SO_SNDTIMEO`** (`sockets.rs:163`, `:300`-`:301`). The point hook reads the
   socket's kernel timeout via `getsockopt` and races the readiness `Op::Watch` against an
   `Op::Sleep` timer, then replays the original call with a 1 µs timeout so it reports the timeout
   the stock way. `ignis_park_recv`/`ignis_park_send` (`park.rs:222`-`:247`) have **no** timeout
   awareness at all — a parked `recv`/`send` on a socket with `SO_RCVTIMEO` set would wait forever
   instead of honouring the kernel-configured timeout. This is the sharpest concrete regression
   risk in group (a); it does not affect group (c), where every timed wait goes through `poll`
   (already timeout-correct).
3. **`stream_select()`'s buffered-read-ahead short-circuit** (`stream_array_emulate_read_fd_set`,
   `streamsfuncs.c:~808`, wired to Ignis's own `stream.rs::has_buffered` by `accept.rs`'s
   `hooked_select`). A bare `select`/`poll` syscall interposition parks on the real fd and has no
   way to know that Ignis's `tcp://` hook already buffered bytes ahead of it in userland (read-ahead
   the kernel fd no longer shows) — without this, a stream with buffered data would wait on a fd
   that will never signal it. This is a coupling specific to Ignis's own transport hook (research 6),
   not a stock-PHP concern, but it means a future `select` park cannot simply be "wrap the syscall".
4. **`accept.rs`'s `default_socket_timeout` swap for stock-shaped timeout errors.** Cosmetic only
   (matches error text/return shape on timeout) — not a hang risk, unlike 1–3.

Test: V-46 — V-22's gate through park with the hook deleted (to extend to groups (a)/(c) once
their symbols move to the table, per ADR-0037 §6 step 2).

## Question

Which call sites inside `libphp.so` (PHP 8.5.10 ZTS as built by `scripts/build-php.sh`) make a
blocking syscall, and is any of them reached while a lock is held — the opcache SHM lock
(`zend_shared_alloc_lock`), TSRM mutexes (`tsrm_mutex_lock`), the reentrancy locks
(`tsrm_reentrancy`), or any `pthread_mutex` in an extension? Research 27 answered this for
OpenSSL, libcurl and libpq from source with line references and explicitly did **not** walk
libphp's compile/execute path. Until this audit exists, every libphp symbol stays `block` in
ADR-0037's table.

## Method (to be executed, not estimated)

1. `nm -D --undefined-only /opt/php85-zts/lib/libphp.so` filtered to research 26's export list —
   the symbols libphp imports (research 26 already lists them; reuse).
2. For each symbol, the call sites in `~/php-src` at `php-8.5.10`, grouped by extension and by
   engine area: `main/streams/*` (plain files, sockets), `ext/standard` (`sleep`, `usleep`,
   `gethostbyname`, `stream_select`), `ext/sockets`, `ext/openssl`, `ext/session` (file handler
   locks — `flock`), `ext/opcache` (`zend_shared_alloc`, file cache `read`/`write` under
   `zend_shared_alloc_lock`), `Zend/` (compile: `zend_stream_open` → `read` on the script;
   `zend_signal`), `TSRM/`.
3. For each call site: the lock state on the path to it, from source, with `file:line` — the
   research-27 table shape: symbol | call site | lock held? (chain) | verdict `park` / `block`.
4. Groups, in the order they would move to the table: (a) `ext/sockets` calls (`recv`/`send`/
   `accept`/`connect`/`poll` — replaces `sockets.rs`, V-29), (b) `ext/standard` `sleep`/`usleep`
   (replaces `sleep.rs`, V-22), (c) `main/streams` socket transport (`connect`/`poll`/`recv`/
   `send` — replaces the factory's tcp path, V-12), (d) `ext/openssl` (replaces the rustls path,
   V-25 — research 27's OpenSSL verdict applies to libssl; this group is libphp's *use* of it),
   (e) everything else stays `block` with the reason (opcache file cache, session file locks,
   script loading).

## Acceptance

- A table with one row per (symbol, call site) for groups (a)–(d), each with a `file:line` at
  `php-8.5.10` and a lock-state verdict from the source path, not from memory.
- The list of libphp symbols that stay `block`, each with its reason.
- H36's shim (`bench/e18/locklib.c`, research 27) run under `park` for a libphp group that the
  audit calls lock-free must **not** deadlock, and for a synthetic lock-holding site must — the
  audit's verdicts are testable, not asserted.
- E15 fiber mode green with each group moved to `park` and its point hook off (ADR-0037 §6
  step 3's gate), baseline unchanged or up.

## What this does not cover

Third-party extensions loaded into libphp (none in the current build: `--disable-all` plus the
list in ADR-0027 §4). A new extension brings its own audit before its symbols get a row.
