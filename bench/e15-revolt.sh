#!/usr/bin/env bash
# E15b / H22b — Revolt's abstract driver suite (revolt/event-loop test/Driver/DriverTest.php)
# run against Ignis\Revolt\IgnisDriver inside the ignis binary, compared with the same suite
# on StreamSelectDriver under the stock PHP CLI.
#
#   bench/e15-revolt.sh            # 1 baseline pair + REPS ignis runs + 4 env variants
#   REPS=10 bench/e15-revolt.sh    # more repetitions (the two known failures are timing races)
#
# phpunit must run INSIDE the ignis binary: IgnisDriver needs ignis_poll()/ignis_watch().
# Two things keep vendor/bin/phpunit from being usable directly under the embed SAPI, so the
# script writes its own entry point into $TMP (see the comments in phpunit-run.php):
#   1. the `#!/usr/bin/env php` shebang (the embed SAPI does not set CG(skip_shebang), so the
#      shebang is inline HTML and the `namespace` on the next line is a fatal error);
#   2. the dom/libxml/xmlwriter platform gate — this PHP build has the minimal Ignis extension
#      set and does not have them (the stock CLI fails that gate too), and PHPUnit only needs
#      them for XML config/logging, which --no-configuration avoids.
#   3. $_SERVER['PHP_SELF'] is unset under embed; PHPUnit's Configuration\Merger realpath()s it.
set -uo pipefail
cd "$(dirname "$0")/.."
ROOT=$PWD
BIN=$ROOT/target/release/ignis
PHP=${PHP_BIN:-/opt/php85-zts/bin/php}
AMPHP=$ROOT/php/amphp
TMP=${TMP_DIR:-/tmp/e15-revolt}
mkdir -p "$TMP"
# php/amphp/ignis.ini names prepend.php by an absolute path; regenerate it for this checkout.
sed "s#^auto_prepend_file=.*#auto_prepend_file=$ROOT/php/amphp/prepend.php#" "$ROOT/php/amphp/ignis.ini" > "$TMP/ignis.ini"
REPS=${REPS:-3}
BOOT=$AMPHP/test/bootstrap.php
mkdir -p "$TMP"

cat > "$TMP/phpunit-run.php" <<'EOF'
<?php declare(strict_types=1);
// Minimal PHPUnit entry point for the embed SAPI: no shebang, no dom/libxml/xmlwriter gate,
// and $_SERVER['PHP_SELF'] filled in. Everything else is stock PHPUnit.
$root = getenv('AMPHP_ROOT') ?: dirname(__DIR__) . '/php/amphp';
$_SERVER['PHP_SELF'] ??= $_SERVER['argv'][0] ?? __FILE__;
$_SERVER['SCRIPT_NAME'] ??= $_SERVER['PHP_SELF'];
$_SERVER['SCRIPT_FILENAME'] ??= $_SERVER['PHP_SELF'];
require $root . '/vendor/autoload.php';
exit((new PHPUnit\TextUI\Application)->run($_SERVER['argv']));
EOF

# Baseline twin of php/amphp/test/IgnisDriverTest.php: same abstract suite, same three
# re-enabled data-provider tests, but on StreamSelectDriver. Keep in sync with that file.
cat > "$TMP/SelectDriverCompatTest.php" <<'EOF'
<?php declare(strict_types=1);
namespace Ignis\Revolt\Test\Baseline;
use PHPUnit\Framework\Attributes\DataProvider;
use Revolt\EventLoop\Driver\DriverTest;
use Revolt\EventLoop\Driver\StreamSelectDriver;
final class SelectDriverCompatTest extends DriverTest
{
    public static function registrationArgs(): iterable
    {
        yield 'defer' => ['defer', [static function (): void {}]];
        yield 'delay' => ['delay', [0.005, static function (): void {}]];
        yield 'repeat' => ['repeat', [0.005, static function (): void {}]];
        yield 'onWritable' => ['onWritable', [\STDOUT, static function (): void {}]];
        yield 'onReadable' => ['onReadable', [\STDIN, static function (): void {}]];
        yield 'onSignal' => ['onSignal', [\SIGUSR1, static function (): void {}]];
    }
    public function getFactory(): callable { return static fn (): StreamSelectDriver => new StreamSelectDriver(); }
    public function testHandle(): void { self::assertNull($this->loop->getHandle()); }
    #[DataProvider('registrationArgs')]
    public function testDisableWithConsecutiveCancel(string $t, array $a): void { parent::testDisableWithConsecutiveCancel($t, $a); }
    #[DataProvider('registrationArgs')]
    public function testCallbackReferenceInfo(string $t, array $a): void { parent::testCallbackReferenceInfo($t, $a); }
    #[DataProvider('registrationArgs')]
    public function testCallbackRegistrationAndCancellationInfo(string $t, array $a): void { parent::testCallbackRegistrationAndCancellationInfo($t, $a); }
}
EOF

export AMPHP_ROOT=$AMPHP

summary() { grep -E '^(Tests:|OK \()' "$1" | tail -1; }          # phpunit's own summary line
failing() { sed -n '/There w.* \(failure\|error\)/,$p' "$1" | grep -oE '::test[A-Za-z]+' | sed 's/:://' | sort -u | tr '\n' ' '; }

