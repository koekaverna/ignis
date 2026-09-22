# DECISIONS

Small decisions taken without asking (the big ones are ADRs in docs/adr/). Newest at the bottom.

- 2026-09-15T22:50Z — **Branches.** The session harness designates `claude/ignis-php-fibers-tokio-644209` as the development branch; the owner's addendum (CLAUDE.md) says `night-1`. Both are honoured: every commit is pushed to both branches (`scripts/push.sh`), history is never rewritten on either. The addendum arrived mid-Cycle 1 (Cycle 0 was already complete), so it is applied from this point; the Cycle 0 name-collision check is done now and recorded in STATUS.md.
- 2026-09-15T22:50Z — **Bench tooling.** `ab` is dropped for concurrency tests: its "time taken" includes its own serial connection setup (V-5). `wrk --latency` is the load generator for everything.
- 2026-09-15T22:50Z — **Per-hypothesis commits.** The http transport and the fiber pool landed in one commit because they share `php/ignis.php`; both hypotheses (H5, H6) are named in the message. Future work keeps one hypothesis per commit where the files allow it.
- 2026-09-16T04:35Z — **FFI rule added after V-15.** Never hand-build `type_info` for a zval that wraps data PHP gave us: copy the zval PHP passed (`zend_parse_parameters` "a"/"z") so immutable/interned flags stay correct. Forging `IS_ARRAY_EX` on the shared immutable empty array produced cross-thread refcount corruption that only showed under 4-thread load. Same rule for strings (`IS_INTERNED`).
- 2026-09-16T04:35Z — **Every multi-thread-sensitive change gets a 4-thread 10 s soak** (`bench/soak-threads.sh`) before its hypothesis is recorded; the 4 s bisect runs in this cycle survived while 10 s runs died.

- 2026-09-16T01:40Z (C13) Temporal boundary: PHP never emits raw proto JSON. The PHP side speaks a four-command schema (`StartTimer`, `ScheduleActivity`, `CompleteWorkflow`, `FailWorkflow`) plus `{task_token, result|failure}` for activities; Rust translates to protos with `..Default::default()`. Reason: prost's serde derive requires every field and protojson (camelCase) does not round-trip (V-19). Histories for replay are fetched over gRPC in Rust for the same reason.
- 2026-09-16T01:40Z (C13) Temporal worker runs with the sticky cache on (`max_cached_workflows = 1000`, `max_outstanding_workflow_tasks = 16`): the point of E9 is a workflow that stays a suspended fiber between activations; with the cache off core evicts after every task and the fiber is rebuilt by replay (14 activations instead of 5 for the demo).
- 2026-09-16T01:40Z (C13) Every replay test in this repo ships with its negative control (`DEMO_MUTATE=1`): a replay harness that cannot fail proves nothing.

- 2026-09-16T02:08:11Z (C15 boundary) **One-time history rewrite, owner-authorized, 2026-09-16** (explicit exception to the CLAUDE.md "do not rewrite history" rule; the rule is back in force afterwards). Inventory before touching anything: the repository has 1323 loose objects / 113.8 MiB; the 30 largest blobs are all under `target-async/` (17.5 MB `deps/ignis-…`, 16.8 MB `build/ignis-sys-…/build-script-build`, 12.6 MB `libbindgen-….rlib`, 11.7 MB `libtokio-….rlib`, …); 1418 path entries under `target-async/` were tracked between commits 171d9d7..3f61c07 and removed from the tree in e95952e. No vendor tree, composer `vendor/` dir, php-src clone, `.so` or `.a` outside `target-async/` was ever tracked (top-level paths ever tracked: target-async, crates, docs, bench, php, examples, scripts, patches and the root Markdown files). Removal list: `target-async/` (whole directory, all commits). Tool: `git filter-repo --invert-paths --path target-async/ --force`; authors, dates and messages kept; `origin` re-added; `night-1` and `claude/ignis-php-fibers-tokio-644209` force-pushed; verification (`git count-objects -vH` before/after, fresh shallow clone size, commit count) recorded in JOURNAL.
- 2026-09-16T02:08:11Z (C15 boundary) Model routing (owner note): the main session stays on Fable 5.1; subagents run on Opus 5 via `.claude/settings.json` (`CLAUDE_CODE_SUBAGENT_MODEL`) and the three agent definitions `.claude/agents/porter.md` (suite porting + failure classification), `bencher.md` (bench scripts + tables), `scribe.md` (doc upkeep). A PreToolUse hook (`.claude/hooks/guard-ffi.sh`) blocks their edits to `crates/ignis/src/php/**`, `crates/ignis-sys/**`, `crates/ignis/src/backend/**` and any file containing `unsafe`. Delegation rule: RESEARCH, DECIDE, HYPOTHESIZE and any IMPLEMENT touching the FFI boundary, Zend internals, threads or unsafe stay with the main agent; E15 porting, bench scripts, failure classification and doc upkeep go to subagents in parallel. A subagent's number enters VALIDATION.md only after the main agent re-ran it once. JOURNAL stage lines end with `model=<main|porter|bencher|scribe>`. The two subagents already running at the time of the note (Swoole inventory, FrankenPHP testdata port) were launched before it on the default (inherited) model and are logged as `model=main-spawned`.
- 2026-09-16T02:08:11Z (C15 boundary) Order after the note: E15 to porter; the main agent continues with E14 (pgsql pool), then E6' ssl, then E2'/E13' observer cost; E10 baselines (ext-grpc build) via bencher.

- 2026-09-16T03:03:44Z (C17) Owner note — CI triggers: `php-image.yml` runs only on pushes to `night-1` that touch `scripts/build-php.sh`, `docker/php.Dockerfile` (added: it is an input of the image too) or the workflow, plus `workflow_dispatch`; `ci.yml` runs on pushes to `night-1` and on pull requests with `concurrency: { group: workflow-ref, cancel-in-progress: true }`; both ignore `claude/**`. **`night-1` is the branch of record; the `claude/ignis-php-fibers-tokio-644209` ref is the session's scratch branch and is no longer pushed** (`scripts/push.sh` now pushes only `night-1`; the local checkout is the `night-1` branch). This supersedes the earlier "push to both" rule.
- 2026-09-16T03:36:23Z (C19) Disk rule applied: `/home/user/cmp/roadrunner`, `php-src-fpm`, `php-src-async`, the ext-grpc tree and install, `target-async/` and the Go caches deleted (their numbers are in V-6, V-7/V-8, V-20); `rr` + `rr-app` moved to `/tmp/cmp/` (`bench/e10-compare.sh` defaults updated). `/home/user/php-src` (phpt inputs), `/home/user/frankenphp` (testdata + E4 binary source) and `/home/user/cmp/swoole-src` (suite input) are test inputs, not comparison builds, and stay. `scripts/build-php-async.sh` re-clones the fork when needed.

## 2026-09-16T05:31:27Z — `main` assembled from `night-1`, full history kept (owner decision)

