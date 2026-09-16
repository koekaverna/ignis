# 21 — Phase A: phpt baseline on a fresh box + fiber-mode failure triage

Date: 2026-09-16 (Phase A groundwork, porter). Fresh machine, different box from the one that
produced the numbers in `bench/results/e15-phpt/summary.md` / `bench/results/e15-baseline.txt` /
research 17 / VALIDATION V-26. Goal: re-run `bench/e15-phpt.sh` here, diff against the recorded
counts, and root-cause the fiber-mode failures the owner named (the ~8 `ext/standard/tests/streams`
ones and the 5 `ext/sockets/tests` ones) using this run's own `.diff` files, not the old docs.

## Machine state

```
$ uname -a
Linux book 6.18.33.2-microsoft-standard-WSL2 #1 SMP PREEMPT_DYNAMIC Thu Jun 18 21:54:43 UTC 2026 x86_64 GNU/Linux
$ /opt/php85-zts/bin/php -v
PHP 8.5.10 (cli) (built: Sep 16 2026 09:51:10) (ZTS)
$ git -C /home/koe/php-src describe --tags   # php-8.5.10, 34308a66
$ md5sum target/release/ignis php/ignis.php
856974a616438cc46dc615a0e31c7d7e  target/release/ignis
960a6e7a0bde5a6e0597f107380d084d  php/ignis.php
$ git rev-parse HEAD   # 7917425aacd0e7d712e77485cc8d770063ef2224
$ df -h /   # 865G free of 1007G (10% used) — no disk pressure
```

## Command

```
PHPSRC=/home/koe/php-src bash bench/e15-phpt.sh
```

No script edits were needed — `PHPSRC` on the command line was sufficient, `/home/user/php-src`
was never touched.

## Counts vs the recorded baseline

| suite | mode | recorded (summary.md / V-26 addendum) | this box | delta |
|---|---|---|---|---|
| Zend/tests/fibers | stock | 108/108 pass, 2 skip | **108/108, 2 skip** | none |
| Zend/tests/fibers | main | 108/108 pass, 2 skip | **108/108, 2 skip** | none |
| Zend/tests/fibers | fiber | 78/108 pass, 30 fail, 2 skip | **78/108, 30 fail, 2 skip** | none |
| ext/sockets/tests | stock | 80 pass, 0 fail, 38 skip | **91 pass, 1 fail, 26 skip** | **+11 pass, +1 fail, -12 skip** |
| ext/sockets/tests | main | 80 pass, 0 fail, 38 skip | **91 pass, 1 fail, 26 skip** | same shape as stock |
| ext/sockets/tests | fiber | 75 pass, 5 fail, 38 skip | **85 pass, 7 fail, 26 skip** | **+10 pass, +2 fail, -12 skip** |
| ext/standard/tests/streams | stock | 138 pass, 0 fail, 22 skip | **139 pass, 0 fail, 21 skip** | +1 pass, -1 skip |
| ext/standard/tests/streams | main | 132 pass, 6 fail, 22 skip | **133 pass, 6 fail, 21 skip** | +1 pass, -1 skip |
| ext/standard/tests/streams | fiber | 120 pass, 18 fail, 22 skip | **122 pass, 17 fail, 21 skip** | +2 pass, -1 fail, -1 skip |

`scripts/ci-gate.sh phpt` against `bench/results/e15-baseline.txt` (108/80/132/78/75/120) would
**pass** on every row here — every count is at or above the floor. Zend/tests/fibers is bit-for-bit
identical to the recording box.

**The one real difference: `ext/sockets/tests` gained 12 tests that used to be `SKIP`ped and now
run**, on **stock** as much as on `main`/`fiber` — this is an environment/kernel capability
difference between the two boxes (this one is WSL2 6.18), not an Ignis effect. Of those 12 newly-run
tests, one — `socket_sendto_zerocopy.phpt` — **fails identically on stock, main and fiber**:

```
$ diff stock-diffs/.../socket_sendto_zerocopy.diff
     16384 sent!
002- 16 received!
003- Received 0123456789abcdef!
002+  received!
003+ Received !
```

`MSG_ZEROCOPY` completion notifications behave differently under this WSL2 kernel than on the
recording box — **upstream** (fails on `/opt/php85-zts/bin/php` too), not an Ignis regression. This
single test explains the whole `sockets` "+1 fail" row in every mode. The streams `+1 pass / -1
skip` on stock is the same kind of box-capability drift (not investigated further — it does not
touch the fiber-mode question below) and is small enough not to matter to the classification.

