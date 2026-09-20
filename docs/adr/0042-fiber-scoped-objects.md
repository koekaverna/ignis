# ADR-0042 — Fiber-scoped objects: declared properties live in per-scope storage, bound per object at creation

Status: **accepted** (2026-09-20). The acceptance this ADR set itself is met and measured.
Step 1, a prototype on a standalone class with two interleaved fibers: passes, gated in
`scripts/smoke.sh`. Step 2, `FiberRequestStack` rewritten on the mechanism — "if that class
disappears the mechanism is right": it disappeared, V-100, 89 lines and its test deleted, with
Symfony's own `RequestStack` marked scoped measuring identically (0/3 leaks either way). Step 3,
the kill criterion, read cost against a plain property: **1.0–1.1×**, V-97, flat from 1 to 256 live
scoped services. And V-99 proves it on a real Symfony kernel under overlapping requests, where an
unmarked control leaks 3 of 3.

Two things this status does **not** claim. The per-switch cost is **not measured** — V-98 records
that it sits below the floor of every instrument on this box and was accepted as negligible by
owner decision, not by measurement, and closing it properly needs an E2-shaped arm. And four of the
eight tests this ADR names are still unwritten: inheritance in both directions,
`get_property_ptr_ptr` for `$this->arr[] =` and `$this->n++`, `clone`/`serialize`/reflection, and
the `IGNIS_CHAOS` arm. `lazy` + `scoped` remains an open question, not a tested one.

## Context

Four façades exist today because Symfony singletons cannot otherwise be made request-safe under
fiber pooling: `FiberRequestStack` (89 lines), `FiberTokenStorage` (53), `FiberEntityManager` (247),
`FiberManager` (58) — 447 lines total, each hand-writing "every method reads `Ignis\Scope`" against
its parent's method list (V-16, V-68, V-69). `Ignis\Scope` itself (`php/packages/runtime/src/Scope.php`)
is a `WeakMap<Fiber, array<string, mixed>>` with a `{main}` fallback bag for code with no active
fiber, cleared once per request by `Loop::admitRequest`'s counterpart (`Scope::clear()`,
`Scope.php:49-59`) — this is the storage ADR-0006's addendum already specified and V-11 measured.

The question this ADR answers is not whether that storage works — it does, repeatedly, at V-16,
V-68, V-69, V-95, V-96 — but whether an *object's own declared properties* can be redirected into it
so that a singleton-shaped service resolves per request without a hand-written façade for every
method. `S-OWNERSHIP`, the sibling proposal that taints every value a scoped service hands out, was
killed as filed 2026-09-20 for being a fourth mechanism (it inspects every property and array write
in the program, not a slot swap on a switch); `S-SCOPED-CLASS` was kept because, unlike ownership
tracking, it does not touch every write in the program — only the declared properties of classes
explicitly marked, and only through the two VM handlers that already exist for property access.

Three defects motivate it directly. V-85 confirmed Symfony hands one PostgreSQL socket to every
fiber on a thread unless the connection is rebuilt per fiber (the fix that shipped is a façade,
`FiberEntityManager`, doing the rebuilding). V-95 confirmed `services_resetter` cannot be trusted to
run between requests and disabled it, which means a plain Symfony singleton — one the framework
itself, not this project, decided was shared — now keeps whatever it captured for the life of the
thread. V-96 confirmed the two halves of that risk split apart under measurement: a singleton
**holding a fiber-scoped service** resolves correctly (0 leaks in 6 requests, because the façade
does the resolving), while one **holding a value taken out of one** is pinned to the first request
(6 of 6 wrong). This ADR is aimed at the first half — turning "hand-write a façade" into "declare
the class scoped" — and is explicit throughout about not touching the second.

## The engine basis, read in `~/php-src` at `php-8.5.10` (`34308a6`)

Two VM handlers touch a declared property with a class constant name, and both carry the same
two-guard shape. `ZEND_ASSIGN_OBJ`'s fast path (`Zend/zend_vm_def.h:2488-2514`):

