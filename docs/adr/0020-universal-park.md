# ADR-0020 — Universal park: the binary interposes the blocking libc calls behind a fiber gate

Status: **accepted** (2026-09-16, research 26/27/28; H32–H36 open). Owner's expectation E18
(BRIEF.md, 2026-09-16, "ADR first").
Affects pain-map items: Swoole 5 (incomplete hooks — this is the general answer), PHP-FPM 1
(in-process C I/O that never touches php_stream: libcurl, libpq), RoadRunner 1 (state discipline:
handles stay on the fiber's thread). Depends on ADR-0007 (the stream factory stays), ADR-0009
(cancellation of a parked fiber), ADR-0016 (offload stays as the fallback for what cannot park).
Research: 26 (symbols), 27 (locks), 28 (interposition feasibility).

## Context

Today a blocking call inside a C library — `curl_exec`, a `pdo_pgsql` query, `getaddrinfo` from
libpq — blocks the PHP thread. ADR-0016 routes those calls to synchronous offload workers, which
costs a thread per concurrent call and copies arguments across (V-24: 13–67 µs per call, bounded by
the pool size). The stream hook (ADR-0007) cannot reach them: they never touch php_stream.

Research 28 established the mechanism: a symbol exported from the executable with
`-Wl,--export-dynamic-symbol=NAME` is first in the process's lookup scope, so a call to `poll`
made *inside* libcurl reached our function (8 hits in `curl_easy_perform`), Rust std keeps working
with `read` interposed when the forward path is a direct `syscall`, and a thread-local gate in
front of a syscall costs ~8 ns (acceptance (4) allows 20). Research 26 gives the real list of
blocking symbols the linked libraries import (31, from `nm`, including `__poll_chk`) and their
request-path call sites.

## Decision

1. **Symbols.** The `ignis` binary exports, each as a 3-line C shim built by `cc` in `build.rs`
   (the shim captures `__builtin_return_address(0)` — stable Rust cannot — and tail-calls the Rust
   handler): `read write readv writev pread pwrite recv send recvfrom sendto recvmsg sendmsg
   connect accept accept4 poll __poll_chk select nanosleep usleep sleep getaddrinfo freeaddrinfo
   getnameinfo`. Not interposed, with the reason: `fsync/fdatasync/flock/fcntl` (disk and locks —
   parking on a regular file is meaningless, epoll refuses them), `sendfile` (libssl's KTLS path
   only), `sigwait/waitpid` (process control), `gethostbyname` (libcrypto server-side/legacy paths
   only, research 26).
2. **Gate.** A thread-local `PARK: Cell<u8>` — `0` off, `1` fiber active, `2` inside our own
   handler (reentrancy). The scheduler sets `1` when it resumes a request fiber on a PHP thread and
   clears it on suspend/return; tokio threads, offload workers and curl's resolver helper thread
   never set it. The non-fiber path is: load TLS, compare, forward — the ~8 ns of research 28.
   `2` makes every nested libc call from inside a handler (our own `poll` in `zif_ignis_watch`, the
   channel's futex, `dladdr`) fall straight through.
3. **Policy.** On the park path only, the return address is resolved once per call site with
   `dladdr` and cached (address → library → policy). Policy is per library: `park` or `block`.
   **Default `block`** — delegating is always semantically correct, parking wrongly is a hang
   (ADR-0018). `park` is an allow-list set from `ignis.toml` `[park] libraries = ["libcurl",
   "libpq"]` / `IGNIS_PARK=libcurl,libpq`, seeded by research 27's verdicts. libphp itself is
   `block`: its stream I/O is ADR-0007's, and double handling would park inside the stream hook.
4. **How a call parks.** `read/recv*/write/send*` on a socket: submit `Op::Watch(fd, dir)`,
   suspend the fiber (the A4 "park then delegate" rule at the syscall layer — H31 taught that
   readiness is only the kernel's; the real call follows on the same fd and returns at once).
   `poll`/`select`: one `Op::Watch` per fd, `await_any`, then the real call with timeout 0 to fill
   the result the library expects. `connect`: flip `O_NONBLOCK` on, real `connect` (`EINPROGRESS`),
   park on writable, restore the flags, return `SO_ERROR` — the library never sees the flag.
   `nanosleep/usleep/sleep`: `Op::Sleep`. `getaddrinfo`: a resolver `Op` (tokio `lookup_host`) with
   glibc-compatible `addrinfo` allocation and `freeaddrinfo` interposed to free what we allocated
   (a list glibc did not build must not reach glibc's free path). A non-socket fd (regular file,
   `EPERM` from epoll) falls through to the real call.
5. **Stays as it is.** The stream factory (ADR-0007). Offload (ADR-0016) remains the path for a
   library on `block` and for `SQLite3` (disk I/O). Feature `universal-park` so the overhead bench
   has its control build; `IGNIS_NO_UNIVERSAL_PARK=1` is the hook-off control at run time.

## Policy table (research 27, versions installed here: OpenSSL 3.5.5, curl 8.18.0, libpq 18.6, libphp 8.5.10)

| library | locks that matter | can a blocking syscall run while one is held? | policy |
|---|---|---|---|
| libssl / libcrypto | `err_string_lock` (string table only; the ERR stack is per-thread), RAND global lock (pointer publish only), per-DRBG lock, session-cache locks, `bio_lookup_lock` | Record and state-machine I/O (`ssl/record/`, `ssl/statem/`) take no `CRYPTO_THREAD_*` lock; the one exception is the primary DRBG's reseed, which calls `getrandom(…, 0)` under `drbg->lock` — `getrandom` is not an exported symbol, so the gate never sees it | **`park`** (revisit if `getrandom` ever joins the list) |
| libcurl | `Curl_share_lock` (a no-op unless the app calls `curl_share_init`; PHP's ext/curl does not); the threaded resolver's helper thread | The fiber thread waits on a socketpair fd through curl's own pollset, never on a condvar; `Curl_async_await` uses a raw `pthread_join` only at teardown and is documented as an API to avoid | **`park`** |
| libpq | `pg_g_threadlock` | Wraps GSSAPI/SSPI negotiation in `fe-auth.c` only; nothing around `pqSocketCheck` or `pqsecure_read/write`; the OpenSSL-locking dance in `fe-secure-openssl.c` is gone | **`park`**, with GSSAPI/Kerberos auth `block` until measured (acceptance 2 uses password/SCRAM) |
| libphp | TSRM mutexes, the opcache SHM lock, reentrancy locks | The traced hot sites open files *before* taking the SHM lock, but the compile/execute path was not walked exhaustively, and a return address cannot split libphp into safe and unsafe sites | **`block`** (also: its stream I/O is ADR-0007's) |
| anything else (libz, libonig, libsqlite3, libnghttp2, libssh2, libkrb5, libgnutls…) | not analysed | — | **`block`** (the default) |

TLS backend fact that collapses the kill criterion's two names into one lock surface: `ldd` shows
libcurl linking both libssl.so.3 and libgnutls.so.30, but `curl --version` reports OpenSSL/3.5.5 —
gnutls arrives through libssh2. ext/curl and ext/openssl share OpenSSL 3.5.5's locks.

Acceptance (5)'s test library (`bench/e18/locklib.c`, specified in research 27): a plain
non-recursive `pthread_mutex_t`, `lock; read(pipe); unlock`. Under `park`, two fibers on one OS
thread — not the same stack re-locking — produce a hang, not `EDEADLK`; the test detects it with
`pthread_mutex_timedlock` on the harness side and corroborates with `gdb -p … thread apply all bt`.

## Resolver, per library (research 26)

- libcurl 8.18.0 (this build): threaded resolver. `getaddrinfo` runs on a helper pthread; the PHP
  thread blocks in `poll` on a socketpair — the `poll` interposer parks it. The interposed
  `getaddrinfo` sees `PARK == 0` on the helper thread and forwards. Acceptance (3) for curl is
  therefore met through `poll`, and the cost is curl's own throwaway thread per lookup.
- libpq: `pg_getaddrinfo_all` → synchronous `getaddrinfo` on the caller's thread
  (`src/common/ip.c:65`) — this is where the runtime resolver parks the fiber.
- libphp (`gethostbyname`, `ext/sockets`, `ext/standard`): same as libpq once libphp's lookups are
  on a `park` policy for that symbol; default stays `block` until measured.

## Kill criterion (owner's, verbatim)

Any OpenSSL or libcurl test failing under "park" with a lock in the trace.

Operationally: the E15 suites and `bench/e6-ssl.sh` run with `IGNIS_PARK=libcurl,libcrypto,libssl`;
a failure whose backtrace shows a `pthread_mutex_lock` owned by the same thread reverses the
`park` verdict for that library (it moves to `block`) — and if that library is libcurl or OpenSSL,
reverses this ADR's claim that universal park covers TLS at all.

## Hypotheses (HYPOTHESES.md H32–H36, one per acceptance)

| H | acceptance | bench | expected |
|---|---|---|---|
| H32 | (1) `curl_exec` parks, no PHP hook, no offload | `bench/php/e18_curl.php`: 100 fibers × `curl_exec` to `/sleep?ms=200`, `CURLOPT_WRITEFUNCTION` records `spl_object_id(Fiber::getCurrent())` | wall ≈ 200 ms on one thread (offload off, `IGNIS_NO_OFFLOAD_ROUTE=1`); every write callback ran in its own fiber; control `IGNIS_NO_UNIVERSAL_PARK=1` → ≈ 20 s |
| H33 | (2) pdo_pgsql parks, offload disabled | `bench/php/e18_pgsql.php`: 100 × `SELECT pg_sleep(0.2)` over pdo_pgsql | ≈ 200 ms; control ≈ 20 s |
| H34 | (3) `getaddrinfo` parks via the runtime resolver | `bench/php/e18_dns.php`: 50 concurrent libpq connects to a hostname the runtime resolver answers slowly (a local resolver stub with 200 ms delay) | ≈ 200 ms; control ≈ 10 s |
| H35 | (4) non-fiber overhead < 20 ns/syscall | `bench/e18-overhead.sh`: two builds (feature on/off), 10 M zero-length `read` on a non-PHP thread, 3 reps | delta < 20 ns (research 28 measured ~8) |
| H36 | (5) the hazard is real and the policy contains it | `bench/e18-deadlock.sh` with research 27's `locklib.c` (mutex → `read` on a pipe → unlock) under `park` and under `block` | `park`: two fibers on one thread deadlock (test times out, `gdb` shows the mutex owner is the same thread); `block`: passes |

## Consequences

- `curl_*`, `pdo_pgsql`, `pgsql` and anything else on a `park` library become non-blocking with no
  code change and no offload thread; the offload pool shrinks to disk I/O and `block` libraries.
- Every syscall in the process pays the gate (~8 ns). E1/E2/E4 are re-measured with the feature on
  and recorded beside the feature-off numbers before the feature becomes the default.
- A library that holds a lock across a blocking call is a hang under `park`; the default is
  `block` and the allow-list is evidence-based (research 27), which is why (5) is an acceptance
  and not a warning in the docs.
- The C shims are the one new `unsafe` surface; each states why the return-address capture and
  the forward are sound. `guard-ffi.sh` covers `crates/ignis/src/park/**` and the shim.

## Not decided here

Whether `park` becomes the default for libcurl and libpq in `ignis.toml.example` — after H32–H36
and the kill-criterion run are green, as a separate decision with the E15 numbers beside it.

## Addendum (owner ADR sweep, 2026-09-17) — the owner's design, element by element, against what is built

The owner's note lists E18 as "proposed, not started". The record disagrees on the mechanism and
agrees on the rest: stage 1 is built behind a default-off feature and measured (V-45), the
elements below are not. Status per element:

| owner's element | status |
|---|---|
| Export the ~25 blocking libc symbols behind a thread-local "fiber active" gate | **built, 20 symbols** (V-47 adds accept/accept4, select, ppoll, __poll_chk, recvmsg/sendmsg, readv/writev; stage 1, 11 symbols (V-45: read/write/recv/send/recvfrom/sendto/poll/connect/nanosleep/usleep/sleep); research 26's list is 31 with `__poll_chk`; the rest is stage 2 |
| Caller-library policy `park | offload | block` | **park \| block built** (`IGNIS_PARK`, default block); **`offload` unbuilt** — routing a call to a worker from inside the interposer, so a library on `offload` neither blocks the thread nor parks; ties to ADR-0016 |
| Address-interval map from `dl_iterate_phdr` | **unbuilt** — today `dladdr` once per call site, cached by return address; the interval map is the cheaper lookup for the first hit and needed before the policy can be per *symbol* within a library (BACKLOG E18-C item 2) |
| **Detector-first rollout**: block + measure + report before any park | **unbuilt, and it is the rollout order** — `IGNIS_PARK_TRACE=1` prints decisions (V-45); a report mode that counts would-park call sites per library while forwarding everything is the detector, and it runs against the E15 suites and a real application before any library goes on `park` |
| pthread_mutex accounting: never park while holding a lock; switch to the lock holder instead of blocking | **unbuilt** — interposing `pthread_mutex_lock/unlock` with a per-thread depth counter; parking at depth > 0 is refused (forward, i.e. block). "Switch to the holder" needs the holder to be a fiber on the same thread and is the H36 hazard turned into a scheduling rule. Research 27's verdicts stand until this exists |
| `getaddrinfo` replaced whole (runtime resolver, glibc-compatible `addrinfo`, `freeaddrinfo` interposed) | **unbuilt** — stage 2; per library: curl parks through `poll` already, libpq/libphp need this |
| Cancellation surfaces as `ECANCELED` | **unbuilt** — stage 2; today a cancelled parked call falls back to blocking (V-45); ADR-0009 addendum item 2 |
| Regular files need io_uring or offload | **recorded** — a regular fd forwards (epoll refuses it); file I/O stays layer 3 (ADR-0021) until io_uring is measured; io_uring is off unless enabled (ADR-0035) |
| Kill criteria "as discussed" | **recorded verbatim** above, plus H36's hazard test |
| glibc-internal-alias limitation | **recorded**: a call that stays *inside* glibc — `fread` → `_IO_file_read` → the internal `__read` alias — never reaches an exported symbol, so stdio-based I/O in a library is not interposed; the fortified `__*_chk` entry points are interposed only where research 26 found them imported (`__poll_chk`, stage 2) |

**What stage 1 taught that the note did not have:** a data call on a non-blocking fd must
forward — curl probes with `recv` and blocks in its own `poll`; parking the probe hung the first
`curl_exec` (V-45). And the slope: 100 concurrent 200 ms operations take ~300 ms on one thread
because the libraries' own CPU work serialises, not because of the parking (V-45, same at 4 server
threads).

**Status after this addendum:** accepted for the mechanism and stage 1; the elements marked
unbuilt are the plan for stage 2 in the order listed, detector mode first. The feature stays off
by default until H32–H36 are green with the detector's report attached.
