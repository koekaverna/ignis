#!/usr/bin/env bash
# Push the current branch to the harness branch and to night-1 (see DECISIONS.md). Retries on network errors.
set -uo pipefail
cd "$(dirname "$0")/.."
HARNESS_BRANCH="claude/ignis-php-fibers-tokio-644209"
for target in "$HARNESS_BRANCH" night-1; do
  for delay in 2 4 8 16 0; do
    if git push -u origin "HEAD:refs/heads/$target" 2>&1 | tail -1; then break; fi
    [ "$delay" = 0 ] && { echo "push to $target failed"; break; }
    sleep "$delay"
  done
done
