# Ignis — autonomous R&D night

## Mission
Prove and push as far as possible tonight: a single Rust process embeds PHP 8.5 (ZTS) and runs many PHP requests concurrently per OS thread on native PHP Fibers, with all I/O waits owned by tokio. Unmodified synchronous PHP must become non-blocking. This is R&D, not a feature ticket: the output is validated knowledge plus working code, in that order.

I'm asleep. Never stop to ask. Decide, log, continue. Ambition is high on purpose; honesty about results is non-negotiable.

## Tech baseline — verify every version at the start of Cycle 0, do not trust memory
- Rust: latest stable, edition 2024. tokio latest, hyper 1.x + hyper-util, bytes, bindgen for php-embed headers. mimalloc as global allocator. tracing + opentelemetry-otlp for spans across fiber switches.
- Quality tooling: cargo-nextest, criterion for micro-benches, miri on every unsafe module, loom for the channel/scheduler core, proptest for request parsing.
- PHP: latest 8.5.x from php-src (8.5.10 or newer), --enable-zts --enable-embed --enable-opcache, minimal extension set (json, fibers, sockets, pdo_sqlite, mbstring). Study Zend/zend_fibers.c and the zend_observer fiber-switch API first.
- References to read, not copy: FrankenPHP (cgo boundary, worker mode), Swoole (coroutine I/O hooks, php_stream interception), Revolt event-loop Driver interface (the userland scheduler must be shaped so it can become a Revolt driver).
- Decide by research, not habit: raw bindgen vs ext-php-rs vs phper; epoll vs tokio-uring; PHP-side scheduler vs Rust-side fiber resume via zend_fiber_resume.

## Expectations — each is a hypothesis to falsify, not a promise
E1  10,000 concurrent requests each doing Ignis\sleep(1000) on ONE thread finish in <1.2s wall.
E2  Ignis\all() of three 200ms calls returns in <230ms; per-fiber overhead <100µs.
E3  RSS flat (±2%) over 1,000,000 requests in worker mode.
E4  Hello-world throughput ≥ FrankenPHP worker mode on the same box, p99 lower.
E5  8 threads ≥ 6.5× single-thread throughput on CPU-bound work.
E6  php_stream hook: unmodified file_get_contents('http://…') and PDO (sqlite first, then pgsql via native driver) suspend the fiber instead of blocking the thread.
E7  A Revolt-compatible driver over the Ignis reactor runs AMPHP examples unchanged.
E8  symfony/runtime adapter boots symfony/skeleton in worker mode; RequestStack is fiber-scoped; two interleaved requests never see each other's Request.
Targets are floors. When one is met, raise it in the Reassess stage and record why.

## The loop — run it continuously until I stop you
Every cycle is 45–120 minutes and passes through ALL six stages, in order. JOURNAL.md gets a timestamped line at every stage transition. Skipping a stage is a bug.

1. RESEARCH — read source, docs, prior art for the current question. Write docs/research/NN-topic.md: sources, findings, what surprised you, what this rules out. Verify versions and APIs against real headers/repos, never memory.
2. DECIDE — write an ADR in docs/adr/NNNN-title.md: context, options considered, decision, consequences, kill criterion (what evidence would reverse it).
3. HYPOTHESIZE — add to HYPOTHESES.md: H-id, falsifiable statement with a number, the exact test/bench that decides it, expected result, time box.
4. IMPLEMENT — the smallest code that can falsify the hypothesis. Commit per hypothesis. Every unsafe block commented with why it is sound. FFI boundary documented: ownership, lifetime, who frees.
5. VALIDATE — run the test/bench. Record raw numbers, command, machine state in VALIDATION.md. Mark hypothesis CONFIRMED / REFUTED / INCONCLUSIVE. No stubs, no "would work". A refuted hypothesis is a valid result — record it and what it teaches.
6. REASSESS — update GOALS.md: raise or lower targets, retire dead ends, re-rank what matters most, choose the next cycle's question. If ahead of plan, escalate ambition (HTTP/3 via quinn+h3, Revolt driver, Symfony adapter, native pgsql pool via tokio-postgres, edge hooks in Rust before Zend). If behind, cut scope explicitly and say what was cut.
Then go to 1.

## Cycle 0 seed
Question: what is the cheapest sound way to run a request inside a Fiber and hand its I/O to tokio? Candidate architecture: hyper on tokio → mpsc → PHP worker OS thread with one embedded ZTS instance → reactor in Rust exposed as ignis_submit()/ignis_poll() → scheduler in PHP userland resuming Fibers. Research whether resuming from Rust via zend_fiber_resume is safer or faster; decide; test E1 first. E1 and E2 are the whole thesis — get them to CONFIRMED or REFUTED before anything else.

Fallback if embedding cannot be built on this machine: child php process over pipes for transport only, note it loudly, still pursue E1/E2 inside that process — the scheduler does not depend on embedding.

