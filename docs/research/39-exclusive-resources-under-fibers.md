# Research 39 — keeping an exclusive resource exclusive under fibers

Date: 2026-09-18. Question from the owner, after V-85: how do we stop two fibers from using the same
database connection — at the language level or in our packages — and can locks or semaphores be made
part of the syntax? The answer has to generalise: a connection is one instance of *a resource that
tolerates exactly one user at a time*.

## 1. What actually went wrong, stated precisely

A `Doctrine\DBAL\Connection` reached two fibers because a container service was shared and nobody
noticed. The failure was not a missing lock — it was **a reference that should not have existed**.
Once two fibers can name the same object, everything else is damage control.

That distinction decides the whole design space:

- **Ownership** makes sharing impossible. Right for anything non-reentrant: a libpq connection, a
  `PDOStatement`, a stateful stream, a file handle mid-read.
- **Mutual exclusion** makes sharing safe but serial. Right for things that genuinely must be one —
  a process-wide cache warm-up, a rate limiter, an in-memory table.

We have neither as a primitive today. What we have is *discipline*: `ConnectionPool::acquire()` pops
the connection out of its idle list, so while it is leased it exists in one place; `Ignis\Scope` is
keyed by the fiber; `Loop` clears the scope in a `finally`. All of that is correct and none of it is
enforced — one `$this->connection = $connection` in an application service defeats it silently
(`S-DBAL-DIRECT`).

## 2. What PHP gives us to work with

Nothing at the type level. Rust refuses this program at compile time because `!Sync` is a type
property and the borrow checker is not optional; Go does not refuse it but ships a race detector
that finds it; Java's virtual threads pin on `synchronized` so the runtime at least knows. PHP has
no ownership types, no race detector, and its concurrency here is cooperative — which is the one
advantage we have: **a fiber can only lose the resource at a suspension point, and we own the
suspension point.**

Three enforcement surfaces exist, and every option below sits on one of them:

| surface | what it can see | cost |
|---|---|---|
| the object boundary (a wrapper's methods) | every call into the resource | one comparison per call |
| the fiber switch (`zend_observer_fiber_switch`, already ours) | every suspension, with the fiber identity | must not move E2's 3.83 µs warm switch |
| before runtime (PHPStan, already at level 9) | the *shape* of the code: a shared service storing a resource | zero at runtime |

## 3. The options

### O1 — fiber affinity on the wrapper (enforcement, ~5 lines)

`PooledConnection` already wraps every driver call. Record the owning fiber at `acquire()` and check
it on each method:

```php
private function guard(): void
{
    if (Fiber::getCurrent() !== $this->owner) {
        throw new ExclusiveResourceException('this connection belongs to another fiber; it was leased at …');
    }
}
```

Turns V-85's failure mode — another request's rows, HTTP 200 — into an exception naming the bug, at
the first misuse rather than at the corrupted result. **Cannot see** a resource used outside the
wrapper (a raw `new PDO` an application shares itself).

### O2 — real `Ignis\Mutex` and `Ignis\Semaphore` (primitive, no new mechanism)

The Doctrine pool already contains a hand-rolled semaphore: a permit count, a queue of `Future`s, a
bounded wait, a timeout exception. That belongs in `ignis/runtime` as a primitive both it and
everything else can use:

```php
$mutex = new Ignis\Mutex();
$mutex->withLock(function () use ($table) { $table->rebuild(); });   // parks, never blocks the thread
$permit = $semaphore->acquire(timeoutMs: 500);                      // or PoolTimeoutException
```

No new mechanism (ADR-0037): it is `Future` plus the loop, the same way the pool's queue already is.
Deduplicates code we have written twice and gives applications the tool for *their* shared state,
which today they have to invent.

### O3 — scoped access instead of a returned handle

`$pool->with(fn (Connection $c) => …)` hands the resource in and releases it in a `finally`. PHP
cannot stop the callback from storing `$c`, so this is a convention — but paired with O1 the escape
is caught on first use. Cheap, readable, and it makes the lifetime visible at the call site, which
review can check and a returned handle cannot.

### O4 — the rules that catch misuse without any new machinery

Two pieces of bookkeeping in `Scope`, both of which we have had and lost:

- **One lease per fiber per resource.** A second `acquire()` from a pool this fiber already holds is
  a bug, not a wait — the old `Ignis\Pg` threw `LeaseError` in 36–39 µs with zero ops submitted.
- **Lock ordering.** Record the set a fiber holds; acquiring B while holding A when some other fiber
  did the reverse is the deadlock we cannot otherwise see. Detect and log; refusing is a later
  decision.

### O5 — "held across a suspension" detector (the generic answer)

ADR-0038 already states the rule — *a lock that can be held across a yield must live on a socket or
in the runtime, never on a file* — and nothing enforces it. The fiber-switch observer can: mark a
resource `mustNotCrossSuspension`, and when a fiber suspends while holding one, log it (or throw
under a strict flag).

This is the one option that covers **any** resource rather than the ones we wrapped, and it is
exactly the shape the context mechanism already has: a slot per fiber, read at the switch. Its price
is the switch path, which E2 pins at 3.83 µs warm — any implementation that moves that number is
refused.

It is a *detector*, not a preventer: it tells you the fiber is holding a file lock across an
`await` before that becomes a thread-wide deadlock (`R-SESS`), which is the difference between a bug
report and a hang.

### O6 — a guarded proxy for classes we do not wrap (`create_object`)

`route.rs` already swaps a class's `create_object` to hand a fiber a proxy (that is how offload
routing works). The same hook can hand out a proxy that carries the owner fiber and checks it in
`__call`, for a configured list — `PDO`, `Redis`, `SQLite3`, anything an application shares by
accident. Enforcement without the application using our API at all.

