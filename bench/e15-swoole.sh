#!/usr/bin/env bash
# E15c / H22c: run Swoole's tests/swoole_runtime/*.phpt through php/packages/swoole/src/shim.php on the ignis binary
# and classify every failure by the first missing hook/API found in its output.
#
#   bench/e15-swoole.sh          # the 55 top-level tests
#   bench/e15-swoole.sh --all    # also the 107 tests in subdirectories (file_hook, sockets, ssl, ...)
#
# Never uses pkill; every child runs under `timeout` (20 s, SIGKILL after 2 more).
set -uo pipefail
ROOT=$(cd "$(dirname "$0")/.." && pwd)
BIN=$ROOT/target/release/ignis
PHP=/opt/php85-zts/bin/php
# CI clones into /home/user/cmp; fall back to $HOME so a local checkout can run it too.
SRC=${SWOOLE_SRC:-$([ -d /home/user/cmp/swoole-src ] && echo /home/user/cmp/swoole-src || echo "$HOME/cmp/swoole-src")}/tests/swoole_runtime
OUT=/tmp/swoole-e15
TIMEOUT=${E15_TIMEOUT:-20}
[ -x "$BIN" ] || { echo "missing $BIN (do not build from here)"; exit 2; }
[ -d "$SRC" ] || { echo "missing $SRC (swoole-src clone)"; exit 2; }
mkdir -p "$OUT/cases" "$OUT/include" "$OUT/out"

# Replacement for tests/include/bootstrap.php: the shim (also auto-prepended, since tests without a
# bootstrap still expect the Swoole classes to exist), then Swoole's own config/functions/Assert.
cat > "$OUT/include/bootstrap.php" <<EOF
<?php
require_once '$ROOT/php/packages/swoole/src/shim.php';
error_reporting(E_ALL ^ E_DEPRECATED);
require_once '$SRC/../include/config.php';
require_once '$SRC/../include/lib/src/Assert.php';
class Assert extends SwooleTest\\Assert { protected static \$throwException = false; }
class_alias(Assert::class, 'SwooleTest\\AssertShim');
EOF
cat > "$OUT/ignis.ini" <<EOF
auto_prepend_file=$ROOT/php/packages/swoole/src/shim.php
display_errors=1
display_startup_errors=1
error_reporting=E_ALL
log_errors=0
html_errors=0
memory_limit=1G
EOF

if [ "${1:-}" = "--all" ]; then
  mapfile -t TESTS < <(cd "$SRC" && find . -name '*.phpt' | sed 's#^\./##' | sort)
else
  mapfile -t TESTS < <(cd "$SRC" && ls *.phpt | sort)
fi

# section <phpt> <name>: prints one section body
section() { awk -v s="--$2--" '$0==s{f=1;next} /^--[A-Z_]+--$/{f=0} f' "$1"; }

# expect_match <expected-file> <actual-file> <F|E>: php-src run-tests EXPECTF semantics (subset)
expect_match() {
  "$PHP" -r '
    [$e, $a, $mode] = [file_get_contents($argv[1]), file_get_contents($argv[2]), $argv[3]];
    $norm = fn ($s) => rtrim(implode("\n", array_map("rtrim", explode("\n", str_replace("\r\n", "\n", $s)))));
    $e = $norm($e); $a = $norm($a);
    if ($mode !== "F") { exit($e === $a ? 0 : 1); }
    $e = preg_replace("/\r?\n/", "\n", $e);
    $e = preg_replace_callback("/%r(.*?)%r/s", fn ($m) => "\0R" . base64_encode($m[1]) . "\0", $e);
    $e = preg_quote($e, "/");
    $e = str_replace(["%e", "%s", "%S", "%a", "%A", "%w", "%i", "%d", "%x", "%f", "%c", "%u"],
        [preg_quote(DIRECTORY_SEPARATOR, "/"), "[^\r\n]+", "[^\r\n]*", ".+", ".*", "\s*", "[+-]?\d+", "\d+", "[0-9a-fA-F]+", "[+-]?\.?\d+\.?\d*(?:[Ee][+-]?\d+)?", ".", "\d+"], $e);
    $e = preg_replace_callback("/\x00R(.*?)\x00/", fn ($m) => base64_decode($m[1]), $e);
    exit(preg_match("/^" . $e . "$/s", $a) ? 0 : 1);
  ' "$1" "$2" "$3"
}

