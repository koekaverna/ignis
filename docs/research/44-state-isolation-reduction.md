# Research 44 — per-request state isolation: which of the five mechanisms survive deletion

Date: 2026-09-19. Question from the owner: not how to fix the open defects in per-request state
isolation — `S-DBAL-DIRECT`, `S-EXCLUSIVE`, `S-RESET-ARRAYPOOL`, `S-RESET-AUTOSCOPE`,
`S-REQUEST-FORMATS`, `S-SINGLETON-CAPTURE` are already filed — but **which of them disappear if the
path is removed instead of guarded**, and whether the two newly filed mechanisms (`S-OWNERSHIP`,
`S-SCOPED-CLASS`) are worth their code. Read-only: no code changed, no `git` write command run.

Sources read in full: `CLAUDE.md`, `BRIEF.md` (E13, E8), `docs/adr/0006-fiber-scoped-superglobals.md`
(including the 2026-09-17 addendum), `docs/adr/0037-three-mechanisms.md`,
`docs/research/36-symfony-state-under-fibers.md`, `37-token-storage-under-fibers.md` (referenced,
superseded by 36/38's findings), `38-doctrine-fiber-scope.md`, `39-exclusive-resources-under-fibers.md`,
every `BACKLOG.md` entry named in the brief plus `R-TA-REQUEST-SCOPE`/`R-TA-SERVER`/`S-SCOPE-TERMINOLOGY`
for context, `VALIDATION.md` V-85 (+ addenda), V-88, V-95 (+ addendum), V-96 (+ addendum), and every
production file the five mechanisms are built from (read in full, not sampled). Upstream:
`Zend/zend_async_API.h` fetched from `true-async/php-src` branch `PHP-8.6-true-async`
(`raw.githubusercontent.com`, 1802 lines) — the specific structures `R-TA-CONTEXT` names, read to the
depth the deletion question needs and no further; `R-TA-CONTEXT`'s own brief (locating the userland
stub, the `Scope` class, `current_context`/`root_context`/`request_context`) was **not** started, per
instruction.

## 1. The inventory, measured (`wc -l`)