**Net: no regression on this box; the counts are consistent with (mostly identical to, in two cases
slightly better than) what was recorded, once the one upstream/kernel-capability test is set aside.**

## Fiber-mode triage

### `ext/sockets/tests` — 7 fiber failures (was told to expect 5; found 7)

| test | actual vs expected | root cause | label |
|---|---|---|---|
| `socket_addrinfo_bind.phpt` | `object(Socket)#7` vs `#2` | `phpt-harness.php` allocates a Fiber/Future/closure before `include`ing the test, shifting every object id the test prints by a fixed offset | **ours (harness)** |
| `socket_addrinfo_connect.phpt` | `object(Socket)#7` vs `#2` | same object-id shift | **ours (harness)** |
| `socket_create_listen_invalid_port.phpt` | `object(Socket)#6` vs `#1` | same object-id shift | **ours (harness)** |
| `socket_create_pair.phpt` | `object(Socket)#6/#7` vs `#1/#2` | same object-id shift | **ours (harness)** |
| `socket_set_nonblock.phpt` | `object(Socket)#6` vs `#1` | same object-id shift | **ours (harness)** |
| `socket_sendto_zerocopy.phpt` | see above | kernel MSG_ZEROCOPY behavior | **upstream** (fails on stock identically; not fiber-specific — main mode fails too) |
| `socket_create_listen.phpt` | `Warning: socket_create_listen(): unable to bind to given address [98]: Address already in use` then a fatal `TypeError` | **not** an object-id artifact — this is a real bind failure on the fixed port 31338. By the time I checked (`ss -ltnp`), nothing held that port; I could not reproduce a hang directly in this test (it does one `socket_create_listen()` call and exits, no timed read) so I read it as a downstream symptom of the streams-suite hang below (an earlier orphaned `ignis` process from the *same run* still holding a port) rather than a distinct defect. **ours, unconfirmed independently — needs a rerun on a quiet box to see if it's port-collision noise or a real socket-listen defect.** |

The 5 tests named in the brief (`socket_addrinfo_bind`, `socket_addrinfo_connect`,
`socket_create_listen_invalid_port`, `socket_create_pair`, `socket_set_nonblock`) are all the same
single root cause: the harness's own object allocations before `include` shift every subsequent
object id by a constant. This confirms research 17's original diagnosis on the fresh box — nothing
new here. `socket_create_listen.phpt` is new (not in the recorded 5) and `socket_sendto_zerocopy`
is upstream/unrelated to fiber mode.

### `ext/standard/tests/streams` — 17 fiber failures, 11 fiber-only

6 of the 17 also fail in **main** mode (not fiber-specific, same diff both places — CLI-SAPI
surface, unchanged from research 17): `bug51056.phpt`, `bug64433.phpt`, `gh10031.phpt`,
`gh11418.phpt`, `glob-wrapper.phpt`, `stream_context_tcp_nodelay_server.phpt` — **not applicable**
(proc_open of `PHP_BINARY` needing CLI stdin, or the CLI built-in web server harness, or — for
`glob-wrapper` — the embed opening the primary script through the `open_basedir`-checked path where
php-cli exempts it).

The 11 **fiber-only** failures, each verified against this run's own `.diff` (and three against a
fresh isolated repro):

