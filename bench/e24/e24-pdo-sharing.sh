#!/usr/bin/env bash
# E24: one PostgreSQL connection, two fibers — the shape Symfony's container makes by default.
#
# `DoctrineFiberScopePass` gives every fiber its own EntityManager (V-69), but until this suite
# existed the manager's `Doctrine\DBAL\Connection` stayed a plain shared service, so every fiber
# ended up on one PDO handle. A query parks the fiber inside libpq; the next fiber then walks into
# the same connection, and PostgreSQL answers in the order the queries arrived, not the order the
# fibers expect.
#
# Four arms, and three of them are controls that decide whether the run measured anything:
#   own       one handle per fiber              MUST pass — otherwise the harness is broken
#   shared    one handle, N fibers              MUST fail — libpq is not reentrant per connection
#                                               and never will be; this is why the container must
#                                               not hand one connection to two fibers
#   symfony   N overlapping requests on /pg     MUST pass — the product path
#   control   the same, fiber scoping disabled  MUST fail — or the probe stopped reaching the bug
#
# Everything runs in docker: PostgreSQL in its own container, the server and curl together in
# another on the same network (published ports do not reach this box).
#
#   bench/e24/e24-pdo-sharing.sh            # E24_KEEP_PG=1 leaves the database up between runs
set -uo pipefail
cd "$(dirname "$0")/../.."

NET=${E24_NET:-ignis-e24}
PG=${E24_PG:-e24-pg}
IMAGE=${IGNIS_PHP_IMAGE:-ghcr.io/koekaverna/ignis-php:8.5.10-zts}
FIBERS=${FIBERS:-4}
REQUESTS=${REQUESTS:-6}
PORT=${PORT:-8195}
APP=bench/e21/app

if [ -z "${E24_IN_DOCKER:-}" ]; then
  command -v docker >/dev/null || { echo "docker needed"; exit 2; }
  docker network inspect "$NET" >/dev/null 2>&1 || docker network create "$NET" >/dev/null
  if ! docker ps --filter "name=^${PG}$" --format '{{.Names}}' | grep -qx "$PG"; then
    docker rm -f "$PG" >/dev/null 2>&1
    docker run -d --name "$PG" --network "$NET" \
      -e POSTGRES_USER=ignis -e POSTGRES_PASSWORD=ignis -e POSTGRES_DB=ignis postgres:17-alpine >/dev/null
  fi
  for _ in $(seq 1 60); do docker exec "$PG" pg_isready -q -U ignis && break; sleep 1; done
  docker exec "$PG" pg_isready -q -U ignis || { echo "postgres never became ready"; exit 1; }

  docker run --rm --network "$NET" -u "$(id -u):$(id -g)" -v "$PWD":/work -w /work \
    -e HOME=/tmp -e E24_IN_DOCKER=1 -e FIBERS="$FIBERS" -e REQUESTS="$REQUESTS" -e PORT="$PORT" -e E24_PG="$PG" \
    "$IMAGE" bash bench/e24/e24-pdo-sharing.sh "$@"
  status=$?
  [ -n "${E24_KEEP_PG:-}" ] || docker rm -f "$PG" >/dev/null 2>&1
  exit $status
fi

# ---- inside the container ----
export LD_LIBRARY_PATH=/opt/php85-zts/lib
export PG_PDO_DSN="pgsql:host=$PG;dbname=ignis" PG_USER=ignis PG_PASSWORD=ignis
export DATABASE_URL="postgresql://ignis:ignis@$PG:5432/ignis?serverVersion=17&charset=utf8"
BIN=${IGNIS_BIN:-./target/release/ignis}
fail=0

echo "== raw PDO handles"
own=$(timeout -k 5 60 "$BIN" --threads 1 bench/php/e24_pdo_sharing.php own "$FIBERS" 2>&1 | grep '^e24 ')
# -k, because the third failure mode of a shared handle is a fiber that never comes back: it waits
# for bytes another fiber already took, and then a graceful shutdown waits for that fiber forever.
shared=$(timeout -k 5 30 "$BIN" --threads 1 bench/php/e24_pdo_sharing.php shared "$FIBERS" 2>&1 | grep '^e24 ')
[ -n "$shared" ] || shared="e24 shared fibers=$FIBERS ok=0 — the fibers never came back (wedged, killed at 30 s)"
echo "  $own"
echo "  $shared"
case "$own" in *"ok=$FIBERS "*"errors=0 "*) ;; *) echo "  FAIL: the control arm did not answer $FIBERS correct rows"; fail=1;; esac
case "$shared" in *"ok=$FIBERS "*) echo "  FAIL: the shared handle stopped failing — the harness is measuring nothing"; fail=1;; esac

if [ ! -f "$APP/vendor/autoload.php" ]; then
  echo "== installing the fixture (composer)"
  COMPOSER_HOME=/tmp/composer timeout 900 composer --working-dir="$APP" update --no-scripts --no-interaction 2>&1 | tail -2
fi
# The path repository copies the packages in, so a source change needs copying over the copy.
for package in runtime symfony-runtime doctrine; do
  [ -d "$APP/vendor/ignis/$package" ] && cp -r "php/packages/$package/." "$APP/vendor/ignis/$package/"
done