```
2491   if (EXPECTED(zobj->ce == CACHED_PTR(opline->extended_value))) {
...
2497       if (EXPECTED(IS_VALID_PROPERTY_OFFSET(prop_offset))) {
...
2501           property_val = OBJ_PROP(zobj, prop_offset);
2502           if (Z_TYPE_P(property_val) != IS_UNDEF) {
                   ... fast write, never calls write_property ...
               }
           }
       }
   ...
2598   value = zobj->handlers->write_property(zobj, name, value, ...);
```

Guard one, line 2491, is keyed by **class**: the opcode's cache slot remembers a `zend_class_entry*`
and a property offset, and the check is "does this object's class match the class the cache was
warmed for". Guard two, line 2502, is keyed by **object**: it reads the actual zval sitting in this
object's declared slot. Only when both hold does the handler write `OBJ_PROP` directly and skip
`write_property` entirely (the `fast_assign_obj` label, line 2507). When guard two fails — the slot
is `IS_UNDEF` — execution falls all the way through the `if`/`else if` chain to line 2598, which
calls `zobj->handlers->write_property`, the per-object handler pointer.

`ZEND_FETCH_OBJ_R`'s hot path mirrors this exactly (`Zend/zend_vm_def.h:2069-2236`):

```
2105   if (EXPECTED(zobj->ce == CACHED_PTR_EX(cache_slot))) {
2106       uintptr_t prop_offset = (uintptr_t)CACHED_PTR_EX(cache_slot + 1);
2108       if (EXPECTED(IS_VALID_PROPERTY_OFFSET(prop_offset))) {
2110           retval = OBJ_PROP(zobj, prop_offset);
2111           if (EXPECTED(Z_TYPE_INFO_P(retval) != IS_UNDEF)) {
                   ... fast copy out, never calls read_property ...
               }
           }
       }
   ...
2211   retval = zobj->handlers->read_property(zobj, name, BP_VAR_R, cache_slot, EX_VAR(opline->result.var));
```

Same two guards, same fallthrough to the per-object handler (line 2211) when the slot reads
`IS_UNDEF`.

**This is the whole mechanism, and it is why the design works at all.** Guard one is per class and
would, on its own, make a warm opcode cache dangerous: once one instance of a class has been
written through with the cache warm, every later instance of the *same class* hits the same cache
slot. But guard two is per **object**, reads live state on that specific `zend_object`, and is
evaluated every time, cache hit or not. A scoped object whose declared slot is kept permanently
`IS_UNDEF` therefore fails guard two on every access, unconditionally, regardless of how warm guard
one's cache is — which is exactly what lets a plain instance and a scoped instance of the same class
coexist: the class-level cache resolves the same offset for both, and the object-level guard is what
decides, per instance, whether that offset is trusted or whether the handler runs. Binding is
consequently per object at creation (whichever handler table `obj->handlers` was given when the
object was made), never per class as an intrinsic property enforced at every call site — the
`ce`-keyed cache does not care which handler table an object carries, only guard two does, and guard
two is decided once, when the object is created, and never revisited.

**The invariant, stated as an invariant.** *A scoped object's declared slots must be `IS_UNDEF`,
always, for the life of the object.* The moment one is populated — by any path that writes
`OBJ_PROP` directly rather than through `write_property`, which by construction none of our own code
does, but a future engine change or an incautious internal function might — guard two starts
passing, the fast path takes over, and `write_property`/`read_property` are silently never called
again **for that instance**, and after the first such instance warms the class-level cache slot,
every other instance of that class sharing the same call site inherits nothing wrong (guard two is
still per-object) but the *symptom* — property reads answering the object's own value instead of the
scope's — is indistinguishable from ordinary PHP unless a test specifically re-checks the invariant.
This is a silent correctness failure: no exception, no warning, a property that quietly stops being
scoped.

**This answers, precisely, the kill criterion DECISIONS.md set for the class-level-call control
level** (2026-09-20): "if the cache is guarded only by `ce` and not by the handler table, the
class-level call has to happen before any code touches the class". The cache is guarded only by
`ce` — confirmed, lines 2491 and 2105 above name no handler-table check anywhere in the fast path.
Read literally, that clause says the decision is wrong. It is not, because the clause did not
anticipate guard two: the fast path additionally requires the object's own slot to be populated, and
a scoped object's slot is never populated, by the invariant above, independent of whether the
class-level cache is warm, cold, or was warmed by a plain instance created before `scopeClass()` was
ever called. A class scoped after code has already run against it is therefore safe **for every
instance created after the call** — which is the backlog's test 4, and it is now answered by
citation rather than by the test alone. The **class-level call still stands**, but for a different
reason than DECISIONS.md gave it: not because the cache cannot see past the class, but because the
object-level guard makes the class-level cache irrelevant to correctness. This is flagged in the
report for this ADR as a finding: DECISIONS.md's own kill criterion asked a narrower question than
the mechanism actually turned on.

