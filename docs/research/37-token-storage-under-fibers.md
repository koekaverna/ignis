# Research 37 — `security.token_storage` under interleaved fibers (H-SEC)

Date: 2026-09-17. Hypothesis under test (H-SEC): *`security.token_storage` is a container singleton,
so when request A parks on I/O and request B authenticates, A resumes and reads B's token — A
completes as B's user.*

**Result: CONFIRMED, 10/10, deterministically, on an app built from stock packages.** Everything
below was run against `./target/release/ignis` (frozen binary, not rebuilt for this). The probe app
lives in this session's scratchpad at `…/scratchpad/secapp` and is reproduced in full in §7.

## 1. The app under test

A minimal Symfony app — `framework-bundle`, `security-bundle`, `symfony/runtime` with
`extra.runtime.class = Ignis\Symfony\IgnisRuntime`, plus `ignis/runtime` and
`ignis/symfony-runtime` from the path repository, installed with composer in docker (our PHP has
no `ext-phar`, same reason as `bench/e20-sdkphp.sh`):

```
docker run --rm -u "$(id -u):$(id -g)" -e COMPOSER_HOME=/tmp/composer \
  -v "$PWD":/app -v /home/koe/projects/ignis/php/packages:/opt/ignis/php/packages:ro \
  -w /app composer:latest install --no-scripts --no-interaction
```

```
$ composer show | grep -E '^symfony/(security-bundle|security-core|security-http|http-kernel|framework-bundle|runtime|http-foundation) '
symfony/framework-bundle           7.4.19
symfony/http-foundation            8.1.7
symfony/http-kernel                8.1.7
symfony/runtime                    7.4.14
symfony/security-bundle            7.4.18
symfony/security-core              8.1.6
symfony/security-http              8.1.7
```

PHP `8.5.10 (cli) (built: Sep 17 2026 15:24:38) (ZTS)`. Two in-memory users, `alice`
(`ROLE_USER`) and `bob` (`ROLE_USER`, `ROLE_ADMIN`), one **stateless** firewall with `http_basic`,
`access_control` requiring `ROLE_USER` on `/whoami`. No session. The ADR-0011 override is in place —
`request_stack` is set to `Ignis\Symfony\FiberRequestStack` (verified in the compiled container,
§4). Served with **one** PHP thread: `IGNIS_THREADS=1`, `--threads 1`, `IGNIS_LISTEN=127.0.0.1:8189`
(8187 was already held by another probe in the same box; port is the only deviation from the brief).

The controller reads the identity, parks with `Ignis\sleep($ms)`, reads it again, and returns both.

## 2. The firewall really authenticates (control)

```
$ curl -s -o /dev/null -w 'http=%{http_code}\n' "http://127.0.0.1:8189/whoami?ms=0"        # no creds
http=401
$ curl -s -o /dev/null -w 'http=%{http_code}\n' -u alice:nope   "…/whoami?ms=0"            # wrong pw
http=401
$ curl -s -o /dev/null -w 'http=%{http_code}\n' -u alice:alicepw "…/whoami?ms=0"
http=200
```

And one request at a time, with the 300 ms park, is clean:

```
{"tag":"warm-alice","fiber":16,
 "before":{"token_storage":"alice","security_getUser":"alice","request_user":"alice","stack_user":"alice","is_admin":false},
 "after": {"token_storage":"alice","security_getUser":"alice","request_user":"alice","stack_user":"alice","is_admin":false},
 "leaked":false}
```

## 3. The leak

`N=10 ./run.sh` — for each pair: A (alice) is fired, parks 300 ms; 100 ms later B (bob) is fired
with no park, so B authenticates and finishes while A is suspended on the same OS thread.

Pair 1, verbatim:

```
A1 {"tag":"A1-alice","fiber":16,
    "before":{"token_storage":"alice","security_getUser":"alice","request_user":"alice","stack_user":"alice","is_admin":false},
    "after": {"token_storage":"bob",  "security_getUser":"bob",  "request_user":"alice","stack_user":"alice","is_admin":true},
    "leaked":true}
B1 {"tag":"B1-bob","fiber":3570,
    "before":{"token_storage":"bob","security_getUser":"bob","request_user":"bob","stack_user":"bob","is_admin":true},
    "after": {"token_storage":"bob","security_getUser":"bob","request_user":"bob","stack_user":"bob","is_admin":true},
    "leaked":false}
```

The interleave trace (worker stderr, `microtime(true)`) shows B running entirely inside A's park:

