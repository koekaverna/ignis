#!/usr/bin/env bash
# E21 (V-68, V-69): what do two overlapping requests share inside one PHP thread?
#
# The smallest Symfony app that can answer it — a firewall with two users, and one Doctrine entity.
# Each probe runs twice: once with the Ignis bundles registered and once without (the control), so
# the suite proves the bundles are what fixes it rather than asserting it. A control that does NOT
# leak means the harness stopped measuring anything, and that fails the run too.
#
#   bench/e21/e21-fiber-scope.sh            # installs the fixture's vendor on first use (docker)
set -uo pipefail
cd "$(dirname "$0")/../.."
APP=bench/e21/app
PORT=${PORT:-8193}
N=${N:-3}
BIN=${IGNIS_BIN:-./target/release/ignis}
export LD_LIBRARY_PATH=${LD_LIBRARY_PATH:-/opt/php85-zts/lib}

if [ ! -f "$APP/vendor/autoload.php" ]; then
  echo "== installing the fixture (composer in docker: our PHP build has no ext-phar)"
  command -v docker >/dev/null || { echo "docker needed to install the fixture"; exit 2; }
  timeout 900 docker run --rm -u "$(id -u):$(id -g)" \
    -v "$PWD/$APP":/app -v "$PWD/php/packages":/php/packages:ro -w /app composer:latest \
    update --no-scripts --ignore-platform-reqs --no-interaction 2>&1 | tail -3
fi
[ -f "$APP/vendor/autoload.php" ] || { echo "fixture not installed"; exit 1; }

# The path repository copies the packages in, so a source change needs copying over the copy.
for p in runtime symfony-runtime doctrine; do
  [ -d "$APP/vendor/ignis/$p" ] && cp -r "php/packages/$p/." "$APP/vendor/ignis/$p/"
done

mkdir -p "$APP/var"
rm -f "$APP/var/probe.sqlite"
"${IGNIS_STOCK_PHP:-/opt/php85-zts/bin/php}" -r '
$db = new PDO("sqlite:'"$APP"'/var/probe.sqlite");
$db->exec("CREATE TABLE IF NOT EXISTS thing (id INTEGER PRIMARY KEY, name VARCHAR(255) NOT NULL)");
$db->exec("INSERT OR REPLACE INTO thing (id,name) VALUES (1,\"seed\")");'

fail=0
# Credentials for the two overlapping requests. Two different users is what makes a token leak
# visible; the logout arms override both to none, because the property they measure is only read
# when there is no token to resolve the firewall from.
AUTH_A=(-u alice:alicepw)
AUTH_B=(-u bob:bobpw)

# $1 label, $2 route for A, $3 "leaks expected"(1) or "must not leak"(0), $4 route for B (default: A's),
# rest: env for the server. B needs a route of its own when the probe measures whether *another*
# request disturbs A -- sending both to the same route measures a shared singleton instead, which is
# a different defect and already covered above.
probe() {
  local label="$1" route="$2" want="$3" routeb="$4"; shift 4
  rm -rf "$APP/var/cache"
  ss -ltn "sport = :$PORT" | grep -q LISTEN && { echo "port $PORT busy"; exit 2; }
  ( export IGNIS_THREADS=1 IGNIS_LISTEN=127.0.0.1:$PORT APP_ENV=prod APP_DEBUG=0 \
      IGNIS_PHP_INI="$PWD/$APP/php.ini" "$@"
    exec "$BIN" --threads 1 "$APP/public/index.php" ) > /tmp/ignis-e21.log 2>&1 &
  local S=$!
  local up=0
  for _ in $(seq 1 100); do curl -sf "${AUTH_A[@]}" "http://127.0.0.1:$PORT$route?tag=warm&ms=0" >/dev/null && { up=1; break; }; sleep 0.2; done
  if [ "$up" != 1 ]; then echo "$label: app never answered"; head -3 /tmp/ignis-e21.log; kill $S 2>/dev/null; fail=1; return; fi

  local leaks=0
  for i in $(seq 1 "$N"); do
    curl -s -m 10 "${AUTH_A[@]}" "http://127.0.0.1:$PORT$route?tag=A$i&ms=300" > /tmp/e21-a.json & local PA=$!
    sleep 0.1
    curl -s -m 10 "${AUTH_B[@]}" "http://127.0.0.1:$PORT$routeb?tag=B$i&ms=0" > /tmp/e21-b.json & local PB=$!
    wait $PA; wait $PB
    # Anything that is not the probe's own "no leak" answer counts as one, a 500 included: a service
    # that throws because it lost its state has lost its state, and reading only for "leaked":true
    # let an exception in the *fixed* arm pass as zero leaks.
    case "$(cat /tmp/e21-a.json)" in *'"leaked":false'*) ;; *) leaks=$((leaks+1));; esac
  done
  kill $S 2>/dev/null; wait $S 2>/dev/null

  if [ "$want" = 1 ]; then
    [ "$leaks" = "$N" ] && echo "$label: leaks $leaks/$N  (control: the defect is real)" \
      || { echo "$label: leaks $leaks/$N — CONTROL DID NOT LEAK, the harness stopped measuring"; fail=1; }
  else
    [ "$leaks" = 0 ] && echo "$label: leaks 0/$N  ok" \
      || { echo "$label: leaks $leaks/$N  REGRESSION"; fail=1; }
  fi
}

