#!/usr/bin/env bash
# Builds PHP 8.5.x (NTS, embed, opcache) into /opt/php85-nts — the second engine ABI (S-NTS-MODE).
#
# Same flags as scripts/build-php.sh minus --enable-zts, in its own source tree so the two builds
# never share object files. NTS is one interpreter per process, so an Ignis built against it serves
# on a single PHP thread and has no `offload`: that mechanism is a second pool of PHP threads, each
# with its own TSRM context, and in NTS there is no second context to give them.
#
# Why it exists: every PHP package a distribution ships is NTS — deb.sury.org has no ZTS build of
# any version, embed included (checked 2026-09-21: libphp8.5-embed exports `executor_globals`, not
# `tsrm_startup`) — and most PECL extensions are neither tested nor safe under ZTS. This is the ABI
# an unmodified application's extensions are actually built for.
#
# Idempotent: skips if the prefix already has a non-ZTS php of the requested version.
set -euo pipefail
PHP_TAG="${PHP_TAG:-php-8.5.10}"
PREFIX="${PREFIX:-/opt/php85-nts}"
SRC="${PHP_NTS_SRC:-$HOME/php-src-nts}"
JOBS="${JOBS:-$(nproc)}"

if [ -x "$PREFIX/bin/php" ] && "$PREFIX/bin/php" -r 'exit(PHP_ZTS ? 1 : 0);' 2>/dev/null \
   && "$PREFIX/bin/php" -m 2>/dev/null | grep -q "^session$" \
   && "$PREFIX/bin/php" -m 2>/dev/null | grep -q "^pdo_pgsql$" \
   && nm -D --defined-only "$PREFIX/lib/libphp.so" 2>/dev/null | grep -qw zend_signal_globals; then
  echo "nts php already present at $PREFIX ($("$PREFIX/bin/php" -v | head -1)); skipping build"
  exit 0
fi

if [ ! -d "$SRC" ]; then
  git clone --depth 1 --branch "$PHP_TAG" https://github.com/php/php-src "$SRC"
fi
cd "$SRC"
./buildconf --force
# Two flags differ from scripts/build-php.sh on purpose, and both are about loading a
# distribution's extensions — which is the entire reason this ABI exists (V-113).
#
#   --with-external-pcre : the bundled PCRE2 is compiled with hidden visibility, so `pcre2_match_8`
#       and `pcre2_code_free_8` exist nowhere in the process. Every extension built against a
#       distribution PHP expects to resolve them from the system libpcre2 that its libphp links;
#       without this, sury's `mbstring` and `pgsql` fail to load with exactly those two symbols.
#   zend signals stay ON : `--disable-zend-signals` is in the thread-safe script for FrankenPHP
#       parity, because that build is the E4 baseline against the same libphp. Nothing compares
#       against this one, and the flag costs `zend_signal_globals` — which is what sury's `apcu`
#       fails to find.
# --with-external-pcre needs libpcre2-dev on the build host. Without it the bundled PCRE2 is used
# and two of sury's extensions stay unloadable, so the script says which rather than failing or
# pretending. `pgsql` and `mbstring` are the two.
EXTERNAL_PCRE=""
if pkg-config --exists libpcre2-8 2>/dev/null; then
  EXTERNAL_PCRE="--with-external-pcre"
else
  echo "note: libpcre2-dev is absent, so PCRE2 will be bundled with hidden visibility." >&2
  echo "      A distribution's mbstring and pgsql will not load against this build" >&2
  echo "      (undefined symbol: pcre2_match_8 / pcre2_code_free_8). Install libpcre2-dev to fix." >&2
fi

./configure --prefix="$PREFIX" --enable-embed=shared \
  --enable-opcache --disable-all --enable-mbstring --enable-sockets \
  --enable-pdo --with-pdo-sqlite --with-sqlite3 --enable-fibers --with-zlib \
  $EXTERNAL_PCRE \
  --enable-cli --disable-cgi --disable-phpdbg \
  --enable-filter --enable-ctype --enable-tokenizer --enable-session --with-iconv \
  --enable-bcmath \
  --with-pdo-pgsql --with-pgsql --with-curl --with-openssl \
  --with-libxml --enable-dom --enable-xml --enable-simplexml --enable-xmlreader --enable-xmlwriter \
  --enable-phar --enable-fileinfo --enable-posix
make -j"$JOBS"
make install
"$PREFIX/bin/php" -v
"$PREFIX/bin/php" -r 'exit(PHP_ZTS ? 1 : 0);' && echo "confirmed NTS"