# classify <output-file>: first blocker found, as "kind: detail"
classify() {
  local o="$1" m
  if grep -q '^TIMEOUT' "$o"; then echo "hang: timeout ${TIMEOUT}s (blocking call or fiber parked forever)"; return; fi
  if m=$(grep -o 'Call to undefined function [A-Za-z0-9_\\]*' "$o" | head -1); then echo "function: ${m#Call to undefined function }"; return; fi
  if m=$(grep -o 'Class "[^"]*" not found' "$o" | head -1); then m=${m#Class \"}; echo "class: ${m%\" not found}"; return; fi
  if m=$(grep -o 'Undefined constant "[^"]*"' "$o" | head -1); then m=${m#Undefined constant \"}; echo "constant: ${m%\"}"; return; fi
  if m=$(grep -o 'Call to undefined method [A-Za-z0-9_\\:]*' "$o" | head -1); then echo "method: ${m#Call to undefined method }"; return; fi
  if grep -q 'Connection refused' "$o"; then echo "socket: connection refused"; return; fi
  if grep -q 'Unable to connect to tcp://\|Accept failed\|Address already in use' "$o"; then echo "socket: server/accept inside fiber (tcp hook has no bind/listen/accept)"; return; fi
  if grep -q 'Assert failed' "$o"; then echo "assert: $(grep -o 'Assert failed:[^\n]*' "$o" | head -1 | cut -c1-80)"; return; fi
  if grep -q 'Fatal error\|Uncaught' "$o"; then echo "fatal: $(grep -o 'Uncaught [A-Za-z\\]*: [^\n]*' "$o" | head -1 | cut -c1-80)"; return; fi
  if grep -q 'Warning:' "$o"; then echo "warning: $(grep -o 'Warning: [^\n]*' "$o" | head -1 | cut -c1-80)"; return; fi
  echo "mismatch: output differs"
}

pass=0; fail=0; skip=0
: > "$OUT/results.tsv"
for t in "${TESTS[@]}"; do
  name=${t%.phpt}; name=${name//\//__}
  case_php="$OUT/cases/$name.php"; exp="$OUT/cases/$name.exp"; o="$OUT/out/$name.txt"
  skipif=$(section "$SRC/$t" SKIPIF)
  body=$(section "$SRC/$t" FILE)
  mode=E; expected=$(section "$SRC/$t" EXPECT)
  if grep -q '^--EXPECTF--$' "$SRC/$t"; then mode=F; expected=$(section "$SRC/$t" EXPECTF); fi
  # SKIP: external hosts (never contacted from here) and fixtures that do not exist on this VM.
  reason=""
  if echo "$skipif$body" | grep -Eq 'skip_if_offline|baidu|qq\.com|gov\.cn|tsinghua|taobao|example\.com|httpbin|google\.com|yahoo'; then reason="external host"
  elif echo "$skipif$body" | grep -Eq "skip_if_extension_not_exist\('redis'\)|REDIS_SERVER"; then reason="redis fixture"
  elif echo "$skipif$body" | grep -Eq 'skip_if_pdo_not_support_mysql8|MYSQL_SERVER'; then reason="mysql fixture"
  elif echo "$skipif$body" | grep -Eq 'skip_if_no_ftp|FTP_'; then reason="ftp fixture"
  elif echo "$skipif" | grep -Eq 'skip_if_no_ssl|skip_if_no_http2|skip_if_extension_not_exist\(.(openssl|curl|pcntl)'; then reason="ext missing (openssl/curl/pcntl)"
  elif echo "$body" | grep -Eq 'skip_if_darwin'; then reason=""
  fi
  if [ -n "$reason" ]; then
    skip=$((skip+1)); printf "SKIP  %-45s %s\n" "$t" "$reason"; printf "%s\tSKIP\t%s\n" "$t" "$reason" >> "$OUT/results.tsv"; continue
  fi
  # Point the test's `require __DIR__ . '/../include/bootstrap.php'` at our bootstrap.
  printf '%s\n' "$body" | sed -E "s#__DIR__ \. '/(\.\./)+include/#'$OUT/include/#g" > "$case_php"
  printf '%s\n' "$expected" > "$exp"
  ( cd "$OUT/cases" && IGNIS_PHP_INI="$OUT/ignis.ini" timeout -k 2 "$TIMEOUT" "$BIN" "$case_php" </dev/null >"$o" 2>&1 ); rc=$?
  [ "$rc" = 124 ] || [ "$rc" = 137 ] && echo "TIMEOUT after ${TIMEOUT}s" >> "$o"
  if [ "$rc" = 0 ] && expect_match "$exp" "$o" "$mode"; then
    pass=$((pass+1)); printf "PASS  %s\n" "$t"; printf "%s\tPASS\t\n" "$t" >> "$OUT/results.tsv"
  else
    fail=$((fail+1)); why=$(classify "$o")
    diffline=$(diff <(sed 's/[[:space:]]*$//' "$exp") <(sed 's/[[:space:]]*$//' "$o") | grep '^[<>]' | head -1 | cut -c1-100)
    printf "FAIL  %-45s rc=%-3s %s\n      first diff: %s\n" "$t" "$rc" "$why" "$diffline"
    printf "%s\tFAIL\t%s\n" "$t" "$why" >> "$OUT/results.tsv"
  fi
done

echo
echo "== summary: PASS=$pass FAIL=$fail SKIP=$skip (of ${#TESTS[@]}); details in $OUT/results.tsv, outputs in $OUT/out/"
echo "== blockers (first missing hook/API per failing test -> number of tests)"
awk -F'\t' '$2=="FAIL"{print $3}' "$OUT/results.tsv" | sed -E 's/^(assert|mismatch|warning|fatal|hang): .*/\1: (see results.tsv)/' | sort | uniq -c | sort -rn
echo "== skips"
awk -F'\t' '$2=="SKIP"{print $3}' "$OUT/results.tsv" | sort | uniq -c | sort -rn
exit 0
