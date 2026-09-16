# 31 — DNS resolution: how name resolution stops blocking a PHP thread

2026-09-16, research only (no code, no build, no git, no bench run — HARD LIMIT on this ticket).
`getaddrinfo(3)` has no file descriptor: it is a function call that blocks somewhere inside libc's
NSS machinery (files/dns/nis lookups), not a readiness wait on a socket. Universal park (ADR-0020)
parks by watching an fd become ready (`Op::Watch`) and only then making the real call — there is no
fd to watch here, so the park mechanism as ADR-0037 §2 defines it ("park... never buffers, never
changes an fd's flags except `connect`'s temporary `O_NONBLOCK`") cannot cover this call by
construction. The codebase has already half-said so in two places, both quoted verbatim below —
this note pins the decision down with call sites and a recommendation, since ADR-0020 (accepted,
2026-09-16) and `park.rs` (2026-09-17) currently disagree with each other in their wording.

**The existing self-contradiction, for the record.**
- `crates/ignis/src/php/park.rs:55-56` (comment, current code): *"Anything libphp calls that is
  not listed stays `block`; `getaddrinfo` has no fd and is offload's, not park's."*
- `docs/adr/0020-universal-park.md:54` (decision text, item 4 of "How a call parks"):
  *"`getaddrinfo`: a resolver `Op` (tokio `lookup_host`) with glibc-compatible `addrinfo`
  allocation and `freeaddrinfo` interposed..."* — listed as a sub-case of *park*, not offload.
- `docs/research/22-ext-sockets-and-transports.md:75` and
  `docs/research/30-libphp-blocking-call-sites.md:46,67,97` both independently conclude "no fd —
  offload, not park", and `docs/adr/0018-ext-sockets-and-missing-transports.md:26` called
  `socket_addrinfo_lookup` "answerable only by ADR-0016 offload" back on 2026-09-16.

So three research notes and the shipped code comment agree it is not `park` in ADR-0037's strict
fd-readiness sense; only ADR-0020's prose (written on the same day as research 26-28, before the
libphp audit existed) still calls it a park sub-case. §4 below resolves this: the mechanism is
real (interposing the symbol, parking the *fiber*), the label "park" is wrong for it under
ADR-0037 §2's definition, and it is not ADR-0016 "offload" either — it needs a name of its own.

## Method

Read-only: `grep` over `~/php-src` at tag `php-8.5.10` (the exact tag `scripts/build-php.sh`
builds, confirmed by `git log -1` in that checkout: `34308a66 Update versions for PHP 8.5.10`,
tag `php-8.5.10`), and the extension list from `docs/adr/0027-build-and-distribution.md:37`
(`--disable-all` plus `sockets, pdo_pgsql, pgsql, curl, openssl, opcache`, `scripts/build-php.sh`).
No source was cloned for libcurl/libpq/OpenSSL — research 26 and 27 already cloned the matching
installed tags (`curl-8_18_0`, `REL_18_6`, `openssl-3.5.5`) and read them; their line citations
are reused here verbatim, not re-derived, per this ticket's brief. Every php-src line number below
was independently re-checked in this pass with `sed -n`/`grep` against the same checkout research
26/30 used, and matches.

## 1. Who calls it today, in this build