echo "== machine: $(uptime | sed 's/.*load average/load/')"
echo "== php:    $($PHP -v | head -1)"
echo

# ---------------------------------------------------------------- baselines (stock CLI)
echo "-- [1] stock CLI, StreamSelectDriverTest.php as revolt ships it"
timeout 300 "$PHP" "$TMP/phpunit-run.php" --no-configuration --bootstrap "$BOOT" \
    "$AMPHP/vendor/revolt/event-loop/test/Driver/StreamSelectDriverTest.php" > "$TMP/out-select.txt" 2>&1
SEL_RC=$?
SEL_LINE=$(summary "$TMP/out-select.txt")
echo "   rc=$SEL_RC  $SEL_LINE"
echo "   failing: $(failing "$TMP/out-select.txt")"

echo "-- [2] stock CLI, StreamSelectDriver through the same class shape as IgnisDriverTest"
timeout 300 "$PHP" "$TMP/phpunit-run.php" --no-configuration --bootstrap "$BOOT" \
    "$TMP/SelectDriverCompatTest.php" > "$TMP/out-select-compat.txt" 2>&1
SELC_RC=$?
SELC_LINE=$(summary "$TMP/out-select-compat.txt")
echo "   rc=$SELC_RC  $SELC_LINE"
echo "   failing: $(failing "$TMP/out-select-compat.txt")"
echo

# ---------------------------------------------------------------- ignis
echo "-- [3] ignis binary, IgnisDriverTest.php (IGNIS_NO_STREAM_HOOK=1 IGNIS_NO_UNIVERSAL_PARK=1), $REPS run(s)"
IGN_LINE=""
for i in $(seq 1 "$REPS"); do
    IGNIS_PHP_INI=$TMP/ignis.ini IGNIS_NO_STREAM_HOOK=1 IGNIS_NO_UNIVERSAL_PARK=1 \
        timeout 300 "$BIN" "$TMP/phpunit-run.php" --no-configuration --bootstrap "$BOOT" \
        "$AMPHP/test/IgnisDriverTest.php" > "$TMP/out-ignis-$i.txt" 2>&1
    rc=$?
    line=$(summary "$TMP/out-ignis-$i.txt")
    [ -z "$IGN_LINE" ] && IGN_LINE=$line
    echo "   run $i: rc=$rc  $line"
    echo "           failing: $(failing "$TMP/out-ignis-$i.txt")"
    [ "$rc" -ge 128 ] && echo "           !! process died with signal $((rc - 128)): $(tail -1 "$TMP/out-ignis-$i.txt")"
done
echo

# ---------------------------------------------------------------- env variants
echo "-- [4] env variants (one run each; the two known failures are timing races, so compare the shape, not one run)"
run_variant() { # label env...
    local label=$1; shift
    ( for kv in "$@"; do export "$kv"; done
      timeout 300 "$BIN" "$TMP/phpunit-run.php" --no-configuration --bootstrap "$BOOT" \
          "$AMPHP/test/IgnisDriverTest.php" > "$TMP/out-var-$label.txt" 2>&1 )
    local rc=$?
    echo "   $label: rc=$rc  $(summary "$TMP/out-var-$label.txt")"
    echo "           failing: $(failing "$TMP/out-var-$label.txt")"
}
run_variant no-stream-hook-only  "IGNIS_PHP_INI=$TMP/ignis.ini" "IGNIS_NO_STREAM_HOOK=1"
run_variant no-park-only   "IGNIS_PHP_INI=$TMP/ignis.ini" "IGNIS_NO_UNIVERSAL_PARK=1"
run_variant both-hooks-on        "IGNIS_PHP_INI=$TMP/ignis.ini"
run_variant no-ini               "IGNIS_NO_STREAM_HOOK=1" "IGNIS_NO_UNIVERSAL_PARK=1"
echo

echo "== summary lines"
echo "   ignis  IgnisDriver          : $IGN_LINE"
echo "   stock  StreamSelectDriver   : $SELC_LINE   (same class shape)"
echo "   stock  StreamSelectDriverTest: $SEL_LINE   (as shipped)"

# Gate input: scripts/ci-gate.sh greps IGNIS_PASSED=<n>. Without this line the revolt gate
# reported "no baseline yet" and protected nothing (found 2026-09-16 raising the E15 baseline).
pass_count() { # phpunit summary line -> tests that passed
    local l=$1 t f e
    if [[ $l =~ OK\ \(([0-9]+)\ tests ]]; then echo "${BASH_REMATCH[1]}"; return; fi
    if [[ $l =~ Tests:\ ([0-9]+) ]]; then t=${BASH_REMATCH[1]}; else echo 0; return; fi
    [[ $l =~ Failures:\ ([0-9]+) ]] && f=${BASH_REMATCH[1]} || f=0
    [[ $l =~ Errors:\ ([0-9]+) ]] && e=${BASH_REMATCH[1]} || e=0
    echo $(( t - f - e ))
}
echo "IGNIS_PASSED=$(pass_count "$IGN_LINE")"
