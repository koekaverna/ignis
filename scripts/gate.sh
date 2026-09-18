#!/usr/bin/env bash
# The gate a push must be green on, in CI's own order and with CI's own commands.
#
# It exists because on 2026-09-18 three pushes in a row left `main` red: `cargo deny` rejected a new
# dependency's licence, and nothing on the developer's side had run it. A gate that lives only in a
# documentation list is not a gate.
#
# The PHP half runs inside the builder image, not against the host's vendor tree, because that is
# what CI runs and the two have already disagreed once: phpstan fits in 128 MB here and crashed a
# worker there. A step that cannot run is a failure, never a silent pass (smoke.sh learned this the
# hard way when a missing vendor tree printed "skipped" into a green run for weeks).
#
# Usage: scripts/gate.sh [--fast]   # --fast skips smoke and the compat suites (minutes, not seconds)
set -euo pipefail
cd "$(dirname "$0")/.."

FAST=0
[ "${1:-}" = "--fast" ] && FAST=1

IMAGE=ghcr.io/koekaverna/ignis-php:8.5.10-zts
export PHP_CONFIG="${PHP_CONFIG:-/opt/php85-zts/bin/php-config}"
export LD_LIBRARY_PATH="${LD_LIBRARY_PATH:-/opt/php85-zts/lib}"

step() { echo; echo "== $*"; }

free_percent=$(df --output=pcent / | tail -1 | tr -dc '0-9')
if [ "$((100 - free_percent))" -lt 15 ]; then
  echo "less than 15% free on / -- clean up first (owner rule 2026-09-16)" >&2
  exit 1
fi

step "fmt";                    cargo fmt --all --check
step "clippy";                 cargo clippy --workspace --all-targets --locked -- -D warnings
step "the off build compiles"; cargo check --workspace --no-default-features --locked
step "deny";                   cargo deny check
step "nextest";                cargo nextest run --workspace

step "php half (in the builder image, as CI runs it)"
command -v docker >/dev/null || { echo "docker is required for the php half" >&2; exit 1; }
docker run --rm -v "$PWD":/w -w /w -e COMPOSER_ALLOW_SUPERUSER=1 "$IMAGE" bash -euc '
  find php/packages php/tests examples bench/php bench/e22 -name "*.php" \
       -not -path "*/vendor/*" -not -path "*/var/cache/*" -print0 \
    | xargs -0 -n1 -P4 /opt/php85-zts/bin/php -l > /dev/null
  cd php
  /opt/php85-zts/bin/php "$(command -v composer)" run-script stan
  /opt/php85-zts/bin/php vendor/bin/php-cs-fixer check --diff --show-progress=none
  /opt/php85-zts/bin/php vendor/bin/phpunit --colors=never
'

if [ "$FAST" = 1 ]; then
  echo; echo "GATE GREEN (fast: smoke and the compat suites were not run)"
  exit 0
fi

step "smoke";  scripts/smoke.sh

# The suites gate on their pass counts, so the run has to be kept and handed to ci-gate.sh -- a
# green e15-phpt.sh only means it finished, not that nothing regressed.
step "e15 phpt"
bench/e15-phpt.sh 2>&1 | tee /tmp/ignis-gate-e15-phpt.log
scripts/ci-gate.sh phpt /tmp/ignis-gate-e15-phpt.log

echo; echo "GATE GREEN"
