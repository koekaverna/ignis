# 32 — Park boot self-check and blocked-in-fiber detector: specification

Status: **spec only, unbuilt** (research doc for ADR-0037 §4's two countermeasures; main agent
implements). Written against `crates/ignis/src/php/park.rs` (647 lines) and
`crates/ignis/csrc/park.c` (68 lines) as they stand after cycle 2 (V-47/V-48: stage-2 symbols,
`sockets.rs`/`accept.rs` deleted), and `crates/ignis/src/config.rs`'s precedence (CLI flag >
env var > `ignis.toml` > product default, bridged into the environment by `default_env`, which
only sets a var the environment doesn't already have). Companion bench:
`bench/php/e18_selfcheck.php` (run and its output recorded in §C below — today's "before").

ADR-0037 §4 names the reason both exist: universal park changes the failure mode from "exception"
(a missing hook throws) to "hang" (a wrong park waits forever), and research 28's own run — "0
hits" on the `getaddrinfo` probe, which looked like a clean pass because nothing crashed — is the
concrete instance of the mistake this spec closes off mechanically rather than by remembering to
read the number.

## A. Boot self-check

### A.1 What is probed

Only libraries that are **both** (a) named in the resolved policy (`park::libs()`, i.e. `IGNIS_PARK`
if set, else the `SEED` constant in `park.rs:57` — today `libphp:sleep,libphp:usleep,
libphp:nanosleep,libphp:select,libphp:accept,libphp:poll,libphp:recv,libphp:send,libphp:recvfrom,
libphp:sendto,libphp:recvmsg,libphp:sendmsg,libphp:connect,libphp:read,libphp:write,libcurl,libpq,
libssl,libcrypto`) **and** (b) actually loaded in this process (`dlopen(name, RTLD_NOLOAD)` — cheap,
no I/O, returns null if the `.so` was never mapped, e.g. `ext/curl`/`pdo_pgsql` not enabled in
`php.ini`) get probed. `libphp` itself is excluded from probing (it is always loaded and is not the
thing research 28's mistake was about — the risk is a *third-party* library's calls not reaching
our symbol; libphp's own call sites are covered by the H36 lock audit, research 30, a separate
mechanism). In practice that means: probe `libcurl` if policy names it and `dlopen("libcurl.so.4",
RTLD_NOLOAD)` succeeds; same for `libpq` (`libpq.so.5`); `libssl`/`libcrypto` get the identical
treatment for completeness even though the ADR text singles out curl and libpq.

Per library, one call is made that forces **that library's own compiled code** — not our probe
code directly — to invoke an interposed symbol, because the failure research 28 guards against is
specifically "does interposition bind inside a third-party `.so`'s call sites", which a direct call
from our own Rust does not exercise (our own object also resolves `connect` to the exported symbol
trivially — that proves nothing about `libcurl.so`'s PLT). Both probes use a `connect()` to
`127.0.0.1:1` (a port nothing listens on, loopback only — no external network, no DNS, no root):

- **libcurl**: `dlsym` for `curl_easy_init`/`curl_easy_setopt`/`curl_easy_perform`, build a handle
  with `CURLOPT_URL=http://127.0.0.1:1/`, `CURLOPT_CONNECTTIMEOUT_MS=300`,
  `CURLOPT_NOSIGNAL=1`, call `curl_easy_perform`, `curl_easy_cleanup`. The call is expected to fail
  (`CURLE_COULDNT_CONNECT`) — the self-check does not care about the result, only about whether
  `ignis_park_connect` was entered (§A.2).
- **libpq**: `dlsym` for `PQconnectdb`/`PQstatus`/`PQfinish`, call
  `PQconnectdb("host=127.0.0.1 port=1 connect_timeout=1")`, `PQfinish`. Same story: expected to
  fail (`CONNECTION_BAD`), irrelevant to the check.
- **libssl/libcrypto**: no bare "make a socket call" API exists at this layer worth adding; these
  two ride on the libcurl probe (curl's TLS path is OpenSSL in this build, ADR-0020's TLS-backend
  note) — a `libssl`/`libcrypto` row in the policy without `libcurl` in the same run (unusual, but
  not disallowed) is **not separately probed**; recorded as a known gap, not a silent one (§A.6).

### A.2 Counting a "hit" without adding cost to the hot path

The self-check runs **once, before the HTTP/gRPC listener binds and before any worker thread with
a reactor starts** (i.e., after PHP MINIT — extensions must be loaded so `dlopen(RTLD_NOLOAD)` can
see them — but before `main.rs` spawns worker threads). It is single-threaded, so there is no
concurrency to protect against; the design goal is only "must not add a branch or a counter to the
per-syscall path that every request pays" (H35's <20 ns/syscall budget), which a boot-only routine
automatically satisfies as long as it does not leave anything behind that later syscalls have to
check.

Mechanism: a thread-local `SELFCHECK: Cell<Option<&'static AtomicU32>>` (default `None`), set to
`Some(&HIT)` only for the duration of the boot probe call, cleared immediately after (a
`Drop` guard, same shape as `InHandler` at `park.rs:109`). `site_parks` (`park.rs:126`) already
does the one relevant thing — resolve the return address to a library with `dladdr` — for every
call; the self-check adds a single `if let Some(c) = SELFCHECK.with(|s| s.get()) { c.fetch_add(1,
Relaxed); }` **inside that existing function**, immediately after computing `base` (the resolved
library basename), gated on the thread-local being `Some`. Outside the boot probe window the
thread-local is `None` on every thread for the rest of the process's life, so the check compiles to
one predictable branch on a path that already costs far more (a `dladdr` call, cache miss) than the
branch itself — no measurable addition, and nothing at all on the ~8 ns non-fiber gate path
(`PARK != 1` returns before `site_parks` is ever reached).

Critically, the hit is counted at **function entry** (inside `ignis_park_connect`, before the real
`connect()` syscall runs), not at completion — so the self-check's verdict does not depend on the
loopback connect actually finishing, timing out, or the kernel's refusal latency. This is what
makes the probe network/environment-independent: as long as `ignis_park_connect` is entered at all,
the hit is already recorded before anything that could be slow or environment-dependent happens.

Also record, for the operator-facing message on a miss, whether `dladdr` resolved the return
address to the expected library at all (a *different* library name than expected is its own
distinct failure — e.g., static linking merged symbols in a way that makes attribution wrong —
logged, but treated the same as "0 hits" for the refuse-to-start decision).

### A.3 What happens on a miss

**Refuse to start**, matching the existing convention for a startup configuration error
(`config.rs`'s `serve_to_legacy_args` error path returns `ExitCode::from(2)` in `main.rs`, not a
new number): exit code **2**. Before exiting, log at `error` (self-check runs before the `warn`
floor would hide anything, and this is exactly the class of thing ADR-0022's floor exists for) one
line per library that missed:

```
error: park self-check: libcurl is on IGNIS_PARK but no interposed call was observed from inside
libcurl.so during boot probe (0 hits) — parking for this library would silently never happen and
every "parked" call would actually block the PHP thread. Check: was the binary built with
-Wl,--export-dynamic-symbol for connect/poll (build.rs)? Is this libcurl.so statically resolving
its own symbols (LTO, static curl)? Set IGNIS_SKIP_PARK_SELFCHECK=1 to start anyway (not
recommended — see docs/research/32-park-selfcheck-and-detector.md).
```

then `std::process::exit`-equivalent return of `ExitCode::from(2)` from `main`, before the tokio
runtime or any worker thread exists — there is nothing yet to shut down cleanly. A **miss is not a
panic**: it is a clean, documented refusal, because the alternative (starting anyway) is strictly
worse than the alternative CLAUDE.md already rejects for `park` itself — parking wrongly is a hang,
and here the failure mode is quieter than a hang: every "parked" library just blocks the PHP
thread, indistinguishable under load from a stalled thread until someone reads the logs research 28
almost didn't.

Override: `IGNIS_SKIP_PARK_SELFCHECK=1` starts anyway, after printing the same message at `warn`
instead of `error` with "self-check skipped (IGNIS_SKIP_PARK_SELFCHECK=1)" appended — for a sandbox
where loopback genuinely does not behave (rare, but the escape hatch matches the codebase's existing
pattern of `IGNIS_NO_*` / `IGNIS_SKIP_*` off-controls rather than no way out at all).

### A.4 Empty policy (`IGNIS_PARK=`)

`park::libs()` already treats an explicitly-empty `IGNIS_PARK` as "nothing parks" (`park.rs:70-82`:
`v.split(',').filter(|s| !s.is_empty())` yields an empty `Vec`). The self-check must read the same
resolved list, not the `SEED` constant, and probe **nothing** when `libs()` is empty — log one
`info` line ("park self-check: policy is empty (IGNIS_PARK=), nothing to probe, skipped") and
proceed. This is the one case in this spec where "0 hits" is correct and must not be flagged: the
self-check's question is "does what the policy claims will park actually park", and an empty policy
claims nothing.

### A.5 `IGNIS_NO_UNIVERSAL_PARK=1`

`park::install()` (`park.rs:85-94`) already returns before registering the fiber-switch observer
when this is set, logging `"universal park disabled"` at `info`. The self-check is called from the
same startup path, after `install()`, and short-circuits identically: if the env var is set, skip
probing entirely (log `info`, "self-check skipped: universal park disabled
(IGNIS_NO_UNIVERSAL_PARK)") — there is no gate to have missed.

### A.6 Cost at boot

**Estimate, not measured**: `dlopen(_, RTLD_NOLOAD)` per library is a hash lookup in the loader's
already-loaded-object list, sub-microsecond. Each probe's `connect()` to a loopback port with
nothing listening returns `ECONNREFUSED` near-instantly on Linux (no SYN retransmit wait — RST
comes back on the same host) — call it under 1 ms per probe, bounded regardless by the 300 ms /
1 s timeouts set on the two client libraries as a safety net against an unusual sandbox. Total:
**low single-digit milliseconds**, once, at process start, before the listener binds — negligible
next to the ~7-minute PHP build or even the sub-second engine MINIT this rides on. If the override
path (§A.3) is exercised or the policy is empty (§A.4), cost is a handful of `dlopen(RTLD_NOLOAD)`
calls or nothing at all.

## B. Blocked-in-fiber detector

### B.1 Where the timing is taken, without a syscall per call

Every call already reaches `may_park` (`park.rs:117`), which is entered only when `PARK == 1` (a
fiber is active on this thread — non-fiber threads return `None` before any of this, so H35's
~8 ns budget is untouched by definition, not by care taken here). `may_park` returns `Some(guard)`
when the call is going to attempt to park, `None` when it is not. There are exactly two places
where "not parked" resolves into "the real, possibly-blocking syscall runs anyway", and both are
already distinct code paths:

1. **`may_park` returns `None`** because `site_parks` resolved the caller's library (or
   library:symbol) to `false` — the policy says `block` for this call site. This is the common,
   sanctioned case (most of the process's syscalls, by construction: default policy is `block`,
   ADR-0020 decision 3).
2. **`may_park` returns `Some(guard)`** (policy says `park`), but the subsequent parking attempt
   could not complete: `park_on`/`park_io`/`park_pollfds`/`park_sleep` return "could not park"
   (`park.rs:180`: `try_reactor()` found no reactor on this thread — should not happen on a real
   worker thread but is exactly the kind of thing this detector exists to catch if it ever does) —
   the call site then falls through to the raw blocking syscall (visible in every handler as the
   final `libc::syscall(...)` after an `if` whose condition was false or whose branch fell through).

Timing wraps the **raw forward call itself**, in both cases, with `std::time::Instant::now()`
(Linux: `clock_gettime(CLOCK_MONOTONIC)` through the vDSO — no kernel entry, no syscall — already
what `Instant` uses on this platform) immediately before and after. This adds two vDSO reads only
to calls that were already going to block synchronously in the kernel for an unknown-but-nonzero
time — the cost of a `clock_gettime` pair (tens of nanoseconds) is immaterial next to a call that,
by construction, is about to cost at minimum microseconds and is being flagged specifically because
it might cost milliseconds. It adds **nothing** to: the non-fiber path (excluded before
`may_park`), the successful-park path (the fiber suspends; the reactor, not a busy wait, owns that
latency and it is not "blocked" in the sense this detector means), and the `PARK == 2` reentrant
path (nested calls from inside a handler never reach `may_park`'s `Some`/policy branches with
`PARK == 1`, because the guard sets it to `2` first).

### B.2 Default N and configuration

Default **50 ms**. Rationale: the existing watchdog (`main.rs:200`, `http::stalled_threads`,
ADR-0022) already flags a thread stalled for **1 s** as a coarse, externally-sampled, thread-level
signal — useful for "is this thread wedged" but far too coarse to catch the head-of-line-blocking
class of problem ADR-0037 §4 is about (a `block`-policy call serializing a handful of fibers on one
thread for tens of milliseconds is enough to show up in P99 latency long before it would ever trip
a 1 s stall). 50 ms is chosen as "long enough that ordinary scheduling jitter, a slightly slow page
fault, or a fast disk read do not fire it" and "short enough to catch the class of bug this exists
for before it becomes a user-visible latency spike" — an estimate, tunable per deployment, not a
measured number (no suite yet exercises it to justify a tighter figure).

Configuration follows `config.rs`'s existing pattern exactly: a new field on the (currently
nonexistent) `[park]` table already implied by ADR-0020 (`ignis.toml [park] libraries = [...]`,
`park.rs:45`) —

```rust
#[derive(Deserialize, Default, Debug)]
#[serde(deny_unknown_fields)]
pub struct Park {
    pub libraries: Option<Vec<String>>,       // already implied by ADR-0020; not yet a Config field — add alongside
    pub blocked_in_fiber_ms: Option<u64>,
}
```

added as `pub park: Park` on `Config`, bridged in `serve_to_legacy_args` the same way
`budget.fibers` is (`config.rs:107-110`):

```rust
if let Some(v) = cfg.park.blocked_in_fiber_ms {
    default_env("IGNIS_BLOCKED_IN_FIBER_MS", &v.to_string());
}
default_env("IGNIS_BLOCKED_IN_FIBER_MS", "50");
```

so precedence is CLI (none defined for this — there is no per-flag CLI surface for `park` options
today, matching `libraries`) > `IGNIS_BLOCKED_IN_FIBER_MS` env (already-set wins, `default_env`'s
rule) > `ignis.toml [park] blocked_in_fiber_ms` > product default `50`. `park.rs` reads it the same
way `libs()` reads `IGNIS_PARK` today: a `OnceLock<u64>` parsed once. **`0` disables the detector**
(matching `budget.fibers`/`budget.queue`'s "0 = unbounded/off" convention, `config.rs:43-45`) —
every timed branch becomes a no-op comparison against a constant that can never be exceeded... more
precisely, `0` should skip the `Instant::now()` pair entirely (checked once via the same `OnceLock`
load pattern `trace()` uses at `park.rs:61-68`), not merely fail every comparison, so a deployment
that wants zero added vDSO reads on the not-parked path can have that.

### B.3 Identifying the offender

**Library**: already resolved. `site_parks` (`park.rs:126-148`) computes `base` (the `dladdr`-derived
basename, e.g. `libcurl.so.4`, cached by return address in `SITES`) for every call already — the
detector reuses that same string, no new resolution needed. For case 2 (§B.1, "policy says park but
couldn't"), the same cached-by-return-address library name applies (the call still went through
`site_parks` to get a `true` verdict before the parking attempt failed).

**PHP function**: not currently captured anywhere in `park.rs`, but the pattern exists one file over
in `crates/ignis/src/php/route.rs:106-111`'s `frame_name`, which reads `(*execute_data).func` →
`(*f).common.function_name` for a `zend_execute_data` it already has as a parameter (a zif handler's
own frame). The detector does not have a `zend_execute_data*` handed to it — it needs the
*currently executing* PHP frame at the moment a C library is blocked deep inside a syscall park.rs
intercepted. That is `EG(current_execute_data)`, read the same way `park.rs`'s own `eg()` helper
(`park.rs:104-106`) already reads executor globals (`tsrm_get_ls_cache()` + `executor_globals_offset`
— both already resolved once at MINIT for the fiber-switch observer, no new lookup machinery
needed): `(*eg()).current_execute_data`, then the same two-field walk `frame_name` does —
`function_name` for the function, and if `(*f).common.scope` is non-null, that scope's `name`
zstr prepended as `Class::method` (a method call is otherwise indistinguishable from a
same-named global function in the log). `NULL` `current_execute_data` (should not normally happen
inside a fiber that got this far, but the field can be null very early in a frame's setup) resolves
to the string `"?"` rather than a panic or a skipped sample — the metric and log still fire, with an
honest "don't know" instead of losing the sample.

Cost: this resolution only happens on the already-timed, already-over-threshold branch (i.e., after
the elapsed check in §B.1 already decided a sample fires) — never on every not-parked call, only on
the ones that actually blocked past N ms. A not-parked call that returns in 2 ms (recv with no
sock timeout, resolved instantly, common case for `block`-policy libz/libonig-style calls) never
pays for function-name resolution at all.

### B.4 Metric name, labels, log level (ADR-0022)

Metric: **`ignis_blocked_in_fiber_seconds{lib,func,reason}`** — the exact name ADR-0037 §4 already
commits to. `reason` ∈ `policy_block` (case 1, §B.1 — the library's policy row is `block`, expected,
not a bug by itself) | `park_failed` (case 2, §B.1 — policy said `park` and the attempt could not
complete, the surprising case this whole mechanism exists to surface). Shape: a histogram/summary
in spirit (`_seconds` suffix, ADR-0022's own convention) but since no metrics exporter exists yet
(`/_ignis/metrics` is unbuilt per ADR-0022's table, M3-7/M4-4) the interim representation is an
in-process table — `OnceLock<Mutex<HashMap<(String, String, &'static str), Sample>>>` with
`Sample { count: u64, sum_ms: u64, max_ms: u64 }` — updated only on a detector fire (rare by
construction: something has to have blocked past N ms), so lock contention is not a hot-path
concern. This table is what `/_ignis/metrics` reads once it exists (M3-7); until then it is exposed
the same way `stalled_threads()` is consumed by `/_ignis/health` today (`http.rs:58,136`) — a plain
Rust function other runtime code can call, e.g. from a future `/_ignis/stats` addition.

Log level: **`debug`** for `reason=policy_block` (expected, still worth being able to find when
digging, not worth a default-`warn` floor seeing it — this is exactly the class of thing that
would otherwise turn into alert fatigue given `block` is the *default* policy for everything not
explicitly listed). **`warn`** for `reason=park_failed` (policy explicitly said this library should
park; it didn't; that is the "wrong park is a hang" case CLAUDE.md and ADR-0020 both call out, and
`warn` is the floor ADR-0022 raised specifically so this class of thing is not silent). Fields on
both: `lib`, `func`, `ms` (elapsed), `reason`, `fd` (where available — a park attempt always has
one; `policy_block` calls the identical handler signatures so `fd` is always in scope too).

Log-line volume: rate-limit `warn`/`debug` emission per `(lib, func, reason)` key — first
occurrence logs immediately, subsequent occurrences within a rolling window (reuse the shape, not
necessarily the code, of `main.rs:200-203`'s "only log when the count changes" pattern) log at most
once per few seconds with a "seen N times since" count, while the metric table's `count`/`sum_ms`
keep incrementing on every fire regardless — sustained load hitting the same offending call site
must not flood stderr, but must not lose the measurement either.

## C. Failure modes of the mechanism itself

| risk | which mechanism | mitigation |
|---|---|---|
| A legitimately slow-but-not-broken syscall (large write to a nearly-full pipe, a slow but real disk read on a `block`-policy path) reads as an "alarm" | detector | Not an alarm by construction: `policy_block` logs at `debug`, not `warn` — it is telemetry ("this is where fibers spend blocked time"), not a page. Only `park_failed` (policy said park, couldn't) is `warn`, because that is the case that is actually surprising. |
| A non-fiber thread (tokio, an offload worker, curl's resolver helper thread) doing genuinely blocking work by design gets flagged | detector | Cannot happen by construction, not by an added check: the timed branches live entirely inside `may_park`'s `PARK == 1` gate (`park.rs:117-124`), and non-fiber threads never set `PARK` to `1` (`park.rs:96-102`, the fiber-switch observer only fires for fiber contexts) — those threads return from `may_park` (or rather, never call it in a state that matters) before any timing code exists. |
| A `block` row that is `block` *on purpose* (research 27/30's verdict, e.g. libphp under a lock, or `libz`/`libsqlite3` never audited) shows up in logs/metrics as if it were a defect | detector | This is exactly what `reason=policy_block` + `debug` level is for: visible on request, silent by default, and distinguishable in the metric from `park_failed` by the label — an operator or the ADR-0037 audit work can query "which `policy_block` call sites cost the most total time" to prioritize research 30's next group, without that query ever paging anyone. |
| Boot self-check flags a library as "not interposed" when it is simply **not loaded** (extension disabled) | self-check | §A.1's `dlopen(_, RTLD_NOLOAD)` gate: a library not mapped into the process is skipped, not probed, not flagged — absence is not the failure this check is for. |
| Boot self-check flags a library because the loopback probe's `connect()` itself is slow/blocked in an unusual sandbox (no loopback, a restrictive netns) | self-check | The hit is counted at **function entry** (§A.2), before the real syscall runs — the verdict does not depend on the connect completing, timing out, or succeeding, only on whether our symbol was entered at all. The client-library timeouts (300 ms curl, 1 s libpq) are a backstop so the *process* (not the verdict) does not hang in a pathological sandbox. |
| Self-check probes a library the operator deliberately excluded from `IGNIS_PARK` (e.g. `IGNIS_PARK=libpq` only, no `libcurl`) | self-check | §A.1 probes only libraries present in the **resolved** policy (`park::libs()`), never the `SEED` constant directly — an operator's override is respected, not second-guessed. |
| Detector or self-check adds cost to the ~8 ns/syscall non-fiber path that H35 gates on | both | Both are structurally downstream of the `PARK == 1` check (detector) or run once before any worker thread exists (self-check) — neither touches the branch every tokio-thread/offload-worker syscall takes. §B.1 and §A.2 each say exactly where the one new branch/counter lives and why it is on an already-more-expensive path. |
| Clock adjustment (NTP step) makes an elapsed-time measurement wrong | detector | `Instant`/`CLOCK_MONOTONIC` is immune to wall-clock steps by construction; not a real risk here, noted for completeness. |

## D. Bench script

`bench/php/e18_selfcheck.php` (written alongside this doc) is the smallest reproduction: one fiber
does a `socket_recv()` with `SO_RCVTIMEO=200ms` on a Unix socketpair nobody ever writes to (a
genuine, bounded, kernel-timed block — no network, no DB, no root), run under
`IGNIS_PARK=libphp:usleep` so `recv` (normally on the default `SEED`) is *not* on the narrowed
policy for this run — reproducing "a call the table does not cover" without needing a second
library. The control fiber does `usleep(200_000)`, which the narrowed policy still allows to park.
Both fibers are started before either is awaited.

Verified run (2026-09-17, this box, release binary already built, `universal-park` default-on
feature):

```
$ IGNIS_PARK=libphp:usleep LD_LIBRARY_PATH=/opt/php85-zts/lib ./target/release/ignis bench/php/e18_selfcheck.php
e18_selfcheck: case=blocks(recv, off policy) result=false elapsed_ms=201
e18_selfcheck: case=parks(usleep, on policy) elapsed_ms=201
e18_selfcheck: total_wall_ms=402 (serialized ~= 400 if 'blocks' really blocked the thread; ~= 200 if both ran concurrently)
```

This is the "before" measurement: total wall is ~402 ms, i.e. the two 200 ms operations serialized
on the one PHP thread rather than overlapping — proof today's runtime has no visibility into it
(no warning, no metric, nothing but this bench's own print). Once §B is built with the default
`IGNIS_BLOCKED_IN_FIBER_MS=50`, the same invocation is expected to additionally print (to stderr, at
`warn`, via `tracing`) one line naming `lib=libphp func=socket_recv reason=policy_block ms=200` (or
close to it, allowing the `SO_RCVTIMEO` fudge already documented in park.rs's `sock_timeout_ms`),
and the in-process metric table (§B.4) to hold one sample under that same key — the script's own
output is unchanged (it does not read the metric or the log; it is the workload, not the
assertion), which is why the file is safe to keep as a bench script rather than needing a rewrite
once the detector lands.