## Morning deliverables
- STATUS.md: one screen. What is CONFIRMED with numbers, what is REFUTED and why, how to run everything in ≤5 commands, current best architecture diagram in text, your ranked recommendation for the next 3 cycles.
- JOURNAL.md, HYPOTHESES.md, VALIDATION.md, GOALS.md, docs/adr/, docs/research/ — complete and honest.
- cargo nextest green, scripts/smoke.sh green, bench/ with wrk scripts and a results table vs php-fpm and FrankenPHP if FrankenPHP installs cleanly.
- examples/app.php that reads like real application code — this file is the API spec.
- One commit per hypothesis, conventional messages. Init git; push only if a remote already exists. (Superseded by "Cloud session rules" below.)

## Rules
- Numbers or it didn't happen. Every claim in STATUS.md links to a validation entry.
- Prefer refuting fast over building wide. A dead end found in 30 minutes is a win.
- PHP callbacks never touch the tokio runtime directly; threads talk via channels.
- Readable over clever, but do not compromise on soundness at the FFI boundary — that is the one place where clever is required.
- MIT license. English everywhere.

### Pain map
docs/pain-map.md lists known production failure modes of PHP-FPM, RoadRunner, FrankenPHP and Swoole and the architectural answer Ignis gives to each. It is not a task list. Rules:
- Every REASSESS stage re-reads it and updates each item's status with a link to the hypothesis or ADR.
- Every ADR states which pain-map items it affects, including ones it makes worse.
- Do not start a pain-map item before E1 and E2 are CONFIRMED or REFUTED.

### Additional expectations
E9  Temporal: link temporal sdk-core (Rust) in-process; workflows on Fibers with deterministic replay; one workflow with two activities and a timer passes a replay test. Research first how the Python SDK bridges core ↔ asyncio.
E10 gRPC: tonic server + client on the shared hyper/h2 stack; unary and server-streaming handlers in PHP; a client call suspends the fiber. Compare with the RR grpc plugin and ext-grpc on latency and build complexity.
E11 Cancellation: client disconnect cancels the request's Fiber and every pending future it owns within 10ms; no phantom work continues. One wall-clock deadline per request, inherited by child fibers and futures.
E12 Isolation: a fatal error or a deliberate 30s CPU loop in one Fiber affects only its thread; other threads keep serving; the supervisor restarts the thread without an opcache reset; pools and Table survive.
E13 State: superglobals and a fiber-scoped container are swapped on every fiber switch via the zend_observer fiber-switch hook; two interleaved requests never observe each other's $_SERVER, $_POST or scoped services. A dev-mode leak detector reports any static that outlives its Fiber.
E14 Connections: pool owned by the runtime; lease per Fiber; a transaction pins the lease; session reset on return (DISCARD ALL / COM_RESET_CONNECTION); a second acquire from the same pool inside one Fiber is an error, not a wait.
E15 Compat (added by the owner during the night, 2026-09-16T01:55Z): run Zend/tests/fibers, ext/standard/tests/streams, ext/sockets/tests under ignis run-tests — report the pass rate, every failure classified (our bug / not applicable / upstream). Revolt DriverTest on IgnisDriver: 100%. Swoole swoole_runtime hook tests through an Ignis shim: report which hooks we lack. FrankenPHP testdata ported to integration tests. Symfony + Doctrine test suites in chaos mode (random fiber switch at every I/O point): zero new failures vs stock PHP.
E16 Offload (added by the owner, 2026-09-16T02:32:04Z): a pool of synchronous PHP worker threads with their own TSRM context; `Ignis\offload(fn)` copies scalar/array args in, runs the closure there, copies the result back, the calling fiber sleeps. Config-driven auto-routing for a list of functions/classes (curl_exec, PDO pgsql, SQLite3, Redis) with no code changes. Test: 100 concurrent pdo_pgsql queries of 200 ms complete in ~200 ms wall on one fiber thread with an 8-thread offload pool → limited by pool size, never by the fiber thread; curl_exec with CURLOPT_WRITEFUNCTION works. Record the copy overhead per call.

### Cloud session rules (replace the git line under Morning deliverables)
- Push after every commit. The VM can be reclaimed at any time; anything unpushed is lost.
- Make scripts/build-php.sh idempotent and cache its output so a fresh VM rebuilds in one command.
- If a download is blocked by the network allowlist, log the domain in STATUS.md, try a mirror on an allowed host once, then move to the next milestone. Never loop on a blocked fetch.
- After any restart or context reset, re-read CLAUDE.md, BRIEF.md, STATUS.md and the tail of JOURNAL.md before doing anything else.
- Cycle 0 also checks name collisions for "ignis" on crates.io, Packagist and GitHub and proposes two alternatives if taken. Do not rename anything; just record it in STATUS.md.
