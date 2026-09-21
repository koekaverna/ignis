#!/usr/bin/env bash
# S-NTS-MODE: what the non-thread-safe build must still do (V-113). Compiling and linking is the
# floor; each claim below is one that would break with nothing failing to build.
#
# Run with LD_LIBRARY_PATH unset — both prefixes install a same-soname `libphp.so` and the variable
# beats RUNPATH, so a stale one makes this binary die in the loader.
#
#   scripts/nts-checks.sh            # uses target-nts/release/ignis and /opt/php85-nts
set -uo pipefail
cd "$(dirname "$0")/.."
BIN=${IGNIS_NTS_BIN:-./target-nts/release/ignis}
PREFIX=${NTS_PREFIX:-/opt/php85-nts}
fail=0
say() { echo; echo "== $*"; }
bad() { echo "  FAILED: $*"; fail=1; }

[ -x "$BIN" ] || { echo "no $BIN — build it first (see CLAUDE.md)"; exit 2; }

say "the engine behind it is not thread-safe"
zts=$("$BIN" -r 'echo PHP_ZTS ? "1" : "0";' 2>/dev/null)
echo "  PHP_ZTS=$zts"
[ "$zts" = 0 ] || bad "this binary is linked against a thread-safe PHP; the ABI under test is absent"

say "fibers park: three concurrent 200 ms sleeps"
cat > /tmp/nts-park.php <<'PHP'
<?php
require __DIR__ . '/../php/packages/runtime/src/ignis.php';
$t = hrtime(true);
Ignis\all([Ignis\async(fn() => Ignis\sleep(200)), Ignis\async(fn() => Ignis\sleep(200)), Ignis\async(fn() => Ignis\sleep(200))]);
printf("nts_park_ms=%.0f\n", (hrtime(true) - $t) / 1e6);
PHP
sed -i "s#__DIR__ . '/../php/packages#'$PWD/php/packages#" /tmp/nts-park.php
ms=$(timeout 60 "$BIN" /tmp/nts-park.php 2>/dev/null | sed -n 's/^nts_park_ms=//p')
echo "  ${ms:-?} ms  (parked => ~200, serialized => ~600)"
[ -n "$ms" ] && [ "$ms" -lt 400 ] || bad "three 200 ms sleeps took ${ms:-no} ms — nothing parked"

say "fiber-scoped objects (ADR-0042) work on this ABI"
sem=$(timeout 60 "$BIN" bench/php/scoped_semantics.php 2>/dev/null | tail -1)
echo "  $sem"
grep -q "plain_untouched=true" <<<"$sem" && grep -q "clone_independent=true" <<<"$sem" \
  || bad "scoped semantics did not hold"

say "waitpid parks here too (V-112)"
wp=$(WAIT_MS=3000 timeout 60 "$BIN" bench/php/waitpid_parks.php 2>/dev/null | tr '\n' ' ')
echo "  $wp"
grep -q "answers_wrong=0" <<<"$wp" || bad "a parked wait changed what the call answers"

say "the three flags that need a second PHP thread are refused"
for flag in "--threads 2" "--offload 1" "--supervise"; do
  out=$("$BIN" $flag -r 'echo 1;' 2>&1); rc=$?
  printf "  %-14s exit=%s\n" "$flag" "$rc"
  [ "$rc" = 2 ] || bad "$flag was not refused (exit $rc); a second interpreter cannot exist here"
  grep -qi "thread-safe" <<<"$out" || bad "$flag was refused without saying why"
done

say "a stale LD_LIBRARY_PATH is a loud failure, not a subtle one"
if [ -d /opt/php85-zts/lib ]; then
  out=$(LD_LIBRARY_PATH=/opt/php85-zts/lib "$BIN" -r 'echo 1;' 2>&1)
  grep -q "executor_globals" <<<"$out" && echo "  dies in the loader, as expected" \
    || bad "expected a loader failure naming executor_globals, got: $(head -c 120 <<<"$out")"
else
  echo "  skipped (no thread-safe prefix on this machine to cross-link against)"
fi

echo
[ "$fail" = 0 ] && echo "NTS: GREEN" || echo "NTS: FAILED"
exit $fail
