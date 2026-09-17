# Research 20 — Symfony and Doctrine suites under Ignis with chaos scheduling (E15e / H22e)

Date: 2026-09-16 (Cycle 19, porter). Script: `bench/e15-chaos.sh`. Install tree: `/tmp/e15-chaos/`.
Subject: `target/release/ignis` (PHP 8.5.10 ZTS embed, module `ignis`).
Baseline: `/opt/php85-zts/bin/php` — the CLI of **the same build**, same extension set, so *upstream*
here means "fails on plain PHP 8.5.10 ZTS too", not "fails on some other PHP".
Machine: 4 vCPU / 16 GB. `uptime` load average **2.1–3.2** during the measured runs (`up 6:06`–`6:28`);
other Ignis work ran on the box earlier in the night, see "Flakiness under load" below.

## Summary table — 5 suites × 4 modes, 10 849 tests per mode

`bench/e15-chaos.sh`, one full pass, 2026-09-16 04:18–04:41Z. `tests/failures/errors/skipped` are
PHPUnit's own summary line; `secs` is wall time; `noiseTicks`/`chaosYields` are explained below.

| suite | mode | tests | failures | errors | skipped | secs | chaosYields | noiseTicks |
|---|---|---|---|---|---|---|---|---|
| symfony-http-foundation | stock | 1815 | 0 | 62 | 126 | 16 | – | – |
| symfony-http-foundation | ignis | 1815 | 0 | 62 | 126 | 17 | 0 | 0 |
| symfony-http-foundation | chaos-seed-1 | 1815 | 0 | 62 | 126 | 17 | 10 844 | 21 976 |
| symfony-http-foundation | chaos-seed-20260916 | 1815 | 0 | 62 | 126 | 17 | 11 048 | 21 820 |
| symfony-http-kernel | stock | 1389 | 2 | 26 | 0 | 1 | – | – |
| symfony-http-kernel | ignis | 1389 | **3** | 26 | 0 | 2 | 0 | 0 |
| symfony-http-kernel | chaos-seed-1 | 1389 | **3** | 26 | 0 | 1 | 4 | 4 |
| symfony-http-kernel | chaos-seed-20260916 | 1389 | **3** | 26 | 0 | 2 | 2 | 3 |
| symfony-httpcache | stock | 103 | 0 | 0 | 0 | 304 | – | – |
| symfony-httpcache | ignis | 103 | 0 | 0 | 0 | 304 | 0 | 0 |
| symfony-httpcache | chaos-seed-1 | 103 | 0 | 0 | 0 | 305 | **222 539** | **445 363** |
| symfony-httpcache | chaos-seed-20260916 | 103 | 0 | 0 | 0 | 304 | **219 300** | **437 838** |
| dbal | stock | 3901 | 1 | 0 | 633 | 2 | – | – |
| dbal | ignis | 3901 | 1 | 0 | 633 | 1 | 0 | 0 |
| dbal | chaos-seed-1 | 3901 | 1 | 0 | 633 | 2 | 3 | 2 |
| dbal | chaos-seed-20260916 | 3901 | 1 | 0 | 633 | 1 | 4 | 1 |
| orm | stock | 3641 | 16 | 62 | 79 | 7 | – | – |
| orm | ignis | 3641 | 16 | 62 | 79 | 7 | 0 | 0 |
| orm | chaos-seed-1 | 3641 | 16 | 62 | 79 | 7 | 4 | 1 |
| orm | chaos-seed-20260916 | 3641 | 16 | 62 | 79 | 7 | 3 | 2 |

**Result: exactly one test fails under ignis/chaos and not under stock, in 10 849 tests per mode, and
it is the same one in every ignis mode. Chaos adds nothing: chaos never changed a single count, and
the two seeds agree with each other and with plain `ignis`.**

Raw output and the per-mode failing-test list: `/tmp/e15-chaos/<suite>-<mode>.txt` (each file ends
with a `== FAILING TESTS (suite / mode) ==` block).