**Property hooks share the same switch, and only partly the same guard.** `IS_HOOKED_PROPERTY_OFFSET`
(`Zend/zend_vm_def.h:2120`, mirrored at `:2572`) is a third state the same cache slot can hold. A
"simple" hook — one that only reads or writes the backing slot — is rewritten to `fetch_obj_r_simple`
/ `assign_obj_simple` (`:2122-2124`, `:2573-2579`) and so **does** pass through guard two, same as an
unhooked property. A hook with an actual body is dispatched as a real function call
(`:2125-2157`) and never reaches `OBJ_PROP`, `write_property`, or `read_property` at all — a hooked
property with a body on a scoped class would silently bypass scoping entirely, in either direction.
Listed under Open questions; this ADR takes no position on hooked scoped properties.

**Lazy objects are the engine's only existing user of a permanently-`IS_UNDEF` object, and they are
distinguished by a different flag, not by UNDEF-ness.** `zend_object_make_lazy`
(`Zend/zend_lazy_objects.c:269-283`) sets every declared slot to `IS_UNDEF` with `ZVAL_UNDEF(p)`
(line 274) when it creates a ghost, the same shape this ADR needs. But the check that decides
whether an access must go through lazy initialisation, `zend_lazy_object_must_init`
(`Zend/zend_lazy_objects.h:98-101`), is `zend_object_is_lazy(obj)`
(`Zend/zend_lazy_objects.h:81-84`), which reads `OBJ_EXTRA_FLAGS(obj) & (IS_OBJ_LAZY_UNINITIALIZED |
IS_OBJ_LAZY_PROXY)` — an explicit per-object flag, not an inference from the slot's type. A scoped
object is never given that flag, so it never enters the lazy-initialisation path even though its
slots are `IS_UNDEF` the same way a ghost's are; the two mechanisms use the same emptiness at the
storage level and are told apart by a bit the lazy machinery owns and this ADR never touches. Whether
a class can be *both* lazy and scoped is a separate question from whether the two mechanisms
conflict at this specific check — they do not — and is listed under Open questions because nobody
has built or run it.

## Rejected alternatives

- **`#[FiberScoped]` attribute alone.** Cannot reach a vendor class (`RequestStack`,
  `EntityManagerInterface` are not ours to annotate) and arrives only at autoload, which is after
  the class is already in the class table doing whatever autoload does before the attribute is read
  — too late relative to "no instance exists yet".
- **Marking at MINIT.** `route.rs`'s own installer is the demonstration of the failure mode:
  `zend_hash_str_find` against the compiler globals' class table, and `if zv.is_null() { continue; }`
  (`crates/ignis/src/php/route.rs:96-99`, mirrored for functions at `:84-87`) — a userland class name
  not yet defined at MINIT silently does nothing, and MINIT runs before user code loads. That
  silent-skip is exactly why `route.rs`'s own default class list is the one internal class that is
  always present, `SQLite3` (`route.rs:58`), never a userland one. The same installer would silently
  fail to scope every userland façade.
- **Initiating from the constructor.** `create_proxy` (`crates/ignis/src/php/route.rs:206-218`)
  shows the handler table is assigned inside `create_object`, per object: `(*obj).handlers =
  &raw const sys::std_object_handlers` at line 215, before the constructor ever runs. A constructor
  that tried to swap its own object's handlers would run after `create_object` already fixed them
  for this instance, and the class would only start converting from its **second** instance onward
  — the first object built (the one code is already holding a reference to) stays unscoped forever.
- **Binding per class as an inheritance rule.** Rejected implicitly by the engine basis above: guard
  two is per object, not per class, so nothing at the VM level enforces or even expresses "every
  instance of this class is scoped" as a single fact. Trying to enforce it in our own code would need
  an inheritance rule for what happens when a scoped class is subclassed by a non-scoped one and vice
  versa — which per-object binding dissolves by construction: an object either has the swapped
  handler table or it does not, decided once, at its own creation, with no class-wide invariant to
  maintain.
