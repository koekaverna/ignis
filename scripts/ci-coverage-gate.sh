#!/usr/bin/env bash
# PHP line-coverage floor. Usage: scripts/ci-coverage-gate.sh <phpunit --coverage-text log>
#
# A fixed floor, not a ratchet. `achieved - 5` is set once, by hand, when a body of test work lands
# (V-79 addendum 2: 36.60% on 2026-09-18 -> floor 31.6). A "must not decrease" rule on a ratio reddens
# every time somebody adds a source file, which teaches people to delete the gate rather than write
# a test. Raise FLOOR deliberately in the same commit that earns it.
set -uo pipefail
log="${1:?usage: ci-coverage-gate.sh <coverage log>}"
FLOOR="${COVERAGE_FLOOR:-31.6}"

percent=$(sed -nE 's/^[[:space:]]*Lines:[[:space:]]+([0-9]+\.[0-9]+)%.*/\1/p' "$log" | head -1)
if [ -z "$percent" ]; then
  echo "coverage gate: no 'Lines: NN.NN%' summary in $log — the run produced no coverage"
  exit 1
fi
if awk -v p="$percent" -v f="$FLOOR" 'BEGIN { exit (p >= f) ? 0 : 1 }'; then
  echo "coverage gate: lines=$percent% >= floor $FLOOR%  ok"
else
  echo "coverage gate: lines=$percent% < floor $FLOOR%  REGRESSION"
  exit 1
fi
