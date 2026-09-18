# ADR-0041 — A blocking quality gate, and what it costs

Status: **proposed** (main agent, 2026-09-17). Becomes accepted when §7's gate is green in CI on
`main`. Owner decisions that led here: the code style rules in CLAUDE.md (2026-09-17), and
"gates blocking immediately" rather than a warning period.

Every count below was measured on this tree on 2026-09-17, not estimated. Where a number came from
a prior agent's run and my own re-run disagreed, mine is the one recorded and the disagreement is
stated.

## 1. What was there

Nothing. No `[workspace.lints]`, no `rustfmt.toml`, no `clippy.toml`, no `deny.toml`, no nextest
profile, no `phpstan.neon`, no `phpunit.xml`, no `.php-cs-fixer.dist.php`. CI ran nextest, a narrow
miri, `scripts/smoke.sh`, E9 and the four E15 compat suites — no linter of any kind, on either
side, and no coverage. A repo-wide grep for `clippy|cargo fmt|tarpaulin|llvm-cov` returned zero
hits.

Two consequences had already bitten, and neither was noticed by any gate:

- `cargo check --workspace --no-default-features` **did not compile**, although
  `crates/ignis/Cargo.toml` documents that configuration as the off build for the H35 overhead
  bench. `metrics.rs` and `main.rs` referenced `php::park::*` while `park.rs` is
  `#![cfg(feature = "universal-park")]` in its entirety.
- The **PHP unit tests had never executed in CI**. The builder image had no `zip` extension, no
  `unzip` and no `7z`, so `composer install` could not extract a dist archive; both composer steps
  in the smoke job ended in `|| echo`; `smoke.sh` then found neither a vendor directory nor docker
  and printed `skipped`; the job went green.

## 2. The lints chosen, with their measured cost

| lint | level | sites |
|---|---|---|
| `clippy::all` | warn (blocking via `-D warnings` in CI) | 55 |
| `clippy::undocumented_unsafe_blocks` | warn | **92** |
| `unsafe_op_in_unsafe_fn` | deny | 0 |
| `elided_lifetimes_in_paths` | warn | 0 |
| `unused_qualifications` | warn | 26 |
| `dbg_macro`, `todo`, `unimplemented`, `mem_forget` | deny | 0 each |

`warn` in the table rather than `deny`, with `-D warnings` on the CI command line: the gate blocks
either way, but a local run then prints the whole list instead of stopping at the first one, and
`cargo build` keeps working mid-edit. One knob, not two. `deny` is reserved for the four markers
that are at zero and should never reach a commit at all.

**`undocumented_unsafe_blocks` is the expensive choice and it was made deliberately.** CLAUDE.md
already asserted that every `unsafe` block states why it is sound. It did not: 92 blocks had no
SAFETY comment, and `zval.rs` — one of the two modules miri covers — had thirteen blocks and nine
`unsafe fn` with none at all. The lint costs nothing to enforce and cost several hours to satisfy
honestly; the alternative was leaving a documented invariant that was not true.

## 3. The lints rejected, with their numbers

Recording the rejections is most of this ADR's value: it is what stops the argument recurring.

`clippy::pedantic` 347 — of which 282 are `borrow_as_ptr`, `ptr_as_ptr` and the `cast_*` family,
which an FFI crate cannot avoid. `clippy::nursery` 46 — and nursery lints change between releases,
so gating on them means red builds nobody caused. `unreachable_pub` 147 — `ignis` is a binary, so
every `pub` trips it, several of them `#[no_mangle] pub extern "C"`. `missing_debug_implementations`
199 and `missing_docs` 4777 — both dominated by generated bindings.
`clippy::multiple_unsafe_ops_per_block` 113 — it wants one operation per block, which would triple
the SAFETY-comment cost for no gain in safety. `clippy::unwrap_used` 83 / `expect_used` 11.
`clippy::print_stderr` 11 and `print_stdout` 1 — that is the CLI's usage and error output, and it
is correct. `trivial_casts` 13, no autofix, ten of them in the riskiest file, near-zero value.