| test | actual vs expected (trimmed) | root-cause hypothesis | label |
|---|---|---|---|
| `bug60106-001.phpt` | `Deprecated: substr(): Passing null to parameter #1` then `unlink(): File name too long` instead of just the one expected truncation warning | the test's error handler does `global $socket_file;` — but the phpt body runs as an `include` inside the harness's pooled Fiber closure, so top-level `$socket_file` is a local of that closure, not a true global; the handler sees `null`, `substr(null,...)` warns, and `unlink()` runs on the untruncated 517-byte path | **ours (harness)** — global-scope defect, same class as research 17's `destructors_006`/`_009`/`_010` |
| `bug60106-002.phpt` | same pattern, two globals (`$socket_file`, `$max_normal_length`), cascades into `Undefined variable` warnings | identical global-scope defect | **ours (harness)** |
| `bug69521.phpt` | `Warning: stream_socket_client(): Unable to connect to tcp://127.0.0.1:74321 (Unknown error)` then fatal `TypeError` on `fwrite(false, ...)` | **confirmed by isolated repro**: `stream_socket_server('tcp://127.0.0.1:74321', ..., STREAM_SERVER_BIND\|LISTEN)` binds fine in every mode (port 74321 > 65535 wraps to a 16-bit port, same on stock/main/fiber); but `stream_socket_client()` to that same URI **fails only inside a fiber** ("Unknown error", errno 0) while stock and `main` mode connect successfully. The hooked async-connect path mishandles a port literal outside 0–65535 differently from the hooked bind path. | **ours** — real defect, not just an error-text mismatch as previously guessed |
| `bug70362.phpt` | `Warning: open_basedir restriction in effect. File(.../scripts/phpt-harness.php) is not within the allowed path(s): (.)` then a fatal include failure — the entire test never runs | the test's `--INI-- open_basedir=.` applies to the *harness* file too (`phpt-harness.php` lives under `/home/koe/projects/ignis/scripts/`, outside `.` = php-src's test dir), so the harness itself is rejected before the test body executes | **ours (harness)** |
| `bug77664.phpt` | stack trace `#2 {main}` vs `#2 /…/phpt-harness.php(40): include(...)` … `#7 {main}` | the test body is an `include` inside a Fiber, so the trace tail carries harness/`ignis.php`/`Loop`/`Fiber` frames instead of collapsing to `{main}` | **ours (harness)** |
| `gh8409.phpt` | same stack-trace-tail pattern | same | **ours (harness)** |
| `gh14506.phpt` | `Fatal error: Uncaught TypeError: fclose(): Argument #1 ($stream) must be of type resource, null given` instead of the expected `Warning: fclose(): cannot close the provided stream...` chain | same global-scope defect as `bug60106-*`, here on `global $readStream;` inside the userspace stream wrapper's methods — the wrapper closes a `null` instead of the real (already-open) stream, throwing instead of warning | **ours (harness)** |
| `ghsa-3cr5-j632-f35r.phpt` | `Warning: stream_socket_client(): ... (Unknown error)` → actually observed: `errstr=""`, expected `"The hostname must not contain null bytes"` | the hooked connect path's NUL-byte-in-hostname validation returns failure without populating `$errstr`/`$errno` the way the stock transport does | **ours** — real defect (error text/errno not propagated on this hooked-path rejection) |
| `stream_get_meta_data_socket_variation2.phpt` | actual: `Warning: stream_socket_server(): Unable to connect to tcp://127.0.0.1:31332 (Address already in use)` then a fatal type error; **but the real bug is upstream of that** | **confirmed by isolated repro, most serious finding of this triage**: `stream_set_timeout($client, 0, 1000); fread($client, 1);` on a hooked tcp socket with no data available **never returns** inside a fiber — `IGNIS_PHPT_FILE=.../variation2.php timeout -k2 20 ./target/release/ignis scripts/phpt-harness.php` hung past the 20 s wrapper and had to be SIGKILLed (exit 124), stuck exactly at `"Set a timeout on the client and attempt a read:"`. The read timeout set via `stream_set_timeout()` is not applied to the fiber-parked read (it waits for data-readiness with no deadline). Because `scripts/ignis-php` execs `target/release/ignis` as a **forked child of bash**, not via `exec`, `run-tests.php`'s `--set-timeout 15` (which kills the bash wrapper) does **not** reach the grandchild `ignis` process — it survives as an orphan, still holding the listening socket. That's what produced the "Address already in use" seen in *this run's* diff (an earlier hang, in the same suite run, left an orphan on the same port), and is the most likely explanation for `socket_create_listen.phpt`'s spurious bind failure above too. | **ours** — a real hang, not just a metadata mismatch; plus a secondary process-leak hazard in the bash wrapper |
| `stream_select_null_usec.phpt` | stack trace `#1 {main}` vs harness frames | trace-tail artifact only, the `ValueError` itself is raised correctly | **ours (harness)** |
| `user_streams_context_001.phpt` | stack trace `#1 {main}` vs harness frames | trace-tail artifact only | **ours (harness)** |

Repro commands used to confirm the two "real" (non-harness) bugs:

