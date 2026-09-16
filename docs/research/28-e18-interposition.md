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
3. **`getaddrinfo` was never called by libcurl** on this box — 0 hits for a hostname (`localhost.`,
   trailing dot to defeat any shortcut). This libcurl does not resolve through glibc's
   `getaddrinfo` on the calling thread: it is either c-ares (its own UDP queries — which we would
   see as `poll`, and did) or the threaded resolver (glibc on a helper thread curl spawns, which
   would still hit our export — so 0 hits says c-ares). E18-R1 settles which. Consequence for
   acceptance (3): "`getaddrinfo` parks via the runtime resolver" is about libpq and libphp's own
   `gethostbyname`/`getaddrinfo` (ext/standard, ext/sockets), not about this libcurl.
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
- The resolver acceptance has to be restated per library once R1 reports which resolver each uses.

The scratch crate, for the record:

```rust
// build.rs
fn main() { for s in ["getaddrinfo", "read", "poll"] { println!("cargo:rustc-link-arg=-Wl,--export-dynamic-symbol={s}"); } }
// main.rs (abridged): three exported fns + the gate + the 10 M-call timing loop, as described above.
```
