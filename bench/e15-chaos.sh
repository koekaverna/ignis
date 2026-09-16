#!/usr/bin/env bash
# E15e / H22e — Symfony (http-foundation, http-kernel) and Doctrine (DBAL, ORM) test suites
# under the Ignis runtime with chaos scheduling on, against the stock CLI of the same PHP build.
#
#   bench/e15-chaos.sh                 # install if missing, then 4 modes x 5 suites
#   SUITES="dbal orm" bench/e15-chaos.sh
#   SKIP_SLOW=1 bench/e15-chaos.sh     # drop symfony-httpcache (~5 min of real sleep() per mode)
#
# Modes
#   stock          /opt/php85-zts/bin/php  (same build as the one ignis embeds)
#   ignis          target/release/ignis, the whole PHPUnit Application inside ONE Ignis fiber
#   chaos-seed-N   same + IGNIS_CHAOS=1 IGNIS_CHAOS_SEED=N and IGNIS_NOISE background fibers
#
# Chaos (php/ignis.php, Loop::$chaos) shuffles the ready-fiber batch and the completed-op batch
# and inserts an extra 0 ms yield after every awaited op. It can only act where PHP running inside
# an Ignis fiber awaits an Ignis op (Ignis\sleep, hooked sleep()/usleep(), hooked tcp:// streams).
# IGNIS_NOISE=N spawns N fibers looping on Ignis\sleep(1) so those batches have >1 entry and the
# shuffle is not a no-op; noiseTicks in the trailing IGNIS line says how often they got to run.
#
# Every phpunit invocation is wrapped in `timeout 900`. Raw output + the failing test names of
# each run land in $TMP/<suite>-<mode>.txt.
set -uo pipefail
cd "$(dirname "$0")/.."
ROOT=$PWD
BIN=$ROOT/target/release/ignis
PHP=${PHP_BIN:-/opt/php85-zts/bin/php}
TMP=${TMP_DIR:-/tmp/e15-chaos}
SEED_A=${SEED_A:-1}
SEED_B=${SEED_B:-20260916}
NOISE=${NOISE:-4}
TIMEOUT=${TIMEOUT:-900}
SUITES=${SUITES:-"symfony-http-foundation symfony-http-kernel symfony-httpcache dbal orm"}
[ "${SKIP_SLOW:-0}" = "1" ] && SUITES=$(echo "$SUITES" | sed 's/symfony-httpcache//')
export COMPOSER_ALLOW_SUPERUSER=1 COMPOSER_NO_INTERACTION=1

# ---------------------------------------------------------------- install
write_entry_point() {   # $1 = suite dir
cat > "$1/run.php" <<'PHPEOF'
<?php declare(strict_types=1);
/**
 * E15e entry point. vendor/bin/phpunit cannot be used under the embed SAPI: it starts with a
 * shebang (embed does not set CG(skip_shebang)) and hard-gates on ext-dom/libxml/xmlwriter,
 * which this PHP build does not have — the stock CLI fails that gate too, and --no-configuration
 * means PHPUnit never needs them. $_SERVER['PHP_SELF'] is unset under embed and PHPUnit's
 * TextUI\Configuration\Merger realpath()s it unconditionally.
 *
 * IGNIS_MODE=1   run the whole PHPUnit Application inside ONE Ignis fiber.
 * IGNIS_CHAOS=1  (+ IGNIS_CHAOS_SEED / IGNIS_CHAOS_P) chaos scheduling, see Ignis\Loop::$chaos.
 * IGNIS_NOISE=N  N background fibers looping on Ignis\sleep(1) for the whole run, so the loop's
 *                ready and completed-op batches have more than one entry for the shuffle to act on.
 */
ini_set('memory_limit', getenv('E15_MEMORY_LIMIT') ?: '-1');
$_SERVER['PHP_SELF'] ??= $_SERVER['argv'][0] ?? __FILE__;
$_SERVER['SCRIPT_NAME'] ??= $_SERVER['PHP_SELF'];
$_SERVER['SCRIPT_FILENAME'] ??= $_SERVER['PHP_SELF'];
require __DIR__ . '/vendor/autoload.php';
if (is_file(__DIR__ . '/bootstrap.php')) {
    require __DIR__ . '/bootstrap.php';
}
$run = static fn (): int => (new PHPUnit\TextUI\Application)->run($_SERVER['argv']);
if (!getenv('IGNIS_MODE')) {
    exit($run());
}
require '__IGNIS_ROOT__/php/ignis.php';
$stop = new stdClass();
$stop->v = false;
$noiseTicks = 0;
$noise = (int) (getenv('IGNIS_NOISE') ?: '0');
for ($i = 0; $i < $noise; $i++) {
    Ignis\async(static function () use ($stop, &$noiseTicks): void {
        while (!$stop->v) {
            Ignis\sleep(1);
            ++$noiseTicks;
        }
    });
}
$rc = Ignis\async(static function () use ($run): int {
    Ignis\sleep(0);          // Loop::chaosInit() is lazy: it runs on the first Loop::awaitOp()
    return $run();
})->await();
$stop->v = true;
fwrite(STDERR, sprintf(
    "IGNIS chaos=%s chaosP=%s chaosSeed=%s noise=%d noiseTicks=%d chaosYields=%d resumes=%d fibers=%d pollMs=%d\n",
    Ignis\Loop::$chaos ? '1' : '0', Ignis\Loop::$chaosP, getenv('IGNIS_CHAOS_SEED') ?: '-',
    $noise, $noiseTicks, Ignis\Loop::$chaosYields, Ignis\Loop::$resumes,
    Ignis\Loop::$fibersCreated, (int) (Ignis\Loop::$phaseNs['poll'] / 1e6)
));
exit($rc);
PHPEOF
# The heredoc is quoted, so the repo root is substituted after the fact; it used to be an
# absolute path to another machine, which made this suite unrunnable off that box.
sed -i "s|__IGNIS_ROOT__|$ROOT|" "$1/run.php"
}

