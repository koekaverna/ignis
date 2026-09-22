# ADR-0037 — Consolidation to three mechanisms: park, offload, context, one policy table

Status: **accepted 2026-09-18**; **offload deleted 2026-09-22** (owner, MVP cut, DECISIONS), so the budget is two mechanisms and one table. The text below is kept as written. (main agent, under §7: all four conditions measured — V-46, V-48, V-49, V-51 — and cycles 1–3 shipped; the stream transport factory and the rustls path are deleted, which was the last step §6 named). It stood at *proposed* for a day after that, while STATUS, CLAUDE.md, `docs/concept/mechanisms.md` and `docs/packages/index.md` all described the three-mechanism budget as the shipped architecture; the audit of 2026-09-18 closed the gap in the direction the evidence pointed. (owner note 2026-09-17; main agent.) The owner
named this file `0021-three-mechanisms`; 0021 was already taken by the coverage-layers ADR during
the sweep and is not renumbered — it now points here. The mechanism budget is CLAUDE.md
"Mechanism budget (owner, 2026-09-17)". Numbers: `docs/research/29-mechanism-inventory.md`
(measured with `wc`/`grep`; `tokei`/`cloc` absent) and the V-n cited; "estimate" is labelled and
kept out of the decision table. This note adds no code.

## 1. Inventory — measured

Seven wait mechanisms exist today (research 29): the stream transport factory (705 Rust, 16
`unsafe {`), the reactor's connection actor and rustls arms (~250 of 812, estimate), `ext/sockets`
hooks (326 / 21), the `sleep`/`usleep` swap (112 / 7), the `accept` hook (315 / 11), offload
(329 Rust + 490 PHP / 10), the context observer (269 / 15), universal park stage 1 (397 Rust + 47 C /
23). **Measured deletion under the target model: 1,458 Rust lines, 55 `unsafe {`, 53 `unsafe fn`**
(rows 1–4). Keeps: context, park, offload's worker half, everything shared, every adapter. Adds:
estimate ~750 lines / ~15 blocks (stage 2, per-symbol policy, boot self-check, detector). **Net:
estimate −960 lines, −40 `unsafe {`** — an estimate because the adds are; the measured half is the
1,458 / 55 / 53 that go.

## 2. Target model

Three mechanisms and one table; reactor/scheduler beneath, adapters above.

| mechanism | is | is NOT allowed to |
|---|---|---|
| **park** | syscall interposition (ADR-0020) with a **per-symbol** policy: the return address resolves to a symbol name, not only a library, so libphp's own call sites can move from `block` to rows — only after the audit of §5 | **buffer**: it never holds bytes; readiness then the real call, one copy (the kernel's). It never changes an fd's flags except `connect`'s temporary `O_NONBLOCK`, restored before return |
| **offload** | synchronous worker threads with copy-in/copy-out and worker-pinned proxies (ADR-0016 addendum); the routing trampolines become rows of the same table | **share objects**: scalars and arrays cross by copy, objects and resources never; a proxy is a handle, not the object |
| **context** | fiber-switch observer slots (ADR-0006 addendum): superglobals are the first rows, listed vendor statics the next | **allocate per switch**: a slot swap is pointer moves; anything needing allocation happens at dispatch, not on the observer |
| **table** | `symbol \| PHP function \| class → park \| offload \| block`, in `ignis.toml`, seeded with research 27's verdicts (libcurl park, libpq park, libssl/libcrypto park, libphp block until audited) | — |

Adapters (Revolt, symfony/runtime, Laravel classic, gRPC, Temporal, `Ignis\Pg`) carry no
mechanism: they call the scheduler's primitives (`Op::Sleep/Watch/Custom`, `ignis_watch`) and are
listed in research 29 separately.

## 3. Universality and maintainability — metrics, before / after

