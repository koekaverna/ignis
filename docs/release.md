# Cutting a release

What the owner runs to ship a tagged release, what it produces, and how to check it. See
[VALIDATION.md V-39](../VALIDATION.md) for the runtime image acceptance and
[ROADMAP.md](../ROADMAP.md) M5 for status. `.github/workflows/release.yml` does the work;
`scripts/release.sh` only bumps the version and commits — the session git proxy refuses tag
pushes (commit 8ffd758), so tagging and pushing the tag are manual, by the owner, outside this
session.

## Before tagging

```
git status                              # clean tree; release.sh refuses otherwise
gh run list --workflow=ci.yml --branch=main --limit=1     # main is green
```

## Cut it

```
scripts/release.sh 0.0.2-rc.1           # bumps crates/ignis/Cargo.toml, commits "release: v0.0.2-rc.1"
git push                                # the version-bump commit, to main (or merge the night branch first)
git tag v0.0.2-rc.1
git push origin v0.0.2-rc.1
```

The tag push triggers `release.yml` (`on: push: tags: ["v*"]`). It:

1. builds `docker/Dockerfile` and pushes `ghcr.io/koekaverna/ignis:v0.0.2-rc.1`
2. smokes that pushed image — `/_ignis/health`, `ldd` has no "not found" (same check as `image.yml`)
3. runs `docker run ... --version` against it and fails the job if it doesn't print `ignis 0.0.2-rc.1`
4. extracts `ignis` + `libphp.so` from the image into `ignis-v0.0.2-rc.1-linux-x86_64.tar.gz`
5. builds release notes from `git log` since the previous `v*` tag
6. publishes the GitHub Release with that tarball attached, `prerelease: true` for any tag
   containing `-` (rc/beta/etc.)

Watch it: `gh run watch --workflow=release.yml` (do not use `gh workflow run` — that's a dispatch,
see below).

## Dry-running first (no real tag)

```
gh workflow run release.yml -f tag=v0.0.2-rc.1
```

Builds and smokes the same way, but does **not** push the image to `ghcr.io` and does **not**
create a git tag or GitHub Release (fixed in this audit — it used to do both, silently). The tag
input must match `crates/ignis/Cargo.toml`'s current version on `HEAD` exactly (same check as a
real run). The tarball lands as a workflow artifact — download it from the run page to inspect.

Note: this session must not run `gh workflow run` either (HARD LIMIT) — the owner runs the dry run.

## Where it lands

- Image: `ghcr.io/koekaverna/ignis:v0.0.2-rc.1` (and `:latest`/`:<sha>` only from `image.yml` on
  pushes to `main`, never from a release tag)
- Tarball: attached to the GitHub Release at `github.com/koekaverna/ignis/releases/tag/v0.0.2-rc.1`
- Release notes: commit log since the previous `v*` tag, in the release body

## Verify the published release

```
docker run --rm ghcr.io/koekaverna/ignis:v0.0.2-rc.1 --version    # -> ignis 0.0.2-rc.1
docker run -d -p 8080:8080 --name ignis-check ghcr.io/koekaverna/ignis:v0.0.2-rc.1
curl -s localhost:8080/_ignis/health                               # -> {"status":"ok",...}
docker exec ignis-check ldd /usr/local/bin/ignis | grep "not found" # -> nothing
docker rm -f ignis-check
```

(Port publishing is broken on some docker daemons — see V-39 — use the in-container `/dev/tcp`
probe from `release.yml` if `-p 8080:8080` doesn't come up.)

Or, without Docker, from the tarball: unpack it, `LD_LIBRARY_PATH=./ignis-v0.0.2-rc.1-linux-x86_64
./ignis --version`, plus install the six runtime libraries the tarball's `README.txt` names
(`libssl3t64 libsqlite3-0 libcurl4t64 libonig5 libpq5 zlib1g` + `ca-certificates`) and bring your
own `php/`, `examples/`, and `ignis.toml` (README.txt says so; the tarball is binary-only).

## Yanking a bad release

```
gh release delete v0.0.2-rc.1 --yes           # removes the GitHub Release + tarball, keeps the tag
git push origin :refs/tags/v0.0.2-rc.1        # deletes the tag (owner only — proxy refuses this too)
```

The `ghcr.io` image tag is not deleted by the above — remove it separately if it must not be
pullable:

```
gh api -X DELETE /user/packages/container/ignis/versions/<version-id>   # find <version-id> via
gh api /user/packages/container/ignis/versions
```

Ship a fixed version under a new tag rather than reusing the bad one.

## What is still unproven

Never run against a real tag as of this writing (ROADMAP.md M5). Everything below is reviewed by
reading, not observed:

- That `packages: write` + `contents: write` from a **tag-push** event (as opposed to `image.yml`'s
  proven branch-push event) actually grants `docker push` to `ghcr.io` and lets
  `softprops/action-gh-release` create the tag object and the Release.
- The "extract runtime binaries" step (`docker create` + `docker cp` of `libphp.so` out of a
  *published* image) — never executed; only the Dockerfile's own `COPY` of that same path has run.
- `softprops/action-gh-release@v2` itself: creating the release, attaching the tarball, marking
  prerelease for a `-rc.1`-style tag.
- Whether the job finishes inside its 45-minute timeout on a cold GHA cache (image.yml's build has
  always run warm-cached and finished in 2-3 minutes; a tag push after a long gap could miss cache).
- Registry propagation timing: the job pushes then immediately `docker run`/`docker create`s the
  same tag back — never observed to race on a real push (only on `image.yml`'s branch pushes).
- The now-fixed dry-run path (`load` instead of `push`, release step gated off) — the fix in this
  audit has not itself been dispatched, per the HARD LIMIT on triggering workflows.
- `scripts/release.sh`'s `cargo update -p ignis --offline` — not run here (no cargo allowed this
  session); relies on an already-populated local registry cache.