install_symfony() {
    [ -f "$TMP/symfony/vendor/autoload.php" ] && return 0
    echo "== installing symfony/http-foundation + symfony/http-kernel (from source: Tests/ are export-ignore'd in the dist)"
    mkdir -p "$TMP/symfony"
    cat > "$TMP/symfony/composer.json" <<'EOF'
{
    "require": { "symfony/http-foundation": "^7.3", "symfony/http-kernel": "^7.3" },
    "require-dev": { "phpunit/phpunit": "^12.0" },
    "config": { "platform": { "php": "8.5.10" }, "preferred-install": "source" },
    "minimum-stability": "stable"
}
EOF
    ( cd "$TMP/symfony" && timeout 900 composer update --prefer-source --no-progress >/dev/null 2>&1 \
      && timeout 900 composer require --dev --prefer-source --no-progress \
         symfony/browser-kit symfony/clock symfony/config symfony/console symfony/css-selector \
         symfony/dependency-injection symfony/expression-language symfony/finder symfony/process \
         symfony/property-access symfony/routing symfony/serializer symfony/stopwatch \
         symfony/translation symfony/uid symfony/validator symfony/var-exporter symfony/cache \
         symfony/mime symfony/rate-limiter symfony/http-client-contracts psr/cache >/dev/null 2>&1 )
    cat > "$TMP/symfony/bootstrap.php" <<'EOF'
<?php declare(strict_types=1);
// composer applies autoload-dev only to the root package, so the components' Tests\ namespaces
// are not in vendor/autoload.php.
require __DIR__ . '/vendor/autoload.php';
$loader = new Composer\Autoload\ClassLoader();
$loader->addPsr4('Symfony\\Component\\HttpFoundation\\Tests\\', __DIR__ . '/vendor/symfony/http-foundation/Tests');
$loader->addPsr4('Symfony\\Component\\HttpKernel\\Tests\\', __DIR__ . '/vendor/symfony/http-kernel/Tests');
$loader->register();
EOF
    write_entry_point "$TMP/symfony"
}

