# 48 — Can our per-scope storage sit on upstream's `internal_context` (R-TA-CONTEXT)

2026-09-20, main agent. Read-only against a **named revision**: `true-async/php-src`, branch
`async-core`, commit `14af3cb2` ("async: coroutine engine core, the reference scheduler and the
coroutine context"), shallow-cloned for this reading. That branch is what
`scripts/build-php-async.sh` pins by default, so it is the revision backend (b) would be built from
today. Every claim below cites `Zend/zend_async_API.h` at that commit.

This item blocked `S-SCOPED-CLASS`. It was filed on 2026-09-19 and **should have been answered
before any of that item was built**; it was not, and the userland half went in first. That is
recorded in `DECISIONS.md`, not softened here.

## The finding that outranks the three questions

The two candidate branches **disagree about the very fields this design would use**. Comparing
`async-core@14af3cb2`, read here, against `PHP-8.6-true-async`, read over the network on
2026-09-19:

| | `async-core@14af3cb2` | `PHP-8.6-true-async` (2026-09-19) |
|---|---|---|
| coroutine's internal storage | `HashTable internal_context;` — **by value** (`:130`) | `HashTable *internal_context;` — a pointer |
| coroutine's user-facing context | `zend_object *context;` — the `Async\Context` object, lazy (`:131`) | `zend_async_context_t *context;` |
| teardown entry point | `zend_async_internal_context_destroy` (`:486`) | `zend_async_coroutine_internal_context_dispose` |
| `request_scope` / `ZEND_ASYNC_REQUEST_SCOPE` | **absent** | **absent** |

Four material differences, one of them a pointer-versus-value change in a struct field, which is an
ABI difference and not a naming one. And `request_scope` — which php-async's CHANGELOG #105
describes as a field on `zend_async_scope_t` with `ZEND_ASYNC_REQUEST_SCOPE` for O(1) access — is
on **neither** branch. It exists in a revision neither of these is.

**So backend (b) cannot be designed against "the fork".** It can only be designed against a named
revision, and the two revisions on the table contradict each other on exactly the surface this
research was asked about. Settling which revision is `R-TA-REQUEST-SCOPE`'s job and it is now a
hard prerequisite, not a tidy-up: an amendment to ADR-0003 that names no commit is worth nothing.

## Question 1 — can our per-scope storage sit on `internal_context`?

**Structurally yes; practically not yet, and it deletes nothing today.**

```c
struct _zend_coroutine_s {                                    /* :104 */
	/* Coroutine-local storage; the provider initialises and destroys both at
	 * the coroutine's birth and death. */
	HashTable internal_context; /* C extensions, numeric keys; PHP never sees it */   /* :130 */
	zend_object *context;       /* the Async\Context object, lazy */                  /* :131 */
};
```

The surface is `zend_async_internal_context_key_alloc(const char *key_name)` returning a `uint32_t`
(`:478`), then `_find` / `_set` / `_unset` keyed by that number (`:479-482`), with `_init` / `_destroy`
bracketing the coroutine's life (`:485-486`). The comment on the field states the intent in the
fork's own words: **C extensions, numeric keys, PHP never sees it.** That is precisely what
`Ignis\Scope`'s storage is, and the lifetime the doc block promises — initialised and destroyed by
the provider at the coroutine's birth and death — is the lifetime we hand-roll today.

Why it does not help yet, and this is the load-bearing half of the answer:

- It hangs off `zend_coroutine_t`. Backend (a) is stock PHP 8.5 fibers, where no such struct
  exists. So this is reachable only under backend (b), which **nothing on this box has ever built**
  (`A-BACKEND-B-CI`, closed 2026-09-20 with a scheduled job that has not yet had a green run).
- `Ignis\Scope`'s public API would not change, so no consuming code is deleted — not the façades,
  not the PHPStan rule, not `Scope::create()`. At best `Scope`'s internals gain a **second,
  backend-conditional** implementation. That is more total code, not less, until backend (a) is
  retired — which is research 44's conclusion, reached independently and confirmed here against a
  named commit rather than a fetched page.

**What this means for `S-SCOPED-CLASS`:** its engine half must be written against our own storage
regardless. Upstream's `internal_context` is a future substrate for that storage under backend (b),
not an alternative to building it. The blocker is therefore **answered, not satisfied** — the design
does not change, and the item can proceed on backend (a).

## Question 2 — does their `context` cover what our superglobal slots do?

**No. It is strictly a user-facing map, and it is not even the same kind of thing.**

`zend_object *context; /* the Async\Context object, lazy */` (`:131`) is a **PHP object** — the
userland `Async\Context` — created on demand. Its accessors are keyed by `zend_string *skey` or
`zend_object *okey` (`:521-525`), matching the `string|object` key type the stub documents, with
`zend_async_context_tables_init` / `_destroy` managing the tables (`:518-519`) and
`zend_async_context_entry_gc` participating in cycle collection (`:526`).

`superglobals.rs` does something categorically different: it swaps the `zval`s that `$_SERVER`,
`$_GET`, `$_POST` and `$_COOKIE` are bound to **in the symbol table**, so unmodified PHP code that
never heard of Ignis reads its own request's values. Upstream's `context` is a side-channel map an
application asks; it never touches the symbol table. Nothing in it replaces that mechanism, and
under backend (b) `superglobals.rs` would still be needed unchanged.

## Question 3 — what do their `switch_handlers` give that our observer does not?

**Per-coroutine subscription, an explicit direction, and removal.** Ours has none of the three.

```c
typedef bool (*zend_coroutine_switch_handler_fn)(zend_coroutine_t *coroutine, bool is_enter);  /* :81 */

typedef uint32_t (*zend_async_coroutine_add_switch_handler_t)(
		zend_coroutine_t *coroutine, zend_coroutine_switch_handler_fn handler);                /* :371 */
typedef bool (*zend_async_coroutine_remove_switch_handler_t)(
		zend_coroutine_t *coroutine, uint32_t handler_id);                                     /* :373 */
```

Three differences that matter to us:

1. **Registration is per coroutine.** A handler is added to *one* coroutine and returns a handle.
   Ours is `zend_observer_fiber_switch_register` (`superglobals.rs:261`, `park.rs:139`) — one
   process-wide callback that fires for every switch of every fiber and must work out for itself
   whose switch it is.
2. **`is_enter` is passed in.** Ours receives `from` and `to` contexts and derives the direction.
3. **Handlers can be removed** (`:373`, by the handle `add` returned). Ours cannot be unregistered
   at all.

What it does **not** give, contrary to the 2026-09-19 note's framing: there is no `in_execution`
re-entrancy flag on this revision. That field was read on `PHP-8.6-true-async` as part of
`zend_coroutine_switch_handlers_vector_t`; here the storage is described as living with the
scheduler (`:366-368`) and the core "only adds and removes". So the re-entrancy guard our two
handlers lack is **not** something this revision hands us either — it is still ours to build if
`S-OWNERSHIP`-shaped work ever puts real weight on the switch path. (`S-OWNERSHIP` was killed on
2026-09-20, so nothing needs it today.)

## Verdict

`R-TA-CONTEXT` is answered. All three questions have an answer with a citation into a named commit,
and none of them changes the design of `S-SCOPED-CLASS`: we build our own per-scope storage, keep
`superglobals.rs` as it is, and keep the process-wide observer. What changes is `ADR-0003`, which
now has something concrete to say about what backend (b) inherits, and `R-TA-REQUEST-SCOPE`, which
is promoted from a tidy-up to a prerequisite by the branch disagreement above.

**What this note does not do:** it reads one revision. It does not build the fork, run anything
against it, or verify that `internal_context` behaves as its comment says. The scheduled
`backend-b` CI job is what would turn "the header says" into "the binary does", and it has not had
a green run yet.
