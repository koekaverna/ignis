#!/usr/bin/env bash
# Regression gate for the E15 compat suites: pass counts must not drop below bench/results/e15-baseline.txt.
# Usage: scripts/ci-gate.sh <phpt|revolt|frankenphp> <log file>
set -uo pipefail
cd "$(dirname "$0")/.."
suite="$1"; log="$2"; base=bench/results/e15-baseline.txt; fail=0
check() { # key actual
  local min; min=$(awk -v k="$1" '$1==k {print $2}' "$base")
  if [ -z "$min" ]; then echo "gate: $1=$2 (no baseline yet)"; return; fi
  if [ "$2" -lt "$min" ]; then echo "gate: $1=$2 < baseline $min  REGRESSION"; fail=1; else echo "gate: $1=$2 >= baseline $min  ok"; fi
}
# Per-test regression check (2026-09-17, V-50): the count gate alone hides a swap — one test
# regressing while another recovers keeps the total flat, which is exactly how the `can_block`
# regression in socket_read_params shipped. Compares the fresh .tsv against the one committed in
# HEAD: a test that PASSED there and does not pass now is a regression, whatever the total says.
# Tests absent from either side are ignored, so a different box running a different subset (the
# CI box skips more, research 21) raises nothing. In CI it warns instead of failing until a set
# from the CI box itself is committed — the counts are still a hard gate there.
check_set() { # <mode>-<suite>.tsv
  local f="bench/results/e15-phpt/$1" old new lost
  [ -f "$f" ] || return 0
  old=$(git show "HEAD:$f" 2>/dev/null) || { echo "gate: $1 (no committed set yet)"; return 0; }
  new=$(awk -F'\t' '$1=="PASSED" {print $2}' "$f" | sort)
  lost=$(comm -23 <(printf '%s\n' "$old" | awk -F'\t' '$1=="PASSED" {print $2}' | sort) <(printf '%s\n' "$new"))
  # only tests that still ran this time
  lost=$(comm -12 <(printf '%s\n' "$lost" | sed '/^$/d') <(awk -F'\t' '{print $2}' "$f" | sort))
  [ -z "$lost" ] && { echo "gate: $1 set ok (no test lost a PASS)"; return 0; }
  echo "gate: $1 REGRESSION — these passed in HEAD and do not now:"
  printf '       %s\n' $lost
  [ -n "${CI:-}" ] || fail=1
}

case "$suite" in
  phpt)
    # rows: | suite | mode | total | passed | ...
    while IFS='|' read -r _ s m _ p _; do
      s=$(echo "$s" | tr -d ' ' | tr '/' '_'); m=$(echo "$m" | tr -d ' '); p=$(echo "$p" | tr -d ' ')
      [ "$m" = main ] || [ "$m" = fiber ] || continue
      check "phpt.$m.$s" "$p"
      check_set "$m-$s.tsv"
    done < <(grep -E "^\| (Zend|ext)" "$log") ;;
  frankenphp) check frankenphp.pass "$(sed -nE 's/^passed=([0-9]+).*/\1/p' "$log" | tail -1)" ;;
  revolt) check revolt.pass "$(sed -nE 's/.*IGNIS_PASSED=([0-9]+).*/\1/p' "$log" | tail -1)" ;;
  *) echo "unknown suite $suite"; exit 2 ;;
esac
exit $fail
