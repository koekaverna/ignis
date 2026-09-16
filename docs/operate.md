# Operating Ignis

For a team running `ignis serve` in production: how to size it, what happens under load, how to
read its health and logs, and every knob in `ignis.toml`. See [ignis.toml.example](../ignis.toml.example)
for the file itself and [README.md](../README.md) for install/run. Numbers here link to
[VALIDATION.md](../VALIDATION.md) (`V-n`); none are guessed.

## Sizing

Three independent axes — do not confuse them:

- **`threads` = cores.** One PHP worker thread per core, each with its own embedded engine and its
  own reactor (`ADR-0004`, `ADR-0010`). Default: the machine's available parallelism
  (`std::thread::available_parallelism`). More threads than cores buys nothing on CPU-bound work
  and costs context-switch overhead; fewer under-uses the box.
- **`budget.fibers` = concurrency, per thread.** `IGNIS_FIBER_BUDGET` (default 1024) is how many
  request fibers one thread admits at once. A request past that waits as **data** — a small PHP
  array in a FIFO — not as a fiber, which is the whole point of the budget (ADR-0019). Total
  concurrent in-flight requests the process will *admit* is therefore `threads × budget.fibers`.
- **Memory is the third axis, not a knob.** `memory ≈ fibers × 14.7 kB + held connections × 33 kB`
  — both are *marginal* costs, i.e. the RSS delta per additional held request, **measured on one
  box** (V-37): admitting a fiber costs 14.7 kB over just holding the connection (47.7 kB per held
  request with a fiber vs 33.0 kB queued as data). The connection term dominates at scale: at 4000
  held requests the queued arm used 132,484 kB against 188,920 kB with a fiber each — about 30%
  less, not an order of magnitude, and **the budget does not bound RSS on its own** — a held
  connection still costs ~33 kB whatever `budget.fibers` is set to (V-37; `ADR-0019` consequences).
  There is no connection-count cap yet (ROADMAP B8); size `budget.queue` and your load balancer's
  own connection limit accordingly until it lands.

Rule of thumb: pick `threads` = cores first, then raise `budget.fibers` until `threads ×
budget.fibers` covers your worst-case concurrent in-flight requests, then check RSS against the
formula above for that many held requests plus your own per-request PHP heap.

## Budget, queue, and 503 (ADR-0019)

1. Each thread admits at most `budget.fibers` (`IGNIS_FIBER_BUDGET`) request fibers at once. `0`
   means unlimited (pre-B1 behaviour).
2. A request that cannot be admitted waits as data in a FIFO, not as a Fiber.
3. Past `budget.queue` (`IGNIS_QUEUE_DEPTH`) requests, the server answers **`503` with
   `retry-after: 1`** immediately instead of queueing without bound. `0` means an unbounded queue.
4. A slot is released in the admitted request's outermost `finally`, which admits the next waiting
   request — no new wait point, no new reactor `Op`.
5. `IGNIS_BUDGET_EXEMPT` (`exempt` in the file, default `["/_ignis/"]`) is a comma-separated list of
   path prefixes admitted regardless of the budget — a saturated server is exactly when its own
   health/stats endpoints must not queue behind the load they report.
6. A client that disconnects while queued is dropped from the queue and never admitted.

Measured (V-37, `bench/b1-budget.sh`): budget 2, 10 concurrent `/sleep?ms=200` serialises to
**1028 ms** (ideal 1000) with **10/10 answered**, nothing lost. Budget 2 + queue depth 3, 12
concurrent `/sleep?ms=300` gives exactly **5 × 200 ms answered, 7 × 503** — the 2 admitted + 3
queued the configuration allows. The budget does not cost admitted requests latency: p99 of `/`
under load was 1.79–1.90 ms with budget 0 (unlimited) and 1.78–1.89 ms with budget 512 across three
paired reps — fully overlapping ranges (V-37).

## `/_ignis/health`

Answered by the Rust runtime before dispatch — not by PHP, and exempt from the budget by
construction, so a saturated server can still be probed. **`200`** while at least one worker thread
is alive and not stalled; **`503`** otherwise, so a load balancer stops sending traffic to a wedged
process even when PHP itself cannot say so (V-38):