- **A per-call-site thread-local flag.** Revives `R-PDO-SQLITE`'s problem, restated in `route.rs`'s
  own comment on `DEFAULT_CLASSES` (`route.rs:47-52`): `create_object` runs before the constructor's
  arguments exist, so a flag set from a call site cannot see what the constructor is about to be
  given, and cannot distinguish "this call wants a scoped instance" from "this call wants a plain
  one" for the same class.

## Decision

1. **The mechanism.** A class becomes scoped by a class-level call, `Ignis\Scope::scopeClass(X::class)`,
   made from a bootstrap or a container factory before the class's first instance — never from
   MINIT (silently skips undefined userland names) and never from the constructor (too late, per
   object). By the time this call can name the class, the class is linked, so `properties_info` is
   complete and every declared slot's offset is known.
2. **What the call does.** It installs a `create_object` trampoline for `X` — the same shape
   `route.rs::install()`/`create_proxy` already use for offload proxies (save the original
   `create_object`, keep it reachable, install a replacement) — that runs the ordinary creation path
   (`zend_objects_new` plus `object_properties_init`, so the properties table is allocated exactly as
   it always was) and then overwrites the new object's `handlers` pointer to a table with six
   functions replaced: `read_property`, `write_property`, `has_property`, `unset_property`,
   `get_property_ptr_ptr`, `get_properties`. Every other handler — `clone_obj`, `free_obj`, `dtor_obj`,
   `cast_object`, and so on — is inherited unchanged from `std_object_handlers`.
3. **Layout is unchanged.** The properties table `object_properties_init` allocates becomes the zero
   scope's row of defaults; the allocation size for an instance never changes between a scoped and a
   plain object of the same class. This withdraws the backlog entry's earlier "a façade with no
   properties table": the properties table is kept, not removed, and the six handlers redirect
   through `[scope_id][slot]` storage instead of `OBJ_PROP` directly, copying from the zero scope's
   row on first write in a new scope.
4. **The invariant is enforced by never writing `OBJ_PROP` on a scoped object.** The six handlers
   are the only place a scoped object's slots are ever touched from our own code, and none of them
   populates the declared slot itself — the value lives in the scope's row, and the slot stays
   `IS_UNDEF` for the object's entire lifetime so that guards one and two, above, always fall through
   to our handlers.
5. **Inheritance is not a rule this mechanism enforces — it is a fact about handler tables.** A
   subclass that does not override `create_object` uses its parent's, so a scoped parent's child is
   scoped without asking for it: this is stated as owner decision in DECISIONS.md and is a
   consequence of point 2, not a separate mechanism. A hierarchy needing both shapes leaves the
   parent unscoped and calls `scopeClass()` on the branch of descendants that needs it.

## Rules that fall out, because applications are written against them

- **What the constructor stores is process-wide; what a method reads is per scope.** Construction
  happens once, for the one object application code holds as "the singleton" — typically at
  container boot, outside any fiber, so its writes land wherever `Ignis\Scope` puts code with no
  active fiber (the `{main}` bag, `Scope.php:14-16, 21-24, 34-36`) and become the zero scope's
  defaults every later scope copies from. A method call made during a request runs inside that
  request's fiber and its reads/writes land in that fiber's scope row instead.
- **Hold collaborators, never values taken out of them — and this mechanism does not close
  `S-SINGLETON-CAPTURE`.** Scoping a class makes holding *the object* safe (V-96's first half,
  already true for the façades it measured); it does nothing about a singleton that reads
  `$stack->getCurrentRequest()` in its own constructor and keeps the `Request` in a plain property of
  a plain class — that `Request` is a value taken out of a scoped service, stored in an unscoped
  one, and V-96's second half shows it pinned 6 of 6. `S-EXCLUSIVE`'s foreign-fiber check is the
  mechanism aimed at that half; this ADR is silent on it deliberately.
