#!/usr/bin/env bash
# E6' / H25: ssl:// and STARTTLS through the stream hook. Needs openssl (cert) and /opt/php85-zts/bin/php (stock TLS servers).
set -uo pipefail
cd "$(dirname "$0")/.."
D=/tmp/e6-ssl; mkdir -p "$D"
# A tiny PKI: a CA and a leaf certificate for localhost signed by it (a self-signed leaf would be
# "CA used as end entity" for webpki). cert.pem = leaf key + leaf cert for the servers; ca.pem for clients.
if [ ! -f "$D/cert.pem" ]; then
  openssl req -x509 -newkey rsa:2048 -nodes -keyout "$D/ca-key.pem" -out "$D/ca.pem" -days 2 -subj "/CN=ignis test CA" >/dev/null 2>&1
  openssl req -newkey rsa:2048 -nodes -keyout "$D/key.pem" -out "$D/leaf.csr" -subj "/CN=localhost" >/dev/null 2>&1
  printf 'subjectAltName=DNS:localhost,IP:127.0.0.1\nbasicConstraints=CA:FALSE\nextendedKeyUsage=serverAuth\n' > "$D/ext.cnf"
  openssl x509 -req -in "$D/leaf.csr" -CA "$D/ca.pem" -CAkey "$D/ca-key.pem" -CAcreateserial -out "$D/crt.pem" -days 2 -extfile "$D/ext.cnf" >/dev/null 2>&1
  cat "$D/key.pem" "$D/crt.pem" > "$D/cert.pem"
fi
PIDS=(); for p in 8441 8442 8443; do PORT=$p DELAY_MS=200 CERT="$D/cert.pem" /opt/php85-zts/bin/php bench/php/e6_ssl_server.php 2>/dev/null & PIDS+=($!); done
sleep 1
echo "== hook on";  CAFILE="$D/ca.pem" timeout 60 ./target/release/ignis bench/php/e6_ssl.php; echo "client exit=$?"
echo "== nothing parks (control: IGNIS_PARK= )"; CAFILE="$D/ca.pem" IGNIS_PARK= timeout 60 ./target/release/ignis bench/php/e6_ssl.php 2>&1 | head -1
kill "${PIDS[@]}" 2>/dev/null; wait 2>/dev/null || true
