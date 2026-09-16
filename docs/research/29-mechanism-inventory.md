# 29 — Mechanism inventory (measured), for ADR-0037

2026-09-17, main agent, at commit `cf8db53` plus the E18 stage-1 code (`5822e37`). Method: `tokei`
and `cloc` are not installed on this box, so **lines = `wc -l` per file** (blank and comment lines
included — consistently, for every row) and **`unsafe` = occurrences of `unsafe {` (blocks) and
`unsafe fn`/`unsafe extern` (declarations)** counted with `grep -c`. Tests: the suite or bench that
gates the row today; V-n: the entry that created it. Adapters are listed separately and are not
mechanisms.

## Mechanisms (a request's wait can go through these today)

| # | mechanism | files | Rust | C | PHP | `unsafe {` | `unsafe fn` | gated by | created by |
|---|---|---|---|---|---|---|---|---|---|
| 1 | stream transport factory: `tcp://`/`ssl://`/`tls://`/`unix://`, `op_read/write/connect/cast/set_option`, `hooked_select`, `has_buffered`, timeouts, META_DATA_API | `php/stream.rs` | 705 | 0 | 0 | 16 | 17 | E15 streams phpt (fiber 125/160), Revolt DriverTest (80/81), smoke E6/E6''/E11, Symfony chaos (V-27) | V-12, V-25, V-26, V-31, V-36 |
| 1b | connection actor + rustls arms inside the shared reactor (`conn_actor`, `Connect`/`Upgrade`/`Read`/`Write`/`TryRead`, `watch_fd`) | `reactor.rs` (shared; 812 lines total) | ~250 of 812 (**estimate** — the file is not split by concern) | 0 | 0 | 6 (whole file) | 0 | as row 1; nextest `reactor::tests` (4) | V-12, V-25 |
| 2 | `ext/sockets` hooks (nine functions, `can_block`) | `php/sockets.rs` | 326 | 0 | 0 | 21 | 16 | E15 sockets phpt (fiber 84/118), Swoole shim (55/153) | V-29 |
| 3 | `sleep()`/`usleep()` MINIT swap | `php/sleep.rs` | 112 | 0 | 0 | 7 | 8 | smoke "E15 fixes" leg; E15 fibers phpt | V-22 |
| 4 | `stream_socket_accept` hook | `php/accept.rs` | 315 | 0 | 0 | 11 | 12 | E15 streams phpt, Revolt, Swoole shim, smoke server leg | V-26 |
| 5 | offload: workers, routing trampolines, `create_object` hook, worker-pinned proxies, PHP client | `offload.rs`, `php/route.rs`, `php/offload/ignis-offload.php`, `php/offload/worker.php` | 329 | 0 | 490 | 10 | 10 | **no suite** — `bench/e16-offload.sh` and smoke's `/offload` route only | V-24 |
| 6 | context: superglobal/scope fiber-switch observer | `php/superglobals.rs` (+ `Ignis\Scope` in `php/ignis.php`) | 269 | 0 | (in 825) | 15 | 14 | smoke E13 (isolation, 200 concurrent HTTP), Symfony chaos (V-27), phpt fiber modes | V-11 |
| 7 | universal park, stage 1 | `php/park.rs`, `csrc/park.c` | 397 | 47 | 0 | 23 | 19 | **no suite in CI** (feature off); phpt/E1/E2 run once on the park build (V-45 addendum) | V-45 |

Shared beneath every mechanism (kept by any model): `reactor.rs` 812 (6 blocks), `php/module.rs`
867 (34 / 31), `php/embed.rs` 292 (14 / 4), `php/zval.rs` 177 (14 / 10), `php/ignis.php` 825.

## Adapters (carry no mechanism)

