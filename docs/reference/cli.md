# CLI reference

Source: `crates/ignis/src/main.rs` (argument parsing, thread/offload spawn, exit codes) and
`crates/ignis/src/config.rs` (`ignis serve`'s file/env bridge). There is one binary, `ignis`, with
two front ends that share everything past argument parsing: `ignis serve` (product mode, reads
`ignis.toml`) and the lower-level `ignis <script.php>` form serve rewrites itself into.

## Forms

```
ignis --version | -V
ignis serve [--config ignis.toml] [entry.php] [args...]
ignis [--threads N] [--offload N] [--supervise] <script.php> [args...]
ignis [--threads N] [--offload N] [--supervise] -r <code>
ignis [--threads N] [--offload N] [--supervise] -- [args...]      # script read from stdin
```

`--version`/`-V` must be the very first argument; it prints `ignis <CARGO_PKG_VERSION>` and exits
0 without doing anything else (no engine init, no config load).

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

Note `serve` does **not** accept `--threads`/`--offload`/`--supervise` as its own CLI flags — those
are `ignis.toml` keys (`threads`, `offload`, `supervise`) or `IGNIS_THREADS`/`IGNIS_OFFLOAD`
environment variables under `serve`. They're CLI flags only on the raw form below.

### `ignis [--threads N] [--offload N] [--supervise] <script.php> [args...]`

The form every other entry point (including `serve`, after its rewrite) ends up running. Flags are
parsed left to right, in any combination/order, stopping at the first token that isn't one of the
three — that token and everything after it is the script name plus its own `$argv`.

`-r <code>` and `--` (script piped on stdin) are also accepted here, mirroring `php-cli`'s `-r` and
`--`: both run as a single script on the main thread only — no worker threads, no offload pool, no
supervisor — because the embed SAPI (unlike CLI) has neither `-c` nor `-d`, so these two exist
purely to let test harnesses that re-exec `PHP_BINARY -r ...`/`... -- ` keep working under
`scripts/ignis-php`. Exit status is the script's own return value from `engine.eval()`, clamped to
`0..=255`.

For the normal `<script.php>` form: the script path is canonicalized if possible (falls back to the
given path if that fails), `threads` is floored at `1` regardless of what `--threads`/`IGNIS_THREADS`
said, and `offload` worker threads (if `> 0`) are spawned first, each running
`php/offload/worker.php` on its own attached engine context. Then `threads` PHP worker threads are
spawned (`ignis-php-<i>`), each with its own `Reactor` and its own `WorkerThread::attach()`, all
running the *same* script file. If `--supervise` is set, thread slot 0 (the main thread) becomes a
pure supervisor loop — it runs no PHP itself — and worker slots `1..=threads` are respawned
whenever their script ends (fatal error or normal completion), up to 10 restarts per rolling
60-second window per slot; past that the slot is logged (`tracing::error!`) and left dead rather
than respawned forever. Without `--supervise`, the main thread runs the script directly as worker 0
alongside `threads - 1` additional worker threads, and the process exits once all of them finish.

## Flags

| Flag | Applies to | Default | What it does |
|---|---|---|---|
| `--config PATH` | `ignis serve` only | `./ignis.toml` if present, else built-in defaults | Explicit `ignis.toml` path; must be the first two tokens after `serve`. |
| `--threads N` | raw `ignis <script>` form | `IGNIS_THREADS` env if set, else `1` (floored at `1` either way) | PHP worker OS threads. Parsed with `.parse().unwrap_or(1)` — a non-numeric `N` silently becomes `1`, not an error. |
| `--offload N` | raw `ignis <script>` form | `IGNIS_OFFLOAD` env if set, else `0` | Synchronous offload worker threads for what cannot park (E16: `SQLite3`, file-backed `PDO`, CPU-bound work; `curl_*` parks — V-59). `.parse().unwrap_or(0)` — same silent-fallback behavior as `--threads`. |
| `--supervise` | raw `ignis <script>` form | off (present only if passed, or added by `serve`'s rewrite) | Enables the respawn supervisor described above. |
| `-r <code>` | raw `ignis <script>` form | — | Runs `<code>` as PHP on the main thread only, like `php -r`, then exits with its status. |
| `--` | raw `ignis <script>` form | — | Reads the script from stdin, like `php --`, runs it on the main thread only. |
| `--version` / `-V` | any invocation, first token only | — | Prints the version and exits 0. |

## Exit codes

| Code | When |
|---|---|
| `0` | `--version`/`-V`; or a normal run where every thread (and, under `--supervise`, the supervisor loop) reports 0. |
| `1` | PHP engine initialization failed (`Engine::init` error, printed to stderr); or `-r`/`--` code errored without producing a numeric status; or the worst exit status among worker threads when no thread explicitly reported a higher code. |
| `2` | Usage error: no script/`-r`/`--` given (usage message printed); `ignis serve` config/entry error (see above, two distinct messages); or — only in builds with the `universal-park` feature — the boot self-check (`park::selfcheck()`) found a policy library that never bound through the interposer (V-52), printed as `ignis: <message>`. |
| `1..=255` | Otherwise, the worst (`max`) exit status across all worker threads (and the main-thread script under non-`--supervise` runs), each individually clamped to `0..=255`. Under `--supervise` the reported exit code is always `1` regardless of why the loop stopped (the loop itself only stops when every worker slot has exhausted its restart budget). |

## Not a flag: `ignis.toml` / environment

Everything that isn't `--threads`/`--offload`/`--supervise`/`--config`/`-r`/`--`/`--version` is
configured through `ignis.toml` and `IGNIS_*` environment variables, documented in full in
`configuration.md` — including the listen address (`listen` / `IGNIS_LISTEN`, which is actually
read back out by the PHP entry script via `getenv()`, not by the Rust binary), the fiber/queue
budget, and the park policy table.