| # | symbol | file:line (php-8.5.10 / installed tag) | caller | compiled in? |
|---|---|---|---|---|
| 1 | `getaddrinfo` | `main/network.c:190` (`if ((n = getaddrinfo(host, NULL, &hints, &res)))`) | `php_network_getaddresses()` — every `host:port` stream connect (`fsockopen`, `pfsockopen`, `stream_socket_client('tcp://…')`/`'ssl://…'`/`'udp://…'`, via `php_network_connect_socket_to_host`) | yes, `HAVE_GETADDRINFO` (glibc) |
| 2 | `freeaddrinfo` | `main/network.c:242` | same function, paired — see §2 | yes |
| 3 | `gethostbyname_r` | `main/network.c:1352` (`gethostname_re`, `HAVE_FUNC_GETHOSTBYNAME_R_6` branch) ← `php_network_gethostbyname()` (`:1424`) | `ext/standard` `gethostbyname()`/`gethostbynamel()` (`ext/standard/dns.c:219,245`) | yes — glibc defines `HAVE_GETHOSTBYNAME_R`, so plain `gethostbyname()` at `network.c:1412` is dead code (confirmed: `#if !defined(HAVE_GETHOSTBYNAME_R)` guards it) |
| 4 | `getnameinfo` | `ext/standard/dns.c:184` (AF_INET6), `:191` (AF_INET) | `gethostbyaddr()` → `php_gethostbyaddr()` | yes |
| 5 | `getaddrinfo` | `ext/sockets/sockets.c:2828` | `socket_addrinfo_lookup()`; hints built from a caller-supplied `ai_flags`/`ai_socktype`/`ai_protocol`/`ai_family` array (`sockets.c:2770-2816`, read in this pass) | yes, `--enable-sockets` |
| 6 | `freeaddrinfo` | `ext/sockets/sockets.c:2856` | same function, paired | yes |
| 7 | `getaddrinfo` | libcurl `lib/curl_addrinfo.c:543`/`:546` ← `Curl_getaddrinfo_ex()` (`:217`) ← `getaddrinfo_thread()` (`lib/asyn-thrdd.c:203`), on a `pthread_create`d helper thread, not the fiber's OS thread | any `curl_exec()`/`curl_multi_exec()` resolving a non-literal hostname | yes — research 26: `AsynchDNS` feature, no `c-ares`, threaded resolver confirmed from source; **cited, not re-derived** |
| 8 | `freeaddrinfo` | libcurl `lib/curl_addrinfo.c`, paired with #7, same helper thread | same | yes |
| 9 | `getaddrinfo` | libpq `src/common/ip.c:65` (`pg_getaddrinfo_all`) ← `fe-connect.c:3056/3068/3093` | `pg_connect()`/`pg_pconnect()` (ext/pgsql) and `PDO`'s `pgsql` driver (pdo_pgsql) — both link `libpq.so.5` | yes — research 26: **synchronous, on the caller's own thread, no internal thread pool**; **cited, not re-derived** |
| 10 | `freeaddrinfo` | libpq `src/common/ip.c`, paired with #9, same (caller's) thread | same | yes |
| 11 | `getaddrinfo` | libcrypto `crypto/bio/bio_addr.c:735` (`BIO_lookup_ex`'s `if (1) { #ifdef AI_PASSIVE … getaddrinfo() … }` branch — always compiled on this glibc, per research 27 row 6, which flagged the *lock* on the dead `#else` branch, not this call) | `BIO_s_connect`/`BIO_ADDR`-family helpers | yes, in the .so, but **not confirmed reachable from PHP userland in this build** — `ext/openssl`'s `xp_ssl.c` wraps an already-connected fd from `main/network.c` (research 30 group (c)); curl and libpq resolve their own IPs before handing them to OpenSSL (research 26's "bottom line for the request path") |

**Row count: 11** (5 `getaddrinfo`/`freeaddrinfo` call-site pairs + `gethostbyname_r` +
`getnameinfo`, across 5 distinct compiled-in libraries: libphp ×2 call paths, ext/sockets,
libcurl, libpq, libcrypto).

**Reachable from PHP userland today**, i.e. an ordinary script can trigger it with no special
build flag: rows 1–6 directly (`fsockopen`, `stream_socket_client`, `gethostbyname*`,
`gethostbyaddr`, `socket_addrinfo_lookup`), row 7–8 via `curl_exec` (does **not** block the PHP
thread today under `park` — see below), row 9–10 via `pg_connect`/`pg_pconnect`/PDO pgsql. Row 11
is present in the linked `.so` but no PHP-facing call path to it was found in this pass.

**What already doesn't block, and why it's out of scope here:** rows 7–8 (libcurl). The resolver
runs on curl's own helper pthread (`asyn-thrdd.c:447`); the *fiber's* OS thread only ever waits in
`poll()` on a socketpair fd curl created for the wakeup (research 26/27/28, all cited above) — and
`poll` is already one of the 20 symbols `park.rs` interposes (`park.rs:57`'s `SEED`, `libcurl`
listed with no `:symbol` qualifier). Acceptance (3) for curl (H34, `docs/adr/0020…md:107`) is
already met, today, with zero DNS-specific code — this note is entirely about rows 1–6 and 9–10.

## 2. What each caller needs if resolution happened elsewhere

Every call site above hands `&hints` in and reads `struct addrinfo *res` (or `struct hostent *`
for row 3) out; here is exactly what each does with it, read from source in this pass:

- **`hints` the replacement must honour.** `main/network.c:164-186`: `ai_family` defaults to
  `AF_INET`, widened to `AF_UNSPEC` if IPv6 probes as working (`ipv6_borked`, a lazily-cached
  static int, `:152-163`); `ai_socktype` is passed straight from the caller
  (`php_network_getaddresses(host, socktype, …)`, `socktype` from `stream_socket_client`'s
  transport). `ext/sockets/sockets.c:2770-2816`: the *user* sets `ai_flags`/`ai_socktype`/
  `ai_protocol`/`ai_family` from a PHP array (validated: `ai_family` restricted to `AF_INET`/
  `AF_INET6`, everything else passed through as a raw int). A replacement that does its own
  resolution (not a forward to the real `getaddrinfo`) must implement all four hint fields or
  silently change behaviour for any script that sets them (e.g. `AI_PASSIVE`, `AI_NUMERICHOST`,
  `AI_CANONNAME`).
- **What the caller reads afterwards.** `struct addrinfo` fields `ai_next`, `ai_family`,
  `ai_addr`, `ai_addrlen` (`network.c:225-235`, `sockets.c:2836-2854`) — `ai_canonname` is not
  read by either PHP caller in this pass (no `AI_CANONNAME` hint set by default). Row 3
  (`gethostbyname_r`) is a different ABI entirely: `struct hostent` with a caller-supplied scratch
  buffer that grows on `ERANGE` (`network.c:1345-1358`) — a resolver replacement for row 3 cannot
  reuse row 1's `addrinfo`-shaped design; it needs its own `hostent`-shaped translation, or (see
  §3) never touch this call site and just relocate the underlying syscall.
- **Who frees it, and when.** **Every PHP-level caller frees the chain itself, synchronously, on
  the same call stack, before returning** — this was the one fact worth re-deriving carefully,
  since it de-risks §3(b)/(c) a lot. `main/network.c`: `res` is walked once to count entries
  (`:226-227`), walked again to `emalloc`+`memcpy` each `sockaddr` into PHP's own `*sal` array
  (`:228-235`), then `freeaddrinfo(res)` at `:242` — all inside `php_network_getaddresses`, no
  `addrinfo*` pointer survives past that function's return. `ext/sockets/sockets.c`: same
  shape — the loop at `:2836` builds a PHP `AddressInfo` object per node (copying fields, not
  storing the pointer) and `freeaddrinfo(result)` follows at `:2856`, still inside
  `socket_addrinfo_lookup()`. Row 9 (libpq) is the one not fully re-verified in this pass — research
  26 places `getaddrinfo`/`freeaddrinfo` both in `src/common/ip.c`, and libpq's connection setup
  (`PQconnectPoll`'s state machine) never hands control to a different OS thread mid-connect
  (research 26: "synchronous, on the caller's own thread"), so whatever `pg_getaddrinfo_all`
  returns is consumed and freed within that same single-threaded state machine, not handed across
  threads by libpq itself — **not independently re-read line-by-line in `fe-connect.c` this pass**,
  flagged as inherited from research 26, not newly verified.
- **Thread-safety of the returned memory.** `struct addrinfo` nodes are `malloc`'d by whichever
  `getaddrinfo` implementation built them (glibc's own, if we forward to it); glibc's `malloc`/
  `free` are thread-safe for a pointer allocated on one thread and freed on another — nothing here
  is thread-local storage or a `alloca`. Since (per the point above) **no caller today frees a
  chain from a different thread than the one that called `getaddrinfo`**, this only becomes a live
  question if *our own* replacement introduces a cross-thread hand-off, which §4's recommendation
  addresses directly by not fabricating a chain at all.

## 3. Options, cost and risk

**(a) Route the resolving PHP functions through ADR-0016 offload.** `gethostbyname()`,
`gethostbynamel()`, `gethostbyaddr()`, `socket_addrinfo_lookup()` are ordinary `zif_*` functions
with PHP call frames — exactly what `route.rs`'s function-swap-at-MINIT mechanism already hooks
for `curl_*` (`route.rs:32`, `DEFAULT_FUNCTIONS`). Adding these four names to
`IGNIS_OFFLOAD_FUNCTIONS` (or a new default list) costs nothing new in Rust: the router copies the
hostname string in, runs the real function on an offload worker (its own TSRM context, V-24:
13-67 µs/call), copies the `array`/`string`/`false` result back. **What it cannot cover: row 1
(`main/network.c:190`, `fsockopen`/`stream_socket_client`) and rows 9-10 (libpq, reached from
`pg_connect`/PDO pgsql)** — neither has a PHP-level call frame at the resolving line; the
resolution happens *inside* a C function (`php_network_connect_socket_to_host`,
`pg_getaddrinfo_all`) that itself is many frames below any `zif_*` entry point ADR-0016 can swap.
`PDO`'s `pgsql` driver is already in `route.rs`'s `DEFAULT_CLASSES` (`"PDO,SQLite3"`, `route.rs:33`)
and so already runs its *whole* `connect()` — including the internal `getaddrinfo` — on an offload
worker today; the gap is `pg_connect()`/`pg_pconnect()` (ext/pgsql, not PDO — used by
`bench/php/e18_dns.php`), which is not in the routed list, and every `tcp://`/`ssl://` stream
wrapper, which ADR-0016 was never designed to reach (it routes whole PHP function/class calls, not
one C call three frames inside `stream_socket_client`).

**(b) Interpose `getaddrinfo` itself, run it on an offload worker, return a glibc-compatible
`addrinfo` chain plus an interposed `freeaddrinfo`.** This is what ADR-0020's prose describes.
The ownership contract, stated exactly: the interposer's `getaddrinfo` must return a pointer the
caller can walk with `ai_next`/`ai_addr`/`ai_addrlen` exactly like glibc's own, and whatever
`freeaddrinfo` symbol the caller later calls (also interposed, since it's already on ADR-0020 §1's
export list) must recognise "did we build this chain" vs "did glibc build this chain" and free
accordingly — ADR-0020's own words: *"a list glibc did not build must not reach glibc's free
path."* That sentence is the risk: it presupposes we **fabricate** our own chain (e.g., from a
`hickory-dns` answer with different node/string layout than glibc's resolver would allocate), which
means every one of the eleven call-site rows in §1 that reads `ai_canonname`, calls `inet_ntop` on
`ai_addr`, or is compiled with an ABI assumption about the allocator (fortified `_FORTIFY_SOURCE`
bounds on `ai_addrlen`, glibc's own `freeaddrinfo` walking a linked list it allocated with its own
bookkeeping) is now trusting memory *we* built. A single call site anywhere in the elven-row list
(or a future one, e.g. a PHP extension not in `--disable-all`'s list that calls the real
`freeaddrinfo` directly without going through our interposed one — possible via a statically-linked
alias, or the same `fread`-style "stays inside glibc" limitation ADR-0020's addendum already flags
for other symbols, research 26 §"glibc-internal-alias limitation") corrupts the heap. **This risk
disappears if the "offload worker" never fabricates a chain and instead forwards to the *real*
`getaddrinfo`/`freeaddrinfo`** (dlsym(`RTLD_NEXT`), exactly the pattern research 28's interposer
already uses and proved works for the other libc-level symbols) **on a different thread** — see §4.
Cost: one thread (or tokio's blocking pool, see (c)) per concurrent lookup, same shape as
ADR-0016's worker-per-call bound (`docs/adr/0016-offload.md`'s addendum: "Pool size is the
concurrency bound").

**(c) A runtime resolver on the tokio side** (`hickory-dns`, or tokio's own blocking-pool
`getaddrinfo` via `tokio::net::lookup_host`/`ToSocketAddrs`, dispatched with
`tokio::task::spawn_blocking`). `crates/ignis/Cargo.toml:10` already has `tokio` with the `net`
feature; no `hickory-dns` dependency exists today (`grep -n hickory\|trust-dns` over
`crates/ignis/Cargo.toml`/`crates/ignis-sys/Cargo.toml`/the workspace `Cargo.toml` found nothing).
`tokio::net::lookup_host` itself is not a "true async" resolver — it wraps `std::net::ToSocketAddrs`
inside `spawn_blocking`, i.e. it *is* glibc's `getaddrinfo` run on tokio's own bounded blocking
thread pool, not a hand-rolled DNS client. Structurally this is exactly `pg.rs`'s and `grpc.rs`'s
existing shape: a new `Op::Custom` future (`reactor.rs:29`, "any tokio future producing a
PHP-facing outcome... plain data in, plain data out") plus a thin `module.rs` function — the
"normal recipe" CLAUDE.md names for a new capability, already proven at V-24/pg.rs's numbers. Two
sub-variants: **(c-i)** a real DNS client (`hickory-dns`) that returns `SocketAddr`s we then
translate into a fabricated `addrinfo` — inherits (b)'s exact fabrication risk, *plus* a new
dependency, *plus* behaviour drift from glibc (NSS `/etc/hosts`, `/etc/nsswitch.conf`, mDNS,
`AI_ADDRCONFIG` heuristics are glibc-specific and `hickory-dns` does not replicate them). **(c-ii)**
call the *real* `getaddrinfo` inside the `spawn_blocking` task and hand the *real* pointer back —
no fabrication, no new dependency, glibc's NSS behaviour preserved exactly. (c-ii) is the
recommendation in §4.

**(d) Do nothing, document it.** What breaks: rows 1, 3-6, 9-10 (six of eleven call sites) keep
blocking the calling PHP OS thread for the lifetime of the DNS lookup — under today's shipped
default (`IGNIS_PARK`'s seed already lists `libpq` and `libphp:connect` etc., `park.rs:57`, so
`connect()` itself already parks for these callers) the *connect* half is non-blocking but the
*resolve* half in front of it is not, so a slow-resolving hostname still stalls the fiber's thread
before `connect()` is ever reached — negating H33/H34's promised concurrency for any workload that
resolves a real (non-cached, non-`localhost`) hostname. **How often this fires today**: every
`fsockopen`/`stream_socket_client` to a hostname (not a literal IP), every `pg_connect`/
`pg_pconnect` to a hostname, every `gethostbyname*`/`gethostbyaddr` call — i.e. it is the common
case for any application-level DNS name, not an edge case; `bench/php/e18_dns.php` (already in the
tree, `bench/php/e18_dns.php:1-11`'s own comment) documents that it currently measures
`localhost` (resolved instantly, no real lookup) precisely because this gap is open — its own
header says *"H34's real target is `slow.ignis.test`... getaddrinfo(3) only sees that stub once
the runtime resolver is pointed at it... H34 needs a knob the implementation doesn't have yet"*.

## 4. Recommendation

**(b)+(c-ii), not (a), not (c-i), not (d):** interpose `getaddrinfo`/`freeaddrinfo` (both already
named in ADR-0020 §1's 20-symbol export list — no new symbol to add) exactly like the other
interposed calls (research 28's pattern: a 3-line C shim capturing the return address, `dlsym`
`RTLD_NEXT` once, cached), but instead of an `Op::Watch(fd)` (there is no fd), the fiber-active
path submits an `Op::Custom` future that runs the **real** `getaddrinfo` (forwarded, not
reimplemented) inside `tokio::task::spawn_blocking`, and suspends the fiber on it exactly as
`pg.rs`'s `acquire()`/`query()` already do. The result pointer — real glibc heap memory — crosses
the `Op::Custom` boundary as a plain `usize` (one `unsafe impl Send` on a newtype wrapper, with a
one-line safety comment: "glibc heap memory has no thread affinity; `malloc`/`free` are
thread-safe" — this is the new `unsafe` surface, and per CLAUDE.md only the main agent may write
it) and is handed back to the C caller completely unmodified. The interposed `freeaddrinfo` is a
pure pass-through to the real `freeaddrinfo` (also `dlsym`'d once) — no bookkeeping table, no
"did we build this chain" branch, because we never build one. This sidesteps (b)'s entire stated
risk (§3(b)'s "a list glibc did not build must not reach glibc's free path" simply never arises)
and (c-i)'s NSS-behaviour drift and new dependency, while covering **all eleven rows in §1**
uniformly — including rows 9-10 (libpq) and row 1 (the stream wrapper), which (a) structurally
cannot reach. It costs one `spawn_blocking` task per concurrent lookup (tokio's blocking pool,
bounded, already part of the runtime `crates/ignis/Cargo.toml:10` builds today) instead of one
offload-worker thread with a TSRM context per lookup — cheaper to build (no TSRM context needed:
this is a plain C call, not PHP code) and reuses a mechanism (`Op::Custom` + tokio actor) already
proven at V-24/H33's numbers, rather than growing ADR-0016's PHP-worker pool for a job shape
(`(function name, serialized args)`, `offload.rs:17-19`) that doesn't fit a raw libc call with no
PHP frame at all.

**Classification note for ADR-0037 §2/§7.** This is not `park` (no fd, contradicts ADR-0020's
prose per the opening section — that sentence should be corrected) and it is not ADR-0016
`offload` either (no TSRM worker, no serialized PHP args, the "share objects" prohibition doesn't
apply since `addrinfo` is transparent C memory, not a Zend value). It is the same `Op::Custom` +
tokio-actor shape already used for gRPC and Postgres — CLAUDE.md's stated normal recipe for a new
capability, sitting one level below the three-mechanism budget's "wait mechanisms for existing
blocking calls" scope. Recorded here so ADR-0037 §7's "outside the three" table
(`docs/adr/0037-three-mechanisms.md:114-116`, currently empty) has its first candidate row, or so
the two-line contradiction at the top of this note gets fixed in ADR-0020's own text.

**Kill criterion.** Either of: (i) a `freeaddrinfo` call on a chain this design returned crashes,
double-frees, or is flagged by a `valgrind`/ASan run across a soak of concurrent lookups — would
mean glibc's cross-thread malloc/free assumption doesn't hold on this toolchain, and the design
reverts to (a) (function-level ADR-0016 routing for `gethostbyname*`/`gethostbyaddr`/
`socket_addrinfo_lookup`, plus adding `pg_connect`/`pg_pconnect` to `route.rs`'s routed-function
list) for whichever caller broke, since (a) never touches the pointer at all; (ii) `spawn_blocking`
dispatch cost at realistic concurrency (50-100, H34's shape) exceeds ADR-0016's measured per-call
offload cost (V-24: 13-67 µs) by more than an order of magnitude — would mean tokio's blocking
pool is a worse fit than a dedicated worker pool here, and offload (a) becomes the fallback for
cost, not correctness, reasons.

**Smallest test that proves it.** `bench/php/e18_dns.php` already exists
(`bench/php/e18_dns.php`) and is already H34's bench — its own header explains exactly why it
currently can't prove anything: it needs `IGNIS_RESOLVER=127.0.0.1:5353` (a knob `park.rs`/the
new resolver actor doesn't have yet) pointed at `bench/e18/resolver.py`, a local stub that answers
`slow.ignis.test` after a 200 ms delay, so that a real (non-instant) resolution is exercised
instead of `localhost`. Do not run it — per this ticket's constraints — but the file to change is
this one, not a new one: replace its `note=stub_not_wired` line with real numbers once (i) this
design lands and (ii) the `IGNIS_RESOLVER` knob exists. Expected numbers, from H34's own table
(`docs/adr/0020-universal-park.md:107`, unchanged by this note): **park/resolver-actor on** — 50
concurrent `pg_connect` calls to `slow.ignis.test`, wall ≈ 200 ms (the 200 ms delay paid once,
concurrently, matching H32/H33's already-measured shape: 279-337 ms / 296-333 ms at N=100 for the
fd-based calls, V-45); **off** (`IGNIS_NO_UNIVERSAL_PARK=1`, today's shipped behaviour) — wall
≈ 10,000 ms (50 × 200 ms serialized on one thread, matching the control-arm shape already recorded
for H32/H33's controls, ≈20,000 ms at N=100). A second, smaller correctness check worth adding
alongside it (not a new file — a second assertion in the same script): after the timed section,
assert every one of the 50 calls that reached a real connection attempt did not crash the process
and `ok`/error counts are consistent across three repeated runs — a `valgrind`/ASan run of this
same script is the kill-criterion (i) check, not a separate bench file.