| # | mechanism | file(s) | lines |
|---|---|---|---|
| 1 | `Ignis\Scope` (the storage) | `php/packages/runtime/src/Scope.php` | 60 |
| 2 | fiber-scoped façades | `FiberRequestStack.php` 89 + `FiberTokenStorage.php` 53 + `FiberEntityManager.php` 247 + `FiberManager.php` 58 | **447** |
| 2w | — their compiler-pass wiring | `symfony-runtime/.../FiberScopePass.php` 93 + `doctrine/.../DoctrineFiberScopePass.php` 109 | 202 |
| 2b | — supporting bundle/runtime glue | `IgnisBundle.php` 28 + `IgnisRuntime.php` 21 + `IgnisWorkerRunner.php` 112 + `IgnisDoctrineBundle.php` 65 + `IgnisDoctrineExtension.php` 33 + `DoctrinePoolPass.php` 83 | 342 |
| 3 | fiber-switch superglobal slots | `crates/ignis/src/php/superglobals.rs` | 301 |
| 4 | services-resetter no-op | `FiberServicesResetter.php` (the disabling logic itself is 27 lines inside 2w's `FiberScopePass.php`, not separate) | 61 |
| 5 | PHPStan rule | `RequestScopedValueInPropertyRule.php` 170 + `extension.neon` 40 | 210 |
| — | connection pool (S-EXCLUSIVE's territory, not isolation itself) | `ConnectionPool.php` 186 + `PooledConnection.php` 42 + `PoolingMiddleware.php` 109 + `PoolingDriver.php` 33 + `PoolTimeoutException.php` 14 | 384 |

Narrow total (rows 1, 2, 3, 4, 5 — the five mechanisms as named in the brief, excluding wiring/glue
and the pool): **1,079 lines**. Including the compiler-pass wiring and bundle glue that dispatches to
them: **1,623 lines**. None of this is Rust/`unsafe` except superglobals.rs's 301 (15 `unsafe fn` /
`unsafe {}` blocks, per ADR-0037 §1's own count for this mechanism).

## 2. None of the five is redundant today

Each does a job the other four cannot:

1. **`Scope`** is the storage primitive — a `WeakMap<Fiber, array>` plus a `{main}` fallback. It is
   the userland half of the `context` mechanism (ADR-0006). Nothing else in the five stores anything;
   they all call into this one.
2. **Façades** are the *safe access pattern* built on 1 — a shared container singleton whose every
   method reads `Scope` instead of `$this`. This is what makes "one request stack per fiber" reachable
   from code that only ever sees the container's one instance (V-16, V-68, V-85, V-96: 0 leaks across
   every measurement that exercises it).
3. **`superglobals.rs`** solves a problem 1 and 2 cannot: `$_SERVER`, `$_GET`, `$_POST`, `$_COOKIE`
   are read by **unmodified code** through PHP's own variable syntax, not through a method call on an
   injected service — there is nothing to make into a façade. It swaps the zend symbol table itself at
   the fiber-switch observer (ADR-0006). Confirmed against upstream in §4: this is not a problem
   upstream's context API addresses either.
4. **The resetter no-op** exists because Symfony's own built-in mechanism (`services_resetter`,
   driven by `Kernel::boot()`'s `requestStackSize == 0` guard) is not merely unneeded under fibers, it
   is **actively wrong**: research 36 and V-95 both show it firing on top of a request that is still
   streaming or terminating. This is not "isolation" in the same sense as 1–3; it is turning off a
   *different* framework's now-false assumption (that only one request is ever in flight on a thread).
   Nothing else in the list does that job, because nothing else has an opinion on Symfony's own
   lifecycle hooks.
5. **The PHPStan rule** is the one static backstop, and it catches a shape none of 1–4 can catch at
   runtime for free: a *value* pulled out of a façade in a constructor and stored in a property —
   `$this->request = $stack->getCurrentRequest()`. V-96 shows this is a real, silent defect (the
   façade itself stays correct 6/6; the captured value is wrong 6/6) that no compiler pass can see
   structurally (rejecting the *injection* would fail every controller in Symfony, V-96 §"three
   proposed fixes", point 1) and no scope/façade mechanism prevents (the value has already left
   `Scope` by the time it is captured).

**Answer to "which of the five is redundant today": none.** They sit at five different points —
storage, access pattern, raw engine state, a foreign framework's lifecycle assumption, and static
capture-detection — and each was built because a measurement (V-11, V-16, V-68, V-85, V-88, V-95,
V-96) showed the point above it in this list did not cover it.

One real (small) overlap, not a mechanism: `FiberScopePass` (symfony-runtime) and
`DoctrineFiberScopePass` (doctrine) are two files doing the same *kind* of thing — moving a
container id to a fiber-scoped replacement — split by package. That split is not redundancy; it is
CLAUDE.md's own rule ("one package per integration since V-62") and merging them would create a
dependency from `ignis/symfony-runtime` on `ignis/doctrine` (or vice versa) where today neither
depends on the other. Not a deletion candidate.

## 3. `S-SCOPED-CLASS` — does it delete the façades

The item's own acceptance test is specific: prototype on a standalone class, then **rewrite
`FiberRequestStack` on it** — "if that class disappears the mechanism is right." Measuring that
against the actual code:

- **`FiberRequestStack` (89 lines) is the worst candidate to prove the claim on.** It `extends
  Symfony\Component\HttpFoundation\RequestStack` (`FiberRequestStack.php:21`), and the item's own open
  questions list, unresolved: *"inheritance — a scoped class's parent must be scoped too"*
  (`BACKLOG.md`, `S-SCOPED-CLASS`). `RequestStack` is vendor code; it was never declared
  `#[FiberScoped]` and cannot be retroactively attributed. Until that open question is answered, it is
  not established that the mechanism can apply to `FiberRequestStack` **at all** — so the class named
  in the acceptance test may not be a legal target for it. This is not a nitpick: it is the specific
  gap the item files as unresolved, and it sits directly on the one example the item chose to prove
  itself against.
- **`FiberEntityManager` (247 lines) is not even claimed.** The acceptance test names
  `FiberRequestStack` only. And structurally, `FiberEntityManager`'s per-fiber value is not a plain
  property — it is lazily built through a `ContainerInterface` locator with `isOpen()`/reset logic
  (`FiberEntityManager.php:44-56`), and its 35 forwarding methods exist because it implements
  `EntityManagerInterface`'s 35-method surface (research 38 §3), not because it duplicates a property
  access. Moving *properties* into per-scope storage does not remove a 35-method interface
  implementation; the forwarders stay regardless.
- **`FiberTokenStorage` (53 lines) is the one real candidate**, and the item does not name it. It has
  no vendor parent (`implements TokenStorageInterface, ResetInterface`, no `extends`,
  `FiberTokenStorage.php:30`) and its state is a single scalar-ish value
  (`Scope::get($this->key)`/`Scope::set($this->key, $token)`, `:36-51`). If `#[FiberScoped]` could
  legally apply here, the two-line `getToken()`/`setToken()` bodies collapse to plain property
  reads/writes and the class shrinks by perhaps 15–20 lines — the interface methods themselves do not
  go away.

**Best case, measured against the actual files: ≤53 of the 447 façade lines (12%) are a plausible
target, and even that shrinks by roughly a third of its own size, not to zero.** The 89- and 247-line
files are not established candidates — one is blocked by an unresolved open question, the other is
outside the item's own claim. Against that: six new object handlers
(`create_object`, `read_property`, `write_property`, `has_property`, `unset_property`,
`get_property_ptr_ptr`, `get_properties`) with class-link-time slot resolution is new `unsafe` engine
code in the one place CLAUDE.md restricts to the main agent
(`.claude/hooks/guard-ffi.sh` blocks subagents from `crates/ignis/src/php/**` and any file containing
`unsafe`). For scale: ADR-0037 §1 measured the *existing* context mechanism (four fixed symbol-table
slots, one job) at 269–301 lines / 15 `unsafe {}`. A general per-class, per-property, arbitrary-slot
mechanism is a materially bigger and more exposed surface than that, for a deletion measured here at
0–53 lines.

**Verdict: not worth it as filed.** The item's own acceptance plan (prototype first, measure the read
cost, see if the class disappears) is the right gate and should stand — but the honest prediction,
from the code as it exists today, is that it fails that gate: the class it names to prove itself on is
blocked by its own open question, and the class it would actually help is not the one it names.

