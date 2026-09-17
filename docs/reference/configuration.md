# Configuration reference

Every setting Ignis reads: `ignis.toml` keys, the environment variables that back them, and the
env vars that exist only on the Rust side (no config-file equivalent).

**Precedence, highest first: CLI flag > environment variable > `ignis.toml` > product default.**
(`crates/ignis/src/config.rs`, doc comment on `Config`.) `ignis serve` is what implements this: it
loads the file (if any), then calls `default_env(NAME, value)` for each key — which sets the
environment variable **only if it is not already set** — before rewriting itself into the legacy
`[--supervise] [--threads N] [--offload N] <entry.php>` argument form the rest of `main` has always
understood. Because `default_env` never overwrites, an `IGNIS_THREADS=8` already in the process
environment beats whatever `ignis.toml` says, and a `--threads` flag on the plain `ignis` binary
(see `cli.md`) beats both — the plain binary reads its flags after the file/env bridge would have
already run under `serve`. This bridging happens **before any other thread exists** (`config.rs`
comment), which is why it's safe to call `std::env::set_var` there and nowhere else.

`ignis.toml` itself is parsed with `#[serde(deny_unknown_fields)]` on both `Config` and `Budget` —
a misspelled or renamed key is a parse **error**, not a silently-ignored default. There is no key
that reads a per-field default from `serde(default = ...)`; every field is `Option<T>`, and the
"default" behavior is entirely `serve_to_legacy_args`'s explicit `default_env` fallback calls.

## `ignis.toml`

One file, loaded by `ignis serve` (`--config PATH`, or `./ignis.toml` if present, else built-in
defaults with no file at all). Reference copies: `ignis.toml.example` (repo root, annotated) and
`docker/ignis.toml` (the image's `/etc/ignis/ignis.toml`, binds `0.0.0.0:8080` instead of
`127.0.0.1:8080` because inside a container the loopback default is unreachable from the host).

