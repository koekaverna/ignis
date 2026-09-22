#!/usr/bin/env bash
# Builds the true-async php-src fork (branch async-core = PR #22561 head) as ZTS + embed into /opt/php86-async-zts,
# with patches/0001-test-scheduler-idle-hook.patch applied: backend/async_core.rs links against the
# `test_scheduler_set_idle_hook` that patch adds, so an unpatched prefix links nothing (V-7, V-8).
# Idempotent: skips if the prefix already has a ZTS php reporting 8.6.0-dev with the async ABI header
# installed and the idle hook exported.
set -euo pipefail
BRANCH="${ASYNC_BRANCH:-async-core}"
PREFIX="${PREFIX:-/opt/php86-async-zts}"
SRC="${PHP_ASYNC_SRC:-$HOME/php-src-async}"
JOBS="${JOBS:-$(nproc)}"
PATCH="$(cd "$(dirname "$0")/.." && pwd)/patches/0001-test-scheduler-idle-hook.patch"

# grep without -q on purpose: -q closes the pipe on the first match, nm dies of SIGPIPE, and under
# pipefail that is exit 141 (the first dispatch of backend-b.yml, 2026-09-22).
exports_idle_hook() {
  nm -D --defined-only "$1" | grep -w test_scheduler_set_idle_hook >/dev/null
}

if [ -x "$PREFIX/bin/php" ] && [ -f "$PREFIX/include/php/Zend/zend_async_API.h" ] \
   && "$PREFIX/bin/php" -r 'exit(PHP_ZTS ? 0 : 1);' 2>/dev/null \
   && exports_idle_hook "$PREFIX/lib/libphp.so"; then
  echo "async php already present at $PREFIX ($("$PREFIX/bin/php" -v | head -1)); skipping build"
  exit 0
fi
if [ ! -d "$SRC" ]; then
  git clone --depth 1 --branch "$BRANCH" https://github.com/true-async/php-src "$SRC"
fi
cd "$SRC"
if git apply --check --reverse "$PATCH" >/dev/null 2>&1; then
  echo "idle-hook patch already applied in $SRC"
else
  git apply "$PATCH"
fi
./buildconf --force
./configure --prefix="$PREFIX" --enable-zts --enable-embed=shared \
  --enable-opcache --disable-all --enable-mbstring --enable-sockets \
  --enable-pdo --with-pdo-sqlite --with-sqlite3 --enable-fibers --with-zlib \
  --enable-cli --disable-cgi --disable-phpdbg --disable-zend-signals \
  --enable-test-scheduler
make -j"$JOBS"
make install
"$PREFIX/bin/php" -v
exports_idle_hook "$PREFIX/lib/libphp.so"