| metric | before (measured where marked) | after (target) |
|---|---|---|
| mechanisms a wait can take | **7** (research 29) | **3** |
| files a maintainer touches to add a new blocking **library** | 2–3, at least one guarded FFI file: `route.rs` + `php/packages/offload/src/ignis-offload.php` (offload), or `stream.rs` (if on php_stream), or `sockets.rs` — measured by reading | 1 table row (+ a research verdict for `park`) |
| … a new blocking **PHP function** | a hook in a guarded Rust file with arginfo (`sockets.rs` pattern, ~30 lines each — V-29) | 1 row |
| … a new **vendor static** | code in `superglobals.rs` (a slot) | 1 context row (once the slot generalises; **today still code**) |
| LOC / `unsafe {` per mechanism | 705/16, ~250/6, 326/21, 112/7, 315/11, 819/10, 269/15, 444/23 | park ≈ 1,200/38 (estimate), offload ≈ 700/8 (estimate), context 269/15 |
| code paths a request's wait can take | **12**, enumerated: `Ignis\sleep`; `sleep()` hook; stream `op_read/write/connect`; `hooked_select`; `stream_socket_accept`; nine `ext/sockets` hooks; offload trampoline; `Ignis\offload()`; pg `Op::Custom`; gRPC client op; `ignis_watch` (Revolt); universal park | **2**: park or offload (adapters use reactor primitives beneath, not mechanisms) |
| suites gating each mechanism (E15 phpt / Swoole / FrankenPHP / Revolt / chaos / soak) | stream: phpt+Revolt+chaos; sockets: phpt+Swoole; sleep: phpt; accept: phpt+Revolt+Swoole; **offload: none**; context: chaos+smoke; **park: none in CI** (research 29) | each of the three gated by every suite plus the soak, one column per mechanism, before anything is deleted |
| "explain it to a new model in one page" | park: yes (ADR-0020 + `park.rs` header); offload: yes (ADR-0016 addendum); context: yes (ADR-0006 addendum); stream factory: **no** — four behaviours in one file; sockets: yes; sleep: yes; accept: partly | three pages, one per mechanism, with invariants — the §2 "is NOT allowed to" lines are their first paragraph |

## 4. Performance and reliability — what changes, what must be re-measured

- **Gate cost.** ~8.3 ns per syscall on the non-fiber path (research 28). A hello request on the
  tokio side is on the order of 4–6 syscalls (accept/read/write/epoll — **estimate**), so ~50 ns
  per request against 7.8 µs per request at V-6's 128k req/s — well under 1 % (estimate). Required
  before any deletion: **E1/E2/E4/E5 re-measured with `universal-park` on vs off.** E1/E2 on the
  park build already read 1,175.9 ms and 201.03 ms (V-45 addendum) against 1,144–1,159 and 201
  off; the noise band from V-28 is E1 1,175–1,195 ms quiet, i.e. ±1 %, so E1 on is inside it.
  E4/E5 on/off: **unmeasured**.
- **Copies per read.** Today `op_read` copies the actor's `Bytes` into `Sock.pending`, then into
  PHP's buffer — two copies after the kernel; under park the real `read` lands in PHP's buffer —
  one. Measure on `/fetch` (E6) and E6'' before/after; **unmeasured**.
- **TLS.** OpenSSL in PHP (`ext/openssl`, policy `park` per research 27) replaces rustls in the
  reactor. A6 (TLS read-ahead invisible to `stream_select`, research 23) is expected to disappear
  because PHP's own TLS streams handle buffered plaintext; **verify with
  `bench/php/a6_tls_select.php`** under park with the factory off — until then B7 stays open.
