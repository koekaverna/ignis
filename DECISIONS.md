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

## Owner decisions outstanding

- **ADR-0018 kill criterion 2 (A4, `ext/sockets` overhead).** Measured at roughly 1–2 µs against a 0.36 µs bar (V-29); the bar was set at 10 % of the fiber round trip but the hook adds a syscall to an already syscall-bound call, which no readiness-probe design meets on this box. A replacement criterion is proposed in the ADR addendum (hook cost < 25 % of the wrapped operation, measured where a syscall can be resolved, compared against blocking the whole thread). Accept or reject.
- **A6 (TLS read-ahead invisible to `stream_select`) is handed on, not fixed.** The sound fix is an eventfd the stream owns, handed out by `op_cast` — reproduced and diagnosed (docs/research/23), but judged larger than a Phase A item. Schedule it into Phase B, or leave it open.
- **A3's acceptance criterion ("RSS within ±2 % between the 1M and 10M checkpoints") was shown unsatisfiable by construction** (V-30): RSS is front-loaded, growth stops trending around 5M, and readings past that swing ±10–12 % between adjacent checkpoints — wider than the ±2 % the criterion asks of two single points. Proposed restatement: "no monotonic trend past 5M". Accept or reject.
- ~~**Five bench scripts still hardcode `/home/user`.**~~ **Done 2026-09-16** — all five fixed with a
  CI-first fallback (`$PHPSRC`/`$FP`/`$SWOOLE_SRC` → `/home/user/...` when present → `$HOME`), so the
  CI symlink keeps working. Two were worse than "untidy": `e7-revolt.sh` pointed `IGNIS_PHP_INI` at
  another machine, so the ini silently did not apply, and `e15-chaos.sh` generated a `run.php` that
  required an absolute path from that box. `bench/frankenphp/Caddyfile` and `bench/fpm/nginx.conf`
  were left alone on purpose: neither comparison server is installed here, so a change is
  unverifiable.
- **H31 — a request accepted and never answered, ~1 in 400–1200 under inbound load (V-34).** Found
  once the port collision stopped hiding E6. Characterised and reproducible
  (`bench/php/e6_underload.php`), root cause not found; it is accept-path/hyper work. It sits
  under B1's acceptance numbers, which are measured with the same storm shape, so: fix before B1,
  or accept that B1's numbers carry a 0.1–0.3 % unanswered floor and say so in its V-n.

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
