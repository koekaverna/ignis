#!/usr/bin/env bash
# Regression gate for the E15 compat suites: pass counts must not drop below bench/results/e15-baseline.txt.
# Usage: scripts/ci-gate.sh <phpt|revolt|swoole|frankenphp> <log file>
set -uo pipefail
cd "$(dirname "$0")/.."
suite="$1"; log="$2"; base=bench/results/e15-baseline.txt; fail=0
check() { # key actual
  local min; min=$(awk -v k="$1" '$1==k {print $2}' "$base")
  if [ -z "$min" ]; then echo "gate: $1=$2 (no baseline yet)"; return; fi
  if [ "$2" -lt "$min" ]; then echo "gate: $1=$2 < baseline $min  REGRESSION"; fail=1; else echo "gate: $1=$2 >= baseline $min  ok"; fi
}
case "$suite" in
  phpt)
    # rows: | suite | mode | total | passed | ...
    while IFS='|' read -r _ s m _ p _; do
      s=$(echo "$s" | tr -d ' ' | tr '/' '_'); m=$(echo "$m" | tr -d ' '); p=$(echo "$p" | tr -d ' ')
      [ "$m" = main ] || [ "$m" = fiber ] || continue
      check "phpt.$m.$s" "$p"
    done < <(grep -E "^\| (Zend|ext)" "$log") ;;
  swoole) check swoole.pass "$(awk -F'\t' '$2=="PASS"' /tmp/swoole-e15/results.tsv 2>/dev/null | wc -l)" ;;
  frankenphp) check frankenphp.pass "$(sed -nE 's/^passed=([0-9]+).*/\1/p' "$log" | tail -1)" ;;
  revolt) check revolt.pass "$(sed -nE 's/.*IGNIS_PASSED=([0-9]+).*/\1/p' "$log" | tail -1)" ;;
  *) echo "unknown suite $suite"; exit 2 ;;
esac
exit $fail
