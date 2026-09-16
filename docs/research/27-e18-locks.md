# 27 — E18: who holds a lock across a blocking call (E18-R2)

2026-09-16, agent (research, no repo code). Answers, from the installed versions' source, which
per-library locks exist, what they wrap, and whether a blocking syscall can run while one is held —
the input to the `park` / `block` / `park-except-init` policy table in ADR-0020. Companion to
docs/research/26-e18-blocking-symbols.md (which symbols are imported) and
docs/research/28-e18-interposition.md (whether interposition binds at all).

## Versions and where the TLS backend actually lands

```
$ openssl version
OpenSSL 3.5.5 27 Jan 2026 (Library: OpenSSL 3.5.5 27 Jan 2026)

$ curl --version | head -1
curl 8.18.0 (x86_64-pc-linux-gnu) libcurl/8.18.0 OpenSSL/3.5.5 zlib/1.3.1 ...

$ ldd /usr/lib/x86_64-linux-gnu/libcurl.so.4
	... libssl.so.3, libcrypto.so.3, AND libgnutls.so.30/libhogweed/libnettle/libgmp ...

$ dpkg -l | grep -E 'libpq|postgresql'
ii  libpq5:amd64   18.6-0ubuntu0.26.04.1

$ ldd target/release/ignis | grep -iE 'curl|ssl|crypto|pq'
	libssl.so.3, libcrypto.so.3, libcurl.so.4, libpq.so.5
```