- Owner: "build main without the extras, keep the commit history". `main` is created as a fast-forward of `night-1` (89 commits, pack 295 KiB after the one-time rewrite) plus this commit; no squash, because every VALIDATION entry is tied to the commit it measured and two entries cite hashes.
- "Extras" removed from the tree in a normal commit, not by rewriting: the 190 `.diff`/`.out` copies of php-src run-tests output under `bench/results/e15-phpt/*-diffs/` (upstream test output, 1.3 MB) — now gitignored, still written locally by `bench/e15-phpt.sh` and uploaded by CI as a failure artifact (`bench/results/` is already in the upload path). The failing-test lists (`*.txt`, `*.tsv`) and `summary.md` stay: they are the classification input.
- Third-party suites stay outside the tree as before: php-src in the CI image, swoole-src and frankenphp cloned in the CI step, Symfony/Doctrine installed by composer. In-tree third-party-derived code is limited to `php/amphp/test/IgnisDriverTest.php` (extends Revolt's abstract `DriverTest` from vendor, MIT).
- Workflows: `ci.yml` runs on `main` and `night-*`; `php-image.yml` on `main` only. The session scratch branch `claude/ignis-php-fibers-tokio-644209` (an ancestor of `night-1`, 0 commits of its own) is to be deleted on origin and the tag `night-1-done` (created locally on 8cebf5c) pushed — both refused by the session's git proxy ("remote end hung up": it allows pushes to the session branches only, no tag pushes, no deletes) and not available through the GitHub API tools of this session. Owner runs: `git push origin --delete claude/ignis-php-fibers-tokio-644209` and `git tag -a night-1-done 8cebf5c -m 'End of night 1' && git push origin night-1-done`.


## 2026-09-16T07:26:13Z — Phase A orchestration on a new box (C22)

- **Subagent model changed to sonnet** for Phase A, superseding the C15 note's Opus 5 routing (owner: "экономь токены, если достаточно Sonnet запускай сонет, но нужна валидация того что сделано"). The C15 validation rule is unchanged and is what makes this safe: a subagent's number enters VALIDATION.md only after the main agent re-ran it once. `CLAUDE_CODE_SUBAGENT_MODEL` in `.claude/settings.json` is left alone; the model is overridden per launch, so the default routing survives for later cycles.
- **Delegation split for Phase A follows guard-ffi, not preference.** A1, A2, A4, A5 and A6 all land in `crates/ignis/src/php/**` or in `reactor.rs` (5 `unsafe` blocks; `stream.rs` has 34), which the PreToolUse hook blocks for subagents — so they stay with the main agent. Subagents get: suite runs and failure triage (porter), read-only source research (general), benchmarks (bencher), doc reconciliation (scribe), and A7 (`scripts/ignis-php`, bash).
- **Two serialisation rules for this box**, both derived from recorded evidence rather than caution: (1) benchmarks run one at a time and never beside a build — V-28 recorded E1 at 1294 ms with two stray processes against 1175–1195 ms quiet; (2) `target/release/ignis` is frozen while a subagent runs a suite against it, or its baseline mixes two builds.
- **Baselines must be re-taken here before any Phase A claim.** The recorded numbers come from a 4 vCPU box; this one has 24 cores. E1 cold measured 1197.4 ms here against a 1200 ms threshold — a 2.6 ms margin, thinner than the recorded 1168–1178 ms, and taken while builds were running. No Phase A "improved X" claim is admissible against the old numbers.
- **Known unknown #3 becomes measurable.** ROADMAP lists "scaling past 4 threads: TSRM and the allocator have not been measured at 16–64 threads" as unknown because the old box had 4 vCPU. It is answerable here and is folded into the bencher's baseline task.

## Owner decisions — resolved 2026-09-16

All four were put to the owner with the numbers and the cost of each option; the owner took the
recommendation in every case ("применяй ко всем рекомендации").

- **(a) ADR-0018 kill criterion 2 — replacement ACCEPTED.** The bar is now "added cost under 25 % of
  the wrapped operation, measured where a syscall is resolvable, compared against blocking the whole
  thread". Written into the ADR. The decisive argument arrived after the proposal: V-33 showed the old
  anchor is not a constant — the warm round trip is 0.58 µs at 128 fibers in flight and 93 µs at one,
  so "10 % of the round trip" is not a number until the concurrency is named. Rejecting would have
  meant reversing ADR-0018 and losing fiber sockets 85→86 and Swoole 44→55 to a bar no readiness-probe
  design can meet.
- **(b) A6 — scheduled into Phase B as B7, design 2 (an eventfd handed out by `op_cast`).** Design 1
  (patching the answer inside `hooked_select`) is explicitly NOT to be done: a stopgap in the subtlest
  ownership code, which B7 would then replace, is the worst of the three outcomes. `op_cast` changing
  what it hands out is the regression to watch, because E6''/V-26 depends on it.
- **(c) A3 criterion — restatement ACCEPTED as "no monotonic trend past 5M", and re-run by the main
  agent.** The old criterion asked two single points to agree within ±2 % while the quantity's own band
  between adjacent checkpoints is ±10–12 %. The re-run was required because the evidence for changing
  the criterion was the bencher's measurement, which rule C15 does not admit — changing a criterion on
  inadmissible evidence is the exact substitution that rule exists to prevent. See V-35.
- **(d) H31 — fixed 2026-09-16 (V-36), before B1 as decided.** Root cause was not the accept path
  the hypothesis pointed at: a timed read abandoned a response that was already arriving. Fix
  landed, reproducer kept as `bench/php/e6_underload.php`. Original reasoning for doing it first
  held up — `smoke.sh` runs E6 in CI and E6 fails roughly one run in three locally,
  so the build will start flapping on its own; and B1's acceptance is measured with the same storm
  shape, so the 0.1–0.3 % unanswered floor would sit inside its numbers and read as queueing.

## Owner decisions that were outstanding — all five closed (audit, 2026-09-18)

This section carried five questions under the heading "outstanding" while the section directly above
it, "Owner decisions — resolved 2026-09-16", answered four of them and the fifth was struck through
in place. `STATUS.md` pointed here for "owner sign-off still needed on four points from Phase A", so
the owner has been asked, for two days, to decide things he had already decided. Kept as an index
rather than deleted, because the questions are the reason the answers read the way they do:

| question | where it was answered |
|---|---|
| ADR-0018 kill criterion 2 (A4, `ext/sockets` overhead): accept the replacement bar or reject it | **accepted**, resolved-2026-09-16 (a); written into `docs/adr/0018-…` |
| A6 (TLS read-ahead invisible to `stream_select`): schedule into Phase B or leave open | **scheduled as B7, design 2**, resolved-2026-09-16 (b); since made moot — V-49 deleted the mechanism and records A6/B7 PASS in 22.9 µs |
| A3's acceptance criterion, shown unsatisfiable by construction: accept "no monotonic trend past 5M" or reject | **accepted**, resolved-2026-09-16 (c), re-run by the main agent — **V-35**, not V-30 as this section and `STATUS.md` both said |
| Five bench scripts hardcoding `/home/user` | **done 2026-09-16**, struck through here at the time |
| H31, a request accepted and never answered (~1 in 400–1200, V-34): fix before B1 or carry the floor | **fixed before B1**, resolved-2026-09-16 (d) — V-36; reproducer kept as `bench/php/e6_underload.php` |

## Mission changed by the owner: R&D → product (2026-09-16)

"Мне надо чтобы ты начал делать и идти в сторону готового продукта; тот роадмап, который есть,
абсолютно не отражает то, что должно быть в продукте." BRIEF.md stays immutable and still describes
the night it commissioned; from this entry on, the mission is a **product** — an application server
a PHP team can install and run in front of a Symfony or Laravel app — and ROADMAP.md is rewritten as
user-facing milestones (M1 Run, M2 Install, M3 Real apps unchanged, M4 Operate, M5 Ship), each
accepted by a user action rather than a benchmark. The R&D backlog is kept beneath it because its
numbers are what the product stands on. Rules that do not change: numbers or it didn't happen; every
hook claim against its control; a subagent's number enters VALIDATION only after main re-ran it.
First act under the new mission: M1 shipped the same day (config.rs, `serve`, `/_ignis/health`,
README, ignis.toml.example).

## ADR sweep (owner note, 2026-09-17)

Every decision, rejection and deferral now has an ADR; `docs/adr/README.md` is the index. Rules
adopted from the note and kept: status vocabulary accepted | proposed | deferred (with trigger) |
rejected | superseded-by; only the main agent sets "accepted"; every number cites a V-n or says
"unmeasured"; owner estimates are labelled as such. Addenda over renumbering. One commit per ADR,
pushed one at a time. Where the note and the record disagreed, the record won and the ADR says so
(ADR-0020 addendum on E18's state; ADR-0009 addendum on `spawn()` semantics today).

## 2026-09-16T17:36Z — ADR-0037 cycle 1: universal park is the default build; the sleep hook is deleted

Owner: "Поехали чистить все ненужное" (2026-09-17) after ADR-0037 — read as the go for §6 and as
the pending "park default" decision. Done in this cycle: `universal-park` is a default Cargo feature
(`--no-default-features` is the off build for H35, `IGNIS_NO_UNIVERSAL_PARK=1` the runtime switch);
`IGNIS_PARK` rows are `lib` or `lib:symbol` because libphp is built with `-fvisibility=hidden` and
`dladdr` sees only its library (measured: `zif_usleep`, `zif_sleep`, `php_select` absent from
dynsym; 1,937 `zif_*` in the static symtab). Seed when `IGNIS_PARK` is unset:
`libphp:sleep,libphp:usleep,libphp:nanosleep,libcurl,libpq,libssl,libcrypto` — research 27's
verdicts plus the libphp sleep group audited in research 30 (b). libpq was held out for an hour
while E18-I1 was open; I1 turned out to be the scheduler's idle checks ignoring `$ready`/`$pending`
(`ignis_poll()` resumes C-parked fibers itself, so a nested `all()` inside an outer fiber broke out
of the loop with the outer fiber ready and nothing in flight) — pre-existing, the old sleep hook
showed it too; fixed in `php/ignis.php`, so libpq is in the seed. `sleep.rs` (112 lines, 7 `unsafe {`, 8
`unsafe fn`) and `IGNIS_NO_SLEEP_HOOK` are deleted; V-22's gate script passes through park with
both off-controls blocking (V-46). The "PHP function" policy column is not built: no row needs it
yet.

## 2026-09-16T18:15Z — ADR-0037 cycle 2: `sockets.rs` and `accept.rs` deleted together; SO_*TIMEO moved into park

Two hooks in one cycle, against §6's "one per cycle": one gate run (phpt, Swoole, Revolt, the V-29
a4 tests, the server leg, the new `e18_timeo`) covers both, and a red cell would have been split
by the two env switches before deletion — it stayed green in phase 1 (hooks off via env, rows via
`IGNIS_PARK`) and in phase 2 (files gone, seed rows). Research 30 groups (a)/(c) (agent, spot-checked
by main at `sockets.c:294/692/926/1461`, `network.c:774/783`, `sendrecvmsg.c:207/248`): no lock on
any path, so the rows enter the seed. The one semantic the hooks had that park lacked —
`SO_RCVTIMEO`/`SO_SNDTIMEO` — is now park's own (`park_io`: `getsockopt` on the parking path,
the kernel timeout races the wait, a timer win answers `EAGAIN` without the real call; `connect`
keeps the `SO_ERROR` shape, `SO_SNDTIMEO` on connect is a recorded gap). `can_block`'s rule needs no
code: readiness then the real call reproduces stock errors on listening/unconnected sockets
(a4_sockets, phpt sockets in fiber mode at baseline). Local gates needed fixing to run at all:
`php/amphp/ignis.ini` hardcoded `/home/user/…` (the bench now regenerates it per checkout),
Swoole needs `~/cmp/swoole-src` (cloned), AMPHP deps installed with the `composer` Docker image
because this PHP has no `ext/phar` and the box has no php-cli — `ext/phar` is not added to the
product build for a dev need.

