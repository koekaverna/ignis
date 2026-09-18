# ADR-0027 — Build and distribution

Status: **accepted** for what ships today; **deferred** (with triggers) for the static artifact and
arm64 (main agent, owner ADR sweep 2026-09-17). Affects pain-map RoadRunner 7, FrankenPHP 7;
M2, M5.

## Context

Ignis needs PHP 8.5.10 ZTS with the embed SAPI built with `--disable-zend-signals`; the
distribution package (`libphp8.5-embed`) is NTS and older — checked with `nm -D` on 2026-09-16
(no `tsrm_startup`, no `ts_resource_ex`), so it cannot be used. The binary links `libphp.so`
dynamically and, through libcurl, about 35 shared libraries (krb5, gnutls, ldap, ssh2, rtmp…);
there is no `libphp.a` (`--enable-embed=shared`). What ships: the runtime image (V-39: 64 MB when measured, **84 MB since V-83**,
`ldd` clean inside, unprivileged) built from the PHP builder image already on GHCR, with
`release.yml` for tags (M5-1) — not yet exercised by a real tag.

## Options considered

Distro PHP (rejected: NTS); musl/Alpine static (rejected: pain-map RoadRunner 7 — the musl
allocator and DNS behaviour are the source of that pain, and V-5's fiber-stack profile was taken
on glibc); glibc `-static-pie` now (deferred: needs `--enable-embed=static`, a curl built with
fewer backends, and the interposition of ADR-0020 re-done with `-Wl,--wrap` because
`--export-dynamic-symbol` means nothing in a static link — BACKLOG M5-5 is the research);
shipping the binary + `libphp.so` as a tarball with a list of six runtime libraries (done as the
release asset, M5-1 — a stopgap, not the artifact).

## Decision

1. **Ship our own PHP 8.5 ZTS build**; distribution packages are unsupported and the docs say
   why (README "Build").
2. **The image is the artifact today** (`ghcr.io/koekaverna/ignis`, V-39); **the single official
   artifact is a glibc static binary** once M5-5 shows it is possible — no musl.
3. **Interposition moves to `--wrap` when static** (ties to ADR-0020): the symbol export used
   by universal park is a dynamic-linking mechanism.
4. **Extension matrix under ZTS**, with per-extension status: the build's list is `mbstring,
   sockets, pdo, pdo_sqlite, sqlite3, fibers, zlib, filter, ctype, tokenizer, session, iconv,
   pdo_pgsql, pgsql, curl, openssl, opcache` (`scripts/build-php.sh`). Exercised by E15 or a V-n:
   fibers, sockets (V-29), streams/openssl (V-25, V-26), curl and pdo_sqlite/sqlite3 (V-24),
   pdo_pgsql/pgsql (V-45, research 24), session (V-16), opcache (every run). Unmeasured under
   ZTS: mbstring, iconv, tokenizer, filter, ctype beyond their use in the suites. Xdebug: BACKLOG
   R-6.
5. **arm64: deferred.** Trigger: a user on arm64 hardware; the image build is multi-arch-capable
   in principle (buildx), unmeasured.

## Consequences

Better: one supported way to run — the image — with its `ldd` checked in CI. Worse: until the
static artifact exists, "download a binary" means the tarball plus six libraries the host must
provide (release README). Affects M2, M5-1, M5-5, E18.

## Triggers

Static artifact: M5-5's note says it is feasible. arm64: the first user. `--wrap`: the day the
static artifact exists.