`ldd` on libcurl.so.4 lists both `libssl.so.3` and `libgnutls.so.30` — this is the trap the task
warned about. **`curl --version`'s own self-report is the authority, not the presence of the gnutls
link**: it prints `OpenSSL/3.5.5`, meaning libcurl's *active* TLS backend (`Curl_ssl` vtable
selected at build time, see `lib/vtls/vtls.c` `available_backends[]`) is OpenSSL. `libgnutls` is
pulled in transitively by `libssh2.so.1` (SFTP/SCP) and/or GnuTLS-linked LDAP, not by libcurl's TLS
layer. So on this box: **both PHP's ext/curl (via libcurl) and ext/openssl link the same
OpenSSL 3.5.5 libssl/libcrypto** — the kill criterion ("any OpenSSL or libcurl test failing under
`park` with a lock in the trace") is a single library's lock surface for both extensions, not two.

libpq is 18.6, newer than expected; matching tag `REL_18_6` exists upstream and was cloned.

Clones (all removed after this note was written, per the disk rule):
`/tmp/cmp/openssl` @ `openssl-3.5.5` (67b5686), `/tmp/cmp/curl` @ `curl-8_18_0` (2eebc58),
`/tmp/cmp/postgres` @ `REL_18_6` (724edf9). libphp is read from `/home/koe/php-src` @ `php-8.5.10`
(34308a66) — already checked out for `scripts/build-php.sh`, matching the ZTS+embed build this repo
requires; no separate clone needed.

## Verdict table

| # | Library | Lock | What it wraps (file:line @ tag) | Blocking syscall reachable while held? | Verdict |
|---|---|---|---|---|---|
| 1 | OpenSSL 3.5.5 | `err_string_lock` (`CRYPTO_THREAD_read/write_lock`) | `int_error_hash` lookup/insert/delete — the *string table* for error codes, not the per-thread error stack (`ERR_STATE` is TLS, no lock at all). `crypto/err/err.c:194` (`int_err_get_item`, read), `:263`/`:315`/`:771` (write, `err_load_strings`/`ERR_unload_strings`) | No — pure in-memory `LHASH` ops, no I/O in the critical section | `park` |
| 2 | OpenSSL 3.5.5 | `dgbl->lock` (RAND global, per-`OSSL_LIB_CTX`) | `RAND_GLOBAL.primary`/`.seed`/`.public`/`.private` pointers only. `crypto/rand/rand_lib.c:660` (`ossl_rand_get0_seed_noncreating`, read), `:769` (`rand_get0_primary`, read), `:811-824` (write, publish new primary/seed). The expensive work — `rand_new_seed()`/`rand_new_drbg()`/`EVP_RAND_instantiate()` — runs at `:769-808`, **between** the read-unlock and the write-lock, i.e. unlocked | No at this lock — lazy-init pattern deliberately drops the lock before the seed/DRBG is created | `park` |
| 3 | OpenSSL 3.5.5 | `drbg->lock` (per-`PROV_DRBG`, one per public/private/primary DRBG instance) | The *entire* `ossl_prov_drbg_generate()` body, `providers/implementations/rands/drbg.c:632-703`, including a conditional reseed (`ossl_prov_drbg_reseed_unlocked`, `:560-582`) | **Yes, conditionally.** On reseed, `get_entropy()` (`:192-204`) is called with the lock held; for a non-primary DRBG it recurses into the *parent* DRBG's `ossl_prov_drbg_generate` (nested: parent's own `drbg->lock` is taken independently while the child's is still held, `:213-224` via `ossl_drbg_lock_parent`/`parent_get_seed`); for the primary DRBG (`drbg->parent == NULL`) it calls `ossl_prov_get_entropy()` → `providers/implementations/rands/seeding/rand_unix.c:390-394`, which on Linux issues `getrandom(buf, buflen, 0)` — **flags `0`, i.e. the blocking variant** (no `GRND_NONBLOCK`), directly through the `getrandom` libc symbol. This *is* a real blocking-syscall-under-lock case in RAND_bytes's reseed path | **`park-except-init`** — see caveat below |
| 4 | OpenSSL 3.5.5 | `SSL_CTX->lock` / `SSL->lock` (session cache, `ssl_sess.c`, `ssl_lib.c`) | `LHASH` insert/remove (`ssl_sess.c:739-806` `SSL_CTX_add_session`, `:822-833` remove), ex_data get/set (`ssl_lib.c:1062-1103`), refcount bumps (`ssl_sess.c:938-984`). `ssl_get_prev_session` (session-cache lookup during ClientHello processing, `:391-519`) also only takes these locks | No — confirmed no `CRYPTO_THREAD_*` call exists anywhere under `ssl/record/` or `ssl/statem/` (the actual `SSL_read`/`SSL_write`/handshake I/O layer); `grep -rl CRYPTO_THREAD_*_lock ssl/` returns only `ssl_lib.c`, `ssl_sess.c`, and QUIC's `quic_reactor.c` (irrelevant, TCP path doesn't touch it) | `park` |
| 5 | OpenSSL 3.5.5 | `ex_data.c`'s `global->ex_data_lock` | `CRYPTO_get_ex_new_index`/ex-data class registry — in-memory arrays. `crypto/ex_data.c:50-53` | No | `park` |
| 6 | OpenSSL 3.5.5 | `bio_lookup_lock` (`crypto/bio/bio_addr.c:817-964`) | `gethostbyname`/`getservbyname` fallback path of `BIO_lookup_ex` — **but this whole branch is `#else` of `if (1) { #ifdef AI_PASSIVE ... getaddrinfo() ... } else { ... lock + gethostbyname ... }`** (`bio_addr.c:712-782`). `AI_PASSIVE` is always defined by glibc, so on this platform the `if (1)` branch is what compiles and the lock is never taken — `getaddrinfo()` itself (`:735`) runs with no `CRYPTO_THREAD` lock held at all | N/A on Linux glibc — dead branch here; would be yes (gethostbyname is not reentrant/thread-safe and does its own blocking resolution) on a platform without `AI_PASSIVE` | `park` (Linux); flag as `block` if ever built against a libc lacking `AI_PASSIVE` |
| 7 | OpenSSL 3.5.5 | `ossl_lib_ctx`'s `ctx->lock` (`crypto/context.c:63/70/77`) | Provider/property/method-store registry for one `OSSL_LIB_CTX` — used by `property.c`, `provider_core.c`, `core_namemap.c` etc. Not verified line-by-line whether provider *loading* (`dlopen` of a dynamic provider `.so`) can happen while held — default install uses the built-in (non-dynamic) providers, so this path is not exercised by ignis/PHP's normal ext/openssl or ext/curl use | Not verified for the dynamic-provider-load edge case; no reachable path in the default static-provider configuration | `park` (default config); **not verified** for `openssl.cnf`-configured dynamic providers |
| 8 | libcurl 8.18.0 | `Curl_share_lock`/`Curl_share_unlock` (`lib/curl_share.c:270-297`) | **No-op unless the application calls `curl_share_init()`+`CURLOPT_SHARE`** (`if (share->specifier & (1<<type))`, else pretends success without taking any mutex — the mutex itself is application-supplied via `CURLSHOPT_LOCKFUNC`, libcurl holds no lock of its own). When active: DNS cache (`lib/hostip.c:234-246` `dnscache_lock`), cookie jar (`lib/cookie.c:1163-1636`), HSTS cache (`lib/hsts.c:559-567`) — all in-memory list/hash mutation, confirmed by reading the locked sections in `hostip.c`/`cookie.c` | No, and PHP's ext/curl does not set `CURLOPT_SHARE` by default | `park` |
| 9 | libcurl 8.18.0 | multi handle (`CURLM`) | No lock at all — `Curl_multi_poll`/`curl_multi_socket_action` are explicitly single-threaded-per-handle; concurrent use of one `CURLM*` from two fibers is a data race, not a deadlock, and out of scope for this note | N/A (no lock) | N/A |
| 10 | libcurl 8.18.0 | threaded resolver: `addr_ctx->mutx` (`lib/curl_threads.c` mutex primitive) + a **socketpair**, not a condvar | The *normal* path: helper thread (`pthread_create`, `lib/asyn-thrdd.c:447` `getaddrinfo_thread`) runs `Curl_getaddrinfo_ex` → `getaddrinfo()` **on the helper thread**, then writes a byte to `addr_ctx->sock_pair[1]` (`wakeup_write`, `:241`/`:286`). The *calling* (fiber) thread never blocks on a mutex or condvar for the common case — `sock_pair[0]` is registered in curl's own pollset (`Curl_pollset_add_in`, `:705`) and waited on via ordinary `poll()`/`select()`, exactly like a socket. `Curl_thread_join()` (`lib/curl_threads.c:90`, literal `pthread_join`) is called only after `addr_ctx->thrd_done` is already true (`async_thrdd_shutdown`, `:494-499`), so it returns immediately in the common case | **The `getaddrinfo()` syscall chain runs on a plain `pthread_create`d helper thread — not a fiber, not one of ignis's N worker OS threads.** Interposing `getaddrinfo` there must fall through to libc unconditionally (gate sees "not a fiber", `IGNIS_THREADS` threads are the only fiber-carrying threads). The thing that *does* need to park on the calling/fiber thread is the `read`/`poll` on `sock_pair[0]` — already one of the exported symbols, no new Op needed. The one path that truly blocks the calling thread without an fd is `Curl_async_await()` (`asyn-thrdd.c:566-572`, doc comment: "should be avoided since using this risks getting the multi interface to hang") and the final `Curl_thread_join` in `Curl_async_thrdd_destroy` at easy-handle teardown (`:544-551`) — both call raw `pthread_join`, which has no fd and is not one of the exported symbols; PHP's ext/curl event loop (multi-handle + `curl_multi_select`) does not call `Curl_async_await` | `park` (the `sock_pair[0]` read/poll); the helper thread's own `getaddrinfo` call is **not a fiber thread at all** — gate policy is irrelevant there, not `park` vs `block` |
| 11 | postgres/libpq 18.6 | `pg_g_threadlock` (`pgthreadlock_t`, default `default_threadlock` = a real static non-recursive `pthread_mutex_t`, `fe-connect.c:8279-8291`) | **Only** `pg_GSS_startup`/`pg_GSS_continue`/`pg_SSPI_startup`/`pg_SSPI_continue` in `fe-auth.c:1097-1174` (Kerberos/GSSAPI/SSPI auth negotiation). Confirmed by `grep pglock_thread fe-*.c`: the only call sites are those four in `fe-auth.c`; `fe-secure-openssl.c` has **zero** `pg_g_threadlock` references in 18.6 (the old OpenSSL `CRYPTO_set_locking_callback` dance that used to live here is gone since OpenSSL is self-locking since 1.1.0) | **Yes, if GSSAPI/SSPI auth is negotiated** — `pg_GSS_startup`/`pg_GSS_continue` call into libkrb5/libgssapi, which can make their own blocking network round-trips to a KDC, while `pg_g_threadlock` is held. Not reached by password/md5/SCRAM auth (the common PHP `pdo_pgsql`/`pgsql` case, and what `bench/php/e18_pgsql.php` exercises per the ADR) | `park-except-init` — safe for the default (non-GSSAPI) auth path exercised by acceptance (2); `block` (or at minimum flagged) if `PGGSSENCMODE`/Kerberos auth is configured |
| 12 | postgres/libpq 18.6 | `pqSocketCheck`/`pqsecure_read`/`pqsecure_write` (`fe-secure.c`, `fe-secure-openssl.c`, `fe-misc.c`) | No lock at all — confirmed no `pglock_thread`/`threadlock`/`CRYPTO_THREAD` references in `fe-secure.c` or `fe-secure-openssl.c` | N/A (no lock) | `park` |
| 13 | libphp 8.5.10 | TSRM `tsmm_mutex` (`TSRM/TSRM.c:281/327/445/519/548/583`) | Resource-ID table growth/lookup (`ts_allocate_id`, `ts_resource_ex`) — taken at thread registration/new-resource-id time, not on the per-request hot path once a thread is attached (ignis attaches one ZTS context per OS thread once, `embed.rs` `WorkerThread::attach`) | Not reached mid-request in ignis's one-context-per-thread model; not verified for the rare mid-request `ts_allocate_id` (a new extension lazily registering a resource on first use inside a fiber) | Contributes to `block` (see below), not itself a demonstrated hazard |
| 14 | libphp 8.5.10 | opcache SHM lock, `zend_shared_alloc_lock()`/`_unlock()` (`ext/opcache/zend_shared_alloc.c`, TSRM-mutex-backed on non-Windows) | Inspected the two hot call sites: `cache_script_in_shared_memory()` (`ZendAccelerator.c:1586-1692`) — hashtable lookup, SHM `bzero`/`memcpy` of an already-compiled script, hashtable update, **all in-memory**; and the cache-hit key-linking site in `persistent_compile_file()` (`ZendAccelerator.c:2079-2086`) — `zend_accel_add_key`, also in-memory. Critically, `persistent_compile_file()` calls `accelerator_orig_zend_stream_open_function(file_handle)` — the actual file/stream **open** (which for `allow_url_include` + a `http://` filename goes through the network stream wrapper) — at `:2058-2065`, **before** any `zend_shared_alloc_lock()` call in that function | No, at the two sites read — the file/stream open in `persistent_compile_file` happens unlocked, and both locked sections shown are pure SHM/hashtable bookkeeping | Not demonstrated as a hazard at the inspected sites, but **not exhaustively verified** across the full compile path (`accelerator_orig_compile_file` → `zend_compile_file` → `zend_do_link` etc. was not traced line-by-line); contributes to `block` below |
| 15 | libphp 8.5.10 | `main/reentrancy.c` `local_lock`/`local_unlock` (TSRM-mutex-backed) | Wraps non-reentrant libc calls (`ctime`, `localtime`, `asctime`, `gmtime`, `php_crypt_r` etc.) — CPU-only, no I/O in the critical section | No | Not a hazard by itself |
| 16 | libphp 8.5.10 | `Zend/zend_signal.c` signal-block/unblock (`SIGG(blocked)`/`SIGG(depth)`) | Not a `pthread_mutex` at all — a per-thread signal-mask critical section (`zend_signal_block`/`zend_signal_unblock`) used around allocator-sensitive code so a reentrant signal handler can't corrupt Zend internals | Different hazard class from the mutex-deadlock one asked about: if ignis's timer/preemption signal needs to interrupt a parked fiber's real syscall and that syscall happens to run inside a `zend_signal_block()` section, the signal is deferred, not lost — worth a footnote, not a `CRYPTO_THREAD`-style deadlock | Footnote only, not scored `park`/`block` |