## 4. `ignis-sys` was not merely broad, it was insufficient

The crate carried `#![allow(clippy::all)]`. That does **not** cover
`undocumented_unsafe_blocks`, which lives in `clippy::restriction`, so 270 warnings from
`bindings.rs` were heading straight into the gate. The bindgen `include!` now sits in a
`mod bindings` whose lints are named one by one, and `pub use bindings::*` re-exports it — which
also means hand-written code in that crate is linted again, where the crate-level blanket had
silenced it.

## 5. rustfmt: 140 columns, measured

The tree is hand-formatted wide — 137 non-comment lines exceed 120 columns. Adoption diff, whole
workspace, at `use_small_heuristics = "Max"`:

| width | diff |
|---|---|
| 100 | 241 hunks |
| 120 | +763 / −254, 19 files |
| **140** | **+357 / −181, 14 files** |
| 160 | +271 / −221, 15 files |

140 halves 120's diff, and unlike 160 it does not re-join lines somebody split on purpose (160
deletes 221 lines against 140's 181). The heuristics matter as much as the width: they lift
`fn_call_width`/`array_width`/`chain_width` off 60, which is what was exploding packed argument
lists. The format-only commit is in `.git-blame-ignore-revs`.

The plan for this work predicted ~130 changed lines at width 120 on a prior agent's measurement; my
own run said +763/−254. The plan's number was wrong and this table is the correction.

## 6. Coverage is reported, not gated — this cycle

A floor on a codebase where more than half the lines are reachable only through a live PHP engine
rewards tests that touch reachable-but-uninteresting code, which is the opposite of what was asked
for. `bindings.rs` alone is 17,944 lines and ~244 instrumentable functions no test calls, so it is
excluded by `--ignore-filename-regex` or the denominator makes the number meaningless.
`csrc/park.c` is not instrumented at all — `cc` reads `CFLAGS`, not `RUSTFLAGS`, and every line in
it runs only under a live engine with an interposed syscall; an honest exclusion beats a fake 0 %.

The PHP side is different and does get a floor, because its unit-testable surface is most of the
library: a fixed floor of `achieved − 5`, set once the number is known, never a "must not decrease"
ratchet — that turns red every time somebody adds a file.

## 7. Kill criteria

- **Toolchain churn.** `-D warnings` plus a floating `stable` means a six-week Rust release can
  turn `main` red with no commit to blame. Mitigated by pinning the toolchain in the CI lint job
  rather than by a `rust-toolchain.toml`, which would make every one of the seven jobs download a
  second toolchain. **If, over four consecutive weeks, more than one red `main` is caused by a
  toolchain change rather than by a commit, the clippy step drops to `warn` and a weekly
  non-blocking job on `stable` takes over the early warning.**
- **A decorative SAFETY comment is worse than none**, because it makes the next reader trust
  something nobody checked. **If a review of any file finds one SAFETY comment that does not
  actually establish soundness, every comment in that file's pass is rewritten before the gate is
  considered accepted.**
- **PHPStan's real count.** If the total exceeds 250, or more than 40 findings are not mechanical
  annotations, the gate ships at level 4 and level 6 becomes its own backlog item. A
  `phpstan-baseline.neon` is never the answer: it is the file that makes "debt to zero" optional.
- **The format commits.** Each is one commit containing nothing else, precisely so that a failure of
  `scripts/smoke.sh` afterwards is reverted by dropping exactly that commit.

## 8. What this ADR does not decide

Rector stays a one-shot local tool, not a gate: PHPStan already covers the overlap and a second
analyser in the blocking path buys churn. The four allocation items in R-REVIEW-CHORES stay out of
this work entirely — they are hot-path claims and this project's rule is "numbers or it didn't
happen", so they need a before/after benchmark and their own V-n.