```
[A9-alice] 1789648198.773 before={… "token_storage":"alice" … "is_admin":false}
[B9-bob]   1789648198.880 before={… "token_storage":"bob"   … "is_admin":true}
[B9-bob]   1789648198.880 after ={… "token_storage":"bob"   … "is_admin":true}
[A9-alice] 1789648199.074 after ={… "token_storage":"bob"   … "is_admin":true}
```

```
== A-side leaks: 10 / 10
```

Answering the brief's questions directly:

| question | answer |
|---|---|
| did the username change across the sleep? | **yes** — `alice` → `bob`, for the *parking* request, every time |
| for which request? | the one that parked (A). The one that ran to completion inside the park (B) is unaffected |
| was the response served as the wrong user? | **yes for everything read after the await point.** `TokenStorageInterface::getToken()`, `Security::getUser()` and `Security::isGranted()` all answer as B. `isGranted('ROLE_ADMIN')` is `false` before the park and **`true`** after it, inside alice's request — a privilege escalation, not just a wrong label |

It is symmetric — a plain overwrite of one property, not a race with a preferred direction. Running
the same pair with the roles reversed (bob parks, alice interleaves):

```
--- A (bob, parked) ---
{"tag":"A-bob-admin","fiber":16,
 "before":{"token_storage":"bob",  … "is_admin":true},
 "after": {"token_storage":"alice", … "is_admin":false},"leaked":true}
--- B (alice, interleaved) ---
{"tag":"B-alice","fiber":153, … "leaked":false}
```

The admin *loses* his role mid-request. Both directions are the same bug.

## 4. Why, from the code

`security.token_storage` is one object with one property, and nothing in the request lifecycle
scopes it:

```
vendor/symfony/security-core/Authentication/Token/Storage/TokenStorage.php:26
    class TokenStorage implements TokenStorageInterface, ResetInterface
    {
        private ?TokenInterface $token = null;
```

The compiled container confirms it is a shared service, and confirms the ADR-0011 `RequestStack`
override *did* take (`Ignis\Symfony\FiberRequestStack`) while token storage did not get the same
treatment:

```
$ grep -rn "TokenStorage(" var/cache/prod/ | head -3
…/App_KernelProdContainer.php:233:  return $container->services['security.token_storage'] = new \Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorage();
…/App_KernelProdContainer.php:407:  … new \Symfony\Component\Security\Http\Logout\LogoutUrlGenerator(($container->privates['request_stack'] ??= new \Ignis\Symfony\FiberRequestStack()), …, ($container->services['security.token_storage'] ??= new … TokenStorage()));
```

The write happens once per request at `kernel.request`, and is never undone:

```
$ grep -rn "\->setToken(" vendor/symfony/security-http/ vendor/symfony/security-bundle/
…/Authentication/AuthenticatorManager.php:231:  $this->tokenStorage->setToken($authenticatedToken);
…/Firewall/ContextListener.php:149:            $this->tokenStorage->setToken($token);
…/Firewall/ExceptionListener.php:190:           $this->tokenStorage->setToken(null);
…  (LogoutListener, SwitchUserListener, AbstractPreAuthenticatedAuthenticator, Security::logout)
```

There is no `kernel.finish_request`/`kernel.terminate` listener that restores a previous token — the
save/restore that exists for sub-requests (`ContextListener`) is about the *session*, not about
interleaving. So the sequence is exactly: A sets `token=alice`, B sets `token=bob`, A reads `bob`.

**What is *not* affected, and why.** Anything that re-derives the identity from the request rather
than from the singleton:

- `Request::getUser()` — `alice` before and after, in every one of the 10 pairs. The `Request`
  object is the fiber's own (built from per-fiber superglobals, ADR-0006).
- `RequestStack::getCurrentRequest()->getUser()` — `alice` before and after, because the service is
  `Ignis\Symfony\FiberRequestStack` (ADR-0011): one stack per fiber via `Ignis\Scope`.
- Controller arguments resolved from token storage (`#[CurrentUser]`, `UserValueResolver`,
  `SecurityTokenValueResolver`) are bound *before* the controller body runs, so they are correct —
  the exposure is every read **after** an await point, including `isGranted()` in a voter, a
  `denyAccessUnlessGranted()` after a DB call, a Doctrine `blameable`/audit listener at flush, and
  anything logging "who did this".

So H-SEC is right about the mechanism and right about the consequence, with one refinement worth
keeping: the request object and the request stack are already safe, which is exactly why the bug is
easy to miss — the obvious `$request`-shaped checks all pass.

