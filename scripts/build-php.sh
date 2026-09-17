#!/usr/bin/env bash
# Builds PHP 8.5.x (ZTS, embed, opcache, minimal extensions) into /opt/php85-zts.
# E16 adds pdo_pgsql/pgsql/curl (offload auto-routing targets) and openssl (curl https, E6' ssl://).
# The xml family, phar, fileinfo and posix are here for the toolchain, not the runtime: without phar
# composer and the phars cannot run under this engine at all, and without dom/libxml PHPUnit cannot
# even read a phpunit.xml. Their absence is what forced every PHP tool through docker.
# ext-zip is deliberately NOT built: composer falls back to the `unzip` binary, which costs no
# libzip-dev on the build host.
# Idempotent: skips if the prefix already has a php binary of the requested version.
set -euo pipefail
PHP_TAG="${PHP_TAG:-php-8.5.10}"
PREFIX="${PREFIX:-/opt/php85-zts}"
SRC="${PHP_SRC:-$HOME/php-src}"
JOBS="${JOBS:-$(nproc)}"

# --disable-zend-signals: required by FrankenPHP (used as the E4 baseline against the same libphp).
if [ -x "$PREFIX/bin/php" ] && "$PREFIX/bin/php" -r 'exit(PHP_ZTS ? 0 : 1);' 2>/dev/null \
   && "$PREFIX/bin/php" -i 2>/dev/null | grep -q "Zend Signal Handling => disabled" \
   && "$PREFIX/bin/php" -m 2>/dev/null | grep -q "^session$" \
   && "$PREFIX/bin/php" -m 2>/dev/null | grep -q "^pdo_pgsql$" \
   && "$PREFIX/bin/php" -m 2>/dev/null | grep -q "^Phar$"; then
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
  --enable-cli --disable-cgi --disable-phpdbg --disable-zend-signals \
  --enable-filter --enable-ctype --enable-tokenizer --enable-session --with-iconv \
  --enable-bcmath \
  --with-pdo-pgsql --with-pgsql --with-curl --with-openssl \
  --with-libxml --enable-dom --enable-xml --enable-simplexml --enable-xmlreader --enable-xmlwriter \
  --enable-phar --enable-fileinfo --enable-posix
make -j"$JOBS"
make install
"$PREFIX/bin/php" -v
