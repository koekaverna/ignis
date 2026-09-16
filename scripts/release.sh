#!/usr/bin/env bash
# Bumps crates/ignis/Cargo.toml to the given version, refreshes Cargo.lock (offline),
# and commits "release: vX.Y.Z". Never tags or pushes: the session git proxy refuses
# tag pushes (commit 8ffd758) — the printed commands are for the owner to run by hand.
#
# Usage: scripts/release.sh <version>   e.g. scripts/release.sh 0.0.2-rc.1
set -euo pipefail
cd "$(dirname "$0")/.."

version="${1:?usage: scripts/release.sh <version> (no leading v), e.g. 0.0.2-rc.1}"
case "$version" in
  v*) echo "pass the version without the leading v (got: $version)" >&2; exit 1 ;;
esac

if ! git diff --quiet || ! git diff --cached --quiet; then
  echo "working tree has uncommitted changes; commit or stash first" >&2
  exit 1
fi

sed -i.bak -E "s/^version = \"[^\"]+\"/version = \"$version\"/" crates/ignis/Cargo.toml
rm -f crates/ignis/Cargo.toml.bak
grep -qx "version = \"$version\"" crates/ignis/Cargo.toml || { echo "version bump did not take" >&2; exit 1; }

# No network: refreshes Cargo.lock's own entry for the ignis package from Cargo.toml
# (registry deps are already pinned; --offline confirms nothing needs fetching).
cargo update -p ignis --offline

git add crates/ignis/Cargo.toml Cargo.lock
git commit -m "release: v$version"

cat <<EOF

Committed release: v$version. Now run these yourself (the session git proxy refuses
tag pushes, per commit 8ffd758):

  git tag v$version
  git push origin v$version
EOF
