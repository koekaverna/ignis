# 26 — E18-R1: which blocking libc symbols the linked libraries actually import

Date: 2026-09-16. Research only, no code written or built. Binary not rebuilt, no load run (two
other agents and a PG container were active on this box; this note reused source checkouts another
agent had already placed under `/tmp/cmp/` — `curl`, `postgres`, `openssl`, all at the exact tags
needed below — instead of re-cloning).

## Method

```
LD_LIBRARY_PATH=/opt/php85-zts/lib ldd target/release/ignis
```
for the linked `.so` list, then for each library in scope:
```
nm -D --undefined-only <so> | grep -wE 'read|write|readv|writev|pread|pwrite|recv|send|recvfrom|
sendto|recvmsg|sendmsg|connect|accept|accept4|poll|ppoll|select|pselect|epoll_wait|epoll_pwait|
nanosleep|clock_nanosleep|usleep|sleep|getaddrinfo|getnameinfo|freeaddrinfo|gethostbyname|fsync|
fdatasync|flock|fcntl|sendfile|pause|sigwait|wait|waitpid'
```
and separately (the `-w` word-boundary match does **not** catch `__read_chk` etc., since `_` is a
word character):
```
nm -D --undefined-only <so> | grep -E '_chk@|_chk$'
```
then, for the request-path libraries, source at the exact installed tag, cloned into `/tmp/cmp/`
(already present at the right tags from a concurrent agent's E18-R2/R3 work; reused, not deleted —
they are still needed by that other work; nothing was left by *this* note under `/tmp/cmp/` that
wasn't already there).

## Versions and tags used

| Library | Installed version (command) | Source tag cloned |
|---|---|---|
| curl / libcurl | `curl 8.18.0` (`curl --version`; `libcurl4t64:amd64 8.18.0-1ubuntu2.5`) | `curl-8_18_0` |
| PostgreSQL / libpq | `PostgreSQL 18.6 (Ubuntu 18.6-0ubuntu0.26.04.1)` (`pg_config` not on PATH; `strings libpq.so.5 \| grep PostgreSQL` and `libpq5:amd64 18.6-0ubuntu0.26.04.1`) | `REL_18_6` |
| OpenSSL (libssl + libcrypto) | `OpenSSL 3.5.5 27 Jan 2026` (`openssl version`; `libssl3t64:amd64 3.5.5-1ubuntu3.5`) | `openssl-3.5.5` |
| libssh2 | `1.11.1` (`libssh2-1t64:amd64 1.11.1-1ubuntu0.26.04.4`; also in `curl --version`'s feature list) | not cloned — nm + `curl --version` only, per "briefly" |
| nghttp2 | `1.68.0` (`libnghttp2-14:amd64 1.68.0-2ubuntu0.2`; `curl --version`) | not cloned |
| GnuTLS | `3.8.12` (`libgnutls30t64:amd64 3.8.12-2ubuntu1.1`) | not cloned |
| krb5 | `1.22.1` (`libkrb5-3:amd64 1.22.1-2ubuntu4.1`; `curl --version` shows `mit-krb5/1.22.1`) | not cloned |
| glibc | `2.43` (`ldd --version`) | n/a |
| PHP | `8.5.10` ZTS+embed at `/opt/php85-zts` (CLAUDE.md) | n/a |

Disk: `df -h /` before starting showed 863G free / 10% used — well above the 15%-free stop
threshold, no cleanup needed. `/tmp/cmp/curl`, `/tmp/cmp/postgres`, `/tmp/cmp/openssl` were already
checked out by another agent at exactly these tags; left in place for that agent, not deleted by
this note.

## `ldd target/release/ignis` — full link list

```
libphp.so (=> /opt/php85-zts/lib/libphp.so)
libgcc_s.so.1, libm.so.6, libc.so.6                          (toolchain/libc — out of scope)
libssl.so.3, libcrypto.so.3
libsqlite3.so.0
libz.so.1
libcurl.so.4
libonig.so.5
libpq.so.5
libzstd.so.1, libnghttp2.so.14, libidn2.so.0, librtmp.so.1,
libldap.so.2, liblber.so.2, libssh2.so.1, libpsl.so.5,
libgssapi_krb5.so.2, libbrotlidec.so.1, libunistring.so.5,
libgnutls.so.30, libhogweed.so.6, libnettle.so.8, libgmp.so.10,
libsasl2.so.2, libkrb5.so.3, libk5crypto.so.3, libcom_err.so.2,
libkrb5support.so.0, libbrotlicommon.so.1, libp11-kit.so.0,
libtasn1.so.6, libkeyutils.so.1, libresolv.so.2, libffi.so.8   (libcurl's transitive closure)
```
Everything after `libpq.so.5` is pulled in transitively by libcurl (protocol/auth backends: LDAP,
SASL/GSSAPI, SSH, RTMP, IDN, PSL, brotli, zstd) or by those in turn (GnuTLS's own crypto stack:
Nettle/Hogweed/GMP/p11-kit/libtasn1). Notably **no `libcares.so`** — see resolver finding below.

## Per-library tables

Columns: symbol as it appears in `nm -D --undefined-only` output (glibc-versioned), request-path
call site (file:line at the tag), notes. "not verified" where source wasn't read for that row.

### libphp.so (`/opt/php85-zts/lib/libphp.so`, PHP 8.5.10 ZTS+embed)

| symbol (nm) | call site | notes |
|---|---|---|
| `accept@GLIBC_2.2.5` | not verified (not in scope — this note's from-source pass covered curl/libpq/openssl only) | ext/sockets, main/network.c per research 22 |
| `accept4@GLIBC_2.10` | not verified | |
| `connect@GLIBC_2.2.5` | not verified | |
| `fcntl@GLIBC_2.2.5` | not verified | O_NONBLOCK toggling, per E18's ADR sketch for `connect` |
| `fdatasync@GLIBC_2.2.5` | not verified | |
| `flock@GLIBC_2.2.5` | not verified | |
| `freeaddrinfo@GLIBC_2.2.5` | not verified | |
| `fsync@GLIBC_2.2.5` | not verified | |
| `getaddrinfo@GLIBC_2.2.5` | not verified | `main/network.c php_network_gethostbyname` per research 22 |
| `getnameinfo@GLIBC_2.2.5` | not verified | |
| `nanosleep@GLIBC_2.2.5` | not verified | `sleep()`/`usleep()`-adjacent builtins |
| `poll@GLIBC_2.2.5` | not verified | `ext/sockets` `socket_select`? not confirmed here |
| `pread@GLIBC_2.2.5` | not verified | |
| `pwrite@GLIBC_2.2.5` | not verified | |
| `read@GLIBC_2.2.5` | not verified | streams layer, already hooked by `stream.rs` (ADR-0007) |
| `recv@GLIBC_2.2.5` | not verified | |
| `recvfrom@GLIBC_2.2.5` | not verified | |
| `recvmsg@GLIBC_2.2.5` | not verified | ext/sockets `socket_recvmsg` |
| `select@GLIBC_2.2.5` | not verified | ext/sockets `socket_select` |
| `send@GLIBC_2.2.5` | not verified | |
| `sendmsg@GLIBC_2.2.5` | not verified | ext/sockets `socket_sendmsg` |
| `sendto@GLIBC_2.2.5` | not verified | |
| `sleep@GLIBC_2.2.5` | not verified | PHP's `sleep()` builtin — already intercepted at the PHP-function layer, not the libc layer, per `sleep.rs`/ADR |
| `usleep@GLIBC_2.2.5` | not verified | same as above |
| `waitpid@GLIBC_2.2.5` | not verified | `proc_open`/pcntl family |
| `write@GLIBC_2.2.5` | not verified | |
| `writev@GLIBC_2.2.5` | not verified | |

libphp is out of this ticket's from-source scope (research 22 already covers most of its
`ext/sockets` surface in depth); listed here only because it's on the `ldd` list and the symbol
inventory must be complete for the merged export list below. **Not** on this ticket's "for the
request path, from source" list (that's curl/libpq/openssl).

### libcurl.so.4 (curl 8.18.0)

| symbol (nm) | call site (curl-8_18_0) | notes |
|---|---|---|
| `poll@GLIBC_2.2.5` | `lib/select.c:248` — `Curl_poll()`, called by `Curl_socket_check()` (`lib/select.c:160`) and `Curl_pollset_poll()` (`lib/select.c:681`) | the request-path multiplexer; `HAVE_POLL` is defined on this build |
| `select@GLIBC_2.2.5` | `lib/curlx/wait.c:83` — `curlx_wait_ms()` | **not** the socket multiplexer; this is curl's portable "just sleep" primitive, called by `Curl_poll()` (`lib/select.c:224-225`) only when `Curl_poll` is given zero valid fds (`fds_none`). `select(0, NULL, NULL, NULL, timeout)` used purely as a delay, no fd is watched |
| `recv@GLIBC_2.2.5` | `lib/cf-socket.c:1535` — `cf_socket_recv()` via the `sread()` macro | `sread(x,y,z)` expands to `recv(x,y,z,0)` on Linux (`lib/curl_setup_once.h:152`, the `HAVE_RECV` branch — the Minix-only `read()` branch at line 125 is not taken here) |
| `send@GLIBC_2.2.5` | `lib/cf-socket.c:1468` — `cf_socket_send()` via `swrite()` | `swrite(x,y,z)` expands to `send(x,y,z,SEND_4TH_ARG)` on Linux (`lib/curl_setup_once.h:168`) |
| `read@GLIBC_2.2.5` | not identified as a socket path — Minix's `sread` alternative is dead code here; likely file/pipe I/O elsewhere (e.g. `CURLOPT_UPLOAD` from a local `FILE*`, `.netrc`/cookie-jar file access) | not fully traced; not the TCP/TLS data path |
| `write@GLIBC_2.2.5` | same caveat as `read` above | not the TCP/TLS data path |
| `connect@GLIBC_2.2.5` | `lib/cf-socket.c:1246,1249,1257,1260,1266` — `do_connect()` | plain, non-blocking (`O_NONBLOCK` already set before this call; readiness is polled afterwards via `Curl_poll`) |
| `getaddrinfo@GLIBC_2.2.5` | `lib/curl_addrinfo.c:543` (release) / `:546` (debug build), called from `Curl_getaddrinfo_ex` | **called from `getaddrinfo_thread()` (`lib/asyn-thrdd.c:203`), which runs on a pthread curl itself creates** (`Curl_thread_create()` at `lib/asyn-thrdd.c:447`) — see Resolver finding below |
| `freeaddrinfo@GLIBC_2.2.5` | paired with the above, `lib/curl_addrinfo.c` | same thread as `getaddrinfo` |
| `fcntl@GLIBC_2.2.5` | `lib/cf-socket.c`, `O_NONBLOCK` setup before `connect()` | not line-pinned in this pass |
| `accept4@GLIBC_2.10` | not identified — curl is a client library; likely unreachable on the client request path (maybe FTP passive-mode local listener or test-only code) | not verified further |

Feature line from `curl --version`: `AsynchDNS` is present, `c-ares` is **not** in the feature list
and `ldd libcurl.so.4` shows no `libcares.so` — confirms the threaded resolver
(`lib/asyn-thrdd.c`, compiled under `#ifdef CURLRES_THREADED`), not c-ares.

### libpq.so.5 (PostgreSQL 18.6)

| symbol (nm) | call site (REL_18_6) | notes |
|---|---|---|
| `poll@GLIBC_2.2.5` | `src/interfaces/libpq/fe-misc.c:1319` — `PQsocketPoll()`, `#ifdef HAVE_POLL` branch (`fe-misc.c:1287`) | called by `pqSocketCheck()` (`fe-misc.c:1229`) via `pqSocketPoll()` (`fe-misc.c:1185`); `pqReadReady`/`pqWriteReady` (`fe-misc.c:1206`,`:1216`) both route here |
| `read@GLIBC_2.2.5` | **not** on the plaintext socket read path (see `recv` below) — not further identified; possibly Unix-domain-socket-adjacent or file I/O (service file, `.pgpass`) | not fully traced |
| `write@GLIBC_2.2.5` | same caveat | not fully traced |
| `recv@GLIBC_2.2.5` | `src/interfaces/libpq/fe-secure.c:201` — `pqsecure_raw_read()`, called from `pqsecure_read()` (`fe-secure.c:186`) when not using TLS (or as the raw fallback) | plain-text socket read |
| `send@GLIBC_2.2.5` | `src/interfaces/libpq/fe-secure.c:369` — `pqsecure_raw_write()`, called from `pqsecure_write()` (`fe-secure.c:310`) | plain-text socket write |
| `connect@GLIBC_2.2.5` | `src/interfaces/libpq/fe-connect.c:3481` (and `:3553` context) | non-blocking connect, `PQconnectPoll()` state machine polls readiness afterwards with `PQsocketPoll` |
| `getaddrinfo@GLIBC_2.2.5` | `src/common/ip.c:65` — inside `pg_getaddrinfo_all()`, called from `fe-connect.c:3056/3068/3093` | **synchronous, on the caller's own thread** — no internal thread pool, unlike libcurl. This is the opposite of curl's resolver behavior and matters for E18: a fiber calling a blocking `pdo_pgsql` connect will block *its own thread* in `getaddrinfo()`, not a helper thread |
| `freeaddrinfo@GLIBC_2.2.5` | `src/common/ip.c`, paired with the above | |
| `sigwait@GLIBC_2.2.5` | not identified in `fe-*.c`; likely `src/port`/`src/common` signal-blocking helper (e.g. `pqsignal`/thread-safety init) | not fully traced |

`select@GLIBC_2.2.5` was **not** found in `libpq.so.5`'s undefined-symbol list (checked explicitly —
it is easy to misread against libcurl's/libcrypto's lists, which do have it). Source confirms why:
the only literal `select()` call in libpq is `fe-misc.c:1367`, inside the `#else /* !HAVE_POLL */`
branch of `PQsocketPoll` — dead code on a Linux build where `HAVE_POLL` is defined, so it is simply
not compiled in, and indeed doesn't show up as an import.

### libssl.so.3 + libcrypto.so.3 (OpenSSL 3.5.5)

The socket BIO implementation (`BIO_s_socket`, `BIO_s_connect`) lives in **libcrypto**, not
libssl — this is why libcrypto's import list, not libssl's, has `connect`/`accept`/`getaddrinfo`/
`gethostbyname`/`select`.

| symbol (nm) | in | call site (openssl-3.5.5) | notes |
|---|---|---|---|
| `read@GLIBC_2.2.5` | libcrypto | `include/internal/sockets.h:201` — the `readsocket(s,b,n)` macro expands to `read(s,b,n)` on the generic Unix `#else` branch (not Windows/DJGPP/VMS/VxWorks/Tandem); used by `sock_read()` (`crypto/bio/bss_sock.c:105-116`) | **libssl/libcrypto use `read`/`write`, not `recv`/`send`**, for plaintext socket I/O — opposite convention from libcurl and libpq |
| `write@GLIBC_2.2.5` | libcrypto | `include/internal/sockets.h:202` — `writesocket(s,b,n)` → `write(s,b,n)`; used by `sock_write()`, called via `writesocket(b->num, in, inl)` at `crypto/bio/bss_sock.c:156` | |
| `read@GLIBC_2.2.5` / `write@GLIBC_2.2.5` | libssl | `BIO_read`/`BIO_write` (`ssl/record/methods/tls_common.c:406,1492,1932`) call down into the libcrypto BIO, which is where the actual `read`/`write` syscalls happen — libssl.so.3 itself imports `read`/`write` too (its own undefined-symbol list has them), consistent with it calling BIO methods that are inlined/resolved through the same process | |
| `poll@GLIBC_2.2.5` | libssl, libcrypto | not pinned to a specific libssl call site in this pass; libcrypto's `poll` likely from `crypto/bio/bio_sock.c` polling helpers | not fully traced for libssl |
| `sendfile@GLIBC_2.2.5` | libssl only | `ssl/ssl_lib.c:2580` — `SSL_sendfile()` → `ktls_sendfile()` (`:2631`) | **kernel TLS (KTLS) offload path only** — not reached by ordinary `curl_easy_perform`/`pdo_pgsql` TLS traffic unless KTLS is explicitly enabled; a surprise import for a "normal" request path |
| `connect@GLIBC_2.2.5` | libcrypto | `crypto/bio/bio_sock2.c:179` — `BIO_connect()`, called from `conn_state()` in `crypto/bio/bss_conn.c:196` (`BIO_s_connect`'s connect BIO) | |
| `accept@GLIBC_2.2.5` | libcrypto | `crypto/bio/bio_sock2.c:426` — `BIO_accept_ex()`/`BIO_listen`-adjacent path | **server-side accept BIO** (`BIO_s_accept`) — not used by curl/pq's client-mode TLS connections |
| `getaddrinfo@GLIBC_2.2.5` | libcrypto | not directly found; libcrypto resolves names via `getnameinfo`/`gethostbyname` in `bio_addr.c`, and `getaddrinfo` may come from a different `crypto/bio` path not pinned in this pass | not fully traced |
| `getnameinfo@GLIBC_2.2.5` | libcrypto | `crypto/bio/bio_addr.c:244` — `BIO_ADDR_hostname_string()`-adjacent | |
| `gethostbyname@GLIBC_2.2.5` | libcrypto | `crypto/bio/bio_sock.c:128` — `BIO_gethostbyname()`; also `crypto/bio/bio_addr.c:838` | legacy IPv4-only resolver path, not exercised by curl (which resolves itself before handing an IP to OpenSSL) or by libpq (same) |
| `select@GLIBC_2.2.5` | libcrypto | `crypto/bio/bio_sock.c:456` — a `BIO_sock_wait`-style helper | not identified as reachable from curl's or libpq's TLS handshake path (they drive readiness themselves via `Curl_poll`/`PQsocketPoll` and call `SSL_connect`/`SSL_read`/`SSL_write` directly, not through this BIO wait helper) |
| `recvfrom@GLIBC_2.2.5`, `sendmsg@GLIBC_2.2.5`, `recvmsg@GLIBC_2.2.5` | libcrypto | not identified in this pass; likely `crypto/bio/bss_dgram.c` (DTLS) | DTLS is not used by plain `curl_exec`/`pdo_pgsql` over TCP |
| `nanosleep@GLIBC_2.2.5` | libcrypto | not identified; candidate: `RAND_poll`/entropy-gathering jitter, or `crypto/threads_pthread.c` backoff | not verified |
| `freeaddrinfo@GLIBC_2.2.5` | libcrypto | paired with `getaddrinfo` above | |

**Bottom line for the request path:** for a plain HTTPS `curl_exec` or a `pdo_pgsql` TLS connection,
the OpenSSL calls that matter are `BIO_connect` → `connect()` (libcrypto) and `sock_read`/
`sock_write` → `read`/`write()` (libcrypto), reached through libssl's `BIO_read`/`BIO_write`. The
`accept`/`gethostbyname`/`select`/`recvfrom`/`sendmsg`/`recvmsg` imports in libcrypto trace to
server-side (`BIO_s_accept`), legacy (`BIO_gethostbyname`), and DTLS (`bss_dgram.c`) code paths that
a TCP client TLS handshake does not exercise — imported because they're in the same shared object,
not because they're on this request path.

### libsqlite3.so.0 (3.46.1, from `dpkg -l`)

| symbol (nm) | notes |
|---|---|
| `read@GLIBC_2.2.5`, `write@GLIBC_2.2.5` | file I/O on the database/WAL file, not a network request path |
| `fdatasync@GLIBC_2.2.5` | WAL/journal durability sync |
| `nanosleep@GLIBC_2.2.5` | busy-handler backoff (`sqlite3_busy_timeout`) |

Not cloned/read from source — out of the ticket's "for the request path" source-review list
(curl/libpq/openssl only); sqlite operates on a local file, not a socket, so it isn't part of E18's
network-request-path concern in the same way. Flagged here because it's on the symbol import list
and the merged export list must include it if the ADR's gate is meant to cover all blocking calls,
not just network ones.

### libonig.so.5 (6.9.10) and libnghttp2.so.14 (1.68.0)

Both: **zero matches** for the blocking-symbol pattern (checked directly with `nm -D --undefined-only`).
Onig (PHP's `mbstring`/`preg_*` regex engine) is pure in-memory computation. nghttp2 is a HTTP/2
*framing* library — it parses/builds frames in buffers; libcurl (`lib/cf-h2.c`, not read in this
pass) does the actual `recv`/`send` and hands nghttp2 the bytes. Neither library needs to appear in
the ADR's export-list gate.

### libz.so.1 (linked, version not pinned — no direct symbol match beyond `read`/`write`)

| symbol (nm) | notes |
|---|---|
| `read@GLIBC_2.2.5`, `write@GLIBC_2.2.5` | zlib's `gzread`/`gzwrite` file-based API; PHP's zlib usage here is in-memory compression (curl's `Content-Encoding: gzip`, ext/zlib), not the gz-file API — these imports are likely present but unreached on the request path. Not verified further. |

### libgnutls.so.30 (3.8.12), libssh2.so.1 (1.11.1), libkrb5.so.3 (1.22.1) — brief, nm only

Per the ticket's "briefly, since libcurl pulls them in" instruction — no source clone, symbol list
only:

| Library | blocking symbols (nm) |
|---|---|
| libgnutls.so.30 | `connect`, `nanosleep`, `poll`, `read`, `recv`, `sendmsg` |
| libssh2.so.1 | `connect`, `fcntl`, `poll`, `recv`, `send` |
| libkrb5.so.3 | `connect`, `fcntl`, `flock`, `fsync`, `getnameinfo`, `read`, `recv`, `send`, `sendmsg`, `write` |

GnuTLS is **not** curl's TLS backend here (`curl --version` says `OpenSSL/3.5.5`) — it's pulled in
transitively, almost certainly by `libldap.so.2` (OpenLDAP's own TLS support), since curl links
LDAP for the `ldap`/`ldaps` protocols. libssh2 backs curl's `scp`/`sftp` protocols. libkrb5 backs
curl's `Kerberos`/`SPNEGO`/`GSS-API` auth and libpq's GSSAPI auth
(`src/interfaces/libpq/fe-secure-gssapi.c`, compiled in per `Makefile`'s `with_gssapi` check and
confirmed by `ldd` showing `libgssapi_krb5.so.2`/`libkrb5.so.3` linked). None of these three are on
a plain HTTP(S) `curl_exec` or a password/TLS-auth `pdo_pgsql` connection's request path — they
matter only for SFTP/SCP, LDAP, or GSSAPI-authenticated connections respectively.

## `_chk` (fortified) variants found

Full per-library `_chk` scan (separate `grep -E '_chk@|_chk$'` pass, since `-w` on the plain names
misses these — `_` is a word character, so `-w 'read'` does not match `__read_chk`):

| Library | `_chk` symbols found |
|---|---|
| libphp.so | `__fdelt_chk@GLIBC_2.15`, `__fprintf_chk`, `__fread_chk`, `__longjmp_chk`, `__memcpy_chk`, `__memmove_chk`, `__memset_chk`, `__memset_explicit_chk`, `__printf_chk`, `__snprintf_chk`, `__sprintf_chk`, `__strcat_chk`, `__strcpy_chk`, `__strlcpy_chk`, `__strncat_chk`, `__syslog_chk`, `__vasprintf_chk`, `__vfprintf_chk` |
| libcurl.so.4 | `__fdelt_chk@GLIBC_2.15`, `__memcpy_chk`, `__memset_chk`, `__snprintf_chk` |
| libpq.so.5 | `__explicit_bzero_chk`, `__memcpy_chk`, `__memmove_chk`, `__snprintf_chk`, `__strcpy_chk` |
| libssl.so.3 | `__memcpy_chk` |
| libcrypto.so.3 | `__fdelt_chk@GLIBC_2.15`, `__fprintf_chk`, `__memcpy_chk`, `__memset_chk`, `__strcat_chk`, `__syslog_chk`, `__vfprintf_chk` |
| libsqlite3.so.0 | `__memcpy_chk`, `__memmove_chk`, `__memset_chk` |
| libonig.so.5 | `__fprintf_chk`, `__memcpy_chk`, `__snprintf_chk`, `__vsnprintf_chk` |
| libz.so.1 | `__snprintf_chk`, `__vsnprintf_chk` |
| libnghttp2.so.14 | `__vsnprintf_chk` |
| libgnutls.so.30 | `__explicit_bzero_chk`, `__fprintf_chk`, `__fread_chk`, `__memcpy_chk`, `__memset_chk`, `__memset_explicit_chk`, `__snprintf_chk`, `__sprintf_chk`, `__vasprintf_chk` |
| libssh2.so.1 | `__explicit_bzero_chk`, `__fprintf_chk`, `__memcpy_chk`, `__poll_chk@GLIBC_2.16`, `__snprintf_chk` |
| libkrb5.so.3 | `__asprintf_chk`, `__explicit_bzero_chk`, `__fprintf_chk`, `__fread_chk`, `__memcpy_chk`, `__memmove_chk`, `__memset_chk`, `__poll_chk@GLIBC_2.16`, `__snprintf_chk`, `__strlcpy_chk`, `__strncat_chk`, `__strncpy_chk`, `__vasprintf_chk` |

Only two `_chk` symbols are relevant to the ADR's blocking-symbol gate:

- **`__poll_chk@GLIBC_2.16`** — imported by libssh2 and libkrb5 only (not curl, not libpq, not
  OpenSSL). A caller compiled with `_FORTIFY_SOURCE` and a compile-time-checkable `nfds`/buffer
  size gets the linker to resolve its `poll()` call to `__poll_chk` instead of `poll`. An
  interposer that exports only `poll` **will not** intercept these call sites — they need
  `__poll_chk` exported too (a fortified wrapper that does the same bounds check curl/glibc does,
  then defers to the real logic). This directly confirms the ticket's suspicion. It only matters
  for curl's SFTP/SCP (libssh2) and GSSAPI (libkrb5) paths, not plain HTTP(S)/pgsql.
- **`__fdelt_chk@GLIBC_2.15`** (libphp, libcurl, libcrypto) — this is **not** itself a blocking
  call. It's the fortified bounds-check glibc's `<sys/select.h>` inlines into `FD_SET`/`FD_ISSET`
  macro expansions (checking `fd < FD_SETSIZE`) when fortify is on. It shows up because these
  libraries have dead-code-eliminated `select()`-based fallbacks that still reference the macro at
  compile time in some translation unit, or (for libphp) a live `ext/sockets`/`main/network.c`
  `select()` call site (research 22, not re-verified here). It never itself blocks or calls into
  the kernel — it's a value check, not a syscall — so it does not need a place in the ADR's
  park/block symbol table, but a naive `grep` for `select`-adjacent symbols would wrongly flag it as
  one to interpose.

No `__read_chk`, `__write_chk`, `__recv_chk`, `__send_chk`, `__connect_chk`,
`__getaddrinfo_chk`, or any other fortified variant of the primary I/O syscalls was found in any of
the twelve libraries scanned. The fortified fallout on this toolchain/build is limited to
`__poll_chk` (two libraries, non-request-path protocols) and the unrelated `__fdelt_chk`.

## Merged export list (the union, for the ADR's symbol table)

Every distinct symbol name (base name, without the glibc version suffix) found as an undefined
import in at least one of the twelve `.so` files, restricted to the syscall-shaped pattern plus the
one relevant `_chk` variant:

```
accept, accept4, connect, fcntl, fdatasync, flock, freeaddrinfo, fsync,
getaddrinfo, gethostbyname, getnameinfo, nanosleep, poll, pread, pwrite,
read, recv, recvfrom, recvmsg, select, send, sendfile, sendmsg, sendto,
sigwait, sleep, usleep, waitpid, write, writev,
__poll_chk
```

Not seen anywhere in this scan (present in the grep pattern given, absent from every library's
import list): `readv`, `ppoll`, `pselect`, `epoll_wait`, `epoll_pwait`, `clock_nanosleep`, `pause`,
`wait`. If the ADR's symbol table is meant to be exactly "what these libraries import," these seven
can be dropped from the v1 export list; they'd only matter if a future dependency (or a different
build of the same libraries, e.g. with `epoll`-based internals) imports them.

`__fdelt_chk` is listed above in the `_chk` table but **excluded** from the merged export list
above — it is not a blocking call (see previous section) and does not need a symbol in the park/
block gate.

## Resolver finding (for this libcurl build): threaded, not c-ares

`curl --version`'s `Features:` line includes `AsynchDNS` but not `c-ares`; `ldd libcurl.so.4` shows
no `libcares.so` in the link closure; and reading `lib/asyn-thrdd.c` (compiled under
`#ifdef CURLRES_THREADED`, confirmed present as the only `asyn-*.c` resolver TU besides the
c-ares-only `asyn-ares.c`, which is dead code without `libcares.so` linked) shows:

- `Curl_async_getaddrinfo()` (`lib/asyn-thrdd.c:747`) spawns a pthread via `Curl_thread_create()`
  (`:447`) running `getaddrinfo_thread()` (`:203`).
- That thread body calls `Curl_getaddrinfo_ex()` (`:217`), which wraps the system `getaddrinfo()`
  (`lib/curl_addrinfo.c:543`/`:546`).
- On completion the thread writes one byte to a socketpair/eventfd (`wakeup_write`, `:236`-ish) that
  the main libcurl event loop is watching via the normal `Curl_poll` path — so the *notification*
  is readiness-based and request-path-visible, but the **`getaddrinfo()` call itself runs on a
  thread curl created, not on the fiber's OS thread**.

This matters directly for E18's acceptance (3) ("`getaddrinfo` parks via the runtime resolver"): a
symbol-interposition gate keyed off "is a fiber active on *this* thread" will see `FIBER_ACTIVE =
false` on curl's resolver thread (a fresh pthread has no fiber context), so the interposed
`getaddrinfo` would, under the default "unknown caller → block" policy, simply block that helper
thread — which is *not* the calling PHP thread, so it doesn't stall the reactor's poll loop, but it
does mean each concurrent DNS lookup costs curl a whole throwaway OS thread rather than being parked
as a fiber the way a socket read is. libpq's resolver (`pg_getaddrinfo_all`, `src/common/ip.c:65`)
is the opposite: it calls `getaddrinfo()` directly on the caller's own thread with no helper thread
at all, so for `pdo_pgsql` the interposed `getaddrinfo` genuinely would need to park the calling
fiber to meet acceptance (3) — curl's case is already "harmless but wasteful," libpq's case is
"would actually block the PHP thread today."

## Surprises

1. **libcurl's `select` import is a sleep, not a multiplexer.** `Curl_poll()` uses `poll()`
   exclusively for socket readiness on this build (`HAVE_POLL` defined); the one live `select()`
   call site (`lib/curlx/wait.c:83`) is `curlx_wait_ms()`, invoked only when `Curl_poll` has zero
   valid fds to watch, as a portable "just wait N ms" primitive. Interposing `select` in the ADR's
   gate would intercept curl's delay/backoff logic (e.g. between retries), not real socket waits.
2. **`__poll_chk` exists and only two of the twelve libraries use it** (libssh2, libkrb5) — neither
   is on the plain HTTP(S)/pgsql request path (SFTP/SCP and GSSAPI respectively). Confirms the
   ticket's concern that a `poll`-only interposer would have blind spots, but the blind spot here is
   narrow, not on the hot path.
3. **libssl/libcrypto use `read`/`write`; libcurl and libpq use `recv`/`send`.** Traced to
   OpenSSL's `include/internal/sockets.h` choosing the generic-Unix branch (`read`/`write`) vs.
   curl's `curl_setup_once.h` and libpq's `fe-secure.c` both calling `recv`/`send` directly. An
   interposer must cover both pairs — there is no single syscall name that covers "the TLS byte
   stream" across these three libraries.
4. **The socket-BIO layer (`BIO_s_socket`, `BIO_s_connect`) lives in libcrypto, not libssl.** So
   libssl.so.3's own `connect`/`accept`/`getaddrinfo` imports are absent — only libcrypto.so.3 has
   them. Anyone skimming just `libssl.so.3`'s symbol table would wrongly conclude OpenSSL never
   calls `connect()`.
5. **`SSL_sendfile()` → `ktls_sendfile()` → `sendfile()`** (`ssl/ssl_lib.c:2580,2631`) is why
   libssl.so.3 imports `sendfile` — a kernel-TLS zero-copy path, not exercised by ordinary
   `curl_easy_perform`/`pdo_pgsql` traffic unless KTLS offload is explicitly turned on. Easy to
   mistake for a request-path import.
6. **libpq does *not* import `select`** despite `fe-misc.c` containing a `select()` call — it's
   dead code behind `#ifndef HAVE_POLL`, correctly eliminated by the preprocessor on this Ubuntu
   build. Worth stating explicitly since libcurl's and libcrypto's lists *do* have `select`, and
   it would be easy to assume libpq does too by analogy.
7. **libcrypto's `accept`/`gethostbyname`/`select`/DTLS (`recvfrom`/`sendmsg`/`recvmsg`) imports
   trace to server-side (`BIO_s_accept`), legacy (`BIO_gethostbyname`), and DTLS code paths** that a
   TCP client TLS handshake (what curl and libpq actually do) never reaches. They're imported
   because they share the .so, not because they're on the request path — a purely nm-based export
   list would over-count libcrypto's request-path surface by about six symbols.
8. **libonig and libnghttp2 import zero blocking symbols** — pure in-memory libraries, confirmed by
   direct `nm` (no need to guess from their role).
9. **The threaded DNS resolver** (previous section) is the biggest single finding for the ADR:
   `getaddrinfo` from inside libcurl happens off the calling thread, `getaddrinfo` from inside
   libpq happens on it. A single symbol-interposition policy for `getaddrinfo` cannot treat both
   the same way and get the parking behavior E18 wants for both callers "for free."

## What was not verified

- libphp.so's own call sites for its ~26 blocking-symbol imports (deferred to research 22, which
  already covers `ext/sockets`/streams in more depth, and to the fact that `crates/ignis/src/php/**`
  is off-limits for a subagent to edit — reading `php-src` for call sites was judged out of this
  ticket's from-source scope, which named curl/libpq/openssl explicitly).
- Several libcrypto call sites (`poll`, `getaddrinfo`, `nanosleep`, DTLS symbols) — flagged
  "not identified"/"not verified" in the table above rather than guessed.
- libz.so.1's exact version and its `read`/`write` reachability from PHP's in-memory zlib usage.
- libsqlite3 and libonig source were not cloned (out of the from-source scope named in the ticket).