### libphp overall verdict: `block`

Individually, the two hottest libphp locks I could trace (TSRM's `tsmm_mutex`, opcache's
`zend_shared_alloc_lock`) do **not** wrap a blocking network syscall at the call sites inspected —
the file/stream open in `persistent_compile_file` happens unlocked, before the SHM lock is ever
taken. But: (a) that is two call sites out of a compile/execute path that runs through hundreds of
files across `Zend/` and every enabled extension, not exhaustively walked here; (b) unlike OpenSSL/
libcurl/libpq, "libphp" as a *caller* covers ignis's own engine internals — a return address
resolving to `libphp` doesn't distinguish "safe site A" from "unsafe site B" the way a per-library
policy can for a genuinely external dependency; (c) this matches the ADR's own stated default —
"Default policy for an unknown caller is `block` — delegating is always semantically correct;
parking wrongly is a hang." libphp is exactly that unknown-caller case at the granularity this
policy operates on (return address → library), even though specific sub-paths were shown safe.
`block` is the conservative, provably-correct choice; it costs a delegated (not parked) blocking
call only in the rare mid-request case (opcache SHM churn, TSRM resource registration), which
already happens off the hot path in ignis's model.

## Caveat on `park-except-init` (rows 3, 11)

Two libraries (OpenSSL's per-DRBG lock, libpq's GSSAPI lock) hold a lock across a call chain that
*can* reach a blocking syscall (`getrandom`, or a Kerberos KDC round-trip), but only on paths that
are exercised rarely and not by the acceptance benches:

- OpenSSL: `getrandom()` is not itself in the ~25-symbol export list (`read`/`write`/`recv`/`send`/
  `poll`/`select`/`connect`/`accept`/`nanosleep`/`getaddrinfo`/…) named in BACKLOG.md's E18 section
  — so even though it runs under `drbg->lock`, it is not a symbol ignis interposes today, and cannot
  trigger the reentrancy hazard through *our* gate. Flagged `park-except-init` rather than plain
  `park` so that if `getrandom` is ever added to the export list, the policy table already says
  "not safe unconditionally here."
- libpq: only reached under GSSAPI/SSPI auth (`PGGSSENCMODE`/`krb5` configured), not the SCRAM/
  password path `bench/php/e18_pgsql.php` exercises per the ADR's acceptance (2).

Neither is "the DRBG/GSS call is fine to park" — it's "not reached by the symbols we actually
export, or not reached by the bench we actually run." A future export-list change (adding
`getrandom`) or a deployment with Kerberos-authenticated Postgres must re-open this row.

## Acceptance (5): the deliberate mutex-holding test library

### `bench/e18/locklib.c` (sketch — do not add to the repo yet, this is a spec for E18-A/E18-I)

```c
// bench/e18/locklib.c — ~20 lines, proves the hazard is real and the gate respects it.
// Build: cc -shared -fPIC -o liblocklib.so locklib.c -lpthread
#include <pthread.h>
#include <unistd.h>

static pthread_mutex_t g_lock = PTHREAD_MUTEX_INITIALIZER; // non-recursive by default

// Takes the lock, calls read() on a pipe fd (the caller arranges an empty
// pipe so read() blocks until someone writes to it, or never returns),
// releases the lock. Mirrors the hazard shape exactly: lock -> blocking
// syscall -> unlock.
int locklib_call(int fd, char *buf, size_t len) {
    pthread_mutex_lock(&g_lock);
    ssize_t n = read(fd, buf, len);   // <-- this is the symbol ignis interposes
    pthread_mutex_unlock(&g_lock);
    return (int)n;
}
```

