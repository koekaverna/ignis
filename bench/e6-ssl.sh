#!/usr/bin/env bash
# E6' / H25: ssl:// and STARTTLS through the stream hook. Needs openssl (cert) and /opt/php85-zts/bin/php (stock TLS servers).
set -uo pipefail
cd "$(dirname "$0")/.."
D=/tmp/e6-ssl; mkdir -p "$D"
# A tiny PKI: a CA and a leaf certificate for localhost signed by it (a self-signed leaf would be
# "CA used as end entity" for webpki). cert.pem = leaf key + leaf cert for the servers; ca.pem for clients.
# Regenerated when absent **or within an hour of expiry**. `-days 2` plus an absence-only guard is a
# fixture that rots on the third day: on 2026-09-20 this bench reported five verification failures
# that were nothing but certificates issued on the 16th, and because it also exited 0 regardless,
# nothing anywhere said so.
if [ ! -f "$D/cert.pem" ] || ! openssl x509 -in "$D/crt.pem" -checkend 3600 >/dev/null 2>&1; then
  rm -f "$D"/*.pem "$D"/*.csr "$D"/*.srl
  openssl req -x509 -newkey rsa:2048 -nodes -keyout "$D/ca-key.pem" -out "$D/ca.pem" -days 2 -subj "/CN=ignis test CA" >/dev/null 2>&1
  openssl req -newkey rsa:2048 -nodes -keyout "$D/key.pem" -out "$D/leaf.csr" -subj "/CN=localhost" >/dev/null 2>&1
  printf 'subjectAltName=DNS:localhost,IP:127.0.0.1\nbasicConstraints=CA:FALSE\nextendedKeyUsage=serverAuth\n' > "$D/ext.cnf"
  openssl x509 -req -in "$D/leaf.csr" -CA "$D/ca.pem" -CAkey "$D/ca-key.pem" -CAcreateserial -out "$D/crt.pem" -days 2 -extfile "$D/ext.cnf" >/dev/null 2>&1
  cat "$D/key.pem" "$D/crt.pem" > "$D/cert.pem"
fi
PIDS=(); for p in 8441 8442 8443; do PORT=$p DELAY_MS=200 CERT="$D/cert.pem" /opt/php85-zts/bin/php bench/php/e6_ssl_server.php 2>/dev/null & PIDS+=($!); done
sleep 1
echo "== hook on"
CAFILE="$D/ca.pem" timeout 60 ./target/release/ignis bench/php/e6_ssl.php > "$D/on.txt" 2>&1; on_rc=$?
cat "$D/on.txt"
echo "== nothing parks (control: IGNIS_PARK= )"
CAFILE="$D/ca.pem" IGNIS_PARK= timeout 60 ./target/release/ignis bench/php/e6_ssl.php > "$D/off.txt" 2>&1
head -1 "$D/off.txt"
kill "${PIDS[@]}" 2>/dev/null; wait 2>/dev/null || true

# The verdict, which this bench did not have: it printed its findings and exited 0 either way, so it
# could not be gated and was in neither smoke.sh nor ci.yml -- leaving libssl, which every outbound
# TLS byte goes through since rustls was deleted (V-49), with no proof anywhere that it parks.
ms_of() { sed -n 's/.*fetches (200 ms each) in \([0-9]*\) ms.*/\1/p' "$1" | head -1; }
on_ms=$(ms_of "$D/on.txt"); off_ms=$(ms_of "$D/off.txt")
echo "e6_ssl: parked_ms=${on_ms:-?} serialized_ms=${off_ms:-?} verify_rc=$on_rc"
fail=0
[ "$on_rc" = 0 ] || { echo "e6-ssl FAILED: a verification case or the STARTTLS upgrade did not do what it must"; fail=1; }
[ -n "$on_ms" ] && [ -n "$off_ms" ] || { echo "e6-ssl FAILED: no timing line, so nothing was measured"; fail=1; }
if [ -n "$on_ms" ] && [ -n "$off_ms" ]; then
  [ "$on_ms" -lt 400 ] || { echo "e6-ssl FAILED: three concurrent TLS fetches took ${on_ms} ms -- libssl is not parking"; fail=1; }
  [ "$off_ms" -gt 450 ] || { echo "e6-ssl FAILED: the control finished in ${off_ms} ms, so it did not serialize and the arm measures nothing"; fail=1; }
fi
[ "$fail" = 0 ] && echo "E6-SSL: GREEN" || echo "E6-SSL: FAILED"
exit $fail