- **A scoped class's constructor must have no side effects.** Whatever it writes becomes the zero
  scope's defaults for every request that follows, forever, because the constructor runs exactly
  once for the object application code is holding. A side effect performed there — opening a
  connection, capturing "now", incrementing a counter — fires once at that zero-scope
  initialisation and never again; work that must happen per request belongs in a method, not the
  constructor.
- **Per-request teardown is a refcount, not a destructor.** There is exactly one scoped-object
  instance for a singleton-shaped service; its own `__destruct` runs once, whenever the container or
  the process lets that one object go — never per request. What actually releases a request's state
  is the same shape `Scope::clear()` already uses for the fiber-keyed bag today (`Scope.php:44-47`'s
  own doc comment: "Anything holding a resource releases through its destructor when the reference
  goes") — dropping a scope's `[scope_id]` row drops the refcount on whatever values live in its
  slots, and *those* values' destructors fire, on the service fiber, the same rule `A-DESTRUCTOR-IO`
  already states for `Scope`.

## The Symfony integration

`Definition::setFactory()` and nothing else — no dumper patch, no container-internals reach-in. A
service definition for a class marked scoped calls `Ignis\Scope::scopeClass($class)` from its
factory before returning the instance, the same way `lazy: true` already sits beside a definition
without the compiler needing to understand what "lazy" means at the object-graph level. `scoped:
true` is a boolean next to `lazy: true` in the same definition, resolved by `FiberScopePass` (the
existing compiler pass that already marks `doctrine.connections` non-shared, V-85) into exactly one
call per scoped service id, which is the shape `FiberScopePass`'s own doc block already commits to:
"only rows that are measured are listed; adding one is a line here plus a decorator, and it needs a
test that fails without it" (quoted at V-95). No change to `FiberScopePass`'s existing façade rows is
implied by this ADR; it is a second row shape the pass can emit, evaluated service by service exactly
as the façade rows are today.

## The eight tests (BACKLOG.md, owner-named 2026-09-20)

Written in this order because the fourth is the one the control level survives or dies by — the
`IS_UNDEF` invariant is the whole mechanism, per the engine basis above, and its violation is silent,
so it is proven before anything else is trusted.

1. **Two interleaved fibers** on a standalone scoped class each see their own property values,
   neither sees the other's, with `IGNIS_CHAOS` on a second arm.
2. **Inheritance, both directions** — a scoped parent's child is scoped unasked; a non-scoped
   parent's scoped descendant branch leaves the parent's own instances untouched, including
   properties the descendant inherited.
3. **Scope death frees the row**, destructors run on the service fiber (`A-DESTRUCTOR-IO`), and a
   reused fiber starts its next request from the zero scope's defaults, not the previous request's
   values — V-67's shape, the reason `Scope` is cleared at request end.
4. **A class scoped after code has already touched it**, cold and with opcache warm. This was the
   backlog's kill-criterion test; it is answered above by citation (`zend_vm_def.h:2491` vs. `:2502`,
   `:2105` vs. `:2111`) rather than left to the test alone, but the test still runs, because a citation
   is not a gate and DECISIONS.md's own standard is a passing test, not a reading.
5. **`get_property_ptr_ptr`**: `$this->arr[] = x` and `$this->n++` land in the right scope's row —
   these hand out a pointer to the slot and write through it, past `write_property` entirely, and are
   listed under Open questions below because no citation here establishes their correctness the way
   the two-guard reading did for plain read/write.
6. **Outside a request**: instantiation and property access with no active fiber land in `Scope`'s
   existing `{main}` bag (`Scope.php:14-16, 21-24, 34-36`) rather than throwing — infrastructure that
   already exists and is already tested for the fiber-keyed case; this test exercises it for a scoped
   object's properties specifically.
7. **`clone`, `serialize`, reflection** — each either behaves correctly or refuses with a message
   naming the class; silently copying another scope's row is the failure this catches. Listed under
   Open questions: no handler for any of the three is decided here.
8. **Read cost** against a plain property, and the fiber-switch cost against E2's 3.83 µs warm
   (`VALIDATION.md:2573`) — the standing constraint that no `context` work may move. This is also
   this ADR's kill criterion; see below.

## Is this `context`, against ADR-0037's own constraint?