```
# bug69521 — connect fails only in fiber mode for a port > 65535
$ IGNIS_PHPT_FILE=repro69521c.php ./target/release/ignis scripts/phpt-harness.php   # client bool(false), errno 0
$ ./target/release/ignis repro69521c.php                                            # {main} mode: client bool(true)

# stream_get_meta_data_socket_variation2 — read-timeout hang
$ IGNIS_PHPT_FILE=.../stream_get_meta_data_socket_variation2.php timeout -k2 20 ./target/release/ignis scripts/phpt-harness.php
  ... hangs at "Set a timeout on the client and attempt a read:" ...
  exit code: 124        # SIGKILLed by `timeout`, did not exit on its own
```

Both orphaned processes found/created during this triage (pids 365445/365451, bound to
:31332) were reaped with `kill -9 <pid>` (explicit pids, no `pkill -f`) once confirmed; no other
stray `ignis`/`ignis-php`/`phpt-harness` processes were found on the box afterward.

## Summary of the classification (fiber-mode only, 24 tests across both suites)

- **ours — real runtime/hook defects (4)**: `bug69521.phpt` (connect fails for a port literal
  > 65535, fiber-only), `ghsa-3cr5-j632-f35r.phpt` (errstr/errno not populated on a hooked-connect
  rejection), `stream_get_meta_data_socket_variation2.phpt` (read timeout not honored — **hangs the
  process**, most severe), `socket_create_listen.phpt` (unconfirmed independently, likely a
  downstream symptom of the previous item's orphaned process rather than a fifth distinct bug).
- **ours (harness) — artifacts of running the phpt body inside a pooled Fiber via `include`
  (11)**: `bug60106-001/002.phpt`, `gh14506.phpt` (global-scope: 3), `bug77664.phpt`, `gh8409.phpt`,
  `stream_select_null_usec.phpt`, `user_streams_context_001.phpt` (stack-trace tail: 4),
  `bug70362.phpt` (open_basedir on the harness file: 1), 5× object-id shift in
  `ext/sockets/tests` (`socket_addrinfo_bind/connect`, `socket_create_listen_invalid_port`,
  `socket_create_pair`, `socket_set_nonblock`).
- **not applicable (6, all main+fiber, unchanged from research 17)**: `bug51056.phpt`,
  `bug64433.phpt`, `gh10031.phpt`, `gh11418.phpt`, `glob-wrapper.phpt`,
  `stream_context_tcp_nodelay_server.phpt`.
- **upstream (1)**: `socket_sendto_zerocopy.phpt` — fails identically on stock; a WSL2-vs-recording-box
  kernel `MSG_ZEROCOPY` difference, unrelated to fibers.

## What this adds to/corrects in the existing record

- The counts on this box match or slightly exceed the committed baseline everywhere; the gate would
  pass. The `ext/sockets/tests` "+11 pass / -12 skip" on **stock** is a box-capability difference
  (more socket features available under this WSL2 kernel), not an Ignis effect, and it brought one
  new **upstream** failure (`socket_sendto_zerocopy`) along with it — this is a box difference worth
  flagging, not a regression.
- `bug60106-001/002.phpt` were classified in V-26 addendum as "unix-socket `stream_socket_get_name`
  on a hooked server socket"; direct inspection of this run's `.diff` plus the test source shows the
  actual cause is the already-known **global-scope harness defect** (same as `destructors_006` etc
  in research 17), not a `stream_socket_get_name` problem. Correction, not a new bug.
- `bug69521.phpt` was classified as "error text ... differs from stock"; it is actually a full
  connect **failure** (not a text mismatch) for out-of-range port literals, fiber-only, confirmed by
  isolated repro — more serious than previously recorded.
- **New finding, not in any prior doc**: `stream_get_meta_data_socket_variation2.phpt` hangs the
  whole `ignis` process — `stream_set_timeout()` is not honored by a fiber-parked `fread()`, it waits
  forever instead of returning `false` after the configured timeout. Combined with `scripts/ignis-php`
  forking (not exec'ing) the real binary, a hang here survives `run-tests.php`'s own per-test
  timeout as an orphaned process that keeps a port bound, which is the proximate cause of the
  "Address already in use" failures seen on `stream_get_meta_data_socket_variation2.phpt` itself and
  very likely `socket_create_listen.phpt` in the same run. This is the one finding from this session
  that belongs in front of the main agent: **fiber-parked reads ignore `stream_set_timeout()` and
  never wake up**, in `crates/ignis/src/php/stream.rs`'s read path (not edited here — out of scope
  for the porter).

## What blocked me

Nothing. `PHPSRC=/home/koe/php-src` on the command line was sufficient; no script edits were needed.
