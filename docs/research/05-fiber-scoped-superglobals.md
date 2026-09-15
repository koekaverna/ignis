# Research 05 — Fiber-scoped superglobals via the fiber-switch observer

Date: 2026-09-16 (Cycle 5). Sources: `main/php_variables.c:799-842,876-905,975-979`,
`Zend/zend_observer.c` (`zend_observer_fiber_switch_notify`), `Zend/zend_fibers.c:460,467,496`,
`Zend/zend_compile.h:998-1008`.

## Facts

- `$_GET`, `$_POST`, `$_COOKIE`, `$_SERVER` are registered as auto-globals
  (`zend_register_auto_global`, `_SERVER` with JIT when `auto_globals_jit=1`).
  Their creation callback builds the array in `PG(http_globals)[TRACK_VARS_*]`
  and then does `zend_hash_update(&EG(symbol_table), name, &zv)` + addref
  (`php_variables.c:808,827,842,903`). After that, userland reads and writes go
  through the `EG(symbol_table)` entry; the compiler triggers the JIT callback
  once per script via `zend_is_auto_global`.
- Both `EG(symbol_table)` and `PG(http_globals)` are per thread (ZTS). A fiber
  switch does not touch them: two interleaved requests on one thread see the
  same `$_SERVER`. This is the root of ADR-0002's value-only boundary.
- `zend_fiber_switch_context()` calls `zend_observer_fiber_switch_notify(from, to)`
  before switching stacks (zend_fibers.c:496); handlers get both
  `zend_fiber_context*`s and run on the current thread with full EG access.
  `zend_observer_fiber_destroy_notify(context)` fires from
  `zend_fiber_destroy_context` (:467). Registration (`*_register`) appends to a
  llist initialised by `zend_observer_startup()` in `php_module_startup`
  (main.c:2229), so a module's MINIT is the right place.
- `zend_hash_update` takes ownership of one reference of the zval it stores
  and releases the previous entry; `zval_add_ref` / `zval_ptr_dtor` are
  ZEND_API and handle the refcounted/immutable distinction (the empty array
  is immutable).
- Copy-on-write: sharing one HashTable between two fibers by refcount is safe;
  a write through `$_GET[...] = ` separates because the VM's FETCH_W path
  calls `SEPARATE_ARRAY` when refcount > 1.

## Design (minimal)

- Per thread, a map `zend_fiber_context* → [zval; 4]` (saved `_SERVER`, `_GET`,
  `_POST`, `_COOKIE`).
- On switch(from, to): save the four current symbol-table entries under
  `from` (addref); if `to` has saved entries, install them (`zend_hash_update`
  with an addref'd copy); if `to` has none (first entry into a fresh fiber),
  leave the current entries in place = **inherit from the resumer**, sharing by
  refcount.
- On destroy(context): release saved entries.
- `ignis_set_superglobals(array $server, array $get, array $post, array $cookie)`
  writes the four entries from inside the running fiber; the next switch-out
  saves them under that fiber. The loop calls it at request dispatch, so a
  pooled fiber's previous request state is overwritten before the handler runs.
- Not synced: `PG(http_globals)` (used by `php_get_server_var`,
  `filter_input`, phar). Recorded as a limitation for this cycle.

## Cost model

4 × `zend_hash_find` + 4 × `zend_hash_update` + refcount ops per switch
≈ 8 hash operations on a small table ≈ 200–500 ns. Target < 1 µs. Measured as
the delta in V-4's warm per-job figure (two switches per job) and by an
in-process loop of 1M `Fiber::suspend/resume` pairs with and without the hook.
