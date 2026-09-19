# Research 43 — park.rs / wait.rs: what disappears if the path is deleted instead of guarded

Scope: `crates/ignis/src/php/park.rs`, `crates/ignis/csrc/park.c`, `crates/ignis/src/php/wait.rs`,
the `IGNIS_PARK` policy table (ADR-0020/0037). Question asked (owner, via the orchestrating
session): not how to fix the open defects in this slice — they are filed — but which of them stop
existing if the path is removed rather than guarded. Read-only: no code changed, no `unsafe`
touched, nothing built. Every number below is measured with `wc -l` / `grep -c` / `md5sum` against
`main` at commit range ending `55cba25`, or labelled **estimate** when it projects an unbuilt
change, following ADR-0037 §1's own convention of separating the two.

## Sources

`CLAUDE.md`, `BRIEF.md`, `docs/adr/0037-three-mechanisms.md`, `docs/adr/0020-universal-park.md`
(referenced, not re-read line by line — 0037 supersedes its numbers), `docs/adr/0038-locks-across-a-fiber-boundary.md`,
`BACKLOG.md` (`A-PARK-ARITHMETIC`, `A-UNSAFE-CONTRACTS`, `A-RUST-TESTS`, `R-FOREIGN-FIBER`,
`R-SESS`, `R-PDO-SQLITE`, `E18-I`), `VALIDATION.md` (`V-4`, `V-45`, `V-47`, `V-58`, `V-59`, `V-59`
addendum, `V-63`), the source files themselves and their git history (`git log -- crates/ignis/src/php/park.rs`).

## What the files measure as they stand

```
crates/ignis/src/php/park.rs   1056 lines   44 "unsafe {"   41 "unsafe fn"/"unsafe extern"
crates/ignis/src/php/wait.rs    169 lines    4 "unsafe {"    4 "unsafe fn"/"unsafe extern"
crates/ignis/csrc/park.c         73 lines
crates/ignis/src/php/module.rs 1155 lines   40 "unsafe {"   38 "unsafe fn"/"unsafe extern"
```

**`park.rs` is no longer the largest file in the crate.** `A-RUST-TESTS` and this task's own brief
both say so ("1,051 lines, the largest file in the crate"); `module.rs` has overtaken it at 1155
lines (`park.rs` is 1056). `park.rs` is still first by every `unsafe` measure that matters for
`A-RUST-TESTS`'s actual argument — 44 `unsafe {` blocks against `module.rs`'s 40, 41 `unsafe
fn`/`unsafe extern` against 38 — so the item's reasoning survives, but its headline fact needs
correcting.

`park.rs` exports 19 interposed handlers (`grep -c 'pub unsafe extern "C" fn ignis_park_'`):
`read write recv send recvfrom sendto poll ppoll select connect nanosleep usleep sleep accept4
recvmsg sendmsg flock readv writev`. `park.c` (73 lines) is the C shim that captures each caller's
return address and forwards to these — one line per symbol, already minimal; there is no dedup
candidate there (a macro would save characters, not clarity, and C has no zero-cost closure to hide
the `__builtin_return_address(0)` capture behind).

The four-line `// SAFETY: csrc/park.c calls this…Nothing here dereferences the caller's buffer…`
paragraph appears **17 times**, byte-identical (`md5sum` on each extracted block: 17/17 match one
hash) — not 14 as `A-UNSAFE-CONTRACTS` and this task's brief both state. It sits over every handler
except `poll` (which now forwards to the shared `poll_impl` and carries its own note) and `flock`
(its own note, about why there is nothing to dereference). It is **false at three of the 17**, not
two: `ignis_park_select` (`:601`, does `std::ptr::read`/`FD_ISSET`/`ptr::write` on the caller's fd
sets) and `ignis_park_ppoll` (`:576`, dereferences `ts`) are the two `A-UNSAFE-CONTRACTS` names;
**`ignis_park_nanosleep` (`:721`) is a third, unnamed in the backlog item**, and it reads `(*req)`
for `tv_sec`/`tv_nsec` two lines into its body.

## Lead 1 — the duplicated shims: measured, and a real merge candidate

Eleven of the 19 handlers share one shape exactly: `may_park` → (an `MSG_DONTWAIT` check, for six
of the eleven) → `would_block` → `ready_now` → `park_io` → EAGAIN-or-fall-through-to-the-raw-syscall.
Measured body length per function (`awk` over `#[unsafe(no_mangle)] … }` spans):