Test harness (`bench/e18-deadlock.sh`, not written here — this is the shape it needs):

1. Build `liblocklib.so`, `dlopen()` it from a PHP extension or a small Rust/C harness linked into
   the `universal-park`-featured ignis build.
2. Create a pipe with nothing written to it (`read()` on the empty end blocks — real blocking
   syscall, no data ready).
3. Start two fibers **on the same OS thread** (`IGNIS_THREADS=1`), both calling `locklib_call(fd,
   ...)` — fiber A first, then (before A's `read()` returns — it won't, the pipe is empty) fiber B
   is scheduled onto the same thread and also calls `locklib_call`.
4. **Under policy `park`** (return address resolves to `liblocklib.so`, policy table says
   `park`): fiber A takes `g_lock`, calls the interposed `read()`, the gate sees "fiber active" and
   parks A (suspends A's stack, submits `Op::Watch` on `fd`, thread is now free to run other
   fibers). The scheduler resumes fiber B on the *same OS thread*. B calls `locklib_call`, tries
   `pthread_mutex_lock(&g_lock)` — **A still holds it** (A is parked, not finished) — B blocks
   inside `pthread_mutex_lock` on a non-recursive mutex it (as a thread) is contending with itself
   in spirit (same OS thread, different fiber, but `pthread_mutex_t` has no concept of "fiber", only
   of the calling *thread*, and here it's genuinely two different logical callers racing on one
   real OS thread — the mutex itself doesn't deadlock on self-owned-relock here since A and B are
   the same *thread* id calling `pthread_mutex_lock` from two different stacks, so the exact failure
   mode is **the thread wedges**: B's `pthread_mutex_lock` never returns because A (the only other
   holder) never gets to run again to call `pthread_mutex_unlock` — A is parked waiting for `fd` to
   become readable, but nothing will ever write to `fd` in this test, and even if it did, A can only
   resume when the *same* OS thread calls `ignis_poll()` again — which won't happen because that
   thread is now stuck inside B's `pthread_mutex_lock`. Classic loop: A waits on the reactor, the
   reactor wait is driven by the same thread that's stuck in B's lock acquisition. **This is the
   hang**, not a same-thread-relock `EDEADLK` (glibc's error-checking mutex type would report
   `EDEADLK` only for literal self-relock by the same thread id on the same call stack, which is
   not quite what happens here — a plain `PTHREAD_MUTEX_INITIALIZER` (normal, non-error-checking)
   mutex will simply hang forever rather than return `EDEADLK`, since two "logical" callers, not one
   recursive one, are involved from libc's point of view. Use `PTHREAD_MUTEX_INITIALIZER` in the
   shim to get the realistic hang, not the diagnostic-friendly `EDEADLK`).