# $1 label, $2 "clean" (every request must get its own row) or "control" (some must not), rest: env
symfony_probe() {
  local label="$1" want="$2"; shift 2
  rm -rf "$APP/var/cache"
  ( export IGNIS_THREADS=1 IGNIS_LISTEN=127.0.0.1:$PORT APP_ENV=prod APP_DEBUG=0 \
      IGNIS_PHP_INI="$PWD/$APP/php.ini" "$@"
    exec "$BIN" --threads 1 "$APP/public/index.php" ) > /tmp/e24-server.log 2>&1 &
  local server=$!
  local up=0
  for _ in $(seq 1 100); do curl -sf -u alice:alicepw "http://127.0.0.1:$PORT/pg?tag=warm&sleep=0" >/dev/null && { up=1; break; }; sleep 0.2; done
  if [ "$up" != 1 ]; then echo "  FAIL: $label — the app never answered"; tail -3 /tmp/e24-server.log; kill -9 $server 2>/dev/null; fail=1; return; fi

  local clients="" i sleep_for
  for i in $(seq 1 "$REQUESTS"); do
    sleep_for=$(awk "BEGIN{printf \"%.2f\", 0.35 - $i * 0.04}")
    curl -s -m 30 -w " elapsed=%{time_total}" -u alice:alicepw \
      "http://127.0.0.1:$PORT/pg?tag=r$i&sleep=$sleep_for" > "/tmp/e24-r$i.json" &
    clients="$clients $!"
  done
  for client in $clients; do wait "$client"; done   # not a bare `wait`: the server is a background job too
  local backends
  backends=$(psql "postgresql://ignis:ignis@$PG:5432/ignis" -tAc \
    "select count(*) from pg_stat_activity where datname='ignis' and application_name <> 'psql'" 2>/dev/null)
  kill $server 2>/dev/null
  for _ in $(seq 1 20); do kill -0 $server 2>/dev/null || break; sleep 0.5; done
  kill -9 $server 2>/dev/null; wait $server 2>/dev/null

  local mismatches=0
  for i in $(seq 1 "$REQUESTS"); do
    echo "  r$i: $(cat "/tmp/e24-r$i.json")"
    grep -q "\"tag\":\"r$i\",\"marker\":\"r$i\"" "/tmp/e24-r$i.json" || mismatches=$((mismatches + 1))
  done
  echo "  $label: $mismatches of $REQUESTS requests did not get their own row; distinct connection objects $(cat /tmp/e24-r*.json | grep -o '"connection":[0-9]*' | sort -u | wc -l), backends on the server ${backends:-?}"
  if [ "$want" = clean ] && [ "$mismatches" != 0 ]; then echo "  FAIL: $label must keep every request on its own connection"; fail=1; fi
  if [ "$want" = control ] && [ "$mismatches" = 0 ]; then echo "  FAIL: the control stopped reproducing the bug — the probe is measuring nothing"; fail=1; fi
}

# Sequential requests: is a finished request's connection reused by the next one, and when is it
# closed? The per-fiber EntityManager is dropped at request end (V-67), but EntityManager and
# UnitOfWork reference each other, so refcounting cannot free the cycle — only the collector can,
# and `IGNIS_LOOP_GC` takes it off the hot path by default (gc_disable + a collection at an idle
# point once the root buffer crosses IGNIS_LOOP_GC_ROOTS).
# $1 label, rest: env for the server
reuse_probe() {
  local label="$1"; shift
  rm -rf "$APP/var/cache"
  ( export IGNIS_THREADS=1 IGNIS_LISTEN=127.0.0.1:$PORT APP_ENV=prod APP_DEBUG=0 IGNIS_PHP_INI="$PWD/$APP/php.ini" "$@"
    exec "$BIN" --threads 1 "$APP/public/index.php" ) > /tmp/e24-server.log 2>&1 &
  local server=$! up=0
  for _ in $(seq 1 100); do curl -sf -u alice:alicepw "http://127.0.0.1:$PORT/pg?tag=warm&sleep=0" >/dev/null && { up=1; break; }; sleep 0.2; done
  if [ "$up" != 1 ]; then echo "  FAIL: $label — the app never answered"; kill -9 $server 2>/dev/null; fail=1; return; fi

  local n=${SEQUENTIAL:-30} backends="" i
  for i in $(seq 1 "$n"); do
    backends="$backends $(curl -s -m 30 -u alice:alicepw "http://127.0.0.1:$PORT/pg?tag=s$i&sleep=0" | grep -o '"backend":[0-9]*' | cut -d: -f2)"
  done
  local live
  live=$(psql "postgresql://ignis:ignis@$PG:5432/ignis" -tAc \
    "select count(*) from pg_stat_activity where datname='ignis' and application_name <> 'psql'" 2>/dev/null)
  kill $server 2>/dev/null
  for _ in $(seq 1 20); do kill -0 $server 2>/dev/null || break; sleep 0.5; done
  kill -9 $server 2>/dev/null; wait $server 2>/dev/null

  echo "  $label: $(echo $backends | tr ' ' '\n' | sort -u | grep -c '[0-9]') distinct backends for $n sequential requests, ${live:-?} still open on the server at the end"
  # Not a pool: a new backend per request is expected. What must not come back is the leak — a
  # connection released only when a cycle collection happens to run (V-85: 8 and 31 left open here).
  if [ "${live:-99}" -gt 2 ]; then
    echo "  FAIL: $label left ${live} connections open after $n sequential requests"
    fail=1
  fi
}

echo "== are connections reused once a request releases them?"
reuse_probe "loop GC on (the default)"
reuse_probe "loop GC off, PHP collects cycles itself" IGNIS_LOOP_GC=0

echo "== symfony through Doctrine, $REQUESTS overlapping requests on one thread"
symfony_probe "with fiber scoping" clean
echo "== control: the same app with the Ignis Doctrine bundle removed"
symfony_probe "without fiber scoping" control IGNIS_NO_DOCTRINE_SCOPE=1

[ "$fail" = 0 ] && echo "E24: GREEN" || echo "E24: RED"
exit $fail