| fn | lines | fn | lines |
|---|---|---|---|
| read | 18 | accept4 | 20 |
| write | 18 | recvmsg | 19 |
| recv | 19 | sendmsg | 19 |
| send | 19 | readv | 18 |
| recvfrom | 27 | writev | 18 |
| sendto | 27 | | |

Sum: **222 lines** across 11 functions for what is, past the symbol name and the final syscall
call, the same six lines of control flow copy-pasted 11 times.

**The merge (estimate, unbuilt):** one shared `unsafe fn park_gate(ret, sym, fd, flags_nonblocking,
write) -> bool` holding the `may_park && !flags_nonblocking && would_block && !ready_now &&
park_io(..) == TimedOut` chain (~10 lines with its own single doc comment), with each of the 11
handlers reduced to a thin wrapper (`no_mangle` attribute + signature + one short comment pointing
at `park_gate`'s doc + `if park_gate(..) { EAGAIN; return -1 } ` + the raw syscall). A drafted
example for `read`:

```rust
#[unsafe(no_mangle)]
pub unsafe extern "C" fn ignis_park_read(ret: *const c_void, fd: c_int, buf: *mut c_void, n: usize) -> isize {
    // SAFETY: see park_gate's doc. buf/n are forwarded to the kernel unchanged, never read here.
    unsafe {
        if park_gate(ret, "read", fd, false, false) {
            *libc::__errno_location() = libc::EAGAIN;
            return -1;
        }
        libc::syscall(libc::SYS_read, fd, buf, n) as isize
    }
}
```
— 12 lines against today's 18. Weighted across the 11 (recvfrom/sendto stay a little longer for
their extra `addr`/`alen` args): **estimate ≈142 lines total (11 wrappers + 1 helper), against 222
today — estimate −80 lines**, and the duplicated-paragraph count drops by 11 of the current 17 (the
helper carries one copy; the six functions that still differ — poll, select, ppoll, connect,
sleep-family, flock — are untouched by this merge and keep their own notes). This is also most of
`A-UNSAFE-CONTRACTS`'s stated acceptance for free: "whatever is genuinely common goes in the module
doc once."

**What this does not touch:** the raw `libc::syscall(SYS_*, …)` calls themselves, so H32/H33's
numbers (curl 279–337 ms, pgsql 296–333 ms, V-45) and the phpt gate that created each hook are
unmoved by construction — the kill criterion is simply that they stay unmoved after the merge.

## Lead 1b (found, not asked for) — `wait.rs`'s `await_op` is a specialization of `await_any`

`await_op(id)` (`wait.rs:40-63`, 24 lines) and `await_any(ids)` (`wait.rs:71-114`, 44 lines) do the
same suspend/resume dance — insert into `PARKED`, `zend_fiber_suspend`, check the exception, read
back out of `RESULTS` — with `await_any` doing it for a slice instead of one id. `await_op` has
exactly one caller in the whole crate (`grep -rn await_op crates/ignis/src`): `park_sleep`
(`park.rs:359`), used by the sleep/usleep/nanosleep/flock family, not by `Ignis\Loop`'s own hot
resume path.

**The merge (estimate, unbuilt):** `await_op` becomes `unsafe fn await_op(id: u64) -> Option<Outcome>
{ unsafe { await_any(&[id]).map(|(_, o)| o) } }` — checked by hand against `await_any`'s behaviour
for a one-element slice: same null/switch-blocked guard, same `PARKED` insert/suspend, same
exception check, same `RESULTS` removal-and-return. **Estimate ≈5 lines against 24 — estimate −19
lines.** Because the sole caller is off the `Ignis\Loop` warm-resume path that E2 measures, the
merge's kill criterion is cheap: E2 warm stays ≤3.83 µs (it should not even move, since nothing on
that path changes), and a test asserting `await_op(id) == await_any(&[id]).map(second)` under the
existing park/flock scenarios (V-45, V-58) is the correctness check.

## Lead 2 — the 16-symbol seed table: refuted as a `block`-by-default simplification

`SEED` (`park.rs:64`) has 16 `libphp:*` rows plus 4 whole-library rows (`libcurl`, `libpq`,
`libssl`, `libcrypto` — 20 total, matching the task's "16 symbols" if the four whole-library rows
are read separately, which the code itself does). Traced each `libphp:` row against what regresses
if it defaults to `block`:

| row | what breaks under `block` | measured evidence |
|---|---|---|
| `sleep`/`usleep`/`nanosleep` | Symfony `LockRegistry`'s stampede lock (its loser loop polls `flock(LOCK_NB)` + `usleep(100 ms)`) stops proceeding — the exact scenario V-58 measured working *because* `usleep` parks | V-58: "60 of 60" ticks parked, "never finished" (killed at 60 s) unparked |
| `read`/`write`/`recv`/`send`/`recvfrom`/`sendto`/`recvmsg`/`sendmsg`/`poll`/`select`/`connect` | `file_get_contents('http://…')`, `fsockopen`, any PHP stream over a socket blocks the OS thread — this is E6's and E18's headline acceptance | E18 acceptance (1)/(2); V-45's H32/H33 numbers exist only because these rows park |
| `accept` | a PHP-level socket accept loop blocks the thread it runs on | A4/ADR-0018's rule this row carries forward from the deleted `accept.rs` (ADR-0037 cycle 2, V-48) |
| `flock` | **reopens the R-SESS deadlock this row exists to close** — see Lead 3 | V-58 |
| `libcurl` | 62× regression (328 ms → 20,346 ms at N=100) | V-59 |
| `libpq` | 9× regression (303 ms → 2,753 ms at N=100) | V-59 addendum |
| `libssl`/`libcrypto` | every `https://` stream regresses to blocking during TLS; A6/B7's "closes by disappearance" result (ADR-0037 cycle 3) depends on these parking | ADR-0037 §6 step 4 |

**No row in the seed table is a deletion candidate.** Every one is traced to a specific, already-measured
regression, several of them the project's own headline numbers (E1/E6/E18). The concrete lead in
the brief — "determine whether a smaller interposed surface closes [R-SESS/R-FOREIGN-FIBER] by
construction" — is answered **no** for both, for different reasons (Leads 3 and 4 below). This is
a refuted simplification, recorded as the brief asks.

## Lead 3 — R-SESS: already fixed in code; the backlog text is stale

`R-SESS`'s text (`BACKLOG.md:328`) reads "Fixes to choose from, none built" and cites three unbuilt
options. **`ignis_park_flock` (`park.rs:858-886`) already is a fourth, built fix**, shipped in
`a6c1a56` ("interpose flock so a blocking lock inside a fiber parks (S1-FLOCK, V-58)") — the same
commit `V-58`, the validation entry `R-SESS`'s own text cites for its severity correction. The
mechanism: a blocking `flock(LOCK_EX)` is rewritten to a `LOCK_NB` probe in a loop, backing off from
`FLOCK_RETRY_FIRST_US=200` µs to `FLOCK_RETRY_MAX_US=20_000` µs (`park.rs:844-845`), parking on each
wait via the existing `park_sleep` primitive instead of blocking the OS thread. This is exactly
Symfony's own `LockRegistry` shape (`LOCK_NB` + `usleep` poll), applied mechanically to `ext/session`'s
`flock` call by the interposer — which is what ADR-0038 decision 2 already prescribes ("any code
that must use a file lock is expected to take this shape"), just applied by the runtime instead of
by the application.

Reproducing V-58's second arm (`scratchpad/flock2.php`: fiber A holds the lock, fiber B blocks on
`flock`, "killed at 12 s with no progress") against the code that existed **before** `a6c1a56` would
hang exactly as measured; against `main` today, `ignis_park_flock` intercepts B's call before it
reaches the kernel, so it degrades to a bounded poll loop instead. `ADR-0038`'s status line already
says as much ("accepted for the rule…the session-handler choice below is proposed and unbuilt") but
its decision 3 ("the runtime refuses to start when sessions are enabled with the files handler") and
decision 4 (ship a PostgreSQL session handler) are framed as still needed; they are not, for the
deadlock this item names — the deadlock is already closed by construction of the `flock` row in the
policy table.

**What is genuinely still open** is `A-PARK-ARITHMETIC` item (d), already filed against this same
function: the retry loop has no overall deadline, so a lock held by a **crashed** holder (not a live
one still running its own request) parks every waiter for the life of the process. That is a
liveness bug in already-shipped code, not a missing mechanism — the fix is a bounded deadline (or a
documented `ponytail:` ceiling) on the loop that already exists, a handful of lines, not ADR-0038's
heavier guards.