Costs an object per instance and a check per call, and it is per class, not per driver — the same
limitation `R-PDO-SQLITE` documents. Opt-in, off by default.

### O7 — attributes plus a static rule (this is what "syntax" looks like in PHP)

```php
#[Ignis\Exclusive]                 // one fiber at a time, enforced by O1/O6
final class Connection { … }

#[Ignis\FiberBound]                // must not outlive the request
final class UnitOfWork { … }
```

An attribute is metadata; something has to enforce it. Two things can, and together they are most of
the value:

- **PHPStan**, at build time: a service that is shared and stores a property typed `#[Exclusive]` is
  the `S-DBAL-DIRECT` shape, and it is mechanically detectable. Zero runtime cost, and it fails the
  build rather than the request.
- **The runtime**, via O1/O6, for what analysis cannot see.

### O8 — actual language syntax

`synchronized` blocks, `lock ($m) { … }`, `defer` — none exist in PHP 8.5, and adding them is an RFC
against php-src, not something a host can do. The nearest idiom is what `FiberManager` already does:
a guard object whose destructor releases, which is RAII with the scope being "the last reference".
Worth naming so nobody re-proposes it: **we are not going to grow syntax; we are going to make the
object boundary honest.**

## 4. What each option is worth

| option | enforces or detects | where | cost | catches the V-85 bug? | catches a raw shared `PDO`? |
|---|---|---|---|---|---|
| O1 affinity check | enforces | wrapper | one comparison per call | **yes**, at first misuse | no |
| O2 mutex/semaphore | enables safe sharing | userland | a park when contended | not by itself | if the code uses it |
| O3 scoped access | convention | API shape | none | makes it visible | no |
| O4 lease/order rules | enforces | `Scope` | negligible | partly (double lease) | no |
| O5 suspension detector | **detects** | switch observer | must not move 3.83 µs | yes, and more | **yes** |
| O6 guarded proxy | enforces | `create_object` | proxy + check per call | yes | **yes** |
| O7 attributes + PHPStan | enforces early | build | zero | the shape of it | the shape of it |
| O8 syntax | — | php-src | an RFC | — | — |

## 5. Recommendation

In order, cheapest first, each useful alone:

1. **O1 + O4 now.** Five lines in `PooledConnection` plus the double-lease rule turn a data leak into
   an exception. This is the largest return per line in the whole list.
2. **O2 next**, because the code already exists twice and wants to be a primitive — and because
   applications have shared state of their own and we currently offer them nothing.
3. **O7's PHPStan rule**, which catches the exact shape that bit us before the code ever runs.
4. **O5 when the switch budget allows it**, measured against E2 — it is the only one that covers
   resources nobody wrapped, and it makes ADR-0038's rule enforceable instead of advisory.
5. **O6 opt-in**, for applications that share things we do not own.

Not doing: O8.

## 6. What this does not solve

A resource shared between **threads** is a different problem — these are all per-thread mechanisms,
because a fiber never leaves its thread. Cross-thread sharing stays what it is today: the offload
pool's worker-pinned handles, or the runtime owning the resource outright. And nothing here helps a
fiber that hangs while holding something (`S-POOL-LEASE-AGE`): ownership says who has it, not how
long they may keep it.
