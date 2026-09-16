#!/usr/bin/env bash
# Builds the true-async php-src fork (branch async-core = PR #22561 head) as ZTS + embed into /opt/php86-async-zts.
# Idempotent: skips if the prefix already has a ZTS php reporting 8.6.0-dev with the async ABI header installed.
set -euo pipefail
BRANCH="${ASYNC_BRANCH:-async-core}"
PREFIX="${PREFIX:-/opt/php86-async-zts}"
SRC="${PHP_ASYNC_SRC:-$HOME/php-src-async}"
JOBS="${JOBS:-$(nproc)}"

if [ -x "$PREFIX/bin/php" ] && [ -f "$PREFIX/include/php/Zend/zend_async_API.h" ] \
   && "$PREFIX/bin/php" -r 'exit(PHP_ZTS ? 0 : 1);' 2>/dev/null; then
  echo "async php already present at $PREFIX ($("$PREFIX/bin/php" -v | head -1)); skipping build"
  exit 0
fi
if [ ! -d "$SRC" ]; then
  git clone --depth 1 --branch "$BRANCH" https://github.com/true-async/php-src "$SRC"
fi
cd "$SRC"
./buildconf --force
./configure --prefix="$PREFIX" --enable-zts --enable-embed=shared \
  --enable-opcache --disable-all --enable-mbstring --enable-sockets \
  --enable-pdo --with-pdo-sqlite --with-sqlite3 --enable-fibers --with-zlib \
  --enable-cli --disable-cgi --disable-phpdbg --disable-zend-signals \
  --enable-test-scheduler
make -j"$JOBS"
make install
"$PREFIX/bin/php" -v