## 5. What this means for ADR-0011

ADR-0011's kill criterion reads: *"any framework service that keeps request state outside
`RequestStack` (e.g. session storage) leaking across interleaved requests → needs the fiber-scoped
container generalised."* This is that case, measured. V-16 tested `RequestStack` (100 concurrent
`/whoami`, 0 mismatches) and sessions; it did not install `security-bundle`, and `bench/e8-symfony.sh`
measures boot + throughput only — there was no coverage that could have caught this.

`docs/getting-started/symfony.md` currently says "`RequestStack` **and the request-scoped services
Symfony's DI container hands out** are fiber-scoped (V-16)". The second half is false as written:
`security.token_storage` is a plain shared service and is not fiber-scoped. That sentence should be
narrowed to what was measured, or the claim should be made true.

Two shapes of fix, neither prototyped here:

1. A `FiberTokenStorage extends TokenStorage` overriding `getToken`/`setToken` onto `Ignis\Scope`,
   installed as the `security.token_storage` service id — the same one-row move ADR-0011 already
   makes for `request_stack`, and it fits the "context" mechanism of the three-mechanism budget
   (ADR-0037) without adding a fourth. Note the `security.untracked_token_storage` id exists too and
   would need the same treatment when a session is enabled.
2. The general version: a fiber-scoped container layer, so this is not discovered one service at a
   time. More expensive, and the list of affected singletons is not yet enumerated (see §6).

The second is what the kill criterion actually asks for; the first is what makes the security case
safe today.

## 6. What was **not** tested

- **Only one thread** (`--threads 1`). Multi-thread makes it strictly worse, not better (each thread
  has its own container, so the leak is within a thread), but it was not measured.
- **Only a stateless `http_basic` firewall.** A session-backed firewall adds `ContextListener` and
  `security.untracked_token_storage`/`UsageTrackingTokenStorage` to the picture; the singleton is the
  same object, but the session interaction was not exercised.
- **No enumeration of the other leaking singletons.** Only `security.token_storage` was probed. Other
  obvious candidates — `security.firewall.map` context, `Symfony\Component\Security\Http\Firewall`'s
  per-request `ExceptionListener` bookkeeping, `TraceableEventDispatcher`, the profiler, Doctrine's
  `EntityManager` identity map, `LocaleAwareListener`/`Translator` locale, `Monolog` fingers-crossed
  buffers — were not measured. Any of them may have the same shape.
- **No fix was written or benchmarked.** §5 lists options only.
- **No `IGNIS_CHAOS` run.** The reproduction is deterministic with a plain 300 ms/100 ms stagger, so
  chaos was not needed to surface it; it might surface *more*.
- Fiber ids in the output (`16`, `3570`, `153`) come from `spl_object_id` and are reported only to
  show A and B ran on different fibers of the same thread.

## 7. Reproducing it

`…/scratchpad/secapp`, four files plus `composer.json`. The controller:

```php
public function __invoke(Request $request): JsonResponse
{
    $before = $this->snapshot($request);
    \Ignis\sleep((int) $request->query->get('ms', '300'));
    $after  = $this->snapshot($request);
    return new JsonResponse(['before' => $before, 'after' => $after, 'leaked' => $before !== $after]);
}

private function snapshot(Request $request): array
{
    return [
        'token_storage'    => $this->tokenStorage->getToken()?->getUserIdentifier(),
        'security_getUser' => $this->security->getUser()?->getUserIdentifier(),
        'request_user'     => $request->getUser(),
        'stack_user'       => $this->requestStack->getCurrentRequest()?->getUser(),
        'is_admin'         => $this->security->isGranted('ROLE_ADMIN'),
    ];
}
```

The driver (`run.sh`), with the server on one thread:

```bash
export LD_LIBRARY_PATH=/opt/php85-zts/lib IGNIS_THREADS=1 IGNIS_LISTEN=127.0.0.1:8189 APP_ENV=prod
./target/release/ignis --threads 1 public/index.php > server.log 2>&1 &
curl -s -u alice:alicepw "http://127.0.0.1:8189/whoami?tag=A-alice&ms=300" > a.json & PA=$!
sleep 0.1
curl -s -u bob:bobpw     "http://127.0.0.1:8189/whoami?tag=B-bob&ms=0"     > b.json & PB=$!
wait $PA; wait $PB
```

`a.json` has `"leaked":true` with `"token_storage":"bob"` and `"is_admin":true` in `after`.
