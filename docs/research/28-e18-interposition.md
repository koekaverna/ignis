# 28 — E18: does a symbol exported from the executable bind inside a shared library?

2026-09-16, main agent. The experiment that decides whether E18 ("universal park") is buildable at
all on this toolchain, before ADR-0020. Scratch crate under `/tmp/cmp/interpose` (deleted after
these numbers were recorded, per the disk rule); the whole of it is reproduced below.

## Question

If the `ignis` binary itself defines `read`, `poll`, `getaddrinfo`… and exports them, do calls made
*inside* libcurl (or libpq, libssl, libphp) resolve to ours rather than to glibc's — without
`LD_PRELOAD`, without relinking the libraries? And what does the "fiber active" gate cost on every
syscall the process makes when no fiber is active?

## Method

A Rust binary (`edition 2021`, `libc` only) defining three `#[no_mangle] pub unsafe extern "C"`
functions — `getaddrinfo` (forwarding via `dlsym(RTLD_NEXT)`), `poll` (same, counting hits while a
thread-local `FIBER_ACTIVE` is set) and `read` (forwarding via a direct `syscall(SYS_read)`, no
`dlsym`, no recursion) — linked with

```
cargo:rustc-link-arg=-Wl,--export-dynamic-symbol=getaddrinfo   (and poll, read)
```

so only those three are exported (not `--export-dynamic`, which would put every Rust symbol in the
dynamic table). Then: read `/etc/hostname` through `std::fs` (does Rust std survive its own `read`
being interposed?), `dlopen("libcurl.so.4")`, `curl_easy_perform` on `http://localhost.:1/` with a
1.5 s timeout while `FIBER_ACTIVE` is set (does a call *from inside libcurl* land in our symbol?),
and 10 M zero-length `read(fd, buf, 0)` on `/dev/zero` through the interposer vs a direct syscall
(the gate's cost).

## Result

```
$ nm -D target/release/interpose | grep -E " T (getaddrinfo|read|poll)$"
0000000000018050 T getaddrinfo
00000000000180f0 T poll
0000000000018190 T read

std read ok: 5 bytes
curl rc=28 (Timeout was reached)
getaddrinfo interposed from inside libcurl: 0 hit(s)
poll hit while fiber active: 8
read hit while fiber active: 0
read via interposer: 188.2 ns/call; direct syscall: 180.0 ns/call; gate overhead ≈ 8.3 ns
```

1. **Exporting works** with `--export-dynamic-symbol` on the linker cargo uses here, and the
   symbols come first in the process's lookup scope: **`poll` called from inside libcurl reached our
   function 8 times** while the gate was on. Interposition from the executable binds inside a
   shared library loaded later. E18's mechanism is real.
2. **Rust std keeps working** with `read` interposed — the forward path is a direct `syscall`, so
   there is no `dlsym` in the hot path and no way to recurse into ourselves.
3. **`getaddrinfo` showed 0 hits — and that is my experiment's fault, not a fact about libcurl.**
   Research 26 (E18-R1) read this build's source: libcurl 8.18.0 uses the *threaded* resolver
   (`AsynchDNS`, no c-ares): `Curl_async_getaddrinfo()` spawns a pthread (`lib/asyn-thrdd.c:447`)
   whose `getaddrinfo_thread()` calls glibc's `getaddrinfo()` (`lib/curl_addrinfo.c:543`) on that
   helper thread and signals the caller over a socketpair/eventfd. A call on the helper thread
   would still bind to our export — the counter here is not gated — so the 0 means no glibc call
   happened at all, and the reason is the hostname I chose: since 7.87 curl answers `localhost`
   itself without any resolver, and the URL parser drops the trailing dot I added to defeat
   exactly that. The first version of this note said "this libcurl never calls getaddrinfo"; that
   sentence was wrong. Consequence for acceptance (3), now stated correctly: for curl the PHP
   thread never blocks in `getaddrinfo` — it blocks in `poll` on the resolver's socketpair, which
   the `poll` interposer already catches (8 hits above), so curl's resolve parks *through `poll`*;
   the interposed `getaddrinfo` must recognise the helper thread as "not a fiber" and fall through.
   "Parks via the runtime resolver" applies to libpq (`pg_getaddrinfo_all` → synchronous
   `getaddrinfo` on the caller's thread, `src/common/ip.c:65`) and to libphp's own name lookups.
4. **`read` 0 hits** is expected: the connection to port 1 never opened, so curl never read.
5. **Gate cost: ~8 ns per syscall on the non-fiber path** (a thread-local read and a branch in front
   of a 180 ns syscall) — under acceptance (4)'s 20 ns. This is one measurement with the interposer
   compiled into a tiny binary; the ADR's `bench/e18-overhead.sh` repeats it with two builds of
   `ignis` (feature on/off) so the control is the real binary.

## What this rules in and out for ADR-0020

- In: symbol export per function with `--export-dynamic-symbol`; a thread-local gate; a direct
  `syscall` forward for the syscall-shaped functions; `dlsym(RTLD_NEXT)` only for the libc-level
  ones (`getaddrinfo`, `freeaddrinfo`) resolved once.
- Needs a reentrancy guard: our own reactor calls `poll` (`zif_ignis_watch`) and crossbeam parks on
  a futex — with the gate on, those must fall through, so the handler sets a thread-local
  `IN_PARK` while it runs and nested calls bypass.
- Caller-library policy needs the return address; stable Rust cannot take
  `__builtin_return_address(0)`, so each exported symbol is a 3-line C shim (compiled by `cc` in
  `build.rs`) that captures it and calls the Rust handler with it as an extra argument. Resolved to
  a library with `dladdr` once per call site and cached by address — only on the park path.
- The resolver acceptance is per library (research 26): curl parks through `poll`; libpq and libphp through the interposed `getaddrinfo` → runtime resolver `Op`.

The scratch crate, for the record:

```rust
// build.rs
fn main() { for s in ["getaddrinfo", "read", "poll"] { println!("cargo:rustc-link-arg=-Wl,--export-dynamic-symbol={s}"); } }
// main.rs (abridged): three exported fns + the gate + the 10 M-call timing loop, as described above.
```