echo "== security token (V-68)"
probe "  control, no IgnisBundle " /whoami 1 /whoami IGNIS_NO_SCOPE=1
probe "  with IgnisBundle        " /whoami 0 /whoami
echo "== Doctrine identity map (V-69)"
probe "  control, no bundle      " /em     1 /em     IGNIS_NO_DOCTRINE_SCOPE=1
probe "  with IgnisDoctrineBundle" /em     0 /em
echo "== a singleton holding fiber-scoped services (S-SINGLETON-CAPTURE)"
probe "  facade answers per request" /singleton 0 /singleton
echo "== a container service marked scoped (ADR-0042, S-SCOPED-CLASS)"
probe "  control, not marked     " /scoped 1 /scoped IGNIS_NO_SCOPED_SERVICE=1
probe "  marked ignis.scoped     " /scoped 0 /scoped

# S-SAPI-REQUEST-INFO: a form body on PUT/PATCH reaches the framework only if the SAPI carries it.
# Symfony 8's createFromGlobals() calls PHP 8.4's request_parse_body() for those methods, which asks
# SG(request_info) -- php://input is a different door and does not open this one.
echo "== a form body on PUT and PATCH is parsed, not just delivered"
rm -rf "$APP/var/cache"
( export IGNIS_THREADS=1 IGNIS_LISTEN=127.0.0.1:$PORT APP_ENV=prod APP_DEBUG=0 IGNIS_PHP_INI="$PWD/$APP/php.ini"
  exec "$BIN" --threads 1 "$APP/public/index.php" ) > /tmp/ignis-e21-body.log 2>&1 &
BODY_PID=$!
for _ in $(seq 1 100); do curl -sf -u alice:alicepw "http://127.0.0.1:$PORT/body" >/dev/null && break; sleep 0.2; done
for method in PUT PATCH POST; do
  answer=$(curl -s -m 8 -u alice:alicepw -X $method -d 'a=1&b=2' "http://127.0.0.1:$PORT/body")
  echo "  $method $answer"
  case "$answer" in
    *'"parsed":{"a":"1","b":"2"}'*) ;;
    *) echo "  $method FAILED: the form body did not reach \$request->request"; fail=1;;
  esac
done
kill $BODY_PID 2>/dev/null; wait $BODY_PID 2>/dev/null

echo "== lazy and scoped together (ADR-0042 open question: both change object creation)"
probe "  lazy + scoped           " /scoped 0 /scoped IGNIS_SCOPED_LAZY=1

echo "== service reset across requests (S-RESET-FIBER): A streams while B enters handle()"
probe "  control, no IgnisBundle " /reset  1 /whoami IGNIS_NO_SCOPE=1
probe "  with IgnisBundle        " /reset  0 /whoami

# The one framework service whose per-request property is read only when there is NO token: with one,
# LogoutUrlGenerator::getListener() resolves the firewall through security.token_storage, which is
# scoped already, and the arm measures nothing. Two firewalls are the other half -- with one, every
# request writes the same value and the leak is invisible.
echo "== the logout url generator on an anonymous request (two firewalls, no credentials)"
AUTH_A=(); AUTH_B=()
probe "  control, id not scoped  " /logoutprobe       1 /admin/logoutprobe IGNIS_NO_LOGOUT_SCOPE=1
probe "  main sleeps, admin runs " /logoutprobe       0 /admin/logoutprobe
probe "  admin sleeps, main runs " /admin/logoutprobe 0 /logoutprobe
AUTH_A=(-u alice:alicepw); AUTH_B=(-u bob:bobpw)
probe "  and authenticated too   " /logoutprobe       0 /admin/logoutprobe

[ "$fail" = 0 ] && echo "E21: GREEN" || echo "E21: FAILED"
exit $fail