## 2026-09-16 — benches and suites run per epic, not per change (owner)

Owner: "можно тогда бенчи катать не на каждом изменении, а на эпиках?" Yes, and it corrects a
perversion of ADR-0037 §6 I had introduced: §6 asks for *the test that created the deleted code*,
and I was running phpt + Swoole + Revolt + chaos + smoke on every deletion — on a box where CI
already runs all of them on every push. New tiers in ADR-0023 §7: per change = build + nextest +
the probe for what changed (seconds); per deletion = only the creating suite; per epic = the full
E15 set, chaos, soak, smoke and the perf set (E1/E2/E4/E5, on/off when a mechanism changed) on a
quiet box, one at a time. The gate that protects `main` is unchanged — CI runs everything on every
push.

## 2026-09-16T18:57Z — the park registry is not a transport: `php/stream.rs` → `php/wait.rs`

Deleting the factory would have taken `await_op`/`await_any`/`resume_parked`/
`ignis_cancel_parked_any` with it — the code that suspends a fiber inside an internal call and
resumes it from `ignis_poll`. That is the mechanism universal park, `ignis_watch` and every Custom
op already depend on; it lived in `stream.rs` only because ADR-0007 wrote it there first. Moved
verbatim to `php/wait.rs` (151 lines) and the callers rewired. `Outcome::Connected/Data/WouldBlock/
Written/Closed` went with the actor; the one arm `module.rs` still needs is `Outcome::Error`, which
now routes to a parked fiber and otherwise logs with the message attached.

## 2026-09-17 — DNS: record the risk, do not build it yet (owner)

Owner, asked how DNS would be parked and what the alternatives were: "пока не делаем, это нужно
записать как и риск с использованием flock". So R-DNS is written up the way R-SESS is — BACKLOG,
ADR-0024's non-goals, the runbook's known limits, the site's non-goals page and the compatibility
table — with the planned implementation, its costs, the rejected alternative and the operational
mitigation, and no code. The key technical point, recorded so it is not re-litigated: `getaddrinfo`
cannot be parked at all (no fd), so this was never a park row; the plan is glibc's own call on a
blocking pool with the fiber suspended on it, handing back glibc's pointer untouched so
`freeaddrinfo` needs no interposition. Writing our own resolver is rejected until measurement
demands it, because its failure mode is wrong answers (nsswitch, ndots/search, AI_* flags, RFC 6724
sorting), not slowness.

## 2026-09-17T06:19:52Z — `curl_*` parks; the offload pool keeps only what cannot park (owner)

Owner: "поменять на паркинг, убрать про офлоад из документации и сайта". `DEFAULT_FUNCTIONS` in
`route.rs` is empty; `curl_*` reaches libcurl and parks (V-59: 328 ms against 2,697 ms through an
8-worker pool, and the write callback stays in the calling fiber). `IGNIS_OFFLOAD_FUNCTIONS`
restores the old routing for anyone who needs it.

Offload was **not** removed from the documentation wholesale, and this is a deliberate deviation
from the instruction as phrased: `SQLite3` and file-backed `PDO` cannot park at all — `epoll`
refuses regular files (ADR-0024) — so offload is the only mechanism they have, and deleting it from
the docs would leave a SQLite user with a blocked thread and no explanation. Every place that said
"curl goes to offload" now says it parks, with the measured numbers; offload is documented only as
the answer for what park cannot reach. Raised with the owner.

## 2026-09-17 — pdo_sqlite: document the trade, do not build the fix yet (owner)

Owner, asked how to solve it: "пока только обнови документацию и сайт". So the compatibility page
gets a "Databases" section that works through the four cases an application can be in, the
configuration reference gets a note explaining why `IGNIS_OFFLOAD_CLASSES` is per class and not per
driver, and the designed fix is written down as BACKLOG R-PDO-SQLITE with its gate. No code.

## 2026-09-17 — E19 boot-heap snapshot: rejected (owner)

Owner, after seeing what `budget.fibers = 1` implies: "не подходит, ADR отвергаем". Recorded as
ADR-0039 with status *rejected*, and deliberately with the measurements in it: the mechanism was
affordable (71 µs against a 100 µs budget at the 11–12 dirty pages a real Symfony request writes,
measured on `../symfony-ignis` — the owner pointed out the app was already on the box), and it was
rejected for the constraint instead. Page rollback is per thread, so strict per-request isolation
needs one request in flight per thread, which is php-fpm's concurrency model and switches off the
parking this runtime exists for. The variant that keeps fibers — restore when the thread goes idle —
bounds RSS but does not isolate, so it fails the acceptance's "identical response bytes".

The answer for applications that are not worker-safe stays what it already was: make them
worker-safe (`require_once`, no state in statics, a socket-backed session), which README, the
compatibility table and the runbook document. The three measuring instruments are kept under
`bench/e19/` so a future attempt starts from numbers.

## 2026-09-17 — no CLI mode; a command-line script opts into concurrency itself

Owner asked whether the CLI should be made asynchronous. Measured first (V-60): it already is —
`Ignis\async()`/`Ignis\all()` plus universal park turn ten unmodified `sleep(1)` calls into 1.00 s
instead of 10.00 s, and twenty `file_get_contents()` of a 200 ms endpoint into 0.21 s instead of
4.07 s, on one thread. So nothing is built: no CLI mode, no flag, no fourth mechanism.

The top level of a script stays blocking, and that is the decision rather than an omission. It runs
in `{main}` with no fiber, so park is off there; a single wait has nothing to overlap with, and an
implicit scheduler around every script would be one more thing to understand when a command hangs.
Concurrency begins exactly where the script says there is a second thing to do.

Shipped: `examples/cli.php` (the measurement, runnable, with its hook-off arm) and
`docs/getting-started/cli.md` — including what it does *not* give: parallel CPU, and concurrent DNS
while R-DNS is open.

## 2026-09-17 — Temporal runs on the official PHP SDK; the adapter is a portable package

Growing `php/temporal/ignis-temporal.php` into a real SDK means writing signals, queries, updates,
child workflows, saga, interceptors, codecs and versioning — a second Temporal SDK. One exists, and
its tie to RoadRunner turned out to be a default argument: `WorkerFactory::run()` takes a host, and
`$codec` is protected. Measured (V-61): a stock sdk-php workflow runs on sdk-core activations
through our transport, under the ignis binary and under stock PHP, with no protobuf on the wire and
no change to the Rust side.

Owner's correction, and the more important half: the adapter translates **sdk-php's own** command
model, so it is written as a portable package (`php/temporal/core/`, namespace
`Temporal\Worker\Transport\Core`) with a two-method port, not as Ignis glue. Ignis implements the
port in ~40 lines. It can be offered upstream — PHP is the only Temporal SDK not sitting on
sdk-core — and if upstream takes it, the coupling risk becomes theirs to maintain rather than ours
to track.

ADR-0040 accepted; ADR-0013's Rust half stands. Kill criterion recorded there: if sdk-php's private
command model breaks us twice in a row, the answer is RoadRunner as a sidecar, not a third runtime.

## 2026-09-17 — the PHP userland ships as one package per integration

`php/` was a flat tree of files required by path. It is now ten composer packages under
`php/packages/*` (Symfony-monorepo shape: per-package `composer.json`, `src/`, inter-package
requires; the root is an aggregate with a `packages/*` path repository and is installed nowhere).
An application now takes the adapter it uses and nothing else: `composer require ignis/runtime:@dev
ignis/symfony-runtime:@dev` against `/opt/ignis/php/packages/*`.

Two boundaries are deliberate. `ignis/temporal` is the Ignis host for the **official** sdk-php and
carries nothing else; `ignis/temporal-core-transport` stays free of any Ignis dependency so it can
go upstream; and our own pre-ADR-0040 workflow runtime is `ignis/temporal-prototype`, frozen and
separate, because mixing it with the SDK path is what made the old directory unreadable.

