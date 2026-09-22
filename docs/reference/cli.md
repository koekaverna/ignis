# CLI reference

Source: `crates/ignis/src/main.rs` (argument parsing, thread spawn, exit codes) and
`crates/ignis/src/config.rs` (`ignis serve`'s file/env bridge). There is one binary, `ignis`, with
two front ends that share everything past argument parsing: `ignis serve` (product mode, reads
`ignis.toml`) and the lower-level `ignis <script.php>` form serve rewrites itself into.

Writing a command-line script rather than looking up a flag? See
[Command-line scripts](../getting-started/cli.md) — what parks, what does not, and when it is worth
using fibers in a console command at all.

## Forms

```
ignis --version | -V
ignis serve [--config ignis.toml] [entry.php] [args...]
ignis [--workers N] [--threads N] [--supervise] <script.php> [args...]
ignis [--threads N] [--supervise] -r <code>
ignis [--threads N] [--supervise] -- [args...]      # script read from stdin
```

`--version`/`-V` must be the very first argument; it prints `ignis <CARGO_PKG_VERSION>` and exits
0 without doing anything else (no engine init, no config load). Confirmed against the built binary:
`ignis --version` prints exactly `ignis 0.1.0-rc.1`.

**There is no `--help`.** `main.rs` only special-cases `--version`/`-V` before the usage message; any
other unrecognised leading token — `--help` included — falls through to `parse_runtime_flags` (which
ignores it, since it is not `--threads`/`--supervise`) and is then treated as the script
path. `ignis --help` therefore tries to run a PHP script literally named `--help`, fails to open it,
prints a PHP fatal error and **exits 255**; `ignis serve --help` fails the entry-resolution step
instead and **exits 2** with `entry script --help does not exist`. Verified directly:
`LD_LIBRARY_PATH=/opt/php85-zts/lib ./target/release/ignis --help` → `Fatal error: Failed opening
required '--help'`, exit status 255; `... ignis serve --help` → `ignis serve: entry script --help
does not exist`, exit status 2. Running with no arguments at all is the way to see the usage
message (below), which is the closest thing to help this binary has.

### `ignis serve [--config PATH] [entry.php] [args...]`

The product front end. `--config PATH`, if given, must be the first two tokens; otherwise `serve`
looks for `./ignis.toml` and falls back to built-in defaults if that isn't there either. It then:

1. Resolves the entry script: the positional argument if present, else the file's `entry` key.
   Neither present → prints `ignis serve: serve needs an entry script: \`ignis serve app.php\` or
   \`entry = "app.php"\` in ignis.toml` to stderr and **exits 2**. Resolved but not a real file →
   `ignis serve: entry script <path> does not exist`, **exits 2**.
2. Bridges every `ignis.toml` key (and its product default where one exists) into the process
   environment as the matching `IGNIS_*` variable — see `configuration.md` for the full table —
   using "set only if not already set", so an `IGNIS_*` variable already in the environment always
   wins over the file.
3. Rewrites itself into the legacy form: `[--supervise]` (present unless the file says
   `supervise = false`) followed by the entry path, followed by any arguments after the entry
   (these become the script's own `$argv[1..]`, untouched).
4. Falls through into the exact same code path as `ignis <script.php>` below.

Note `serve` does **not** accept `--workers`/`--threads`/`--supervise` as its own CLI flags — those
are `ignis.toml` keys (`workers`, `threads`, `supervise`) or the `IGNIS_WORKERS`/`IGNIS_THREADS`
environment variables under `serve`. They're CLI flags only on the raw form below.

Once the engine is up and the boot self-check (below) has passed, `ignis serve` prints one line to
stderr: `ignis <version> — workers=<N> threads=<N> listen=<addr> park=<summary> — ready` (`main.rs`,
`print_ready_banner`). The raw `ignis <script.php>` form prints nothing on a clean start — this
banner exists specifically so `serve` has a visible sign of life at the default `warn` log floor.

### `ignis [--workers N] [--threads N] [--supervise] <script.php> [args...]`

The form every other entry point (including `serve`, after its rewrite) ends up running. Flags are
parsed left to right, in any combination/order, stopping at the first token that isn't one of the
three — that token and everything after it is the script name plus its own `$argv`.

`--workers N` (config key `workers`, env `IGNIS_WORKERS`; ADR-0044) makes the process a master
instead of a single worker: it binds `IGNIS_LISTEN` once, before any runtime thread exists, then
forks N worker processes, each of which is exactly what a single-process `ignis` was — its own
tokio runtime, its own reactor, its own `--threads` PHP threads — adopting the inherited socket
rather than binding its own. `ignis_serve` inside a worker refuses an address that differs from the
master's. The master never runs PHP after the fork; under `serve` or `--supervise` it reaps and
respawns a worker that ends (10 restarts/min, then a 5 s pause, the same budget the thread
supervisor uses), forwards `SIGTERM`/`SIGINT` to every worker and gives them
`IGNIS_DRAIN_TIMEOUT_MS` + 2 s before `SIGKILL`, and turns `SIGHUP` into a rolling reload — one
worker drained and replaced at a time, so the socket is never left without an accepter. A plain
script run with `--workers` (no `serve`, no `--supervise`) waits for every worker and answers with
the worst exit status, the way `--threads` does for threads. `serve`'s defaults follow the engine:
thread-safe build `workers = 1` (threads fill the cores), non-thread-safe build `workers = cores`
(one PHP thread per process is the only way it uses a second core) — see `configuration.md` and
`S-NTS-MODE` in BACKLOG.md for what a per-worker view (health, metrics, dev reload) still lacks.

`-r <code>` and `--` (script piped on stdin) are also accepted here, mirroring `php-cli`'s `-r` and
`--`: both run as a single script on the main thread only — no worker threads, no supervisor —
because the embed SAPI (unlike CLI) has neither `-c` nor `-d`, so these two exist
purely to let test harnesses that re-exec `PHP_BINARY -r ...`/`... -- ` keep working under
`scripts/ignis-php`. Exit status is the script's own return value from `engine.eval()`, clamped to
`0..=255`.

For the normal `<script.php>` form: the script path is canonicalized if possible (falls back to the
given path if that fails), `threads` is floored at `1` regardless of what `--threads`/`IGNIS_THREADS`
said, and `threads` PHP worker threads are spawned (`ignis-php-<i>`), each with its own `Reactor`
and its own `WorkerThread::attach()`, all running the *same* script file. If `--supervise` is set, thread slot 0 (the main thread) becomes a
pure supervisor loop — it runs no PHP itself — and worker slots `1..=threads` are respawned
whenever their script ends (fatal error or normal completion), up to 10 restarts per rolling
60-second window per slot; past that the slot is logged (`tracing::error!`) and left dead rather
than respawned forever. Without `--supervise`, the main thread runs the script directly as worker 0
alongside `threads - 1` additional worker threads, and the process exits once all of them finish.

## Flags

| Flag | Applies to | Default | What it does |
|---|---|---|---|
| `--config PATH` | `ignis serve` only | `./ignis.toml` if present, else built-in defaults | Explicit `ignis.toml` path; must be the first two tokens after `serve`. |
| `--workers N` | raw `ignis <script>` form | `IGNIS_WORKERS` env if set, else `1` (floored at `1` either way) | Master-forked worker processes sharing one listener and one opcache segment (ADR-0044). Parsed with `.parse().unwrap_or(1)` like `--threads`. |
| `--threads N` | raw `ignis <script>` form | `IGNIS_THREADS` env if set, else `1` (floored at `1` either way) | PHP worker OS threads. Parsed with `.parse().unwrap_or(1)` — a non-numeric `N` silently becomes `1`, not an error. |
| `--supervise` | raw `ignis <script>` form | off (present only if passed, or added by `serve`'s rewrite) | Enables the respawn supervisor described above. |
| `-r <code>` | raw `ignis <script>` form | — | Runs `<code>` as PHP on the main thread only, like `php -r`, then exits with its status. |
| `--` | raw `ignis <script>` form | — | Reads the script from stdin, like `php --`, runs it on the main thread only. |
| `--version` / `-V` | any invocation, first token only | — | Prints the version and exits 0. |

**`--offload N` is gone.** It started a pool of synchronous worker threads for what cannot park;
the pool was deleted on 2026-09-22 with the MVP cut (DECISIONS.md), and the flag, the `offload`
`ignis.toml` key and `IGNIS_OFFLOAD` went with it. A call that cannot park — a regular file,
`SQLite3`, a file-backed `PDO`, CPU-bound work — now blocks its PHP thread for the length of the
call; `curl_*`, `pdo_pgsql`, sockets and `sleep()` park as before.

## Signals

| Signal | What happens |
|---|---|
| `SIGTERM`, `SIGINT` | Drain and exit: `/_ignis/health` answers `503 {"status":"draining"}` for `IGNIS_DRAIN_DELAY_MS` while the listener is still accepting, then it closes and in-flight requests get `limits.drain_timeout_ms` to finish (V-56). With `--workers` > 1 the master forwards the signal to every worker and gives each `IGNIS_DRAIN_TIMEOUT_MS` + 2 s before `SIGKILL` (ADR-0044, V-123). |
| `SIGHUP` | Reload the threads inside a worker: each leaves dispatch in turn, finishes what it is holding, and comes back on a fresh engine with the code re-read from disk (V-90). Needs `--supervise` **and** watching on (`[watch] enabled` / `IGNIS_WATCH`) — with either missing the signal is logged and ignored, because nothing would bring the worker back. With `--workers` > 1 the master instead treats `SIGHUP` as a rolling reload — one worker process drained and replaced at a time, so the listener is never without an accepter (ADR-0044); development reload (`watch.rs`) does not yet send it there itself (BACKLOG `S-WORKERS-FOLLOW-UP`). Configuration is not re-read; `ignis.toml` is parsed once at startup. |

## Exit codes

| Code | When |
|---|---|
| `0` | `--version`/`-V`; or a normal run where every thread (and, under `--supervise`, the supervisor loop) reports 0. |
| `1` | PHP engine initialisation failed (`Engine::init` error, printed to stderr). A worker thread whose `WorkerThread::attach()` call fails also reports `1` for itself, which then feeds into the `1..=255` row below like any other worker exit status. |
| `2` | Usage error: no script/`-r`/`--` given (usage message printed); `ignis serve` config/entry error (see above, two distinct messages); or — only in builds with the `universal-park` feature — the boot self-check (`park::selfcheck()`) found a policy library that never bound through the interposer (V-52), printed as `ignis: <message>`. |
| `255` | `-r <code>` / `--` (stdin): the code ran but did not finish cleanly — a parse error, an uncaught exception, or an explicit `exit(255)` — which `Engine::eval` cannot tell apart (its sentinel only distinguishes an explicit `exit(N)` from no `exit()` at all; anything else that fails is reported as `255`). Confirmed against the binary: `ignis -r 'nonexistentfunc();'` prints the uncaught-error trace and exits 255, while `ignis -r 'exit(7);'` exits exactly 7. |
| `1..=255` | Otherwise, the worst (`max`) exit status across all worker threads (and the main-thread script under non-`--supervise` runs, including a plain script's own `exit(N)` or an unhandled fatal error, which PHP reports as `255`), each individually clamped to `0..=255`. Under `--supervise` the reported exit code is always `1` regardless of why the loop stopped (the loop itself only stops when every worker slot has exhausted its restart budget). |

## Not a flag: `ignis.toml` / environment

Everything that isn't `--threads`/`--supervise`/`--config`/`-r`/`--`/`--version` is
configured through `ignis.toml` and `IGNIS_*` environment variables, documented in full in
`configuration.md` — including the listen address (`listen` / `IGNIS_LISTEN`, which is actually
read back out by the PHP entry script via `getenv()`, not by the Rust binary), the fiber/queue
budget, and the park policy table.