## The one new failure

| test | label | why | first assertion message |
|---|---|---|---|
| `Symfony\Component\HttpKernel\Tests\CacheWarmer\CacheWarmerAggregateTest::testWarmupRecoversFromCorruptedDeprecationLog` | **not applicable** | The test runs `new Process([\PHP_BINARY, '--', $srcDir, $logFile])` and feeds the script on **stdin** — php-cli semantics: `--` means "read the script from standard input". Under ignis `PHP_BINARY` is the embed binary, which takes `argv[1]` as the script *path*, so it tries to open a file literally named `--`. Nothing to do with fibers or chaos: it fails identically with `IGNIS_CHAOS` off. | `Failed asserting that two strings are identical.` — expected `'OK'`, actual `"\nWarning: Unknown: Failed to open stream: No such file or directory in Unknown on line 0\n\nFatal error: Failed opening required '--' (include_path='.:') in Unknown on line 0\nStack trace:\n#0 {main}\n"` |

The corresponding assertion-count drop (2693 vs 2695) is this one test only.

### The other `PHP_BINARY` family — worked around, not hidden

Three http-foundation test classes start their own web server with
`@proc_open('exec '.\PHP_BINARY.' -S localhost:805x', …)`: `RequestFunctionalTest` (8054),
`ResponseFunctionalTest` (8054), `Session\Storage\Handler\AbstractSessionHandlerTest` (8053).
Under ignis `PHP_BINARY` is the embed binary, which has no built-in web server, so with no other
change the run is `Tests: 1815, Assertions: 3184, Errors: 62, Failures: 21, Warnings: 19, Skipped: 126`
— **21 failures over 4 test methods, all `not applicable`** (php-cli's `-S` is a CLI SAPI feature),
first message `Failed asserting that null is identical to Array &0 ['foo' => 'bar']`.

Rather than leave them dead, `bench/e15-chaos.sh` pre-starts the *same two servers on the stock CLI*
before every mode, stock included (`/tmp/e15-chaos/docroot8054` merges the `request-functional` and
`response-functional` fixture dirs; they have no filename collision). The tests' own `proc_open`
then simply fails to bind and their `file_get_contents('http://localhost:805x/…')` still has a peer.
That is deliberate: those `http://` calls are **the only place in the Symfony/Doctrine suites where a
real socket is opened**, i.e. the only place the Ignis `tcp://` transport hook is exercised at all.
With the servers up, all 21 pass in every mode.

## Does chaos have anything to reorder? Mostly no — and here is the number

`IGNIS_CHAOS=1` (php/packages/runtime/src/ignis.php, `Loop::$chaos`) does three things: shuffle the ready-fiber batch,
shuffle the completed-op batch from `ignis_poll()`, and insert an extra 0 ms yield after every awaited
op with probability `IGNIS_CHAOS_P` (0.5). All three only act where PHP **running inside an Ignis
fiber awaits an Ignis op**: `Ignis\sleep`, `Ignis\async/all`, the hooked `sleep()`/`usleep()`, the
hooked `tcp://` transport. Pure CPU, `pdo_sqlite`, and local filesystem I/O are not hooked and never
suspend a fiber, so the loop is never entered and there is nothing to reorder.

Two consequences, both measured:

1. **`Loop::chaosInit()` is lazy** — it runs on the first `Loop::awaitOp()`. A suite that never awaits
   an Ignis op leaves `Loop::$chaos === false` even with `IGNIS_CHAOS=1` in the environment. The entry
   point therefore calls `Ignis\sleep(0)` once before handing control to PHPUnit; without it the first
   http-foundation chaos run reported `chaos=0`.
2. **One fiber cannot interleave with itself.** With the whole PHPUnit `Application` in a single fiber,
   the ready-queue and the completed-op batch have exactly one entry, so `shuffle()` is a no-op. The
   entry point therefore takes `IGNIS_NOISE=N` (4 in the script): N background fibers looping on
   `Ignis\sleep(1)` for the whole run. `noiseTicks` counts how often one of them actually got to run,
   i.e. how often the loop was free because the test fiber was parked on an Ignis op.

What the numbers say per suite:

| suite | chaosYields | noiseTicks | reading |
|---|---|---|---|
| symfony-httpcache | 222 539 | 445 363 | ~300 s of `sleep(2…25)` inside the fiber, every one of them parked on a reactor timer. **This is the one suite where chaos really ran.** |
| symfony-http-foundation | 10 844 | 21 976 | 3 × `sleep(1)` in `setUpBeforeClass()` plus ~30 `http://` requests through the hooked `tcp://` transport ≈ 5 s parked out of a 17 s run. |
| symfony-http-kernel | 2–4 | 3–4 | only the entry point's own `Ignis\sleep(0)` and the shutdown. Nothing else in the suite ever suspends. |
| dbal | 3–4 | 1–2 | idem. `pdo_sqlite` is a C driver on a local file: no Ignis op, no suspension. |
| orm | 3–4 | 1–2 | idem. |

Attribution probe — the same http-foundation chaos run with both hooks switched off:

```
IGNIS_MODE=1 IGNIS_CHAOS=1 IGNIS_CHAOS_SEED=1 IGNIS_NOISE=4 \
IGNIS_NO_STREAM_HOOK=1 IGNIS_NO_SLEEP_HOOK=1  ./target/release/ignis run.php … Tests
  → Tests: 1815, Assertions: 3187, Errors: 62, Skipped: 126   (identical)
  → noiseTicks 21 976 → 4,  chaosYields 10 844 → 4
```

So 100 % of the suspension in that suite comes from the `sleep()` and `tcp://` hooks, and switching
them off changes no test result either.

(`pollMs` in the script output is *not* a measure of time spent waiting on I/O: once the fiber has
C-parked once in the `sleep()`/`tcp://` hook it is resumed from inside `ignis_poll()`, so the rest of
the suite's own CPU work is accounted to the poll phase. `noiseTicks` is the honest signal.)

**Plainly: for doctrine/dbal, doctrine/orm and symfony/http-kernel, chaos mode had nothing to
reorder.** They are CPU + `pdo_sqlite` + local files from start to finish; they never enter the Ignis
loop, so "zero new failures under chaos" for those three is a statement about the fiber/embed
environment (superglobals, constants, shutdown, `$argv`, output buffering, `set_error_handler`,
`register_shutdown_function`, opcache), not about scheduling. The scheduling claim rests on
**symfony-httpcache** (222 539 forced yields, 445 363 interleavings, 103/103 green) and on
**symfony-http-foundation**'s socket traffic (10 844 forced yields, 1815 tests green).

## What it took to run these suites inside the embed binary

`vendor/bin/phpunit` cannot be used: it starts with `#!/usr/bin/env php` (the embed SAPI does not set
`CG(skip_shebang)`, so the line is inline HTML and the next statement is a fatal error) and it
hard-gates on ext-dom/libxml/xmlwriter, which this build does not have — the stock CLI fails that
gate too. `bench/e15-chaos.sh` writes a ~45-line entry point per suite that

* sets `$_SERVER['PHP_SELF'] / SCRIPT_NAME / SCRIPT_FILENAME` (unset under embed; PHPUnit 12's
  `TextUI\Configuration\Merger::merge()` calls `realpath($_SERVER['PHP_SELF'])` unconditionally),
* calls `(new PHPUnit\TextUI\Application)->run($_SERVER['argv'])` directly,
* sets `memory_limit=-1` (`E15_MEMORY_LIMIT` to override) — Doctrine ORM's `QueryLog` blows the 128 MB
  default at 46 % of its suite, **in every mode**,
* and, when `IGNIS_MODE=1`, requires `php/packages/runtime/src/ignis.php` and runs the whole application inside one
  `Ignis\async(…)->await()`, printing `Loop::$chaosYields`, `$resumes`, `$fibersCreated` and the poll
  time on STDERR at the end.

Because the entry point is not `vendor/bin/phpunit`, `PHPUNIT_COMPOSER_INSTALL` is undefined, and
PHPUnit's separate-process child template then has no autoloader. That is the source of the
**58 errors in http-foundation and 19 in http-kernel** with
`PHPUnit\Framework\Exception: Fatal error: Uncaught Error: Class "PHPUnit\TextUI\Configuration\Registry" not found in Standard input code:133`.
They are identical in all four modes, so they do not disturb the comparison; they are the harness,
not Ignis. (Defining the constant would make those tests spawn `PHP_BINARY` with the code on stdin —
the same php-cli-only path that breaks `testWarmupRecoversFromCorruptedDeprecationLog` above.)

### Per suite

| suite | install | test selection | notes |
|---|---|---|---|
| symfony/http-foundation | `composer` `^7.3` **`--prefer-source`** (the split repos `export-ignore` `Tests/`), plus 22 optional dev components so the tests are not skipped for missing classes | `vendor/symfony/http-foundation/Tests` | `bootstrap.php` registers the `…\Tests\` PSR-4 prefixes: composer applies `autoload-dev` only to the root package |
| symfony/http-kernel | idem (v7.4.19) | `vendor/symfony/http-kernel/Tests` **`--exclude-group time-sensitive`** | `KernelTest::testKernelStartTimeIsResetWhileBootingAlreadyBootedKernel` does `sleep(3600)` "to detect if ClockMock ever breaks" — it needs `symfony/phpunit-bridge`'s ClockMock, which PHPUnit 12 only loads through an `<extensions>` XML block (no ext-dom here, so `--no-configuration` always). Without it the run hangs; observed: stuck at 1464/1575 for 15 min under stock. **upstream / harness.** |
| symfony-httpcache | – | `…/Tests/HttpCache/HttpCacheTest.php` alone | the other `time-sensitive` class; its 100 s+ of real `sleep()` are exactly the Ignis I/O we want, so it is run on its own with a 900 s timeout |
| doctrine/dbal | `composer create-project --prefer-source` (4.4.x), `phpstan`/`phpcs`/`phpstorm-stubs` stripped from `require-dev` | `tests` (ships PHPUnit 11.5) | defaults to **in-memory sqlite** when no `db_*` globals are set, so no config is needed |
| doctrine/orm | idem (3.5.x) | `tests/Tests/ORM` `--exclude-group performance,locking_functional` | `bootstrap.php` sets `$GLOBALS['db_driver']='pdo_sqlite'`, `$GLOBALS['db_memory']='true'` — that is what the `<php><var …/></php>` block of `phpunit.xml.dist` would do |

Network note: GitHub *dist* (codeload) downloads are 403 through this box's allowlist, so everything
installs with `--prefer-source` over git. That clones full histories (~2 GB per tree); the script
deletes `vendor/**/.git` and the committed `vendor/**/tools` phars afterwards (6.0 GB → 1.4 GB).

## The baseline failures (identical in all four modes)

| suite | count | what | label |
|---|---|---|---|
| http-foundation | 58 errors | PHPUnit separate-process child without autoloader (above) | harness / upstream |
| http-foundation | 3 errors, 1 skip family | `Class "Doctrine\DBAL\Schema\Schema" not found` (PdoSessionHandler tests) — optional dep not installed | not applicable |
| http-kernel | 19 errors | separate-process child | harness / upstream |
| http-kernel | 6 errors | `Error: Undefined constant "XML_PI_NODE"` — **ext-dom is not built** | not applicable |
| http-kernel | 1 error | `Class "Twig\Environment" not found` | not applicable |
| http-kernel | 2 failures | `DumpDataCollectorTest::testDumpAndDieWritesDumpsBeforeTheScriptShutsDown` (separate process again) and `RegisterControllerArgumentLocatorsPassTest::testTaggedIteratorAndTaggedLocatorAttributes` (symfony/dependency-injection 8.1 vs http-kernel 7.4) | upstream |
| dbal | 1 failure | `Functional\ExceptionTest::testConnectionExceptionSqLite` — `Failed asserting that exception of type "Doctrine\DBAL\Exception\ReadOnlyException" is thrown.` The suite runs as **root**, so a `chmod 0444` sqlite file is still writable | upstream (environment) |
| orm | 62 errors + 9 of the 16 failures | `LogicException: The XML metadata driver cannot be enabled because the SimpleXML PHP extension is missing.` — **ext-simplexml is not built** | not applicable |
| orm | 7 failures | `Tools\Console\Command\*` output assertions — symfony/console 8.1 wraps the lines differently than the version ORM 3.5 expects | upstream |

## Flakiness under load

`HttpCacheTest` keeps its store in a **fixed** `sys_get_temp_dir().'/http_cache'`, so two copies of it
cannot run at the same time. A first attempt to compare the three modes by running them concurrently
produced failures in *all three*, stock included (stock 3 F, ignis and chaos more) — that is the
shared store directory, not Ignis, and the run was discarded. Repeated with `TMPDIR` per mode:

```
TMPDIR=/tmp/e15-chaos/tmp-<mode>  … HttpCacheTest.php     # all three started at the same second
stock   OK (103 tests, 690 assertions)
ignis   OK (103 tests, 690 assertions)   chaosYields=0       noiseTicks=0        pollMs=304298
chaos   OK (103 tests, 690 assertions)   chaosYields=217726  noiseTicks=435749   pollMs=296673   (seed 7)
```

All three green, three copies of a 300 s timing-sensitive suite on 4 vCPU at the same time, with a
third chaos seed for good measure. The earlier "ignis is flakier" reading was an artefact of the
shared `/tmp/http_cache`.

Everything in the summary table above is from sequential runs at load 2.1–3.2.

## Verdict on H22e / E15e

* **Zero new failures from chaos.** Across 5 suites and 10 849 tests per mode, `IGNIS_CHAOS=1` with two
  fixed seeds produced byte-identical PHPUnit summary lines to plain `ignis`, and to the stock CLI of
  the same build except for one test.
* **One new failure under ignis at all, classified `not applicable`**: `PHP_BINARY` is the embed
  binary, and PHPUnit/Symfony use it as a php-cli replacement (`-S`, `--` + script on stdin). Worth a
  runtime note, not a scheduling bug. A cheap partial fix would be to accept `--` and read the script
  from stdin, and to fill `$_SERVER['PHP_SELF']`/`SCRIPT_NAME`/`SCRIPT_FILENAME` and
  `CG(skip_shebang)` at RINIT — that last one would also remove the need for the custom entry point
  (same three items as research 19).
* **The honest caveat**: three of the five suites never reach an Ignis I/O point, so for them chaos is
  vacuous. The claim "chaos changes nothing" is carried by `symfony-httpcache` (222 539 forced yields
  and 445 363 interleavings against 103 timing-sensitive HTTP-cache tests, all green) and by
  http-foundation's socket traffic. A stronger E15e would need a suite whose I/O actually goes through
  the runtime — the obvious next one is Doctrine DBAL's **pgsql** functional tests against the
  Ignis pg pool (E14) rather than `pdo_sqlite`, and amphp/http-client's suite over the `tcp://` hook.

## How to re-run

```
bench/e15-chaos.sh                        # installs into /tmp/e15-chaos if missing; ~27 min
SKIP_SLOW=1 bench/e15-chaos.sh            # drop symfony-httpcache → ~2 min
SUITES="dbal orm" bench/e15-chaos.sh
SEED_A=3 SEED_B=4 NOISE=8 bench/e15-chaos.sh
```