| Key | Env var it sets | Default | What it does | Edges |
|---|---|---|---|---|
| `entry` | *(none — becomes the positional arg)* | none; the resolved entry file must exist | PHP script every worker thread runs. Also accepted as `ignis serve app.php`, which wins over the file's `entry`. | Missing entirely (`entry` absent from the file *and* no positional arg): `serve` exits 2 with "serve needs an entry script...". Entry present but not a real file: exits 2 with "entry script … does not exist". |
| `listen` | `IGNIS_LISTEN` | `127.0.0.1:8080` | `host:port` the HTTP/gRPC listener binds. | The Rust binary itself never reads `IGNIS_LISTEN` back out — it only *sets* it. An entry script has to call `getenv('IGNIS_LISTEN')` itself and pass it to `Ignis\serve()`/`Ignis\Classic\serve()` (every example script and the Symfony runtime does this); a script that hardcodes an address ignores `listen`/`IGNIS_LISTEN` entirely. |
| `threads` | `IGNIS_THREADS` | machine's available parallelism (`std::thread::available_parallelism()`) | PHP worker OS threads. | 0 or unparseable is not validated by `config.rs`; the consuming side (`main.rs`) does `threads.max(1)`, so anything ≤ 0 silently becomes 1. This default (available parallelism) applies **only** under `ignis serve` — the plain `ignis <script>` binary's own fallback is `1` (see `cli.md`). |
| `offload` | `IGNIS_OFFLOAD` | `0` (off) | Synchronous offload worker threads for calls that **cannot** park — `SQLite3` and file-backed `PDO` (a regular file is not epoll-able, ADR-0024) and CPU-bound work. `curl_*` no longer routes here: it parks (V-59) (ADR-0016/E16). Each costs a full PHP thread. | Unlike `threads`/`budget.*`/`listen`, there is **no unconditional `default_env` call** for `offload` in `config.rs` — if the file is silent and `IGNIS_OFFLOAD` is unset, the env var is simply never set, and `main.rs`'s own `unwrap_or(0)` supplies the 0. Net effect is the same default, reached by a different path. |
| `supervise` | *(none — becomes the `--supervise` flag)* | `true` under `ignis serve`; `false` on the plain `ignis` binary unless `--supervise` is passed | Respawn a worker whose script ends (fatal error or normal exit), ADR-0012. | `ignis serve`'s default is `true` even if the key is absent from the file — `cfg.supervise.unwrap_or(true)`. This is the one setting where `serve` and the raw binary disagree on the out-of-the-box behavior. |
| `php_ini` | `IGNIS_PHP_INI` | unset (engine's compiled-in php.ini search path) | Extra php.ini path; the embed SAPI has no `-c`/`-d` flags of its own. | No unconditional default — absent key + absent env means `IGNIS_PHP_INI` is never set and `embed.rs` passes `None` to the engine init. |
| `log` | `RUST_LOG` | unset → `tracing_subscriber` falls back to `warn` | Log filter in `RUST_LOG`/`tracing_subscriber::EnvFilter` syntax. | No unconditional default here either; `main.rs` supplies `"warn"` itself if `RUST_LOG` ends up unset by any path. `warn` is a deliberate floor (comment in `main.rs`): it's what makes worker respawns and stalled-thread warnings visible without opting in. |
| `budget.fibers` | `IGNIS_FIBER_BUDGET` | `1024` | Admitted request fibers per thread at once (ADR-0019). A held fiber is ~15 kB (some comments say ~34 kB, see caveat below) of RSS; past the budget a request waits as **data**, not as a Fiber. `0` = unlimited. | Read back on the PHP side by `Ignis\Loop::budgetInit()` via `getenv('IGNIS_FIBER_BUDGET')`, not by any Rust code — the Rust side only ever *writes* this env var. |
| `budget.queue` | `IGNIS_QUEUE_DEPTH` | `4096` | Requests allowed to wait as queued data once the fiber budget is full. Past it, the answer is `503` + `retry-after: 1`. `0` = unbounded. | Same read-side note as `budget.fibers`: consumed only by `php/ignis.php`. |
| `exempt` | `IGNIS_BUDGET_EXEMPT` | `["/_ignis/"]` | Path **prefixes** admitted regardless of the budget, so health/metrics endpoints stay reachable on a saturated server. Written to the env as a comma-joined string. | `Ignis\Loop::isExempt()` does a plain `str_starts_with`; entries with a literal comma in the path can't be expressed this way. Also read only by `php/ignis.php`. |

`crates/ignis/src/config.rs` doc comment says a fiber costs "~15 kB (V-37)"; `php/ignis.php`'s own
comment on `$fiberBudget` says "~34 KB (V-5)". Both are cited to different VALIDATION.md entries —
flagging the discrepancy rather than picking one, since neither is something this pass can
re-measure.

## Environment variables outside `ignis.toml`

Everything below is read directly with `std::env::var`/`var_os` somewhere in `crates/ignis/src/**`
(or, for the two PHP-side scheduler knobs at the end, `getenv()` in `php/ignis.php`) and has **no
`ignis.toml` key at all** — the file cannot express them.

### Supported settings (meant for production use)

| Env var | Read in | Default | What it does |
|---|---|---|---|
| `IGNIS_PARK` | `crates/ignis/src/php/park.rs` | the built-in seed (see below) | Universal-park policy table: which libraries/symbols may park a fiber instead of blocking the OS thread. See its own subsection below. |
| `IGNIS_OFFLOAD_FUNCTIONS` | `crates/ignis/src/php/route.rs` | **empty** (was the `curl_*` list until 2026-09-17; curl parks now — V-59, and setting the old list back restores routing) | Comma-separated internal function names auto-routed to the offload pool (E16 auto-routing) when running inside a fiber and the extension is loaded; unset means "the default list", not "nothing". |
| `IGNIS_OFFLOAD_CLASSES` | `crates/ignis/src/php/route.rs` | `SQLite3` (was `PDO,SQLite3` until 2026-09-17: routing every `PDO` sent `pgsql` to a worker too, 9× slower than parking — V-59 addendum. Add `PDO` back for a `pdo_sqlite` app) | Comma-separated class names whose `new` is auto-routed to the offload pool inside a fiber. |
| `IGNIS_NO_OFFLOAD_ROUTE` | `crates/ignis/src/php/route.rs` | unset (routing installed) | Any value disables auto-routing installation entirely (`route::install()` returns immediately) — the E16 hook-off control. |
| `IGNIS_PG_LEASE_WARN_MS` | `crates/ignis/src/pg.rs` | `5000` | A PostgreSQL connection lease held longer than this is counted in `ignis_pg_lease_age_seconds_max` / `ignis_pg_leases_over_warn` (`/metrics`, `crates/ignis/src/metrics.rs`) and in `ignis_pg_stats()['leases_over_warn']`. Does not itself kill or reclaim the lease. |
| `IGNIS_LOCKLIB` | `crates/ignis/src/php/locklib.rs` | unset (the `ignis_locklib_*` functions are never registered) | Path to the H36 lock-hazard test shim (`bench/e18/locklib.c`), `dlopen`ed with `RTLD_GLOBAL`. Exists only to prove that `park` deadlocks a library holding a non-recursive mutex across a blocking syscall while `block` does not (ADR-0037 §5) — not something an application ever sets. Listed here rather than under diagnostics because it gates whether a whole function family exists, not just a runtime behavior. |
| `IGNIS_POLL_SPIN_US` | `crates/ignis/src/reactor.rs` | `0` | Microseconds the reactor's `poll` spins on `try_recv` before parking the OS thread (H30). `0` means never spin — go straight to the blocking receive. |
| `IGNIS_LOOP_GC` | `php/ignis.php` (`Loop::gcInit`, read via `getenv`) | on (any value other than `""`/`"0"`, **including unset**, counts as on) | Takes PHP's automatic cycle collector off the hot path: `gc_disable()`, and the userland loop calls `gc_collect_cycles()` itself at an idle point (never mid-request) once the root buffer crosses `IGNIS_LOOP_GC_ROOTS`. |
| `IGNIS_LOOP_GC_ROOTS` | `php/ignis.php` | `5000` (floored at `100`) | Root-buffer size that triggers the loop's own `gc_collect_cycles()` when `IGNIS_LOOP_GC` is on. |

### Front door: limits and shutdown

Added for production (V-55, V-56). All four limits and both drain knobs are environment variables
today; none has an `ignis.toml` key yet (each is marked in `http.rs` with the key it will take).

| Env var | Read in | Default | What it does |
|---|---|---|---|
| `IGNIS_MAX_BODY_BYTES` | `crates/ignis/src/http.rs` | `8388608` (8 MiB) | Request bodies over this are refused with **413** as soon as a frame would exceed it — the rest is never buffered. |
| `IGNIS_HEADER_TIMEOUT_MS` | `crates/ignis/src/http.rs` | `10000` | A client that has not finished sending headers by then has its connection closed (slowloris). |
| `IGNIS_IDLE_TIMEOUT_MS` | `crates/ignis/src/http.rs` | `60000` | An accepted connection with no request for this long is shut down gracefully. |
| `IGNIS_MAX_CONNECTIONS` | `crates/ignis/src/http.rs` | `8192` | Concurrent accepted connections. Over the cap a raw `503` + `Connection: close` is written and the socket dropped — never queued. ADR-0025: this is the RSS bound, because a held connection costs ~33 kB (V-37). |
| `IGNIS_DRAIN_DELAY_MS` | `crates/ignis/src/http.rs` | `0` | On `SIGTERM`/`SIGINT`: how long `/_ignis/health` answers `503 {"status":"draining"}` **while still accepting**, so a load balancer can take the instance out of rotation before the socket closes. |
| `IGNIS_DRAIN_TIMEOUT_MS` | `crates/ignis/src/http.rs` | `10000` | After the listener closes, how long in-flight requests get to finish. Past it the process exits and logs how many were still pending. |

Measured behaviour: 413 on an oversized body, connections closed on both timeouts, 76,969 `503`s
under `wrk -c64` against a cap of 8 with the server still healthy afterwards, and a `SIGTERM` drain
that finished five 1.5 s requests while refusing new connections (V-55, V-56).

### `IGNIS_PARK` — universal-park policy table

Grammar (comma-separated entries, whitespace around each entry trimmed):

```
lib[:symbol][,lib[:symbol]...]
```

- `lib` alone (e.g. `libcurl`) — every interposed symbol called from that shared object may park.
- `lib:symbol` (e.g. `libphp:usleep`) — only that one symbol from that library parks. This form
  exists specifically for `libphp` itself: it's built `-fvisibility=hidden`, so `dladdr` cannot
  resolve its `zif_*` call sites to a library-wide match, and the policy has to be per call site
  (ADR-0037 §2 — "a call site calls exactly one symbol, so the per-site cache holds").
- Matching is by **library basename prefix**, resolved once per call site via `dladdr` and cached
  in a thread-local `HashMap<usize, bool>`.
- **Unset `IGNIS_PARK`** → the built-in seed is used (below), not "nothing parks".
- **Set but empty** (`IGNIS_PARK=`) → the split-on-comma-and-filter-empty parse yields zero
  entries, so nothing at all is in the policy and every interposed call blocks (`block`) instead of
  parking. This is the one place empty and unset behave oppositely.
- Anything not named — including `libphp` symbols outside the seed's `libphp:*` entries — defaults
  to **`block`**: the call runs as an ordinary blocking syscall on the PHP OS thread. Delegating to
  `block` is always semantically correct; parking a call that isn't actually safe to park is a hang
  (ADR-0018), so the default is deliberately the conservative one.

Built-in seed (`SEED` constant, `park.rs`):

```
libphp:sleep,libphp:usleep,libphp:nanosleep,libphp:select,libphp:accept,libphp:poll,
libphp:recv,libphp:send,libphp:recvfrom,libphp:sendto,libphp:recvmsg,libphp:sendmsg,
libphp:connect,libphp:read,libphp:write,libcurl,libpq,libssl,libcrypto
```

This is research 27's verdicts for the third-party libraries (libcurl, libpq, OpenSSL: park) plus
research 30's audited `libphp` groups (sleep family, socket family, stream/network/openssl —
"every row lock-free").

