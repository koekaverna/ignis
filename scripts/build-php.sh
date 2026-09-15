#!/usr/bin/env bash
# Builds PHP 8.5.x (ZTS, embed, opcache, minimal extensions) into /opt/php85-zts.
# Idempotent: skips if the prefix already has a php binary of the requested version.
set -euo pipefail
PHP_TAG="${PHP_TAG:-php-8.5.10}"
PREFIX="${PREFIX:-/opt/php85-zts}"
SRC="${PHP_SRC:-/home/user/php-src}"
JOBS="${JOBS:-$(nproc)}"

# --disable-zend-signals: required by FrankenPHP (used as the E4 baseline against the same libphp).
if [ -x "$PREFIX/bin/php" ] && "$PREFIX/bin/php" -r 'exit(PHP_ZTS ? 0 : 1);' 2>/dev/null \
   && "$PREFIX/bin/php" -i 2>/dev/null | grep -q "Zend Signal Handling => disabled"; then
  echo "php already present at $PREFIX ($("$PREFIX/bin/php" -v | head -1)); skipping build"
  exit 0
fi

if [ ! -d "$SRC" ]; then
  git clone --depth 1 --branch "$PHP_TAG" https://github.com/php/php-src "$SRC"
fi
cd "$SRC"
./buildconf --force
./configure --prefix="$PREFIX" --enable-zts --enable-embed=shared \
  --enable-opcache --disable-all --enable-mbstring --enable-sockets \
  --enable-pdo --with-pdo-sqlite --with-sqlite3 --enable-fibers --with-zlib \
  --enable-cli --disable-cgi --disable-phpdbg --disable-zend-signals
make -j"$JOBS"
make install
"$PREFIX/bin/php" -v
