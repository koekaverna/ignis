#!/usr/bin/env bash
# Push HEAD to night-1, the branch of record (owner note 2026-09-16: the claude/* ref is the session's
# scratch branch and is no longer pushed). Retries on network errors.
set -uo pipefail
cd "$(dirname "$0")/.."
for delay in 2 4 8 16 0; do
  if git push -u origin "HEAD:refs/heads/night-1" 2>&1 | tail -1; then break; fi
  [ "$delay" = 0 ] && { echo "push to night-1 failed"; break; }
  sleep "$delay"
done