- **Failure-mode change: exceptions → hangs.** A wrong hook throws; a wrong park waits. Two
  countermeasures are part of the model, not options — **(a) built and measured (V-52), (b) half
  built (V-52)**: **(a) a boot self-check** — a libcurl and a
  libpq probe at startup with a non-zero interposer hit counter, refuse to start otherwise (the
  research-28 "0 hits" mistake made mechanical); **(b) a blocked-in-fiber detector** — any syscall
  in a fiber that blocks longer than N ms without parking logs library + PHP function and
  increments `ignis_blocked_in_fiber_seconds{lib,func}`. Of (b), the *surprising* half is built —
  a call whose policy says `park` that could not park is counted and warned at the moment it gives
  up (`PARK_FAILED`) — and the other half, a `block` row exceeding N ms, is **not**: a thread stuck
  in a syscall cannot report on itself, so it belongs to the watchdog (ADR-0012) reading a
  per-thread marker. Specified in research 32, recorded rather than half-built.
- **Coverage as a metric:** parked-wait-time / total-wait-time in fibers on `/_ignis/metrics`.
  Today this number does not exist; it is the number the ADR is accepted against.

## 5. Risks, each with mitigation and test

| risk | mitigation | test |
|---|---|---|
| parking inside libphp under a lock (opcache SHM, TSRM, reentrancy locks) — research 27 did not walk compile/execute | a **symbol-level audit of libphp's blocking call sites** before any libphp symbol gets `park` (`docs/research/30-libphp-blocking-call-sites.md`, placeholder with acceptance); groups move to the table one at a time | H36's shim proves containment; E15 in fiber mode with each group on `park` |
| toolchain fragility: glibc internal aliases (`fread` → internal `__read`), `__poll_chk`, LTO, `--wrap` for a static binary, raw `syscall()` numbers per arch | boot self-check + detector; the static build (ADR-0027, M5-5) is tested with `-Wl,--wrap` **before** the dynamic path is deleted | the self-check refuses to start on a miss; nightly runs the detector's counters |
| loss of stream-level observability (URL, transport name, peer) that the factory had | spans reconstructed from the observer's PHP frame (function + args); **lost for good:** the transport's own view of a connection lifecycle (connect → upgrade → close as one span) — a socket is an fd to park | a span per parked call carries fd, symbol, library, PHP function (ADR-0022) |
| loss of the runtime keep-alive idea for a native HTTP client (it lived in the tcp factory's connection actor) | **dropped** as a factory feature; recorded as a `connect()`-policy experiment (a `park` row that returns a pooled fd) — unbuilt, unscheduled | — |
| Linux-only by construction (epoll, `syscall` numbers, `dladdr` semantics) | **accepted constraint**, stated in ADR-0024's non-goals and README | — |

## 6. Migration plan — order, with gates

1. E18-I lands behind the feature flag; H32–H36 green; E1/E2/E4/E5 re-measured on/off (§4).
2. Per-symbol policy and the libphp audit (research 30); libphp symbols move to the table one
   group at a time, each group gated by E15 fiber mode.
3. Delete point hooks **one per cycle** — `sleep.rs` (V-22), `sockets.rs` (V-29), `accept.rs`
   (V-26) — each gated by the test that created it passing with the hook off and park on; the E15
   baseline may only go up; H31's rule (a non-blocking fd forwards) and A4's `can_block` rule (a
   listening/unconnected socket forwards) move into `would_block` before their hooks go.
4. The rustls factory last (V-12, V-25, V-26, V-31, V-36 re-run through park + `ext/openssl`); A6
   closes by disappearance or stays open with the measured reason.
5. Offload trampolines fold into the table; the `create_object` hook stays only if a row cannot
   express "this class is constructed on a worker" — and the ADR says why.

Rollback: the feature flag stays until step 4; deleted code is recoverable at tag
`pre-consolidation` (owner-pushed; the session proxy refuses tags, 8ffd758).

## 7. Decision criteria and status

**Proposed**, with all three acceptance conditions now met — the status moves to *accepted* only
after the owner has seen this section, per CLAUDE.md (only the main agent sets "accepted", and this
ADR's own §7 is the gate):
1. §1's totals **measured for the deletions actually made**: −1,436 Rust lines, −42 `unsafe {`,
   −38 `unsafe fn` (V-46, V-48, V-49), against the estimate of −960/−40 — the estimate was low
   because the adds (stage 2, policy, SO_*TIMEO) came to less than the projected ~750 lines.
2. §4's re-measurements: E1/E2/E5 park on vs off indistinguishable, E4 three alternating quiet runs
   overlapping completely (V-46 addenda 1–2); the box's own drift from V-6 measured separately and
   shown not to be park (V-46 addendum 3, BACKLOG H-12).
3. The libphp audit exists with a verdict per symbol for every group whose rows entered the seed
   (research 30 groups (a), (b), (c); groups (d)/(e) cover what stays `block`).

4. **The lock hazard is measured, not assumed** (V-51, H36): a shim holding a mutex across a
   blocking `read` breaks under `park` (fiber 1 finds the mutex held by a parked fiber) and
   serializes cleanly under `block`. This is what makes §5's risk table and research 30's
   acceptance real rather than rhetorical: a `park` row added without a source audit fails exactly
   this way, and in a real library nothing reports it — the thread simply stops.

**Kill criterion.** Any E15 suite dropping below its baseline after a deletion that cannot be
fixed inside park within one cycle → that hook returns, and this ADR records it in an **"outside
the three"** table with the reason — the model stays three mechanisms plus listed, explained
exceptions.

| outside the three | reason | since |
|---|---|---|
| (none yet) | | |

Note: the `park` policy itself is the containment for the lock hazard — a library that holds a
lock across a blocking call stays `block` (research 27: libphp's opcache path; research 30 group
(d): `fcntl(F_SETLKW)`), and V-51 shows what happens when one does not.

## Progress

- **Cycle 1 (2026-09-17, V-46)** — park is the default build; `IGNIS_PARK` rows are `lib[:symbol]`
  (libphp is `-fvisibility=hidden`: `dladdr` sees the library, not `zif_*`); seed =
  `libphp:sleep,libphp:usleep,libphp:nanosleep,libcurl,libpq,libssl,libcrypto`; research 30 group
  (b) audited; **§6 step 3: `sleep.rs` deleted** (−112 lines, −7 `unsafe {`), V-22's gate through
  park with both off-controls blocking; phpt gate ≥ baseline in all six cells. E18-I1 turned out to
  be the scheduler's idle check ignoring `$ready` — fixed, not park's. Mechanisms: 6.
- **Cycle 2 (2026-09-17, V-47, V-48)** — stage-2 symbols (accept/accept4, select, ppoll, __poll_chk,
  recvmsg/sendmsg, readv/writev); research 30 groups (a)/(c) audited (no locks); `SO_RCVTIMEO`/
  `SO_SNDTIMEO` moved into park (`park_io`); **§6 step 3: `sockets.rs` and `accept.rs` deleted**
  (−641 lines, −32 `unsafe {`), every creating test green in both phases. Deviation: two hooks in
  one cycle (DECISIONS). Mechanisms: 4 — stream factory, offload, context, park. Next: step 4.
- **Cycle 3 (2026-09-17, V-49)** — **§6 step 4: the stream transport factory and the rustls path
  deleted** (`php/stream.rs` 705 lines, `reactor.rs` 812 → 450; `rustls`/`tokio-rustls`/
  `webpki-roots`/`rustls-pemfile` out of the build). The park registry moved to `php/wait.rs` —
  it was never a transport. PHP's `ext/openssl` does TLS and parks; **A6/B7 closes by
  disappearance** (`stream_select` answers from OpenSSL's own buffer in 22.9 µs). Every creating
  test green in both phases, plus chaos and smoke; a CI red on the previous commit
  (`socket_export_stream-1.phpt`) turned out to be the factory's and is fixed by its removal.
  **Mechanisms: 3 — park, offload, context. The target model of §2 is reached.** Net since cycle 1:
  −1,436 Rust lines, −42 `unsafe {` blocks; binary 48.7 → 37.2 MB.