| adapter | files | Rust | PHP | `unsafe {` | gated by | created by |
|---|---|---|---|---|---|---|
| Revolt driver | `php/amphp/src/IgnisDriver.php`, `prepend.php` | 0 | 131 | 0 | Revolt DriverTest (gate 80) | V-13, V-23 |
| symfony/runtime | `php/symfony/src/*` | 0 | 133 | 0 | Symfony chaos (V-27), V-40 image run | V-16, V-40 |
| gRPC glue | `grpc.rs`, `php/grpc/ignis-grpc.php` | 250 | 242 | 0 | `bench/e10-grpc.sh` (bench, no suite) | V-20 |
| Temporal glue | `backend/temporal.rs`, `php/temporal/ignis-temporal.php` | 331 | 249 | 10 | CI job E9 (replay + negative control) | V-18, V-19 |
| PostgreSQL pool | `pg.rs`, `php/pg/ignis-pg.php` | 386 | 159 | 0 | `bench/e14-pg.sh`, `bench/m4-pool-survives.sh` (benches, no suite) | V-21, V-42–44 |
| classic mode | `php/classic.php` | 0 | 271 | 0 | FrankenPHP testdata (gate 29) | V-23 |

Tree totals: Rust 6,326 lines under `crates/ignis/src` with **187 `unsafe {` blocks and 154
`unsafe fn`/`extern` declarations**, C 47, PHP userland 3,288 (without vendor, apps, examples).
The four deleted rows hold 55 of the 187 blocks (29 %).

## What the target model (ADR-0037) deletes, keeps, adds

**Deletes (measured):** rows 1, 2, 3, 4 — **1,458 Rust lines, 55 `unsafe {`, 53 `unsafe fn`** — plus
row 1b's actor and rustls arms (**estimate ~250 lines**, 6 blocks at most) and the routing
trampolines in `route.rs` (**197 lines, all 10 of offload's `unsafe {` blocks** — `offload.rs` itself is 132 lines with none; the worker side of row 5 stays).

**Keeps:** row 6 (context, 269 / 15), row 7 (park, 444 / 23) growing into the table, the worker
half of row 5, everything shared, every adapter.

**Adds (estimate, unmeasured):** E18 stage 2 symbols with `ECANCELED` ~300 Rust + ~40 C; per-symbol
policy and the `ignis.toml` table ~150; boot self-check ~60; blocked-in-fiber detector and its
metric ~200 — **~750 lines, ~15 `unsafe {`**.

**Net (estimate, because the adds are):** about **−960 Rust lines** and **−40 `unsafe {`** blocks
against today's 1,458 + ~250 deleted and ~750 added. The measured half of that sentence is the
1,458 / 55 / 53 that go; the rest is labelled estimate and stays out of ADR-0037's decision table.

## Findings the counting produced

- Offload (row 5) and universal park (row 7) are gated by **no correctness suite**, only benches:
  ADR-0037 §6 makes a suite a precondition for deleting anything they replace.
- Row 1 is four behaviours in one file (transport, `select`, timeouts, TLS/meta): the one row that
  cannot be explained on a page today (ADR-0037 §3).
- Row 1b cannot be counted without splitting `reactor.rs` by concern; that split is a
  prerequisite for measuring the deletion, not an estimate to keep.

## Addendum — cycle 1 (ADR-0037 §6 step 3): row 3 deleted

`php/sleep.rs` is gone: **−112 Rust lines, −7 `unsafe {`, −8 `unsafe fn`** (measured, V-46). Row 7
grew by the `lib:symbol` policy grammar and a per-site trace. Tree after the cycle: see V-46's
recount. Mechanisms a wait can take: 6.

## Addendum — cycle 2 (2026-09-16, V-48): rows 2 and 4 deleted

`php/sockets.rs` (326 / 21 / 16) and `php/accept.rs` (315 / 11 / 12) are gone: **−641 Rust lines,
−32 `unsafe {`, −28 `unsafe fn`**; park grew by the SO_*TIMEO race and stage-2 symbols. Running
total deleted: 753 lines, 39 `unsafe {`. Mechanisms a wait can take: 4 (stream factory, offload,
context, park).

Final recount after cycle 2 (V-48 addendum): Rust 5,753 lines, 157 `unsafe {`, 126 `unsafe fn` —
net −573 / −30 / −28 against the table above; the dead adoption path (`adopt_fd`, `has_buffered`,
`Op::Adopt`, `set_double`, 64 lines) went with the hooks that fed it.