**Recommendation:** close `R-SESS`'s deadlock claim by pointing at `a6c1a56`/V-58; keep only the
crashed-holder liveness gap, folded into `A-PARK-ARITHMETIC` (d) where it is already tracked; mark
ADR-0038 decisions 3 and 4 not-needed unless a *product* reason (not a deadlock reason) reopens them.

## Lead 4 — R-FOREIGN-FIBER: cannot be closed by shrinking the table

The defect (`V-63`, reproduced by `vendor/revolt/event-loop/examples/fiber-local-automatic.php`
under `REVOLT_DRIVER=StreamSelectDriver`) is in `on_switch`'s gate — "park if the target context is
not `EG(main_fiber_context)`" — which is true for **any** fiber, including one a foreign scheduler
made. This is orthogonal to which syscalls are in the policy table: the same hang happens whether
the foreign fiber calls a `libphp:usleep`-policy sleep or a `libcurl`-policy `curl_exec`. Shrinking
the seed table to zero "fixes" it only by disabling park entirely, which fails E1/E2/E6/E18's own
numbers (Lead 2). **This is a case where the honest answer is "cannot be simplified away, a guard is
required"** — the guard being the one already designed in the backlog (a thread-local set of
runtime-owned `zend_fiber_context` pointers, `on_switch` consulting it instead of `to != main`).