5. **Under policy `block`**: the gate sees the return address resolves to `liblocklib.so` with
   policy `block`, does not park — fiber A's `read()` runs the real blocking syscall inline (the OS
   thread itself blocks, not just the fiber); B cannot even be scheduled onto that thread until A's
   `locklib_call` returns and releases `g_lock`. The two calls fully serialise. Test passes as soon
   as something writes to the pipe (or the test's own timeout fires and it writes to unblock A, then
   checks B completed after A).

### Deadlock detection

Use a **timeout, not a hang forever**: run the two-fiber scenario in a child process with
`alarm(5)` (or a `timeout(1)`-wrapped shell invocation, since bench scripts already run under
`timeout` per repo convention) armed before step 3. Two independent, complementary checks on
timeout:

- **`pthread_mutex_timedlock`** instead of `pthread_mutex_lock` in `locklib_call` for the *test's*
  own build of the shim (a `-DTEST` variant, or just always use `timedlock` with a generous
  deadline like 3s): if `pthread_mutex_timedlock` returns `ETIMEDOUT`, the harness can positively
  assert "this is the hang, and it is a lock-acquisition hang specifically" rather than inferring it
  from a generic process timeout. This is the primary, portable, no-extra-tooling signal —
  prefer it, since it works in CI without `gdb`/`eu-stack` installed.
- **`gdb`/`eu-stack` as corroboration**, for when the *first* signal (5) fires (i.e. the process
  wedged without even the `timedlock` variant returning, e.g. because the test is built against the
  plain `pthread_mutex_lock` shim to match production `locklib_call` exactly): attach
  (`gdb -p $PID -batch -ex 'thread apply all bt'` or `eu-stack -p $PID`) and grep the backtraces for
  `pthread_mutex_lock` in one fiber's frame and `read`/the reactor's `poll`/`epoll_wait` in the
  other — confirming *which* thread is stuck where, and that the OS thread count matches
  `IGNIS_THREADS=1` (i.e. it's really the same-thread case, not an unrelated hang). `gdb` can also
  read the mutex's `__owner` field (`p g_lock.__data.__owner`, glibc's internal layout, fragile
  across libc versions but fine for a one-off diagnostic assertion in a bench script) to prove B is
  contending against a lock A itself acquired.

Recommended shape for `bench/e18-deadlock.sh`: run the scenario with `timeout 8s`, build the shim
with `pthread_mutex_timedlock` (3s) as the primary detector (fast, portable, exact), and only fall
back to `gdb`/`eu-stack` process inspection if the harness process itself has to be killed by the
outer `timeout` (meaning even the `timedlock`-based detection path wedged, which would itself be a
bug in the test, not in ignis, and worth knowing about separately).

## Cleanup

```
$ rm -rf /tmp/cmp/openssl /tmp/cmp/curl /tmp/cmp/postgres
```

Left in place: nothing library-related. `/tmp/cmp/curl` and `/tmp/cmp/interpose` seen at start
belonged to a concurrent E18-R3 agent run and were not touched by this note beyond confirming the
tag `/tmp/cmp/curl` was already checked out at.