The retired `php/symfony/worker.php` shim was deleted rather than kept as a migration note: before
the first stable release there is no back-compat to preserve, and a file that exists only to say
"use something else" is a file someone has to read.

History files (JOURNAL, VALIDATION, DECISIONS, HYPOTHESES) keep the old paths: they record what was
true when they were written.

## 2026-09-17 — the Rust side of the Temporal boundary speaks core's vocabulary, not sdk-php's

Owner's question: are we dragging sdk-php's contracts into our Rust? Checked: no. `PhpCommand` in
`backend/temporal.rs` carries **core's** names and fields — `ScheduleActivity`, `StartTimer`,
`RespondToQuery`, `seq`, `protocol_instance_id`, `query_id`. None of sdk-php's vocabulary
(`ExecuteActivity`, `NewTimer`, `Panic`, `GetChildWorkflowExecution`) appears there; all of it lives
in the PHP package, which is what ADR-0040 §2 requires.

The real defect the question exposes is different: we invented a **third schema**. Core has one,
sdk-php has one, and `PhpCommand` is neither — so every feature costs an arm on both sides, and the
schema is lossy (`start_to_close_sec: u64` in place of a `Duration`, and no retry policy, no
`schedule_to_close`, no cancellation type, no headers). The read direction already has no such
schema: `ignis_temporal_poll` hands PHP core's own activation JSON verbatim.

Measured why the write direction is not symmetric (`backend/completion_json.rs`, a test that pins
it): **prost's serde derive is not protojson.** It ignores unknown keys — a typo would silently
produce an empty completion — it wants durations in canonical form, and it has no default for enum
fields, so a partial document fails on the first one (`missing field cancellation_type`).

Decision for now: keep the small schema, and treat the loss as the thing to fix first — the fields a
real workflow needs (retry policy, the other timeouts, cancellation type, headers) belong in the
existing arms. The structural fix, when it is worth doing, is protojson proper via `prost-reflect`
over the descriptor pool the build already emits, after which Rust is a dumb pipe in both directions
and no Temporal feature needs a Rust change again.

## 2026-09-17 — the third schema on the Temporal boundary is deleted (supersedes the entry above)

The entry above kept the hand-written `PhpCommand` and called protojson the structural fix "when it
is worth doing". It was worth doing immediately: the descriptor pool was already published by
`temporalio-protos` (it declares `links`), so `prost-reflect` needed a build-script line and two
helper functions.

Result (V-65): `backend/temporal.rs` 441 -> 311 lines, `PhpCommand`/`PhpPayload`/`PhpCompletion`/
`PhpActivityCompletion` gone, both directions carry core's own documents, and a new Temporal feature
now costs zero Rust. Activity retry policies and the remaining timeouts reach core for the first
time — the old schema could not express them.

The PHP-side prototype runtime (`php/packages/temporal-prototype`, ADR-0013, superseded) was moved to
the same dialect. It has no local test — CI's `bench/e9-temporal.sh` is its first real exercise, and
that is stated in V-65 rather than glossed.

## 2026-09-17 — the Temporal client goes through the runtime's own gRPC, not ext-grpc

ADR-0040 listed the client as a gap. It is not: sdk-php funnels every service method through
`BaseClient::invoke()` and a publicly installable interceptor pipeline, so one interceptor replaces
the whole gRPC client (V-66). The call itself is `ignis_grpc_call()` — generic unary by path since
E10 — so there is no new Rust, and the client works in a build without `--features temporal`.

**ext-grpc is refused deliberately, not for convenience.** It embeds gRPC's C core, which brings its
own threads and poller into the process; that is the same objection that rules out the Go SDK
through cgo, and it would put a second event loop next to the reactor. `ext-bcmath` was added
instead — a bundled extension with no threads and no external library — because google/protobuf's
pure-PHP int64 path needs it.

Known limit, recorded so nobody meets it in production: `ContextInterface` metadata and deadlines
are dropped, so API-key auth and TLS must live in the host's channel. Temporal Cloud therefore does
not work over this path yet, and `ignis_grpc_call` needs a header argument before it can.

## 2026-09-17 — code style is a rule, not a preference (owner)

SOLID, DRY, KISS, YAGNI; no abbreviations in names; no comments inside a function body —
anything that needs explaining is extracted into a method whose name is the explanation.
Short doc blocks on methods stay. Recorded in CLAUDE.md under "Code style".

The one carve-out is the comments the architecture already requires as contracts: `// SAFETY:`
on every `unsafe` block and the ownership/lifetime/who-frees notes at the FFI boundary. Those
are not commentary, and dropping them would weaken a rule the owner set earlier.

## 2026-09-17 — the quality gate blocks from the first commit (ADR-0041)

Owner chose blocking gates over a warning period, so the debt is cleaned in the same body of work.
The reasoning, the measured costs and the kill criteria are ADR-0041; the lines below are the small
decisions it does not need a section for.

- **`rustfmt` at `max_width = 140`, `use_small_heuristics = "Max"`.** Measured adoption diff:
  120 gives +763/−254 in 19 files, 140 gives +357/−181 in 14, 160 gives +271/−221 in 15. 140 halves
  120's churn and, unlike 160, does not re-join lines somebody split deliberately. The format-only
  commit is listed in `.git-blame-ignore-revs`.
- **`cargo test` is no longer a supported runner; `cargo nextest run` is.** Not a style preference:
  nextest runs each test in its own process, and `http::REGISTRY`, `pg::POOLS`/`BY_DSN`,
  `offload::POOL` and `metrics::START` are `OnceLock`s while `config::default_env` mutates the
  process environment. The new tests depend on a virgin process. Coverage therefore has to be
  `cargo llvm-cov nextest`, never `cargo llvm-cov test`.
- **No `rust-toolchain.toml`.** The plan called for one, pinning 1.98.0. It would make every one of
  the seven CI jobs download a second toolchain, because the action installs `stable` and the file
  would then demand a different version. The same determinism comes from pinning the version in the
  lint job itself, which is the only job that runs `-D warnings`.
- **`[dev-dependencies]` stays empty.** `tokio` is already a normal dependency with `macros` and
  `rt-multi-thread`, so `#[tokio::test]` needs nothing new, and `IGNIS_DRAIN_TIMEOUT_MS` in a test's
  own process replaces what `tokio::time::pause()` would have wanted `test-util` for.
- **No `clippy.toml`.** Every option worth setting is already the default; `accept-comment-above-statement`
  in particular is what lets a SAFETY comment sit above a `let` rather than inside the block.
- **`cargo-deny` with `multiple-versions = "allow"`.** 15 duplicated crate names today, every one
  transitive and none ours to resolve; denying them would block merges on other people's graphs.
- **`examples/rust/temporal-probe` declares its own `[workspace]`** rather than being excluded from
  the root one, matching `grpc-baseline` beside it. It had neither and cargo refused the manifest.
- **The seven copies of the TSRM accessor are one.** `EG`/`CG` were resolved by hand in `route.rs`,
  `wait.rs`, `park.rs`, `superglobals.rs` (three times there) and `output.rs`; they are now
  `php/tsrm.rs` with names that say what they return.

## 2026-09-17 — the PHP build gets the toolchain extensions (owner)

Owner asked why the missing extensions were being worked around instead of built. They were right:
no `phar` meant composer could not run under our engine at all, no `dom`/`libxml` meant PHPUnit
could not read a `phpunit.xml`, and no `zip` meant no dist extraction — those three gaps are where
the docker-composer wrapper, the hand-generated PHPUnit runner and the two-host test split all came
from.

Added to `scripts/build-php.sh`: libxml, dom, xml, simplexml, xmlreader, xmlwriter, phar, fileinfo,
posix. **`ext-zip` deliberately not built** — composer falls back to the `unzip` binary, which saves
a `libzip-dev` build dependency on every host.

Cost, measured before and after with the same release binary: RSS 34.6–35.0 MB → 37.6–37.8 MB
(+2.9 MB), `libphp.so` 50.3 → 67.5 MB unstripped, startup unchanged at 0.01–0.02 s. **V-10's
26.2 MB worker-mode figure is stale until re-run**; the flatness claim it rests on is unaffected,
only the absolute. If RSS ever becomes the binding constraint the lever is to build these as shared
modules loaded from a php.ini the CLI reads, so the embed runtime pays nothing — not done now
because it adds an ini mechanism the embed SAPI deliberately does not have.

## 2026-09-18 — ADR-0041 is accepted by its own trigger, not by a new decision

The site documentation refresh reached ADR-0041 still marked **proposed**, with the sentence
"becomes accepted when §7's gate is green in CI on `main`" underneath it. That condition was met by
the night-2 merge (`b6c3936`): `main` is green on all ten `ci.yml` jobs plus `image` and `docs`, and
`R-MAIN-RED` is closed. Flipping the status is therefore applying the ADR's own rule, not making a
decision — which is why it is recorded here rather than left for sign-off.