Kill criterion, already named correctly in the backlog item: `bench/e7-revolt.sh`'s AMPHP-on-
`StreamSelectDriver` repro passes without `IGNIS_NO_UNIVERSAL_PARK=1`, and E2 warm fiber switch
stays ≤3.83 µs (V-4/V-45/V-47's number; V-63 records 3.62–3.83 µs as the current quiet-box range).

## Lead 5 — R-PDO-SQLITE: the simplification already shipped; do not build the proxy

`route.rs:58` reads `const DEFAULT_CLASSES: &str = "SQLite3";`, dated in its own comment to
2026-09-17 (`V-59` addendum): **`PDO` is already out of the auto-route default.** `R-PDO-SQLITE`'s
own framing — "today the choice is `IGNIS_OFFLOAD_CLASSES`, per class and not per driver, and an
application using both under concurrency cannot have both right" — describes a conflict that, for
the *default* configuration, no longer exists: neither `pdo_sqlite` nor `pdo_pgsql` is auto-routed
today, so `pdo_pgsql` parks (303 ms, `V-59` addendum) and `pdo_sqlite` blocks the thread briefly per
query, a cost `route.rs`'s own comment says is "the same rule that already governs
`file_get_contents`, opcache and sessions" (ADR-0024's accepted non-goal). The conflict only recurs
if an operator explicitly sets `IGNIS_OFFLOAD_CLASSES=PDO` to get `pdo_sqlite` concurrency — at
which point they have opted into the documented tradeoff, and the ~60-line `create_object`-on-the-
proxy design (`R-PDO-SQLITE`'s own acceptance) is what would fix *that* case, not the default one.

**Recommendation:** do not build the proxy. Rewrite `R-PDO-SQLITE`'s text to match `route.rs`'s
current default and downgrade it to "reopen only if an operator reports the opt-in conflict in
practice." Net: 0 lines removed from what exists (there is nothing to delete — the deletion already
happened in `V-59`'s change), but +60 lines are never written, and the item stops being read as an
open defect in the default path, which it is not.

## Lead 6 — A-PARK-ARITHMETIC: three sites, one real merge and one unrelated bug

Two of the three named sites share the exact shape "`sec * 1000 + subsec_adjustment`, clamped after
the multiply": `sock_timeout_ms` (`park.rs:315-326`, the `SO_RCVTIMEO`/`SO_SNDTIMEO` reader) and
`ms_ceil` (`park.rs:535-537`, used by `ppoll`'s caller-supplied `timespec`). These two collapse into
one shared saturating helper — modest size change (roughly neutral: two 1-line clamp expressions
become two 1-line calls to one ~4-line function), but real value: the arithmetic bug has one home
to fix and one place `A-RUST-TESTS`' unit test targets, instead of two. The third site,
`module.rs:177` (`(ms.max(0) as u64) * 1000`, converting a PHP-supplied millisecond count to
microseconds for `Op::Sleep`), is a different unit shape in a different file — the correct fix is
`.saturating_mul(1000)` in place, stdlib doing the job in one line (ponytail rung 3), not a shared
helper reaching across files for one call site.

The other two items in `A-PARK-ARITHMETIC` — `ignis_park_sleep` always returning 0 instead of the
POSIX remainder, and `ignis_park_flock`'s missing deadline — are not simplification candidates by
this task's own test (nothing to delete that makes them disappear): the sleep-remainder question
only has a wrong answer if a parked sleep can complete *partially*, which `Op::Sleep` does not
model today (it finishes fully or `park_sleep` reports "could not park" and falls back to the real,
POSIX-correct `nanosleep`) — so this is a smaller fix than the item implies, worth noting but not
re-scoping here. The flock deadline is the residual half of Lead 3.

## A-RUST-TESTS (park.rs/wait.rs half)

Confirmed: neither file has a `#[cfg(test)]` module today (`grep -n "mod tests\|#\[test\]"` returns
nothing in either). The named pure-logic targets still exist at the acceptance's description, at
today's line numbers: `would_block` (`park.rs:206-249`), `park_pollfds`' cancel bookkeeping
(`park.rs:501-532`), the `select` fd-set copy/refill (`park.rs:601-673`), `ms_ceil`
(`park.rs:535-537`, one of the two arithmetic-merge sites above). This is not a deletion candidate —
it is the one line item in scope that adds code — but Leads 1 and 1b shrink what it has to cover:
one `park_gate` function to test instead of the same six lines checked across 11 bodies, and one
`await_op`/`await_any` equivalence check instead of two independent suspend/resume paths.

## E18-I (stage-2 remainder): the backlog text is behind the code

`BACKLOG.md:108` lists the stage-2 remainder as "getaddrinfo, ECANCELED, boot self-check,
blocked-in-fiber detector." Measured: `php::park::selfcheck()` is called at `main.rs:245`, and
`PARK_FAILED` is read by `metrics.rs:19,109` — **both already built and wired**, matching
`ADR-0037` §4's own progress note ("(a) built and measured V-52; (b) half built"). `getaddrinfo` is
a deliberate non-build (`R-DNS`, owner decision 2026-09-17, stated in `park.rs`'s own module doc,
line 29-30) — not a gap. The one item that is genuinely absent is `ECANCELED` handling for a parked
call under cancellation (`grep -rn ECANCELED crates/ignis/src` — zero matches anywhere in the
crate), plus the watchdog half of the blocked-in-fiber detector, which the ADR itself places outside
`park.rs` ("a thread stuck in a syscall cannot report on itself…it belongs to the watchdog,
ADR-0012"). Recommendation: rewrite the "left:" list; no code change follows from this reading.

## Ranked table

| verdict | candidate | backlog ids closed/affected | measured / estimated line delta | kill criterion |
|---|---|---|---|---|
| **MERGE** | 11 io-shaped handlers → one `park_gate` helper + thin wrappers | `A-UNSAFE-CONTRACTS` (removes 11 of the 17 duplicated notes), `A-RUST-TESTS` (one function to test) | measured today 222 lines / 11 bodies; **estimate ≈142 after, estimate −80** | phpt gate ≥ baseline in the suites that created H32/H33 (V-45/V-47); H32 279–337 ms and H33 296–333 ms unmoved |
| **MERGE** | `await_op` → 3-line wrapper around `await_any(&[id])` | `A-RUST-TESTS` | measured today 24 lines; **estimate ≈5 after, estimate −19** | E2 warm ≤3.83 µs unmoved (await_op's only caller, `park_sleep`, is off the Loop's hot path); equivalence test against `await_any` |
| **MERGE** | `sock_timeout_ms` + `ms_ceil` → one saturating helper; `module.rs:177` gets `.saturating_mul(1000)` in place | `A-PARK-ARITHMETIC` (a)(b) | measured today: two 1-line clamps; **estimate roughly neutral, 0 to −2** | unit test per site with `PHP_INT_MAX`/`i64::MAX` inputs, run under `cargo +nightly miri test -p ignis -- php::park` |
| **DELETE (do not build)** | R-PDO-SQLITE's ~60-line `create_object`-on-proxy design | `R-PDO-SQLITE` | 0 removed from existing code (nothing to delete — `route.rs:58`'s `DEFAULT_CLASSES = "SQLite3"` already shipped the simplification, V-59 addendum); **+60 lines never written** | none — reopen only if an operator hits the opt-in conflict in practice |
| **KEEP** | all 20 `IGNIS_PARK` seed rows | closes nothing by dropping a row — every row traced to a specific measured regression | 0 | re-run V-58 (LockRegistry) and V-59/V-59-addendum (curl/pgsql) with any candidate row moved to `block`; any regression keeps the row |
| **KEEP, fix the residual, not the mechanism** | `ignis_park_flock`'s retry loop | `R-SESS` (deadlock claim closes by pointing at `a6c1a56`/V-58), `A-PARK-ARITHMETIC` (d) | a bounded deadline is a handful of lines, additive | reproduce `scratchpad/flock2.php`-style two-fiber scenario against `main`; it now completes instead of hanging at 12 s (confirms the deadlock is already closed); a crashed-holder variant (kill the lock holder's process) proves the residual gap and the fix for it |
| **KEEP, guard required** | R-FOREIGN-FIBER's owned-fiber-context fix | `R-FOREIGN-FIBER` | new state, small, unavoidable — not sized here because unbuilt | `bench/e7-revolt.sh` passes without `IGNIS_NO_UNIVERSAL_PARK=1`; E2 warm ≤3.83 µs |
| **KEEP, textual only** | select/ppoll/**nanosleep**'s SAFETY notes | `A-UNSAFE-CONTRACTS` | ~0 (comment rewrite; nanosleep is a third site not named in the backlog) | none beyond `cargo build` — ADR-0041's gate already requires a note per unsafe block |
| **KEEP, textual only** | E18-I's stage-2 "left:" list | `E18-I` | 0 (self-check and the detector's surprising half are already built) | none — `main.rs:245`/`metrics.rs:109` are the evidence |
| **ADD (in scope, not a deletion)** | tests for the pure logic in park.rs/wait.rs | `A-RUST-TESTS` | additive; smaller after the two merges above | a test per named function; not a coverage percentage (ADR-0041, V-78) |

**Total measured/estimated line delta if every MERGE and DELETE row above were carried out:**
estimate **−99 to −101 lines** (−80 io-shape merge, −19 await_op merge, 0 to −2 arithmetic merge)
against `park.rs` + `wait.rs`'s combined 1225 measured lines today — about 8% of the two files. The
DELETE row (R-PDO-SQLITE) removes nothing that exists; it prevents +60 lines from being written,
which is not counted in the −101 since it is not a subtraction from measured code. This is a modest
number and stated as such: park.rs has no wholesale-mechanism-removal candidate (every seed-table
row is load-bearing, Lead 2), so the honest ceiling here is duplication removal within the
mechanism, not removal of the mechanism.

## What surprised me / what this rules out

- **park.rs is not the crate's largest file any more** (module.rs, 1155 lines, passed it) — the
  premise both the task brief and `A-RUST-TESTS` state needs correcting, even though the "most
  `unsafe`" half of the argument still holds (44 blocks / 41 fns against module.rs's 40/38).
- **The duplicated SAFETY paragraph is undercounted** (17, not 14) **and under-flagged as false**
  (3 sites, not 2 — `nanosleep` dereferences `*req` and was not named).
- **R-PDO-SQLITE's premise is already false in the code.** `DEFAULT_CLASSES` dropped `PDO` on
  2026-09-17 (V-59 addendum); the backlog item's own text was not updated to match, so it currently
  reads as an open defect in the default path when the default path is the fixed one.
- **R-SESS's "none built" framing is stale.** `ignis_park_flock` (`a6c1a56`) is a fourth, built fix
  for exactly the deadlock the item describes, and it is validated by the very `V-58` entry the item
  cites for its own severity correction. ADR-0038's decisions 3/4 describe guards for a problem the
  mechanism already closes; only the crashed-holder liveness gap (`A-PARK-ARITHMETIC` (d)) remains.
- **R-FOREIGN-FIBER refutes the "shrink the surface" hypothesis directly** — it is a gate-ownership
  defect, not a which-symbols-are-parked defect, so no policy-table change closes it; a guard is the
  only fix, and the mechanism budget already allows it (a refinement inside `park`, not a fourth
  mechanism).
- **E18-I's own progress is ahead of its backlog line.** Boot self-check and the detector's
  surprising half are wired into `main.rs`/`metrics.rs`; the stage-2 "left:" list has not been
  updated since.

No fourth mechanism is proposed anywhere above, and none of the three (park, offload, context) is
proposed for removal — `park` is load-bearing for E1/E6/E7/E18 by the seed-table trace in Lead 2,
which is itself the strongest refutation in this note: the obvious-looking simplification ("shrink
the policy table") was checked row by row and does not survive contact with the measurements.
