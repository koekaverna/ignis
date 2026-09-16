#!/usr/bin/env bash
# E18-B / H35 control: baseline ns/call for a zero-length read(2), no interposer. Two-build shape
# per ADR-0020/BACKLOG E18-A: a `universal-park`-off build (baseline) and a `universal-park`-on
# build (interposed), diffed. Until E18-I lands there is only one build and no gate to measure, so
# this prints interposed=n/a note=feature_absent rather than faking a delta.
#
# Builds are NOT this script's job right now (a smoke/phpt gate owns target/release/ignis on this
# box) -- IGNIS_E18_SKIP_BUILD=1 (default) skips any cargo build and reuses ./target/release/ignis
# for what would be the "interposed" arm once it exists; set it to 0 once E18-I adds the
# `--bench-read` subcommand (see bench/e18/readloop.c) and two feature-gated builds exist.
set -uo pipefail
cd "$(dirname "$0")/.."
echo "load: $(uptime | sed 's/.*load average/load average/')"

: "${IGNIS_E18_SKIP_BUILD:=1}"
if [ "$IGNIS_E18_SKIP_BUILD" != 1 ]; then
    echo "bench/e18-overhead.sh: no --bench-read subcommand yet (E18-I); nothing to build against. Set IGNIS_E18_SKIP_BUILD=1." >&2
    exit 1
fi

BIN=/tmp/e18-readloop
cc -O2 -o "$BIN" bench/e18/readloop.c || exit 1

ITERS="${ITERS:-10000000}"
REPS=3
sum=0
for i in $(seq 1 "$REPS"); do
    v=$("$BIN" "$ITERS")
    echo "baseline rep $i: ${v} ns/call ($ITERS zero-length reads)"
    sum=$(awk -v s="$sum" -v v="$v" 'BEGIN{print s+v}')
done
avg=$(awk -v s="$sum" -v n="$REPS" 'BEGIN{printf "%.2f", s/n}')

echo "e18: overhead_ns baseline=${avg} interposed=n/a note=feature_absent"