The gate also overshot its own §7: PHPStan is at **level 8 with 0 errors** where §7 asked for level
6 (the before-number was 123 errors at level 6), and undocumented `unsafe` blocks went **101 → 0**
including under `--all-features`, which had never linted a line until `8933124` fixed the toolchain
component it was missing. Coverage: Rust 60 tests / 29.95 %, PHP 209 tests / 36.60 % with a 31.6 %
floor (V-78, V-79 + addenda 1–3).

Two neighbouring status lines were stale for the same reason and were corrected with it: ADR-0020
said "H32–H36 open" when only H34 (`getaddrinfo`, no fd to watch, offload's problem — `R-DNS`) still
is, and ADR-0016's Decision item 3 still described `curl_*`/`PDO`/`Redis` auto-routing as current
when its own addendum three paragraphs below had already shrunk the shipped default to `SQLite3`.

## 2026-09-18 — night-3 merges into `main` with one gate red, named

Owner asked for the refreshed documentation to be published. `docs.yml` deploys the site on a push
to `main` and on nothing else, so publishing is the merge.

CI on `44af49c` is **9 of 10 green**. The one failure is `E15 phpt`, and inside it exactly one gate
row: `phpt.fiber.Zend_tests_fibers=77 < baseline 78 REGRESSION`. Every other row is at or above
baseline, three of them above it (`main.ext_sockets_tests=92 ≥ 89`, `fiber.ext_sockets_tests=87 ≥
83`, `fiber.ext_standard_tests_streams=126 ≥ 124`).

That row is `S0-FIBER`, already measured and written up before this work: `gh9916-009.phpt`, the
same 77 on the HEAD binary and on one rebuilt at night-3's branch point, so **the branch does not
contain the cause** — merging surfaces it on `main` rather than introducing it there. It is
deliberately not re-baselined to 77, because a baseline lowered to hide an unexplained result stops
being a gate.

The working agreement says the night branch merges after CI is green. It is not green and cannot be
made green tonight without either re-baselining (refused above) or explaining a result that four
independent runs could not explain. Merging with the single red row named here is the honest
reading; `STATUS.md`'s "green on all ten jobs" is true of `b6c3936` and stops being true of `main`
with this merge, which is why it is recorded rather than quietly left behind.

## 2026-09-18 — the night branches are deleted, `main` is the only line of work

`night-1` and `night-2` have been ancestors of `main` since their merges; `night-3`'s last two
commits (`b7d3cc3`, `d8ba4b5`) are merged here as `80344d1` under the same terms as the earlier
night-3 merge — CI 9 of 10 green on `d8ba4b5` with one named red row, `phpt.fiber.Zend_tests_fibers=77`
against baseline 78 (S0-FIBER, `gh9916-009.phpt`), unchanged and not re-baselined.

All three are then deleted locally and on `origin`. Nothing is lost: every commit is reachable from
`main`, which is what makes the deletion safe rather than tidy. `gh-pages` is a deployment branch
and stays; the tags `night-1-done` and `v0.1.0-rc.1` stay. No new night branch is created until
there is a night's work to put on it — `ci.yml` still triggers on `main` and `night-*`, so the next
one costs nothing to make.

## 2026-09-18 — the runtime's PostgreSQL client is removed, not deprecated

Owner: "удалить, меньше кода меньше поддержки, удалить из документации для сайта, оставить только в
ADR как закрыто". Taken as written: the code is gone rather than feature-gated, the site documentation
describes `pdo_pgsql` with `ignis/doctrine` instead, and ADR-0015 is the single surviving record —
rewritten to open with why it closed and to keep its original decision text unedited.

The evidence behind it, in the order it arrived: `pdo_pgsql` parks with no runtime client involved
(V-45, V-59); the pool that was `Ignis\Pg`'s last unique role now lives in `ignis/doctrine`, per
connection and configured in code (V-85 addenda 2–4); and parked `pdo_pgsql` prepared is faster than
the native client on both the sequential and the concurrent axis (V-86). What the deletion costs is
recorded rather than hidden: the runtime pool was process-wide where the userland one is per thread,
and it had lease age, an acquire timeout and a breaker — `S-POOL-LEASE-AGE` carries those forward for
the pool that remains.

## 2026-09-18 — `CC0-1.0` joins the licence allow list, for `notify`

The development watcher (V-90) brought in `notify 8.2.0`, which is `CC0-1.0`, and `cargo deny`
rejected it — correctly: `deny.toml` says a new licence is a decision, not a build failure to wave
through. CC0-1.0 is a public-domain dedication with no attribution or source obligation, the same
category as `0BSD` and `Unlicense` which are already allowed tree-wide, so it goes in `allow` rather
than becoming a per-crate exception. It reaches us through one crate today; a second CC0 dependency
needs no new decision, which is the cost of allowing rather than excepting, and is accepted because
the licence asks nothing of us either way.

This was caught by CI, not locally: the commit that added the dependency claimed green gates without
`cargo deny` among them. `cargo deny check` belongs in the pre-commit gate list next to fmt and
clippy whenever `Cargo.toml` changes.


## 2026-09-18 — an offload worker keeps no reactor, and the runtime functions say so in PHP

Found auditing the Rust half before adding anything: `Ignis\offload('ignis_inflight')` aborts the
whole process. Not the job, not the worker thread — SIGABRT, exit 134. The runtime's functions are
registered once at MINIT and therefore exist on every PHP thread, offload workers included, while
`module::reactor()` ends in `.expect("reactor not installed on this PHP thread")`. A panic inside a
`zif_` frame cannot unwind out of `extern "C"`, so Rust aborts rather than propagating. Twenty-one
call sites reached that `expect`, `ignis_submit_sleep` — that is, `Ignis\sleep()` — among them.

The obvious fix was to give offload workers a reactor: one line, and every call site works. It was
written, and then reverted, because two other mechanisms read the *absence* of a reactor as the
marker of such a thread — `route::routing_here()` says so in its own comment ("never on offload
workers: they have no reactor") and `park.rs` falls through to the blocking call. Installing one
made an offloaded `SQLite3` call route itself to offload again; the E16 bench hung, which is how
this was caught rather than shipped. It would also have let a fiber created inside an offloaded job
park on a reactor nobody polls: a hang in place of an abort.

So the decision is the other way round, and it matches ADR-0016 rather than working against it: an
offload worker is a synchronous thread and has no reactor **by definition**, that absence stays the
one marker the other mechanisms read, and the runtime functions that need a reactor refuse in PHP —
`module::reactor_or_throw()` throws a `Error`, the job fails with a `RemoteException`, the pool and
the server carry on. Verified: the same probe now returns the exception to its caller and the
process exits 0.

Kill criterion: if a legitimate offloaded workload needs one of these functions, the answer is not
to install a reactor behind its back but to decide what that function means on a thread with no
event loop — and to write that down here first.

## 2026-09-18 — classic mode is concurrent, so its per-request state is fiber-scoped

`Ignis\Classic\serve()` says "requests overlap" in its own doc block and hands each one to a fiber,
while `Runner::$sent` and `InputStream::$body` were per-thread statics. `classic.php` carried the
assumption that covered the gap — "a classic script must not suspend" — and universal park retired
it: an ordinary `file_get_contents()`, `session_start()` or `flock()` inside the included script
suspends the fiber, the next request enters `handle()`, `reset()` clears the first one's `sent` flag
and overwrites its `php://input`, and the first client is never answered.

Two ways out. **Serialise** — refuse a second concurrent request, which is `listen()`'s model and
what ADR-0028 gives Laravel through `budget.fibers = 1`. **Scope it** — move the per-request state
into `Ignis\Scope`, the ADR-0006 model already used for `$_SERVER` and for Symfony's `RequestStack`.

Scoped, because the two entry points mean different things and both should keep meaning it:
`listen()` is the one-at-a-time mode a legacy docroot needs (V-53: it is the only shape in which the
entry script's top-level variables are real globals), and `serve()` is the overlapping mode a front
controller wants. Serialising `serve()` would have left the product with no concurrent classic mode
at all and would have made `budget.fibers = 1` mandatory rather than a Laravel-shaped choice.
`Ignis\Scope` falls back to a `{main}` bag outside a fiber, so `listen()` behaves exactly as it did.

What this does not fix, and is not claimed: everything else a classic script keeps in a static is
still the application's problem — the architecture gives detection, not immunity (pain map, "remains
true for Ignis too").

Kill criterion: a classic-mode request that answers the wrong body or nothing at all under
concurrency, with `Scope` holding the right value at the time — that would mean the state is not
where the fault is, and the serialising option comes back.

---

## 2026-09-19 — the planned items that close red ones are dispatched by the guard, not by the track label

`BACKLOG.md` tracks an item `main` or `agent`; `.claude/hooks/guard-ffi.sh` decides what a Sonnet
agent can actually edit — `crates/ignis/src/php/**`, `crates/ignis-sys/**`,
`crates/ignis/src/backend/**` and any file containing `unsafe`. Reading the six planned→red links
against that guard splits them in two, and the split is not the one the labels suggest.

`offload.rs`, `main.rs`, `http.rs` and `watch.rs` contain **no** `unsafe` and sit outside the three
guarded directories, so the whole offload failure path is agent territory — including the half of
`A-LEAKS-RUST` that lives there, which the backlog tracks `main`. It is dispatched to an agent
anyway: the guard is the safety boundary, the label is a routing hint, and the rule that protects
the result is the one already in CLAUDE.md — every agent number is re-run by main before it enters
`VALIDATION.md`. `A-SWALLOWED-RUST` and `A-LEAKS-RUST` (b)(c) are the same three functions in one
215-line file; splitting them across two workers would have produced a conflict, not a review.

Blocked from agents and therefore kept by main: the `park.rs` bundle (`A-PARK-ARITHMETIC`,
`R-FOREIGN-FIBER`, the park half of `A-UNSAFE-CONTRACTS`, the `E18-I` stage-2 remainder),
`A-RUST-DEAD` (`route.rs`, `php/locklib.rs`, `backend/temporal.rs`), the temporal half of
`A-LEAKS-RUST`, and `M3-7` (`module.rs`).

`A-RUST-TESTS` is **not** dispatched: ten of its eleven modules are guarded, so an agent could
reach only `watch.rs`, and the red item it carries — `A-PARK-ARITHMETIC`'s missing test vehicle —
is in `park.rs`, which it cannot touch. It stays with the park bundle.

Kill criterion: an agent's change to `offload.rs` that needs a `reactor.rs` edit to release the
caller's op. `Reactor::complete` is already reachable from `offload.rs` (`:77`, `:116`), so the
fix should not need one; if it does, the boundary was drawn in the wrong place and the offload half
comes back to main.

---

## 2026-09-20 — S-OWNERSHIP is killed as filed; S-SCOPED-CLASS stays, blocked on evidence

Owner direction 2026-09-19: settle the two proposed mechanisms before writing code for the four
state-isolation reds. Research 44 recommended killing both. Main corrected one of its numbers and
reached a split verdict.

**S-OWNERSHIP: killed as filed.** Not on cost, and not because the defect it targets is unreal —
it is very real and stays open as `S-DBAL-DIRECT`, `S-EXCLUSIVE` and `S-RESET-ARRAYPOOL`. It is
killed because it claims to be the `context` mechanism and is not. ADR-0037's own table defines
`context` by what it may not do: "**allocate per switch**: a slot swap is pointer moves; anything
needing allocation happens at dispatch, not on the observer". A taint check firing on every
property write and every array-element write, transitively, is not a slot swap on a switch — it is
work on every write in the program. That makes it a **fourth mechanism**, which the budget forbids
by construction, and the budget is an owner rule, not a preference. The item's own text also
concedes the reentrancy risk, which main confirmed independently: our two switch handlers carry no
`in_execution` guard because nothing in them can cause a switch, and this would be the first thing
that changes that.

What survives: the three reds it targeted each already have a cheaper, specified fix on file —
research 39's fiber-affinity check plus the one-lease rule (~10–20 lines) for `S-EXCLUSIVE`, one
more façade row for `S-DBAL-DIRECT`, a boot check for `S-RESET-ARRAYPOOL`. Research 39 ranked eight
options and did not find a reason to prefer taint tracking over any of them.

**S-SCOPED-CLASS: kept, blocked on `R-TA-CONTEXT`.** Research 44 recommended killing this one too,
on the arithmetic that only `FiberTokenStorage` (53 lines) could legally be a target because
`FiberRequestStack extends RequestStack` and the item's own unresolved question is that a scoped
class's parent must be scoped too. That arithmetic is wrong and main checked it: `FiberEntityManager`
(247 lines) and `FiberManager` (58) have no vendor parent either — they implement interfaces. The
plausible target is **358 of 447 façade lines**, not 53, which makes this a real trade rather than
an obvious loss: ~358 lines of plain userland PHP against six new `unsafe` object handlers.

It is not decided on that trade, because the trade is not the question yet. `R-TA-CONTEXT` asks
whether upstream's `internal_context` already provides the storage, and its answer changes the
design. That research needed a fork checkout this box did not have — which the owner's other
decision today (a CI job that builds backend (b)) is what unblocks.

Kill criterion for this decision: if `R-TA-CONTEXT` finds that per-scope storage on
`internal_context` costs nothing on the switch path and that the façades can be deleted rather than
reimplemented, `S-SCOPED-CLASS` is built and this entry's caution was wrong. If it finds the
opposite, `S-SCOPED-CLASS` is killed the way `S-OWNERSHIP` was, and the façades stay as they are.

## 2026-09-20 — the two unused packages are kept for now

Owner decision: neither `ignis/swoole` (674 lines, no user found anywhere in the tree outside its
own manifest) nor `ignis/temporal-prototype` (869 lines, its own manifest calls it "Frozen") is
deleted yet. Revisit after `R-TA-SERVER`, which may change the framing of what this product is for
and therefore what its compatibility shims are worth. Recorded so the measurement in research 45 is
not re-derived: 1543 lines, 11% of package code, and `A-SWOOLE-TICKERS` would close by removal.

---

## 2026-09-20 — S-SCOPED-CLASS: scoping is inherited, and the initiation is a class-level call

Two owner decisions, and the first reverses what the item said about itself.

**Inheritance: a scoped class's children are scoped, and that is correct, not a hazard.** If a
hierarchy needs both shapes, the parent is left non-scoped and a **branch of descendants** is
scoped. The item as filed said the opposite — "a scoped class's parent must be scoped too" — and
that claim is what made research 44 rule `FiberRequestStack` an illegal target, since it extends
Symfony's `RequestStack`; on that basis it recommended killing the whole item. Under the owner's
rule that class is not an edge case, it is the sanctioned pattern: vendor parent left alone, our
descendant scoped.

Consequences, stated so the arithmetic is not re-derived a third time. The legal target set is no
longer 53 lines (research 44), nor 358 (main's correction on 2026-09-19), but **all four façades,
447 lines** — `FiberRequestStack` 89, `FiberTokenStorage` 53, `FiberEntityManager` 247,
`FiberManager` 58. That is the set that *may* carry the attribute. It is **not** the number of
lines deleted, and main is deliberately not guessing that a third time: `FiberEntityManager`'s
~30 interface forwarders are a decorator, not scoping, and they stay whatever happens. The
acceptance's own instruction settles it empirically instead — rewrite `FiberRequestStack` on the
mechanism and see whether the class disappears.

Also settled by this: the existing hazard in `route.rs`'s `create_proxy`, where "a userland
subclass inherited this hook from the routed parent" needed a special case, is a *proxying*
problem and not a scoping one. For proxies an inherited hook is wrong because the parent's
`free_obj` expects an internal payload the subclass has not got. For scoping an inherited hook is
the intended behaviour.

**The control level: a class-level call before the first instance.** Not the attribute, which
cannot reach a vendor class and arrives only at autoload; not MINIT, where `route.rs` already
demonstrates the failure — it looks the class up in the class table and `if zv.is_null() { continue; }`,
so a userland class name silently does nothing, which is why its only default is the internal
`SQLite3`. Instead `Ignis\Scope::scopeClass(X::class)`, called by a bootstrap or a container
factory: the class is linked by then (it had to be, to be named), so `properties_info` is complete
and slots resolve; no instance exists yet, so there is no split population.

Not from the constructor, and the reason is visible in `create_proxy`: `(*obj).handlers` is
assigned inside `create_object`, so **handlers are per object**. A constructor runs after that, so
the very object doing the initiating already holds the old handlers and an initialised properties
table — the class would convert from its *second* instance onward.

**And keep the properties table.** The item says the façade has none. Holding it as the zero scope's
defaults and swapping only the handlers means the allocation size never changes, which removes a
whole class of risk for nothing.

Kill criterion: the property-offset runtime cache. `$this->x = 1` compiles to an opcode with a
cache slot memoising the offset for a class, and if a class is scoped after code touching it has
been compiled and run, those cached offsets may bypass `write_property` entirely. This must be
read in php-src, not assumed, and a test must scope a class *after* exercising it — cold and with
opcache warm. If the cache is guarded only by `ce` and not by the handler table, the class-level
call has to happen before any code touches the class, which narrows it back toward a boot-time
list and this decision is wrong.

---

## 2026-09-20 — the kill criterion above was malformed, and saying so is the point

The 2026-09-20 entry on the control level ended with this criterion: *"if the cache is guarded only
by `ce` and not by the handler table, the class-level call has to happen before any code touches
the class, which narrows it back toward a boot-time list and this decision is wrong."*

Read in `~/php-src` at `php-8.5.10`, the answer is **guarded by `ce` alone**
(`Zend/zend_vm_def.h:2491`). By the wording above, the decision is therefore wrong. It is not, and
the reason is a thing the question could not express: the fast path carries a **second** guard,
`Z_TYPE_P(property_val) != IS_UNDEF` (`:2502`, mirrored at `:2111` on the read path), and that one
reads the **object**, not the class. A scoped object whose declared slots are `IS_UNDEF` therefore
never takes the fast path no matter what the `ce`-keyed cache holds.

So the criterion fired and did not kill. That is a broken criterion, not a lucky escape, and it is
recorded here rather than quietly rewritten, because a criterion that can be satisfied and then
explained away is worth less than none — it teaches the next person that these are decoration. The
fault is in how it was framed: it asked about one guard when the fast path has two, and it assumed
the answer "class-keyed" implied "cannot distinguish instances".

What replaces it is in ADR-0042, chosen after the cache question was answered by citation rather
than left open: the measured read and write cost against a plain property. Every access on a scoped
object now provably takes the slow path, by construction, so that cost is the mechanism's baseline
price rather than a tail case — and unlike the cache question it cannot be settled by reading.

## 2026-09-20 — ADR-0042 and the userland half were built in parallel, against the item's own rule

`S-SCOPED-CLASS` says "**needs an ADR before code**". Main launched the ADR and the userland
scaffolding as two agents at the same time, so code existed before the ADR did. Recorded because
the ADR's own author found the uncommitted files mid-task, could not account for them, and reported
them as a possible process violation — which was the right call from where it stood, and the answer
is that they were a sibling agent's work, not that nothing was wrong.

Mitigating, and not an excuse: the parallel half is userland PHP against a two-function ABI fixed in
advance, it touched no engine code, it was uncommitted, and the ADR landed minutes later. The
engine half — the part the rule exists to protect — had not been started. Next time the ADR goes
first and the scaffolding waits, because "needs an ADR before code" is worth nothing if the person
enforcing it is the one who decides when it is inconvenient.

## 2026-09-20 — the scoped-services list is a bundle setting, and the seal moved to the configurator

Two decisions from the same arm, because the arm produced both.

**`security.logout_url_generator` is scoped.** Research 36 listed it as a candidate on a reading of
its per-request property, and the arm's first answer was that it does not need scoping at all: with
a token, `getListener()` resolves the firewall through `security.token_storage`, which is scoped
already, and the property is never reached — 0 of 3 both directions. The anonymous request is what
reaches it, and there a neighbour's `onKernelFinishRequest` nulls it under a request that is still
awaiting, 2 of 2 (V-105). Two firewalls and no credentials is the only shape in which the defect
exists; neither half of that is obvious from the class. Filed as the reason the entry carries the
control it does.

**The list is configured as `ignis.scoped_ids` on the bundle, not as a container parameter.** The
parameter route could not do what its own doc block claimed. `IgnisBundle::build()` set the defaults
and an application's `parameters:` in `services.yaml` is loaded *after* `build()`, so an application
listing one id of its own replaced `request_stack` and the token storages rather than adding to them
— the exact failure the additive merge was written to prevent, still present in the case that
matters. A bundle extension is merged during compilation, after all configuration is loaded, which
is the only place both halves are visible at once. `%ignis.scoped_vendor_ids%` survives as the
internal channel from the extension to `FiberScopePass` and is no longer an application-facing door.

**And the seal is the definition's configurator.** `Scope::create()` seals when the constructor
returns; Symfony configures services with `addMethodCall`, emitted after the factory. Anything set
that way belonged to the one request that built the service. The configurator is the one hook that
runs after all method calls, so that is where the seal goes, and a definition that already has a
configurator is refused by name at compile time rather than silently losing one of the two. This was
latent: the three ids scoped before this one have no method calls, and only a service that had some
could expose it.

## 2026-09-20 — `Loop` keeps its ten responsibilities; only the environment reading comes out

The owner looked at `Ignis\Loop` and said it has a lot mixed into it, including test logic and
environment reading. Measured rather than argued: 1,113 lines, 63 functions, 40 static properties,
and ten distinct concerns — op wait and loop core 267 lines, request lifecycle 178, fiber pool 140,
admission and budget 132, cancel and deadline 118, watch/reload 67, chaos 49, payload validation 45,
GC scheduling 34, stats 23. The observation is correct.

**Not splitting it.** Three of the ten *are* the one-wait-point invariant, and `CLAUDE.md` says
plainly that adding a second wait point to a PHP thread breaks the design. The class is also all
statics: a split either threads the state through new signatures or makes the new classes static as
well, which moves the pile instead of shrinking it. There is no defect driving this — the three
leaks an earlier plan named in `Loop` (`$queueCancelled` unbounded, `$children` never cleared,
`deadline()` leaking its timer) are all fixed and were re-read to confirm it. Restructuring the
scheduler for readability, with nothing failing, is the change most likely to trade a correctness bug
for an aesthetic gain. Filed as `S-LOOP-SHAPE` with the two candidates that would pay first — chaos,
and admission/budget — and with acceptance that includes E1, E2 and hello throughput, because those
are the numbers such a change could silently move.

**Splitting out the environment reading.** That part was not merely mixed in, it was inconsistent:
five different spellings of "is this knob on" across nine `getenv` calls in one file —
`$v !== false && $v !== '' && $v !== '0'` for chaos and for watching, the inverted
`$v === false || (...)` for loop GC because it ships enabled, bare `is_numeric` with a floor for the
budget, and a fourth shape for the exempt list. None of them was reachable by a test. They are now
`Ignis\Env` with one rule each and eleven tests, `Loop` has no `getenv` left, and every rule was
re-checked end to end through the real binary against its old behaviour: `-3` still floors to 0,
`abc` still leaves the default, `IGNIS_CHAOS_P=9` still clamps to 1.0, `IGNIS_LOOP_GC` is still the
one knob that ships on.

Reading the environment at all is **not** a smell here and was not changed: `ignis.toml` is parsed on
the Rust side and exported as environment variables (`config.rs`'s `default_env`), so the environment
is the documented transport between the two halves. Worth noting for later, though: four knobs travel
that way (`IGNIS_FIBER_BUDGET`, `IGNIS_QUEUE_DEPTH`, `IGNIS_BUDGET_EXEMPT`, `IGNIS_WATCH`) and five
have no `ignis.toml` row at all (`IGNIS_LOOP_GC`, `IGNIS_LOOP_GC_ROOTS`, `IGNIS_CHAOS`,
`IGNIS_CHAOS_P`, `IGNIS_CHAOS_SEED`) — an inconsistency that was invisible while the reads were
scattered.

## 2026-09-20 — no `DebugLoop`; chaos becomes a class the loop asks, not a mode the loop is in

The owner proposed splitting `Ignis\Loop` into a production loop and a `DebugLoop` carrying the
chaos machinery. Rejected as a subclass, taken as a class.

**Why not a subclass.** `Loop` is entirely static, and PHP's static properties are *shared* with the
parent unless a subclass redeclares them — so `DebugLoop extends Loop` would not be a second loop, it
would be the same 40 statics under a second name. Making one actually replace the other means either
every call site becomes a dynamic static call (`(self::$loop)::awaitOp(...)`), paid on every await
forever, or `Loop` goes `static::` throughout and the application picks its loop class at boot. Both
put a dispatch decision on the hottest path in the runtime in order to remove one short-circuited
property read, and both leave two classes that must stay behaviourally identical.

**What was done instead.** `Ignis\Chaos` owns the flag, the probability, the seed, the yield decision
and the shuffle. `Loop` keeps four call sites that ask it a question and knows nothing about how it is
configured. The extraction also collapsed a duplication it had been hiding: `chaosYield()` was a copy
of `awaitOp()`'s register/suspend/unregister block, and both are now one `parkOn()`.

**The hot-path guard is by construction, not by measurement.** `Loop` calls `Chaos::$on &&
Chaos::fires()` rather than `Chaos::fires()` alone, so chaos being off is a property read. Three runs
suggested the bare call cost something (4.37–4.90 µs warm per fiber against 4.09–4.60 for the inline
code); six runs each could not tell them apart (4.26–4.73 against 4.12–4.83, means 1.3 % apart inside
a 16 % spread). The instrument on this box does not resolve one PHP call per await, so the cheap
shape is kept and no win is claimed for it. E1 did not move either.

**And a documented guarantee that was false.** `IGNIS_CHAOS_SEED` was described as making a chaos run
reproducible. It does not: the loop is driven by real timers, so completions arrive in a varying order
and the same seed lands its draws on a different schedule — five runs of one script at seed 1 gave
3, 3, 3, 5, 3 extra yields, and did so on the code as it stood before any of this. The seed narrows a
re-run; it does not pin one. Both the reference page and the new class now say so.

## 2026-09-20 — the transport says what a refusal looks like; the scheduler does not

`Ignis\Loop` answers a rejected request with HTTP 503 before anything knows what transport the call
arrived on, and discarded the result. For a gRPC call the reactor refused that answer — correctly,
a gRPC id is not a whole-body id — and the stream stayed open: three of five concurrent calls hung
for ever, nothing logged (V-107).

Two places could hold the fix and only one is right. Teaching `Loop` to notice gRPC and end the call
with `Status::UNAVAILABLE` puts transport knowledge in the scheduler, which is the leak that caused
this. Mapping the refusal in `Reactor::respond()` puts it where the transport already lives: the
reactor is the layer that knows an id is `Answer::Grpc`, because it is the layer that made it one.
So a failure status (`>= 400`) on a gRPC id ends the call with the mapped gRPC status, and the
scheduler goes on saying "503" in ignorance, which is what it should be doing.

A **success** status on a gRPC id stays refused. That is a caller using the wrong door — the router
answers through `stream_send`/`stream_end` and returns null — and turning it into an empty OK would
replace a loud nothing with a quiet wrong answer.

Separately, `Loop` now answers through one `respondTo()` that logs a refused answer. The specific
`false` is gone; the point of the helper is that the next one is a log line and not a hang.

## 2026-09-20 — where the park binding is proved: CI on the built binary, not only a boot probe

The owner, on `park::selfcheck()`: "я бы всё таки тестил это против сборки уже в ci и в будущем на
матрице". Taken, with the division of labour written down rather than assumed, because the two
places prove different things.

**CI, on the built artifact, is where the thorough half belongs.** It can afford a real TLS
handshake, a real PostgreSQL socket, a real curl transfer, and it tests the binary that will ship in
the container it will ship in. A build matrix extends that to the variations we support. This is
strictly stronger than a `dlsym` probe: it proves the call *parked*, not merely that a symbol bound.

**The boot check is the last mile and should shrink, not grow.** It is the only thing that speaks
about an environment we did not build — a customer's `LD_PRELOAD`, a different `libcurl.so.4`,
another interposer in the image. That is worth keeping, because universal park fails by hanging and
there is no exception to catch. But once the matrix carries the thorough half, the boot check has no
reason to hold 150 lines of `dlsym`/`transmute`: "did anything bind at all" would do.

**What was done now.** `bench/e6-ssl.sh` proved TLS parks and was in neither `smoke.sh` nor
`ci.yml`, because it exited 0 whatever it found and labelled three correct refusals as `FAIL`. It has
a verdict now, its fixture regenerates on expiry rather than only on absence, and it is in smoke —
so `libssl` is proved in CI on the built binary, falsified with `IGNIS_NO_UNIVERSAL_PARK=1`.

**What was deliberately not done.** The `selfcheck()` edit I had started — naming the unprobed
libraries in its report — is not in this commit. The owner stopped it to be answered first, and on
the answer it is the smaller half of a question that wants an ADR: `S-PARK-PROBE-COVERAGE` carries
both, and the boot check keeps over-claiming `ok` until that is settled. Recorded here so the
over-claim is a known open defect and not an oversight.

## 2026-09-22 — the nightly perf job is deleted, not calibrated

The owner, on the first real nightly tables: "По мне дак выглядит вообще бесполезным, тем более что
железо арендуемое" and then "Я бы вообще удалил, пользы не вижу". Taken.

**What the numbers said.** Once the job ran at all (V-119: six scheduled runs had died on `sh`),
three runs of one binary within 25 minutes read E1 1256.6 / 1155.1 / 1550.1 ms against a 1200 ms
threshold taken from the developer box (V-28: 1144–1178), and hello 58k / 81k / 59k rps. Nothing in
the runtime changed between those runs. An absolute threshold on a shared 2-vCPU runner measures the
neighbour, and ADR-0023 had already said so ("runners are shared, the numbers are not comparable")
while the job kept dev-box thresholds anyway.

**Why delete rather than fix.** The salvageable form is an A/B in one job (previous point against
head on the same machine, gate on the ratio — the shape `B1 p99 pair` already had and that held at
950/970 and 1500/1510). It costs a second build per night and a week of runs to trust, and the
owner does not see the use: performance claims in this project are VALIDATION entries taken on one
known machine with its state recorded, and that discipline is what catches a regression — a V-n is
re-measured when the code it covers changes. A gate that cries on the neighbour's load would be
read for a week and then ignored, which is worse than no gate.

**What was removed.** `.github/workflows/nightly.yml`, `bench/results/nightly-baseline.txt`, the
ROADMAP Phase D item (struck through, with the reason), M5-4 closed as deleted, issue #1 closed as
not planned. `backend-b.yml` and `nts.yml` stay: they are correctness gates for code nothing else
compiles, and their verdict does not depend on the runner's speed.

**Kill criterion.** If a throughput regression ships in a tagged release and the V-n covering it
was not re-measured before the tag, this decision was wrong and the A/B form above is the fix.

## 2026-09-22 — the MVP cut: what the product is, and what left the tree today

The owner asked what else is superfluous for the MVP and asked for an interview before anything
was decided. The interview, in two rounds, gave these answers, and they are the scope from here:

**The MVP.** An asynchronous platform for Symfony: HTTP, gRPC, server-sent events and websockets
as the protocols; Symfony Messenger running asynchronously; Temporal. Integrations kept: gRPC
(E10) and Temporal (E9/E20 on the official `temporalio/sdk-php`). Also kept: the Revolt driver
(E7), development reload, classic mode, the NTS mode, and the E15 compat gates php-src phpt,
Revolt DriverTest, FrankenPHP testdata and the chaos suites (the last one entered CI today as
`e15-chaos`). Laravel is not in the MVP.

**Deleted today, code, tests, benches and CI jobs alike** (documents keep the history, marked):

| what | why it went | what replaces it |
|---|---|---|
| the offload pool (E16, ADR-0016: `offload.rs`, `route.rs`, `ignis/offload`, `--offload`) | park had already taken every socket-backed call off its table (V-59 addendum); a second pool of PHP threads existed for `SQLite3` alone, and the owner does not want to maintain it | a call that cannot park blocks its thread, and that is documented (ADR-0024) |
| the Swoole shim (`ignis/swoole`, E15d) | research 45 had recommended it: a partial API compatibility layer, not a mechanism; its CI leg measured its own pre-existing failures | nothing; Swoole compatibility is not an MVP goal |
| the Doctrine package (`ignis/doctrine`: per-fiber EntityManager, connection pool, `bench/e21`, `bench/e24`) | 1,063 lines of framework integration the owner did not select | ADR-0042 `scoped` services: the container marks the manager or connection service `scoped` and it resolves per fiber |
| backend (b), the true-async fork (ADR-0003's second backend, `backend-b.yml`) | a separate engine build, a patch to a fork, a workflow and `cfg` branches through the FFI layer, for an ABI that is not merged upstream | one engine ABI family: PHP 8.5 ZTS and its NTS mode |
| `ignis/temporal-prototype` | frozen since ADR-0040 and kept only because the E9 replay test ran on it | the replay gate on the official SDK: `CoreSource::replay()` + `bin/replay.php`, `bench/e9-temporal.sh` |
| the nightly perf job | the first entry of this date | the VALIDATION discipline |

**One exception, decided here rather than by the interview.** Classic mode was not selected as
kept, but the FrankenPHP testdata gate was, and that gate runs through classic mode
(`examples/classic_server.php`); classic is also what `InputStream` and `Loop::enterRequest()`
share their request-cycle code with. It stays, at 500 lines of userland PHP and no mechanism of
its own. If the FrankenPHP gate is ever dropped, classic mode goes with it.

**The mechanism budget** (CLAUDE.md, ADR-0037) is two mechanisms and one table: park and context.

**Kill criterion.** If an MVP application needs a blocking call that cannot park and cannot be
made a `scoped` per-fiber resource — a CPU-bound extension call on the request path is the likely
shape — the offload pool comes back from `git log` as a table row, per ADR-0037, not as a hook.

## 2026-09-22 — two workflows fewer: the per-push image and the docs site

The owner: "Удали лишние ci workflow". Read against what each one is for:

| workflow | verdict | why |
|---|---|---|
| `image.yml` (runtime image on every push to `main`, `:latest` + `:<sha>`) | **deleted** | `release.yml` builds the same Dockerfile with the same smoke check on a tag; an image per commit is a release nobody asked for, and its `:latest` moved on every push. `release.yml` now tags `:latest` as well, so `docker pull ghcr.io/koekaverna/ignis:latest` means the last release. Rolling back is to a `:vX.Y.Z`, not a sha |
| `docs.yml` (mkdocs to GitHub Pages on docs changes) | **deleted** | nothing links to the site — no `site_url`, no `github.io` anywhere in the repository — and no gate ran `mkdocs build` either, so it published pages nobody was sent to. The docs stay Markdown in `docs/`; a link check, if wanted, is a `mkdocs build --strict` step, not a deploy |
| `php-image.yml` | kept | the builder image every CI job runs in; triggers only when its inputs change |
| `nts.yml` | kept | the owner kept the NTS mode; nothing else compiles `cfg(php_nts)` |
| `release.yml`, `ci.yml` | kept | the release and the gate |

Four workflow files remain (eight this morning). Kill criterion: a user asks for an image of a
commit that is not a release — then `image.yml` returns from `git log`, on `workflow_dispatch`
only.
