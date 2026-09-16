#!/usr/bin/env bash
# PreToolUse guard for subagents (porter/bencher/scribe): block edits to the FFI boundary
# (crates/ignis/src/php/**, crates/ignis-sys/**, crates/ignis/src/backend/**) and to any file
# containing an `unsafe` block. Exit 2 = deny with the message on stderr.
input=$(cat)
path=$(printf '%s' "$input" | python3 -c 'import json,sys; d=json.load(sys.stdin); print(d.get("tool_input",{}).get("file_path") or d.get("tool_input",{}).get("path") or "")' 2>/dev/null)
[ -z "$path" ] && exit 0
case "$path" in
  */crates/ignis/src/php/*|crates/ignis/src/php/*|*/crates/ignis-sys/*|crates/ignis-sys/*|*/crates/ignis/src/backend/*|crates/ignis/src/backend/*)
    echo "guard-ffi: $path is FFI/Zend/unsafe territory; only the main agent edits it (owner note 2026-09-16)." >&2; exit 2;;
esac
if [ -f "$path" ] && grep -q 'unsafe' "$path" 2>/dev/null; then
  echo "guard-ffi: $path contains unsafe code; only the main agent edits it." >&2; exit 2
fi
exit 0