**A library named in the policy but never actually reached refuses startup.** After PHP's MINIT
(so `dlopen(RTLD_NOLOAD)` can see already-loaded extensions) and before any worker thread exists,
`park::selfcheck()` makes each *third-party* policy library (currently only `libcurl`/`libpq` are
probed this way — `libphp` is covered by the source audit, not by a runtime bind-proof) call an
interposed symbol from inside its own code, and checks the call actually reached the Rust
interposer. If a named library is loaded but never binds through us, `selfcheck()` returns `Err`,
`main.rs` prints it and **exits with status 2** (V-52) — the failure mode this guards against is a
silent hang, not a crash, so refusing to start is the whole point. Two diagnostics gate this
mechanism itself:

| Env var | What it does |
|---|---|
| `IGNIS_SKIP_PARK_SELFCHECK` | Skips the self-check (`tracing::warn!` + `Ok(())`). Diagnostic escape hatch, not for production — it's exactly the check that catches a policy library silently not binding. |
| `IGNIS_PARK_TRACE` | One stderr line per park/block decision (raw `SYS_write` syscall, deliberately bypassing the interposed `write` itself). For diagnosing a library that misbehaves under `park`; very chatty, never for production. |

### Diagnostics / test switches — not meant for production

| Env var | Read in | Default | What it does |
|---|---|---|---|
| `IGNIS_NO_UNIVERSAL_PARK` | `crates/ignis/src/php/park.rs` | unset (universal park installed) | Any value skips registering the fiber-switch gate observer *and* skips `selfcheck()` entirely — every interposed call forwards to the raw syscall as if none of this existed. Used to measure the cost of the mechanism itself. |
| `IGNIS_SKIP_PARK_SELFCHECK` | `crates/ignis/src/php/park.rs` | unset (self-check runs) | See above. |
| `IGNIS_PARK_TRACE` | `crates/ignis/src/php/park.rs` | unset (silent) | See above. |
| `IGNIS_NO_SUPERGLOBALS` | `crates/ignis/src/php/superglobals.rs` | unset (fiber-scoped `$_SERVER`/`$_GET`/`$_POST`/`$_COOKIE` installed) | Any value skips installing the ADR-0006 fiber-switch observer that swaps superglobals per fiber. Exists to measure the swap's cost, not to run production traffic without per-request superglobals. |
| `IGNIS_LOCKLIB` | `crates/ignis/src/php/locklib.rs` | unset | See above (H36 harness only). |
| `IGNIS_CHAOS` | `php/ignis.php` (`Loop::chaosInit`) | off | Any non-empty, non-`"0"` value shuffles the order ready fibers/completed ops resume in, and adds an extra yield point before every awaited op with probability `IGNIS_CHAOS_P`. For finding order-dependent bugs (E15e), never for production. |
| `IGNIS_CHAOS_P` | `php/ignis.php` | `0.5` | Probability of the extra yield when `IGNIS_CHAOS` is on. Clamped to `[0.0, 1.0]`. |
| `IGNIS_CHAOS_SEED` | `php/ignis.php` | current `hrtime()` (non-reproducible) | Seeds `mt_srand()` so a chaos run is reproducible. |

### Mentioned in `CLAUDE.md` but not found in the current source

`IGNIS_NO_STREAM_HOOK` and `IGNIS_NO_SLEEP_HOOK` (listed in the project's `CLAUDE.md` "Useful env"
line) do not appear anywhere under `crates/ignis/src/**` in this checkout, and the files that name
implies (`stream.rs`, `sleep.rs`) don't exist either — `crates/ignis/src/php/` currently has
`embed.rs`, `locklib.rs`, `mod.rs`, `module.rs`, `park.rs`, `route.rs`, `superglobals.rs`,
`wait.rs`, `zval.rs`. This reads as pre-ADR-0020 history: the old per-mechanism stream/sleep hooks
were superseded by the single universal-park mechanism (`IGNIS_NO_UNIVERSAL_PARK` above covers
"hook off" for all of read/write/connect/sleep/etc. today). Flagged rather than documented as if
still current.