## 4. `S-OWNERSHIP` — does it close the three items it claims, cheaper than what already exists

The item claims it closes `S-DBAL-DIRECT`, `S-EXCLUSIVE` and `S-RESET-ARRAYPOOL` "with no code of
their own," via taint bits in every object's GC bits, transitive through property and array writes,
with three release verbs (`Scope::escape`/`share`/`pin`). It states this is "the `context` mechanism
(ADR-0006), not a fourth one."

**That claim does not hold against ADR-0037's own definition of `context`.** §2's table states what
`context` is not allowed to do: *"allocate per switch: a slot swap is pointer moves; anything needing
allocation happens at dispatch, not on the observer."* A taint check firing on **every property write
and every array-element write, for every object, system-wide** — not only at a fiber switch — is a
different shape of interception than a slot read at a switch point. The item's own text concedes the
scale of it: *"A taint check on every property write is the first thing that puts real work on that
path... cheaper to design in than to retrofit after a handler recurses"* — describing a new
write-barrier-style hook, not a switch-time slot swap. Whether that counts as within-budget or as the
fourth mechanism CLAUDE.md forbids proposing is a real question this research raises rather than
settles; either way, it is **not** the same shape as the context mechanism as ADR-0037 defines it
today, and the item should not claim it is one for free.

**Each of the three named items already has a cheaper answer on paper, unbuilt but specified:**

| item | what `S-OWNERSHIP` would catch | what already closes it, per the backlog's own research | relative cost |
|---|---|---|---|
| `S-EXCLUSIVE` | a resource used from a foreign fiber | research 39's own top recommendation: **O1** (a `Fiber::getCurrent()` affinity check in `PooledConnection`, ~5 lines, "the largest return per line in the whole list") + **O4** (a one-lease-per-fiber rule in `Scope`) | ~10–20 lines, no engine change, no new mechanism |
| `S-DBAL-DIRECT` | a shared service holding a raw `Connection` for the thread's life | the item's own text: *"the same shape the manager got — a shared `FiberConnection` published under the connection id"* — i.e. one more row of the **façade mechanism already built and proven** (§2, row 2), not a new one | a few hundred lines of plain PHP decorator, same shape as `FiberEntityManager`, zero new `unsafe` |
| `S-RESET-ARRAYPOOL` | a growing `ArrayAdapter`-backed pool | a boot-time check ("refuse to start with an array-backed pool in fiber mode") or a documented limitation — the backlog item's own acceptance text names exactly this as one of the outcomes the measurement might support | 0–10 lines, no runtime mechanism at all |

