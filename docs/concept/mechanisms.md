# The two mechanisms

A blocking call inside a PHP request gets out of the way in exactly one of two ways. This is a
deliberate budget ([ADR-0037](../adr/0037-three-mechanisms.md), the "mechanism budget" in the
project's own CLAUDE.md): two mechanisms and one policy table, no more. A new blocking library, a
new PHP function, a new vendor static is a row in the table, not a new hook.

The budget used to name a third mechanism, an offload pool of synchronous PHP worker threads. It
was deleted on 2026-09-22 with the MVP cut (DECISIONS.md), so a call that cannot park now blocks
its PHP thread for the length of the call; there is nowhere else for it to go.

| mechanism | what it is | what it is **not** allowed to do |
|---|---|---|
| **park** | Syscall interposition ([ADR-0020](../adr/0020-universal-park.md)): the `ignis` binary exports the blocking libc symbols a linked library calls (`read`, `poll`, `connect`, `nanosleep`, `flock`, …) behind a thread-local "fiber active" gate. A call made *inside* a library — `curl_exec`, a `pdo_pgsql` query — is caught at the syscall boundary and parks the fiber instead of blocking the thread, with no PHP-level hook and no code change in the library. | **Buffer.** It never holds bytes — readiness, then the real call, one copy (the kernel's). It never changes an fd's flags except `connect`'s temporary `O_NONBLOCK`, restored before it returns. |
| **context** | Fiber-switch observer slots ([ADR-0006](../adr/0006-fiber-scoped-superglobals.md)): a `zend_observer` hook fires on every fiber switch and swaps a fixed list of thread-global state — `$_SERVER`, `$_GET`, `$_POST`, `$_COOKIE` first, listed vendor statics after — so two interleaved requests never see each other's. Since [ADR-0042](../adr/0042-fiber-scoped-objects.md) the same hook also swaps the declared property slots of objects marked scoped, which is how a container service answers each request with that request's state. | **Allocate per switch.** A slot swap is pointer moves, not allocation; anything that needs allocation happens at dispatch time, not on the hot observer path. ADR-0042 is held to this by construction: a scope's row is built when the scope first writes, and the switch moves `zval`s between a table and the object's slots. |
| **the table** | One policy table, `symbol \| PHP function \| class → park \| block`, driven from `ignis.toml`. Seeded from source-level lock audits: libcurl, libpq and libssl/libcrypto `park` (research 27); so do libphp's own audited call sites — `sleep`/`usleep`/`nanosleep`, `select`/`accept`/`poll`, `recv`/`send`/`recvfrom`/`sendto`/`recvmsg`/`sendmsg`, `connect`, `read`/`write`, and, since V-81, `flock` (research 30) — with anything else libphp calls left `block` until it is audited. | — |

## Which one a blocking call takes

- **A stream you already call through `php_stream`** — `file_get_contents('http://…')`,
  `fsockopen`, `ssl://`/`tls://` — takes **park** today, the same as any other socket call: the
  dedicated stream-transport hook that used to carry this (a Rust-side connection actor plus
  rustls for TLS) was deleted once universal park covered it, with no PHP-visible change
  ([ADR-0037](../adr/0037-three-mechanisms.md) §6 step 4, V-49; see
  [Compatibility](../compatibility.md)).
- **A C library that never touches `php_stream`** — `curl_exec`, a `pdo_pgsql` query, an OpenSSL
  handshake driven from `ext/openssl` — takes **park**, if its library is on the allow-list; the
  fiber suspends at the real syscall inside the library, with no PHP-level hook at all.
- **Disk I/O** — the `SQLite3` class, `PDO` on the `sqlite` driver, and anything else doing
  `read`/`pread`/`fsync` on a regular file — **blocks the OS thread** for the length of the call.
  `epoll` refuses regular files ([ADR-0024](../adr/0024-non-goals.md)), so there is no readiness to
  park on and, since the offload pool was deleted, nowhere else to run it. Requests already on that
  thread wait; the other threads keep serving. This is a stated non-goal, not a gap — see
  [What it is not](non-goals.md). The same is true of a CPU-bound extension call.
- **A library that holds a lock across a blocking call** — libphp's own opcache and TSRM paths
  today — is `block` by default: parking wrongly is a hang, delegating is always semantically
  correct. `park` is an allow-list populated only after a source-level lock audit says it is safe
  ([ADR-0020](../adr/0020-universal-park.md) has the audit and the hazard test: a shim library
  holding a mutex across a blocking `read` deadlocks under `park` and serializes cleanly under
  `block` — the hazard is measured, not assumed).
- **Superglobals and fiber-scoped objects** are never a wait mechanism at all — they are
  **context**: state that must not leak between two interleaved requests on the same thread. Note
  which noun moved: it used to be "fiber-scoped services", meaning hand-written façades, and since
  ADR-0042 it is any object the container marks, with the class left alone.

## Why two, not more

Before this consolidation the codebase had seven separate wait mechanisms — the stream transport
factory, the reactor's connection actor, `ext/sockets` hooks, the `sleep`/`usleep` swap, the
`accept` hook, offload, the context observer, and universal park itself — each with its own file,
its own `unsafe` surface, its own test suite. ADR-0037 measured what collapsing them costs and
saves: −1,436 Rust lines and −42 `unsafe {` blocks deleted, landing on the target it set of three —
park, offload, context — with every creating test still green under the collapsed model and the
binary down from 48.7 MB to 37.2 MB. The MVP cut of 2026-09-22 then deleted offload as well
(DECISIONS.md), leaving park and context. The budget is not a slogan: every blocking call the
runtime answers for is covered by one of the two, and everything else is honestly a call that
blocks its thread.