```
$ curl -w ' [%{http_code}]' http://127.0.0.1:8096/_ignis/health
{"status":"ok","threads":2,"stalled":0,"restarts":0} [200]
```

`/_ignis/metrics` (Prometheus) does not exist yet — BACKLOG M4-4.

## Logging

The default floor is **`warn`**: with `RUST_LOG` unset, nothing below `warn` prints, but a worker
respawn or a stalled thread is never silent (V-38; JOURNAL 2026-09-16T17:05Z — the floor used to
default to `error`, which hid exactly those two lines, and was raised deliberately). Set
`log = "info"` in the file or `RUST_LOG=info` to see hook installation and more detail; either
accepts full `RUST_LOG` (`tracing_subscriber::EnvFilter`) syntax, e.g. `RUST_LOG=ignis=debug`.
`RUST_LOG` (or `IGNIS_PHP_INI`-style CLI/env precedence) always beats the file's `log` key.

What a respawn and a stall look like at the `warn` floor (V-17, quoted verbatim by JOURNAL
2026-09-16T17:05Z):

```
WARN worker script ended; respawning (opcache SHM untouched) slot=3 status=255
WARN php threads busy for > 1 s without polling stalled=1 total=4 … stalled=0
```

The first line is the supervisor (ADR-0012): a worker whose script ended (fatal, uncaught bailout,
`exit`) is respawned as a new OS thread with a fresh TSRM context; opcache SHM is untouched, so
there is no recompilation (V-17: recovery inside the 50 ms supervisor tick, back to 95.7% of
baseline throughput). The second is the watchdog: a thread that has not called `ignis_poll` for
over 1 s (a CPU-bound handler with no suspension point) is reported `stalled=1`, then `stalled=0`
once it returns — other threads keep serving throughout (V-17: 90,108 req/s / p99 2.67 ms on the
rest while one thread spun).

## Configuration reference

Every key in `ignis.toml` and every `IGNIS_*` / `RUST_LOG` environment variable `crates/ignis/src/config.rs`
reads, with its default. Precedence, highest first: CLI flag > environment variable > file > default
(`deny_unknown_fields`: a misspelled key is a parse error, not a silently ignored one).

| toml key | env var | default | what it does |
|---|---|---|---|
| `entry` | — (positional arg to `ignis serve`) | none, required | PHP entry script every worker thread runs |
| `listen` | `IGNIS_LISTEN` | `127.0.0.1:8080` | listener address |
| `threads` | `IGNIS_THREADS` | available parallelism (cores) | PHP worker threads |
| `offload` | `IGNIS_OFFLOAD` | `0` | synchronous offload workers for `curl_*` / `PDO` / `SQLite3` |
| `supervise` | — (bridged to the `--supervise` CLI flag, no env var) | `true` | respawn a worker whose script ends |
| `php_ini` | `IGNIS_PHP_INI` | none | extra php.ini (the embed SAPI has no `-c`/`-d`) |
| `log` | `RUST_LOG` | `warn` | log filter (`tracing_subscriber::EnvFilter` syntax) |
| `budget.fibers` | `IGNIS_FIBER_BUDGET` | `1024` | request fibers admitted per thread; `0` = unlimited |
| `budget.queue` | `IGNIS_QUEUE_DEPTH` | `4096` | requests allowed to wait before `503`; `0` = unbounded |
| `exempt` | `IGNIS_BUDGET_EXEMPT` | `["/_ignis/"]` | path prefixes admitted regardless of the budget |

`ignis --version` and `ignis serve [--config PATH] [entry.php]` are the CLI surface (V-38).

## Graceful reload

**Not yet — BACKLOG M4-5.** `SIGHUP` draining (stop accepting on old workers, let in-flight
requests finish, respawn each PHP thread one at a time without an opcache reset) and `SIGTERM`
drain-then-exit are designed (the mechanism ADR-0012 already has for a crash respawn is the same
one reload would reuse) but not implemented. Until then, a config change needs a process restart,
which drops in-flight connections.
