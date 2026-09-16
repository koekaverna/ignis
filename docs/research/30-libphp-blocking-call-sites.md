# 30 — libphp's blocking call sites: a symbol-level audit (placeholder; ADR-0037 §5)

Status: **not done**. This document exists so the gate in ADR-0037 §6 step 2 has a name; nothing
below is a result.

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