install_doctrine() {   # $1 = dbal|orm  $2 = packages to strip from require-dev
    [ -f "$TMP/$1/vendor/autoload.php" ] && return 0
    echo "== installing doctrine/$1 (create-project --prefer-source; dist zips are github-only and blocked here)"
    ( cd "$TMP" && timeout 900 composer create-project "doctrine/$1" "$1" --prefer-source --no-progress --no-scripts --no-install >/dev/null 2>&1 )
    "$PHP" -r '
        $f = $argv[1] . "/composer.json"; $a = json_decode(file_get_contents($f), true);
        foreach (explode(",", $argv[2]) as $p) unset($a["require-dev"][$p]);
        $a["config"]["platform"]["php"] = "8.5.10"; $a["config"]["allow-plugins"] = false;
        file_put_contents($f, json_encode($a, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    ' "$TMP/$1" "$2"
    rm -f "$TMP/$1/composer.lock"
    ( cd "$TMP/$1" && timeout 900 composer update --prefer-source --no-progress >/dev/null 2>&1 )
    # --prefer-source clones full histories and committed phar tools: ~2 GB per tree otherwise.
    find "$TMP/$1" -maxdepth 5 -name .git -print0 2>/dev/null | xargs -0 rm -rf
    find "$TMP/$1/vendor" -maxdepth 3 -type d -name tools -print0 2>/dev/null | xargs -0 rm -rf
    write_entry_point "$TMP/$1"
}

install() {
    mkdir -p "$TMP"
    install_symfony
    install_doctrine dbal "doctrine/coding-standard,jetbrains/phpstorm-stubs,phpstan/phpstan,phpstan/phpstan-phpunit,phpstan/phpstan-strict-rules,slevomat/coding-standard,squizlabs/php_codesniffer"
    install_doctrine orm  "doctrine/coding-standard,phpbench/phpbench,phpstan/extension-installer,phpstan/phpstan,phpstan/phpstan-deprecation-rules"
    if [ ! -f "$TMP/orm/bootstrap.php" ]; then
        cat > "$TMP/orm/bootstrap.php" <<'EOF'
<?php declare(strict_types=1);
// Stands in for the <php><var .../></php> block of phpunit.xml.dist: reading an XML config needs
// ext-dom, which this build does not have, so every run is --no-configuration.
$GLOBALS['db_driver'] = 'pdo_sqlite';
$GLOBALS['db_memory'] = 'true';
require __DIR__ . '/tests/Tests/TestInit.php';
EOF
    fi
    # always refresh the entry point, also when the trees are already installed
    for d in symfony dbal orm; do [ -d "$TMP/$d" ] && write_entry_point "$TMP/$d"; done
    find "$TMP/symfony" -maxdepth 5 -name .git -print0 2>/dev/null | xargs -0 rm -rf
    find "$TMP/symfony/vendor" -maxdepth 3 -type d -name tools -print0 2>/dev/null | xargs -0 rm -rf
    # Symfony's *FunctionalTest / AbstractSessionHandlerTest spawn `PHP_BINARY -S localhost:805x`.
    # Under ignis PHP_BINARY is the embed binary, which has no built-in web server, so the tests
    # would be untestable. Pre-start the same servers on the stock CLI for EVERY mode instead: the
    # tests' own proc_open then fails to bind and their file_get_contents('http://…') — the one
    # place in these suites that really goes through the Ignis tcp:// hook — still has a peer.
    rm -rf "$TMP/docroot8054"; mkdir -p "$TMP/docroot8054"
    local fx=$TMP/symfony/vendor/symfony/http-foundation/Tests/Fixtures
    ln -sf "$fx"/request-functional/* "$TMP/docroot8054/" 2>/dev/null
    ln -sf "$fx"/response-functional/* "$TMP/docroot8054/" 2>/dev/null
}

start_servers() {
    "$PHP" -S localhost:8054 -t "$TMP/docroot8054" >"$TMP/srv8054.log" 2>&1 &
    SRV1=$!
    "$PHP" -S localhost:8053 -t "$TMP/symfony/vendor/symfony/http-foundation/Tests/Session/Storage/Handler/Fixtures" >"$TMP/srv8053.log" 2>&1 &
    SRV2=$!
    sleep 1
}
stop_servers() { [ -n "${SRV1:-}" ] && kill "$SRV1" 2>/dev/null; [ -n "${SRV2:-}" ] && kill "$SRV2" 2>/dev/null; SRV1=; SRV2=; }

# ---------------------------------------------------------------- suites
suite_dir() {
    case $1 in
        symfony-*) echo "$TMP/symfony";;
        dbal)      echo "$TMP/dbal";;
        orm)       echo "$TMP/orm";;
    esac
}
suite_args() {
    case $1 in
        symfony-http-foundation) echo "--bootstrap bootstrap.php vendor/symfony/http-foundation/Tests";;
        # time-sensitive needs symfony/phpunit-bridge's ClockMock; without it KernelTest's
        # sleep(3600) really sleeps. HttpCacheTest (the other time-sensitive class) is run on its own.
        symfony-http-kernel)     echo "--bootstrap bootstrap.php --exclude-group time-sensitive vendor/symfony/http-kernel/Tests";;
        symfony-httpcache)       echo "--bootstrap bootstrap.php vendor/symfony/http-kernel/Tests/HttpCache/HttpCacheTest.php";;
        dbal)                    echo "tests";;
        orm)                     echo "--bootstrap bootstrap.php --exclude-group performance,locking_functional tests/Tests/ORM";;
    esac
}

# PHPUnit prints either "Tests: N, Assertions: N, Errors: N, ..." or "OK (N tests, N assertions)".
counts() {
    "$PHP" -r '
        $s = file_get_contents($argv[1]);
        $t = $f = $e = $k = 0;
        if (preg_match("/^Tests: .*$/m", $s, $m)) {
            foreach (explode(", ", rtrim($m[0], ".")) as $part) {
                [$k2, $v] = array_pad(explode(": ", $part), 2, 0);
                if ($k2 === "Tests") $t = (int) $v;
                elseif ($k2 === "Failures") $f = (int) $v;
                elseif ($k2 === "Errors") $e = (int) $v;
                elseif ($k2 === "Skipped") $k = (int) $v;
            }
        } elseif (preg_match("/^OK \((\d+) tests?/m", $s, $m)) {
            $t = (int) $m[1];
        }
        printf("tests=%d failures=%d errors=%d skipped=%d", $t, $f, $e, $k);
    ' "$1"
}
failing_names() {
    grep -oE '^[0-9]+\) [A-Za-z0-9_\\]+::[A-Za-z0-9_]+' "$1" | sed 's/^[0-9]*) //' | sort -u
}

run_mode() {   # $1 suite  $2 mode  $3.. env assignments
    local suite=$1 mode=$2; shift 2
    local dir out start rc secs
    dir=$(suite_dir "$suite")
    out=$TMP/$suite-$mode.txt
    start=$(date +%s)
    ( cd "$dir" || exit 127
      for kv in "$@"; do export "$kv"; done
      # shellcheck disable=SC2046
      timeout "$TIMEOUT" "$PHPBIN_FOR_MODE" run.php --no-configuration --do-not-cache-result $(suite_args "$suite") ) > "$out" 2>&1
    rc=$?
    secs=$(( $(date +%s) - start ))
    {   echo
        echo "== FAILING TESTS ($suite / $mode) =="
        failing_names "$out"
    } >> "$out"
    printf 'suite=%-24s mode=%-16s %s  rc=%d secs=%d %s\n' \
        "$suite" "$mode" "$(counts "$out")" "$rc" "$secs" "$(grep -m1 '^IGNIS ' "$out" || true)"
}

# ---------------------------------------------------------------- main
install
echo "== machine: $(uptime | sed 's/.*load average/load/')"
echo "== php:     $($PHP -v | head -1)"
echo "== ignis:   $BIN"
echo
for suite in $SUITES; do
    case $suite in symfony-*) start_servers;; esac
    PHPBIN_FOR_MODE=$PHP        run_mode "$suite" stock
    PHPBIN_FOR_MODE=$BIN        run_mode "$suite" ignis            "IGNIS_MODE=1"
    PHPBIN_FOR_MODE=$BIN        run_mode "$suite" "chaos-seed-$SEED_A" "IGNIS_MODE=1" "IGNIS_CHAOS=1" "IGNIS_CHAOS_SEED=$SEED_A" "IGNIS_NOISE=$NOISE"
    PHPBIN_FOR_MODE=$BIN        run_mode "$suite" "chaos-seed-$SEED_B" "IGNIS_MODE=1" "IGNIS_CHAOS=1" "IGNIS_CHAOS_SEED=$SEED_B" "IGNIS_NOISE=$NOISE"
    case $suite in symfony-*) stop_servers;; esac
    echo "   new under ignis vs stock : $(comm -13 <(failing_names "$TMP/$suite-stock.txt") <(failing_names "$TMP/$suite-ignis.txt") | tr '\n' ' ')"
    echo "   new under chaos vs stock : $(comm -13 <(failing_names "$TMP/$suite-stock.txt") <(cat <(failing_names "$TMP/$suite-chaos-seed-$SEED_A.txt") <(failing_names "$TMP/$suite-chaos-seed-$SEED_B.txt") | sort -u) | tr '\n' ' ')"
    echo
done
echo "== raw output and failing-test lists: $TMP/<suite>-<mode>.txt"
