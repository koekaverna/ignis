# Cutting a release

What the owner runs to ship a tagged release, what it produces, and how to check it. See
[VALIDATION.md V-39](https://github.com/koekaverna/ignis/blob/main/VALIDATION.md) for the runtime image acceptance and
[ROADMAP.md](https://github.com/koekaverna/ignis/blob/main/ROADMAP.md) M5 for status. `.github/workflows/release.yml` does the work;
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
2. smokes that pushed image — `/_ignis/health`, `ldd` has no "not found" (M2's acceptance, V-39 addendum)
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

- Image: `ghcr.io/koekaverna/ignis:v0.0.2-rc.1` and `:latest` (since 2026-09-22 the tag is the only
  thing that publishes an image; the per-push `image.yml` is gone)
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

The workflow has since run against a real tag — `v0.1.0-rc.1` was published and verified by
pulling the image and the tarball and running `--version` on both (V-57), after the first attempt
failed on the tarball step and was fixed. What is still unobserved is narrower than it was, and is
what follows; it is reviewed by reading, not by running:

- Whether the job finishes inside its 45-minute timeout on a cold GHA cache (the per-push image
  build that kept the cache warm is gone since 2026-09-22; a tag push after a long gap misses it).
  `v0.1.0-rc.1` ran warm, so it did not test this.
- The now-fixed dry-run path (`load` instead of `push`, release step gated off) — the fix has not
  itself been dispatched, per the HARD LIMIT on triggering workflows.
- `scripts/release.sh`'s `cargo update -p ignis --offline` — relies on an already-populated local
  registry cache and has not been run here.

**Proven by `v0.1.0-rc.1` (V-57), and no longer on the list above:** that `packages: write` +
`contents: write` from a tag-push event grants `docker push` to `ghcr.io` and lets
`softprops/action-gh-release@v2` create the tag object, the Release and the prerelease marking; that
the "extract runtime binaries" step (`docker create` + `docker cp` of `libphp.so` out of the
*published* image) works — it is in fact the step the first tag **failed** on, because `tar -C`
changes where tar reads and not where it writes, and it was fixed and re-run; and that registry
propagation does not race, since the job pushed and immediately pulled the same tag back. Verified
as a user afterwards, both ways: `docker run … --version` and `gh release download` → extract →
`./ignis --version`, each printing `ignis 0.1.0-rc.1`.
