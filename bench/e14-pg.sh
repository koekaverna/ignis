#!/usr/bin/env bash
# E14 / H23: runtime-owned PostgreSQL pool. Needs a local PostgreSQL (pg_ctlcluster 16 main start; user/db ignis/ignis).
set -uo pipefail
cd "$(dirname "$0")/.."
BIN="${BIN:-./target/release/ignis}"
PGHOST="${PGHOST:-127.0.0.1}"; export PG_DSN="${PG_DSN:-host=$PGHOST user=ignis password=ignis dbname=ignis}"
pg_isready -h "$PGHOST" -q || { echo "postgres not ready on $PGHOST"; exit 1; }
echo "load: $(uptime | sed 's/.*load average/load average/')"
echo "== pool 20, 200 fibers"; POOL=20 FIBERS=200 N="${N:-2000}" timeout 120 "$BIN" bench/php/e14_pg.php
echo "== pool 50, 200 fibers (concurrency line only; server max_connections is 100)"; POOL=50 FIBERS=200 N=200 timeout 120 "$BIN" bench/php/e14_pg.php 2>&1 | head -2
