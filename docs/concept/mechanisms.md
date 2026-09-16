# The three mechanisms

A blocking call inside a PHP request gets out of the way in exactly one of three ways. This is a
deliberate budget ([ADR-0037](../adr/0037-three-mechanisms.md), the "mechanism budget" in the
project's own CLAUDE.md): three mechanisms and one policy table, no more. A new blocking library, a
new PHP function, a new vendor static is a row in the table, not a new hook.

| mechanism | what it is | what it is **not** allowed to do |
|---|---|---|
| **park** | Syscall interposition ([ADR-0020](../adr/0020-universal-park.md)): the `ignis` binary exports the blocking libc symbols a linked library calls (`read`, `poll`, `connect`, `nanosleep`, `getaddrinfo`, …) behind a thread-local "fiber active" gate. A call made *inside* a library — `curl_exec`, a `pdo_pgsql` query — is caught at the syscall boundary and parks the fiber instead of blocking the thread, with no PHP-level hook and no code change in the library. | **Buffer.** It never holds bytes — readiness, then the real call, one copy (the kernel's). It never changes an fd's flags except `connect`'s temporary `O_NONBLOCK`, restored before it returns. |
| **offload** | A second pool of PHP threads, each with its own TSRM context, running synchronous code. `Ignis\offload($fn, ...$args)` copies scalar/array arguments in, runs `$fn` on a worker, copies the result back; the calling fiber parks on one reactor op. Config-driven auto-routing does this transparently for `curl_*`, `PDO` (pgsql), `SQLite3` — the calling fiber gets a worker-pinned proxy object and never sees the pool. | **Share objects.** Scalars and arrays cross by copy; objects and resources never do. A proxy is a handle to a worker-resident object, not the object itself (`instanceof` and `var_dump` show the seam). |
| **context** | Fiber-switch observer slots ([ADR-0006](../adr/0006-fiber-scoped-superglobals.md)): a `zend_observer` hook fires on every fiber switch and swaps a fixed list of thread-global state — `$_SERVER`, `$_GET`, `$_POST`, `$_COOKIE` first, listed vendor statics after — so two interleaved requests never see each other's. | **Allocate per switch.** A slot swap is pointer moves, not allocation; anything that needs allocation happens at dispatch time, not on the hot observer path. |
| **the table** | One policy table, `symbol \| PHP function \| class → park \| offload \| block`, driven from `ignis.toml`. Seeded from source-level lock audits (research 27): libcurl `park`, libpq `park`, libssl/libcrypto `park`, libphp `block` until its own call sites are audited. | — |

## Which one a blocking call takes

- **A stream you already call through `php_stream`** — `file_get_contents('http://…')`,
  `fsockopen`, `ssl://`/`tls://` — parks via the stream hook path today (see
  [Compatibility](../compatibility.md)); as the consolidation in ADR-0037 completes, the same call
  moves onto the universal-park mechanism instead of a dedicated hook, with no PHP-visible change.
- **A C library that never touches `php_stream`** — `curl_exec`, a `pdo_pgsql` query, an OpenSSL
  handshake driven from `ext/openssl` — takes **park**, if its library is on the allow-list; the
  fiber suspends at the real syscall inside the library, with no PHP-level hook at all.
- **Disk I/O** — `SQLite3`, `PDO sqlite`, anything doing `read`/`pread`/`fsync` on a regular file —
  is never parked (epoll refuses regular files); it takes **offload**, or blocks the OS thread if
  offload is not configured. This is a stated non-goal, not a gap — see
  [What it is not](non-goals.md).
- **A library that holds a lock across a blocking call** — libphp's own opcache and TSRM paths
  today — is `block` by default: parking wrongly is a hang, delegating is always semantically
  correct. `park` is an allow-list populated only after a source-level lock audit says it is safe
  ([ADR-0020](../adr/0020-universal-park.md) has the audit and the hazard test: a shim library
  holding a mutex across a blocking `read` deadlocks under `park` and serializes cleanly under
  `block` — the hazard is measured, not assumed).
- **Superglobals and fiber-scoped services** are never a wait mechanism at all — they are
  **context**: state that must not leak between two interleaved requests on the same thread.

## Why three, not more

Before this consolidation the codebase had seven separate wait mechanisms — the stream transport
factory, the reactor's connection actor, `ext/sockets` hooks, the `sleep`/`usleep` swap, the
`accept` hook, offload, the context observer, and universal park itself — each with its own file,
its own `unsafe` surface, its own test suite. ADR-0037 measured what collapsing them costs and
saves: −1,436 Rust lines and −42 `unsafe {` blocks deleted so far, with every creating test still
green under the collapsed model and the binary down from 48.7 MB to 37.2 MB. Three mechanisms is
not a slogan; it is what the inventory in that ADR argues is the minimum that still covers every
blocking call the runtime has to answer for.