Every one of the three is closer to "one more row in a table, or a five-line guard, or a boot check"
than to "taint bits on every value in the process." Research 39 explicitly ranked and rejected the
larger options in this space already — O5 (its own "held across a suspension" detector, the nearest
thing to `S-OWNERSHIP`'s runtime half) is gated behind E2's 3.83 µs warm-switch budget and ranked
**fourth** of five, behind the cheap wrapper check, the mutex/semaphore extraction, and a PHPStan
rule. `S-OWNERSHIP` reopens ground research 39 already surveyed and ranked, without citing why the
cheaper options it found are insufficient.

**Verdict: not worth it as filed.** Extend the PHPStan rule that already shipped for
`S-SINGLETON-CAPTURE` (V-96 addendum, 170 lines, one AST-level check) with a second, narrow rule for
the `S-DBAL-DIRECT` shape (a non-façade, scoped-marked type injected into a shared service's
constructor and kept in a property — the shape V-96's rule *deliberately* does not flag today because
the façade shape is correct), do O1+O4 for `S-EXCLUSIVE`, and settle `S-RESET-ARRAYPOOL` with a boot
check. All three fit inside the three existing mechanisms and the fifth item (the PHPStan rule),
extended by rows, not rebuilt.

## 5. `R-TA-CONTEXT` — bearing on deletion only

Read `Zend/zend_async_API.h` at `true-async/php-src@PHP-8.6-true-async` directly (not the fork's
higher-level stubs, and not the userland `Scope`/`current_context` surface — that is `R-TA-CONTEXT`'s
own unstarted brief). Confirms the backlog's verbatim quotes and adds the detail needed here: the
coroutine struct (`zend_async_API.h:1037-1050`) carries **two separate** fields —

```c
zend_async_context_t *context;      /* user-facing, string|object keys, Scope-chain lookup */
HashTable *internal_context;        /* "Internal context (for C extensions with numeric keys)" */
```

with `internal_context` backed by `zend_async_internal_context_key_alloc`/`_find`/`_set`/`_unset`
(`:1439-1450`) — a per-coroutine, engine-owned, numeric-keyed key→value store, disposed automatically
at coroutine teardown (`zend_async_coroutine_internal_context_dispose`). The comment at line 1040
states its intended use in upstream's own words: for C extensions, not for userland PHP code.

**Three questions, answered only as far as they bear on deletion:**

1. **Could our storage sit on `internal_context`?** Structurally, yes — it is the same shape as
   `Ignis\Scope::set`/`get`/`clear`: allocate a key once (`key_alloc`), read/write per coroutine
   (`_find`/`_set`), free automatically on teardown. **But this is backend (b) only** — nothing on
   this box has ever built backend (b) (BACKLOG's own note, confirmed unchanged), and `Scope.php`'s
   public API (`set`/`get`/`clear`) would stay identical either way: only its *internal*
   implementation would change, from a `WeakMap` to an FFI binding. **No consuming code deletes** —
   the façades, the PHPStan rule and every call site go through `Scope`'s three static methods, not
   its internals. The honest answer to "does our own storage get deleted": **no.** It gets a second,
   backend-conditional implementation (`cfg(php_async_abi)`) the day backend (b) is real, which is
   **more** total code across both backends, not less, until backend (a) is retired — which is not on
   the table.
2. **Does their `context` (not `internal_context`) cover our superglobal slots?** No. `context` is
   reached through an explicit API (`Async\request_context()` and friends) with string/object keys —
   it is a side-channel map, not the PHP symbol table. `$_SERVER`/`$_GET`/`$_POST`/`$_COOKIE` are read
   by unmodified code through ordinary PHP variable syntax; nothing in either `context` or
   `internal_context` touches `EG(symbol_table)`. **`superglobals.rs` is unaffected by this question
   either way** — it solves a problem neither upstream field addresses.
3. **What do `switch_handlers` give beyond `zend_observer_fiber_switch_register`?** Upstream attaches
   a per-coroutine *vector* of handler function pointers (`zend_coroutine_switch_handlers_vector_t`,
   `:320-325`) rather than one process-wide registration inspecting per-context state, which is what
   ours does (`superglobals.rs:180-215`, `SLOT`/`VIEW` thread-locals). Different shape, not a missing
   capability — nothing here suggests our observer is doing something upstream's cannot, or vice
   versa. This bears on an eventual ADR-0003 amendment, not on any deletion today.

**Net effect on this slice's deletion question: none of the five mechanisms shrinks because of
`R-TA-CONTEXT`.** `Scope`'s *storage* is a plausible reimplementation target for backend (b), which
is a future addition (a second code path), not a subtraction. `superglobals.rs` is unaffected.
Façades, the resetter and the PHPStan rule sit above `Scope`'s API and never see its internals change.

## 6. `S-RESET-AUTOSCOPE` — does it shrink to two named services and a note

Yes, and the backlog's own inventory (`BACKLOG.md`, `S-RESET-AUTOSCOPE`, table of 15 tagged services)
already does the arithmetic: 9 must stay shared (they are caches whose `reset()` is a `clear()` that
would defeat the cache), 1 has an empty `reset()` body, 2 are already handled by the façade mechanism
(§2, row 2 — `security.untracked_token_storage` via `FiberTokenStorage`, `doctrine` via
`FiberEntityManager`), 1 is a test double. That leaves exactly **two** real candidates —
`security.logout_url_generator` and `doctrine.debug_data_holder` — each needing its own probe and its
own fix (the inventory table gives the reason per row). **The generic "a `ResetInterface` tag
auto-registers fiber scope" idea is exactly what the item's own inventory argues against**: applying
it blanket would put a proxy in front of the 9 that must stay shared, in order to reach the 2 that
should not be. The right shape is what the item already says it is: two named services and a note —
not a new tag-driven auto-scoping framework. No new mechanism; each of the two is one more row of
either the façade pattern (§2, row 2) or a documented exception, decided per-service by reading its
`reset()`, the same way the inventory itself was built.

## 7. What does not simplify — said plainly

`S-REQUEST-FORMATS` has no code-shaped answer today and should not be given one to look finished.
`Request::$formats` is `protected static`, thread-wide by construction
(`http-foundation/Request.php:235`), and cannot be moved into `Scope` without rewriting
`Symfony\Component\HttpFoundation\Request` itself — out of scope for an adapter package by design.
The two paths (leave the reset inherited, or neutralise it) are both zero-code decisions gated on one
measurement that has not been run (an E21 arm: one request registers a custom format, a second
triggers the reset, does the first still see its own). Until that measurement exists, the honest
status is "open, mitigated, no code to write" — not "closed" and not "needs a mechanism."

## 8. Ranked table

| verdict | candidate | backlog ids closed/affected | measured line delta | kill criterion |
|---|---|---|---|---|
| **keep** | `Ignis\Scope` | none (foundation for all) | 0 (no change) | none proposed here — would be revisited only if `R-TA-CONTEXT`'s backend-(b) reimplementation ships and a second backend-conditional path is judged not worth carrying |
| **keep** | façades as written (`FiberRequestStack`, `FiberTokenStorage`, `FiberEntityManager`) | none directly; underlies `S-SINGLETON-CAPTURE`'s correct shape (V-96) | 0 (no change) | a working `S-SCOPED-CLASS` prototype that shrinks `FiberRequestStack` net of its own new engine code — §3 predicts this fails |
| **keep** | `superglobals.rs` | none — unaffected by every proposal in this note | 0 (no change) | none; confirmed distinct problem in §5 q2 |
| **keep, extend by 2 rows** | resetter no-op + `FiberScopePass`/`DoctrineFiberScopePass` | `S-RESET-AUTOSCOPE` | +est. 10–40 lines (two named services, §6) | a probe for either of the two that cannot be made to fail — then it is not scoped, and the inventory already names that outcome as acceptable |
| **merge into façade mechanism (§2, row 2)** | `FiberConnection` (new) | `S-DBAL-DIRECT` | +est. 150–300 lines (comparable order to `FiberEntityManager`'s 247, ~40-method surface per research 38) | an E24 arm with a constructor-injected `Connection` failing today and passing after; if the decorator surface turns out unable to cover DBAL's private driver state, the fallback is the existing build-time warning list (research 38 §4 step 5), already specified, not a new mechanism |
| **merge into wrapper (research 39 O1+O4)** | fiber-affinity check + one-lease rule | `S-EXCLUSIVE` (and detects `S-SINGLETON-CAPTURE`'s capture shape at the point of use) | +est. 10–20 lines in `PooledConnection.php`/`Scope.php` | the owner's chaos-mode two-interleaved-fiber test (already specified in the item) not catching a foreign-fiber use after the guard lands |
| **merge into boot check** | array-pool guard | `S-RESET-ARRAYPOOL` | +est. 0–10 lines | none needed — this is a documentation/refusal decision, not a mechanism |
| **no code — measure and document** | request-formats trade-off | `S-REQUEST-FORMATS` | 0 | the missing E21 arm (§7) run and read; either outcome is zero further code |
| **delete from the roadmap as filed** | `S-OWNERSHIP` (taint bits, three release verbs) | claims `S-DBAL-DIRECT`, `S-EXCLUSIVE`, `S-RESET-ARRAYPOOL` — all three already closed above at a fraction of the cost | 0 built; avoids an estimated several-hundred-line write-barrier-shaped addition across every object write in the engine | superseded the moment any one of the three rows above is measured working — at that point this item has nothing left to close |
| **delete from the roadmap as filed** | `S-SCOPED-CLASS` (`#[FiberScoped]`, six object handlers) | claims to close "the façade half of V-96" — already closed by the façade pattern itself (V-96: 0/6 leaks) | 0 built; avoids an estimated 300+ line, `unsafe`-heavy addition (§3) for a ≤53-line, unresolved-precondition deletion | a working prototype rewriting `FiberTokenStorage` (not `FiberRequestStack` — §3) that nets fewer lines than the handler code it required, with the parent-inheritance question answered first |

**Total measured line delta from this note's recommendations, stated separately from estimates per
ADR-0037's own convention:** the two **delete** rows commit **0 lines** (nothing was ever built) and
avoid a combined **estimated 600–1,000+ lines of new `unsafe` FFI/engine code** that this note found
no evidence would net-positive against the ≤53+small number of lines they could actually remove. The
**merge** rows add an **estimated 170–370 lines** total, entirely in userland PHP, entirely extending
mechanisms that already exist and are already measured working (façade pattern, wrapper guard,
PHPStan rule) — no Rust, no new `unsafe`, no fourth mechanism. Nothing in the existing five is deleted
outright; §2 found no redundancy among them to remove.

## 9. Verdict

`S-OWNERSHIP` and `S-SCOPED-CLASS` are not worth their code as filed. Both would add real machinery —
a process-wide write-barrier-shaped taint check and six new class-level object handlers — in the one
place (`crates/ignis/src/php/**`, anything `unsafe`) CLAUDE.md restricts to the main agent, against
deletions this note could not establish beyond "possibly 53 lines of one already-small façade,
contingent on an open question the item itself has not answered." Every red item either mechanism
claims to close already has a cheaper, specified, in-budget answer: research 39's own top-ranked
option (O1+O4, ~10–20 lines) for `S-EXCLUSIVE`, one more row of the existing façade pattern for
`S-DBAL-DIRECT`, and a boot-time check for `S-RESET-ARRAYPOOL`. The smaller shape does the job.

The five mechanisms this slice already runs on are not redundant with each other — §2 shows each
covers ground the other four do not — so there is no "collapse into fewer" available today either.
The one genuine shrink available is `S-RESET-AUTOSCOPE`'s: a speculative tag-driven auto-scoping
framework was never built, and the inventory already on file (`BACKLOG.md`) shows it should not be —
two named services and a note is the whole of it, which is smaller than what was proposed, not merely
cheaper.
