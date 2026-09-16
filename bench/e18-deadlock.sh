#!/usr/bin/env bash
# H36 (ADR-0020 acceptance 5): the lock hazard is real and the policy contains it.
# Runs bench/php/e18_deadlock.php three ways on ONE PHP thread:
#   1. liblocklib on a `park` row      -> fiber B must report -2 (the mutex is held by a parked fiber)
#   2. liblocklib absent from the table -> `block`: the calls serialize, both return 1
#   3. universal park off entirely      -> same as 2, the control for the control
# A `park` run in which fiber B does NOT hit the held mutex refutes the hazard model and is itself
# a finding (HYPOTHESES.md H36).
set -uo pipefail
cd "$(dirname "$0")/.."
D=/tmp/e18; mkdir -p "$D"
cc -shared -fPIC -o "$D/liblocklib.so" bench/e18/locklib.c -lpthread || exit 1
export LD_LIBRARY_PATH=/opt/php85-zts/lib IGNIS_LOCKLIB="$D/liblocklib.so" IGNIS_THREADS=1 RUST_LOG=error
B=${BIN:-./target/release/ignis}
run() { echo "== $1"; shift; env "$@" timeout 30 "$B" --threads 1 bench/php/e18_deadlock.php 2>&1 | tail -4; echo "   rc=$?"; }
run "1. liblocklib on a park row"  IGNIS_PARK=liblocklib
run "2. liblocklib absent (block)" IGNIS_PARK=libcurl
run "3. universal park off"        IGNIS_NO_UNIVERSAL_PARK=1