ADR-0037 defines `context` by what it is and, deliberately, by what it may not do: "fiber-switch
observer slots (ADR-0006 addendum): superglobals are the first rows, listed vendor statics the
next" and "**allocate per switch**: a slot swap is pointer moves; anything needing allocation
happens at dispatch, not on the observer" (`docs/adr/0037-three-mechanisms.md`, §2 table). Making
this argument honestly means separating two things this ADR's own decision keeps separate: the
**storage**, and the **plumbing** that reaches it.

The storage is `Ignis\Scope`, already `context`, already accepted (ADR-0006), unchanged by this ADR
— the six handlers do not invent a new place to put values, they redirect `OBJ_PROP` syntax into
the same per-fiber container that already holds superglobals and, per V-16/V-68/V-69, the façades'
own state. ADR-0029's isolation ladder already named the generalisation this ADR performs as its own
step (a), for statics: "observer static slots — the ADR-0006 mechanism generalised to a named
(getter, setter) pair per static". This ADR is the same generalisation applied to instance
properties instead of class statics, for the reason instance properties need a different plumbing
than statics do: a static is reached by a fixed, named accessor the mechanism controls, while an
instance property is reached by ordinary `$this->x` syntax on an object arbitrary code may hold a
reference to at any time — there is no "the observer swaps it back at switch time" available for
that, because nothing about the syntax changes on a switch. The object handlers are what makes
`$this->x` resolve per scope without every read site knowing it is talking to a scoped object.

On allocation, specifically: the six handlers run as part of ordinary opcode execution — the
fallthrough at `zend_vm_def.h:2598` and `:2211` above — never inside
`zend_observer_fiber_switch_register`'s callback, which this mechanism does not touch at all. No
slot swap happens on switch, unlike superglobals (which do pay a fixed, measured +100 ns per switch,
V-11, because every switch repoints all four regardless of use). The per-scope row a handler
allocates — copying the zero scope's defaults into `[scope_id]` on first write — happens at the
moment application code first writes a scoped property in a given scope, which is ordinary,
request-driven allocation of the kind every PHP statement causes, not work inserted into the switch
path. In that specific sense this satisfies "anything needing allocation happens at dispatch, not on
the observer" more strictly than ADR-0006's own superglobal swap does: the superglobal swap touches
every switch (as pointer moves, not allocation) whether or not the fiber ever reads a superglobal,
while a class that is never scoped, and a scoped object that is never touched in a given scope, costs
this mechanism nothing on any switch, ever.

**Where the argument is not free, and is stated rather than glossed:** the object-handler
installation itself (`create_object` trampoline, six replaced function pointers) is new C surface
`context` did not previously need — ADR-0006's superglobal swap needed no per-object hook at all, it
owns four fixed slots on the symbol table. This ADR asks `context` to grow a second plumbing shape
(object handlers) alongside its first (observer slot swap) to reach a second address space (instance
properties) alongside its first (superglobals, listed statics). That is consistent with what
ADR-0029 already anticipated `context` would need to become, and it adds no new *wait* — nothing here
changes how or whether a fiber suspends — but it is a real, new capability inside the mechanism, not
a free relabelling of existing code, and the honest version of this argument says so rather than
calling it a pure reuse.

Also distinguishing this from `S-OWNERSHIP`'s rejection, since the shapes look adjacent: ownership
tracking was killed for inspecting every property write and every array-element write, transitively,
across the whole program — work on every write regardless of whether the class involved opted in.
The six handlers here run only for the classes a bootstrap or factory explicitly named through
`scopeClass()`; an unscoped class's property access never reaches them, at any point in this design.
It is targeted, not universal, which is the property `S-OWNERSHIP` lacked.

## Consequences

- Better: the 358–447 façade lines this ADR could replace (`FiberRequestStack` 89,
  `FiberTokenStorage` 53, `FiberEntityManager` 247, `FiberManager` 58) stop needing every method of a
  vendor interface hand-written against `Ignis\Scope`; a new singleton-shaped vendor class needs a
  `scopeClass()` call, not a new façade class.
- Worse: six new `unsafe` object handlers in FFI territory, gated on read cost against a plain
  property (test 8) — every access on a scoped object is now, unconditionally, a call through
  `zobj->handlers`, never the fast path, by the invariant's own design.
- Worse: the invariant is silent to violate and nothing outside a test currently detects a
  populated slot on a scoped object; no runtime assertion is proposed here beyond the tests.
