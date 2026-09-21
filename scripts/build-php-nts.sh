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
   && "$PREFIX/bin/php" -m 2>/dev/null | grep -q "^pdo_pgsql$"; then
  echo "nts php already present at $PREFIX ($("$PREFIX/bin/php" -v | head -1)); skipping build"
  exit 0
fi

if [ ! -d "$SRC" ]; then
  git clone --depth 1 --branch "$PHP_TAG" https://github.com/php/php-src "$SRC"
fi
cd "$SRC"
./buildconf --force
./configure --prefix="$PREFIX" --enable-embed=shared \
  --enable-opcache --disable-all --enable-mbstring --enable-sockets \
  --enable-pdo --with-pdo-sqlite --with-sqlite3 --enable-fibers --with-zlib \
  --enable-cli --disable-cgi --disable-phpdbg --disable-zend-signals \
  --enable-filter --enable-ctype --enable-tokenizer --enable-session --with-iconv \
  --enable-bcmath \
  --with-pdo-pgsql --with-pgsql --with-curl --with-openssl \
  --with-libxml --enable-dom --enable-xml --enable-simplexml --enable-xmlreader --enable-xmlwriter \
  --enable-phar --enable-fileinfo --enable-posix
make -j"$JOBS"
make install
"$PREFIX/bin/php" -v
"$PREFIX/bin/php" -r 'exit(PHP_ZTS ? 1 : 0);' && echo "confirmed NTS"