- Does not close `S-SINGLETON-CAPTURE` (stated above, deliberately, twice).
- `FiberEntityManager`'s ~30 interface forwarders are a decorator, not scoping, and are unaffected
  regardless of what this ADR resolves to (DECISIONS.md, 2026-09-20).

## Open questions

Left open rather than answered, because no reading or measurement here settles them:

- **`lazy` and `scoped` together.** They do not conflict at the one check this ADR read
  (`zend_object_is_lazy` keys off a flag this mechanism never sets), but nothing here builds or runs
  a class that is both, and `zend_object_make_lazy`'s own reflection-class restriction
  (`zend_lazy_objects.c:237-248`, internal classes and internal parents refused) is unread against
  our `create_object` trampoline.
- **Property hooks with a body** (`ZEND_IS_PROPERTY_HOOK_SIMPLE_GET`/`SET` false,
  `zend_vm_def.h:2125-2157`) bypass `OBJ_PROP` and our handlers entirely, dispatched as a function
  call instead; a hooked scoped property may silently not be scoped, depending on hook shape. Simple
  hooks (`:2122-2124`, `:2573-2579`) do pass through guard two and are covered.
- **`clone`, `serialize`, reflection** (backlog test 7). No handler decision is made here for
  `clone_obj`, `__serialize`/`__unserialize`, or `ReflectionProperty::getValue()` against a scoped
  object's `IS_UNDEF` slots.
- **`get_property_ptr_ptr` correctness** (backlog test 5) for `$this->arr[] =` and `$this->n++`,
  which take a pointer to the slot and write through it — no citation here establishes what happens
  when that pointer is taken against a permanently-`IS_UNDEF` slot.
- **Instantiation outside a request** beyond the `{main}` bag's existing behaviour for value storage
  (test 6) — whether `scopeClass()` itself is safe to call before `Ignis\Scope` or any fiber
  infrastructure exists (extension load, MINIT-adjacent code) is unread.
- **`R-TA-CONTEXT`**, which blocks this at the BACKLOG/DECISIONS level regardless of anything in this
  ADR: whether backend (b)'s `internal_context` already provides this storage for free, which would
  change what "per-scope storage" means on that backend without changing anything above for backend
  (a).

## Kill criterion

Not the property-offset cache — read above, and answered: the cache is guarded by class alone, but
correctness does not depend on it, because the object-level `IS_UNDEF` guard is what actually gates
the fast path and that guard is evaluated every time, cache hit or not.

**The kill criterion is test 8's read cost, weighed against two other candidates and rejected for
both:**

- *The fiber-switch cost against E2's 3.83 µs warm* (`VALIDATION.md:2573`) is the wrong gate for
  this specific mechanism, argued above: nothing here runs inside the switch observer, so there is
  no reason to expect this to move E2 at all, and if it somehow did, that would indicate a coding
  error (a handler reached from the switch path) rather than tell us anything about whether the
  design is sound. It stays as a regression check, not the criterion the design lives or dies by.
- *`lazy` + `scoped` proving incomposable* is a real risk (listed under Open questions) but is a
  narrower question than the design's soundness — an application that never combines the two is
  unaffected either way, so this cannot be the criterion that kills the whole mechanism.
- *Read cost against a plain property* is the right criterion because it is the cost every scoped
  object pays on every single access, by the invariant's own construction — there is no fast path
  for a scoped object, ever, by design, so this number is not a tail cost or an edge case, it is the
  mechanism's baseline price. The four façades already pay a version of this cost today (a method
  call plus a `Scope::get`/`set`), so the number that matters is not "is it free" but "is it cheap
  enough that turning a façade's hand-written method into a property access is still a win once the
  handler indirection and the scope lookup are both paid for".

**If the measured read (and write) cost against a plain property is not markedly cheaper than the
equivalent façade method call it would replace, the trade DECISIONS.md left open — "~358 lines of
plain userland PHP against six new `unsafe` object handlers" — resolves against building this: the
façades are boring, working, well-tested PHP, and six new handlers earn their keep only if they are
both correct and cheap. "Markedly cheaper" is deliberately not pinned to a number here, because no
measurement exists yet to anchor one; the prototype's first number is what test 8 sets it against.**
